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

use MindfulMarkup\MindfulA11y\Service\InteractiveLabelContextReviewService;
use MindfulMarkup\MindfulA11y\Service\ModuleSettingsService;
use MindfulMarkup\MindfulA11y\Service\OpenAIService;
use MindfulMarkup\MindfulA11y\Service\PermissionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * AJAX endpoint for the optional AI context review of an already
 * rule-flagged interactive label (link/button).
 *
 * Deliberately unsigned/un-demanded unlike alt-text and scan: this endpoint
 * never touches the database and never reads a record itself — it only
 * forwards text the requesting editor's browser already legitimately holds
 * (the finding was rendered into the module after InteractiveLabelFinderService
 * already authorized it). Module access plus the per-page TSconfig gate
 * (mirroring ScanAjaxController's aiAudit re-check) are therefore sufficient.
 */
final readonly class InteractiveLabelContextReviewAjaxController
{
    use JsonErrorResponseTrait;
    use ModuleAccessGuardTrait;

    public function __construct(
        private InteractiveLabelContextReviewService $contextReviewService,
        private ModuleSettingsService $moduleSettingsService,
        private OpenAIService $openAIService,
        private PermissionService $permissionService,
    ) {}

    public function assessAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($error = $this->requireModuleAccess()) {
            return $error;
        }

        $requestBody = $this->parseJsonBody($request);

        $pageId = (int)($requestBody['pageId'] ?? 0);
        $label = trim((string)($requestBody['label'] ?? ''));
        if ($pageId <= 0 || $label === '') {
            return $this->errorResponse('error.invalidRequest', 400);
        }

        $pageTsConfig = $this->moduleSettingsService->getConvertedPageTsConfig($pageId);
        if (!$this->moduleSettingsService->hasInteractiveLabelAiReviewAccess($pageTsConfig)) {
            return $this->errorResponse('interactiveLabels.aiReview.error.notAllowed', 403);
        }

        if (!$this->openAIService->isApiKeyConfigured()) {
            return $this->errorResponse('interactiveLabels.aiReview.error.notConfigured', 500);
        }

        $assessment = $this->contextReviewService->assess(
            $label,
            (string)($requestBody['elementType'] ?? ''),
            trim((string)($requestBody['target'] ?? '')),
            (string)($requestBody['rule'] ?? ''),
            trim((string)($requestBody['pageTitle'] ?? '')),
            trim((string)($requestBody['surroundingContext'] ?? '')),
            (string)($requestBody['locale'] ?? 'en'),
        );

        if ($assessment === null) {
            return $this->errorResponse('interactiveLabels.aiReview.error.openAIConnection', 500);
        }

        return new JsonResponse([
            'assessment' => $assessment->assessment->value,
            'reason' => $assessment->reason,
            'suggestedLabel' => $assessment->suggestedLabel,
        ], 201);
    }
}
