<?php
declare(strict_types=1);

/*
 * Mindful A11y extension for TYPO3 integrating accessibility tools into the backend.
 * Copyright (C) 2026  Mindful Markup, Felix Spittel
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 */

namespace MindfulMarkup\MindfulA11y\Middleware;

use MindfulMarkup\MindfulA11y\Domain\Model\StructureAnalysisTicket;
use MindfulMarkup\MindfulA11y\Service\StructureAnalysisAuthorizationService;
use MindfulMarkup\MindfulA11y\Service\StructureAnalysisTicketService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\DateTimeAspect;
use TYPO3\CMS\Core\Context\VisibilityAspect;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Domain\DateTimeFactory;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Aspect\PreviewAspect;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;

/** Validates a signed analysis capability before frontend page resolution. */
final readonly class StructureAnalysisAuthenticationMiddleware implements MiddlewareInterface
{
    public function __construct(
        private StructureAnalysisTicketService $ticketService,
        private Context $context,
        private StructureAnalysisResponseHardener $hardener,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $token = $request->getQueryParams()[StructureAnalysisTicketService::TICKET_QUERY_PARAMETER] ?? null;
        if (!is_string($token) || $token === '') {
            return $handler->handle($request);
        }
        $ticket = $this->ticketService->validate($token);
        if ($ticket === null) {
            return $handler->handle($request);
        }

        $requestOrigin = $this->ticketService->originFromUrl((string)$request->getUri());
        $requestTarget = $this->ticketService->normalizeTarget((string)$request->getUri());
        $language = $request->getAttribute('language');
        if ($ticket->frontendOrigin !== $requestOrigin
            || $ticket->target !== $requestTarget
            || !$language instanceof SiteLanguage
            || $language->getLanguageId() !== $ticket->languageId
            || !$this->getAuthorizationService()->isTicketHolderAuthorized($ticket)
            // Authorization can involve several database queries. Do not grant
            // preview visibility if the ticket expired while those ran.
            || $ticket->isExpired(time())
        ) {
            return $handler->handle($request);
        }

        // The capability was issued and redeemed after the same current TYPO3
        // authorization checks. It is intentionally stateless and may be
        // redeemed again only during its very short validity window. Apply only
        // the preview aspects needed to resolve that exact signed frontend
        // target; no backend session is recreated.
        $this->context->setAspect('workspace', new WorkspaceAspect($ticket->workspaceId));
        $this->applyPreviewSimulation($request);
        $this->context->setAspect('frontend.preview', new PreviewAspect(true));

        $response = $handler->handle($request->withAttribute(StructureAnalysisTicket::REQUEST_ATTRIBUTE, $ticket));

        // Fail closed when an inner middleware short-circuited past
        // StructureAnalysisResponseMiddleware (e.g. a page-resolver redirect,
        // which runs outside it): no response produced under the ticket's
        // preview privileges may leave without the hardened CSP/no-store
        // headers, and the sandboxed frame must never follow redirects off the
        // signed target.
        if (!$this->hardener->isHardened($response, $ticket->backendOrigin)) {
            return $this->hardener->createNonScriptedErrorResponse($ticket->backendOrigin);
        }

        return $response;
    }

    /**
     * Applies the preview simulation parameters signed into the ticket target.
     *
     * PreviewUriBuilder adds ADMCMD_simUser/ADMCMD_simTime for access-restricted
     * pages, but core's PreviewSimulator only evaluates them for logged-in
     * backend users or offline workspaces — a ticketed live-workspace request
     * has neither (no backend session is recreated), so they are applied here.
     * Tampering is excluded: both parameters are part of the signed target
     * compared in process(). Where PreviewSimulator does run (offline workspace,
     * or a backend cookie reaching the iframe), it re-applies the same values.
     */
    private function applyPreviewSimulation(ServerRequestInterface $request): void
    {
        $queryParams = $request->getQueryParams();
        $simulatedTime = (int)($queryParams['ADMCMD_simTime'] ?? 0);
        $simulatedGroupId = (int)($queryParams['ADMCMD_simUser'] ?? 0);

        // Mirror a logged-in core frontend preview: the exact authorized page may
        // be hidden, but hidden content remains excluded and start/end-time
        // restrictions stay active. ADMCMD_simTime changes the time at which
        // those restrictions are evaluated; it does not disable them.
        $this->context->setAspect('visibility', new VisibilityAspect(true, false, false, false));
        if ($simulatedTime > 0) {
            $GLOBALS['SIM_EXEC_TIME'] = $simulatedTime;
            $GLOBALS['SIM_ACCESS_TIME'] = $simulatedTime - $simulatedTime % 60;
            $this->context->setAspect('date', new DateTimeAspect(DateTimeFactory::createFromTimestamp($simulatedTime)));
        }

        // Mirrors PreviewSimulator::simulateUserGroup(): group access checks read
        // the frontend.user aspect, and plugins read the user object itself, so
        // both are populated. Requires the frontend user created by the
        // typo3/cms-frontend/authentication middleware (this one runs after it).
        $frontendUser = $request->getAttribute('frontend.user');
        if ($simulatedGroupId > 0 && $frontendUser instanceof FrontendUserAuthentication) {
            $frontendUser->user[$frontendUser->usergroup_column] = (string)$simulatedGroupId;
            $frontendUser->userGroups[$simulatedGroupId] = [
                'uid' => $simulatedGroupId,
                'title' => '_PREVIEW_',
            ];
            $frontendUser->user[$frontendUser->userid_column] = PHP_INT_MAX;
            // Prevent updateOnlineTimestamp() from persisting the faked user.
            $frontendUser->user['is_online'] = $this->context->getPropertyFromAspect('date', 'timestamp');
            $this->context->setAspect('frontend.user', $frontendUser->createUserAspect());
        }
    }

    /**
     * Resolved on demand rather than injected: the authorization service pulls in
     * the backend module registry (which builds every registered module) and the
     * icon registry. This middleware sits in the frontend stack and bails without
     * a ticket on virtually every request, so that graph must not be constructed
     * for requests that will never redeem one.
     */
    private function getAuthorizationService(): StructureAnalysisAuthorizationService
    {
        return GeneralUtility::makeInstance(StructureAnalysisAuthorizationService::class);
    }
}
