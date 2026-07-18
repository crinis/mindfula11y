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

use MindfulMarkup\MindfulA11y\Service\BackendUserProvider;
use MindfulMarkup\MindfulA11y\Service\ModuleSettingsService;
use MindfulMarkup\MindfulA11y\Service\PagePreviewService;
use MindfulMarkup\MindfulA11y\Service\RecordSnapshotService;
use MindfulMarkup\MindfulA11y\Service\StructureAnalysisAuthorizationService;
use MindfulMarkup\MindfulA11y\Service\StructureAnalysisTicketService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\NormalizedParams;

/** Issues signed tickets for rendering a frontend preview in the structure analyzer. */
final readonly class StructureAnalysisTicketAjaxController
{
    use JsonErrorResponseTrait;
    use AjaxGuardTrait;

    public function __construct(
        private StructureAnalysisTicketService $ticketService,
        private StructureAnalysisAuthorizationService $authorizationService,
        private ModuleSettingsService $moduleSettingsService,
        private PagePreviewService $pagePreviewService,
        private RecordSnapshotService $recordSnapshotService,
        private BackendUserProvider $backendUserProvider,
    ) {}

    public function ticketAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->parseJsonBody($request);
        $pageId = (int)($body['pageId'] ?? 0);
        $languageId = (int)($body['languageId'] ?? 0);
        $backendUser = $this->backendUserProvider->getAuthenticated();
        if ($backendUser === null) {
            return $this->unavailableResponse();
        }
        $workspaceId = $backendUser->workspace;
        // The service returns the workspace-overlaid page record it already
        // authorized, so previews of workspace versions build from the draft.
        $page = $this->authorizationService->authorizePage($backendUser, $pageId, $languageId, $workspaceId);
        if ($page === null || !$this->isStructureAnalysisEnabled($pageId)) {
            return $this->unavailableResponse();
        }
        $previewUrl = $this->pagePreviewService->buildPreviewUrl($page, $pageId, $languageId);
        $previewPage = $this->pagePreviewService->getPreviewPageRecord($page, $pageId, $languageId);
        if ($previewUrl === null || $previewPage === null) {
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
                $this->recordSnapshotService->fingerprint('pages', $previewPage, RecordSnapshotService::PAGES_SCOPE_COLUMNS),
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
     * around that page-tree restriction. The gate is enforced here at issuance
     * and re-evaluated for the reconstructed ticket holder at redemption, so a
     * TSconfig or current preview-URL change invalidates an outstanding ticket
     * immediately.
     */
    private function isStructureAnalysisEnabled(int $pageId): bool
    {
        $pageTsConfig = $this->moduleSettingsService->getConvertedPageTsConfig($pageId);
        return $this->moduleSettingsService->hasHeadingStructureAccess($pageTsConfig)
            || $this->moduleSettingsService->hasLandmarkStructureAccess($pageTsConfig);
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
