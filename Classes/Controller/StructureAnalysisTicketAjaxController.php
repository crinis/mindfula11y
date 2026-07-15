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
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 */

namespace MindfulMarkup\MindfulA11y\Controller;

use MindfulMarkup\MindfulA11y\Service\GeneralModuleService;
use MindfulMarkup\MindfulA11y\Service\StructureAnalysisAuthorizationService;
use MindfulMarkup\MindfulA11y\Service\StructureAnalysisTicketService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\PreviewUriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Site\SiteFinder;

/** Issues signed tickets for rendering a frontend preview in the structure analyzer. */
final readonly class StructureAnalysisTicketAjaxController
{
    use JsonErrorResponseTrait;

    public function __construct(
        private StructureAnalysisTicketService $ticketService,
        private StructureAnalysisAuthorizationService $authorizationService,
        private GeneralModuleService $generalModuleService,
        private SiteFinder $siteFinder,
    ) {}

    public function ticketAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = json_decode((string)$request->getBody(), true);
        $pageId = is_array($body) ? (int)($body['pageId'] ?? 0) : 0;
        $languageId = is_array($body) ? (int)($body['languageId'] ?? 0) : 0;
        $backendUser = $this->getBackendUser();
        if ($backendUser === null) {
            return $this->unavailableResponse();
        }
        $page = $pageId > 0 ? BackendUtility::getRecord('pages', $pageId) : null;
        $workspaceId = $backendUser->workspace;
        if (!is_array($page)
            || !$this->authorizationService->isAuthorized($backendUser, $pageId, $languageId, $workspaceId)
            || !$this->isStructureAnalysisEnabled($pageId)
        ) {
            return $this->unavailableResponse();
        }
        $previewUrl = $this->buildPreviewUrl($page, $pageId, $languageId);
        if ($previewUrl === null) {
            return $this->unavailableResponse();
        }

        // Core registers the normalizedParams attribute unconditionally in the
        // backend stack. An unexpectedly absent one yields an empty origin, which
        // issue() rejects below as an invalid ticket scope.
        $normalizedParams = $request->getAttribute('normalizedParams');
        $backendOrigin = $normalizedParams instanceof NormalizedParams ? $normalizedParams->getRequestHost() : '';
        try {
            $result = $this->ticketService->issueAnalysisUrl(
                $previewUrl,
                $pageId,
                $languageId,
                $workspaceId,
                (int)($backendUser->user['uid'] ?? 0),
                $backendOrigin,
            );
        } catch (\InvalidArgumentException|\JsonException) {
            return $this->errorResponse('structure.error.previewUrl', 400);
        }

        return new JsonResponse([
            'url' => $result['url'],
            'requestId' => $result['requestId'],
        ]);
    }

    /**
     * The module renders the structure views (and their ticket requests) only
     * where Page TSconfig enables at least one structure feature, so a ticket
     * request for a page with both features disabled can only be a direct POST
     * around that page-tree restriction. The gate is enforced here at issuance,
     * where the session user's own TSconfig overrides apply; redemption keeps
     * relying on the signed, page-pinned ticket within its short lifetime.
     */
    private function isStructureAnalysisEnabled(int $pageId): bool
    {
        $pageTsConfig = $this->generalModuleService->getConvertedPageTsConfig($pageId);
        return $this->generalModuleService->hasHeadingStructureAccess($pageTsConfig)
            || $this->generalModuleService->hasLandmarkStructureAccess($pageTsConfig);
    }

    /** @param array<string, mixed> $page */
    private function buildPreviewUrl(array $page, int $pageId, int $languageId): ?string
    {
        try {
            $site = $this->siteFinder->getSiteByPageId($pageId);
            $site->getLanguageById($languageId);
            $previewPage = $languageId > 0
                ? $this->generalModuleService->getLocalizedPageRecord($pageId, $languageId)
                : $page;
            if (!is_array($previewPage)) {
                return null;
            }
            $previewUri = PreviewUriBuilder::create($previewPage)->buildUri();
        } catch (\Throwable) {
            return null;
        }

        return $previewUri === null ? null : (string)$previewUri;
    }

    private function getBackendUser(): ?BackendUserAuthentication
    {
        $backendUser = $this->generalModuleService->getBackendUserAuthentication();
        return (int)($backendUser->user['uid'] ?? 0) > 0 ? $backendUser : null;
    }

    /**
     * Deliberately uniform across all authorization failures: the response
     * must not reveal which specific check rejected the request.
     */
    private function unavailableResponse(): JsonResponse
    {
        return $this->errorResponse('structure.error.unavailable', 403);
    }
}
