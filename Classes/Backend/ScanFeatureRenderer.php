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

namespace MindfulMarkup\MindfulA11y\Backend;

use MindfulMarkup\MindfulA11y\Domain\Model\CreateScanDemand;
use MindfulMarkup\MindfulA11y\Service\GeneralModuleService;
use MindfulMarkup\MindfulA11y\Service\PermissionService;
use MindfulMarkup\MindfulA11y\Service\ScanApiService;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Routing\PreviewUriBuilder;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\Buttons\DropDownButton;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

/**
 * Renders the accessibility-scanner feature: scan status, results, and the
 * signed demands for triggering new scans.
 */
final readonly class ScanFeatureRenderer implements FeatureRendererInterface
{
    use ModuleNoticeTrait;

    /** Page-levels choices offered by the scan menu; other values are rejected. */
    private const PAGE_LEVELS_OPTIONS = [0, 1, 5, 10, 99];

    public function __construct(
        private GeneralModuleService $generalModuleService,
        private ScanApiService $scanApiService,
        private PermissionService $permissionService,
        private UriBuilder $backendUriBuilder,
        private PageRenderer $pageRenderer,
        private FlashMessageService $flashMessageService,
        private DocHeaderMenuBuilder $menuBuilder,
    ) {}

    public function render(ModuleContext $context): ResponseInterface
    {
        if (!$this->generalModuleService->hasScanAccess($context->pageTsConfig)) {
            return $this->noticeResponse($context->moduleTemplate, 'scan.noAccess', ContextualFeedbackSeverity::ERROR, 403);
        }

        if (!$this->scanApiService->isConfigured()) {
            return $this->noticeResponse($context->moduleTemplate, 'scan.notConfigured', ContextualFeedbackSeverity::INFO);
        }

        if (!$this->scanApiService->checkStatus()) {
            return $this->noticeResponse($context->moduleTemplate, 'scan.error.apiNotReachable', ContextualFeedbackSeverity::WARNING);
        }
        $this->addLocalizedFlashMessage('scan.status.apiReachable', ContextualFeedbackSeverity::OK);

        // Get localized page info for preview URL generation
        $finalPageInfo = $context->getPreviewPageInfo();

        if (!$this->generalModuleService->isPageVisible($finalPageInfo)) {
            return $this->noticeResponse($context->moduleTemplate, 'scan.error.pageVisible', ContextualFeedbackSeverity::INFO);
        }

        // Let PreviewUriBuilder decide if a preview can be built. It returns null when a preview is not available.
        $previewUri = PreviewUriBuilder::create($finalPageInfo)
            ->buildUri();

        if (null === $previewUri) {
            return $this->noticeResponse($context->moduleTemplate, 'scan.previewNotEnabled', ContextualFeedbackSeverity::INFO);
        }

        $pageLevels = (int)$context->moduleData->get('scanPageLevels', 0);
        // Guard against arbitrary values set via URL manipulation; only accept the menu's values
        if (!in_array($pageLevels, self::PAGE_LEVELS_OPTIONS, true)) {
            $pageLevels = 0;
        }

        $this->menuBuilder->addDropDown($context->moduleTemplate, $this->buildPageLevelsMenu($context, $pageLevels), 3);

        // Check if user has edit access to the page record (needed to trigger new scans)
        $canTriggerScan = $this->permissionService->checkRecordEditAccess('pages', $finalPageInfo);

        // Check if content has changed since last scan
        $contentChanged = $this->generalModuleService->shouldInvalidateScan($finalPageInfo, (int)($context->pageInfo['SYS_LASTCHANGED'] ?? 0));

        // Only use existing scan ID if content hasn't changed — stored per language on $finalPageInfo
        $scanId = null;
        if (($finalPageInfo['tx_mindfula11y_scanid'] ?? false) && !$contentChanged) {
            $scanId = $finalPageInfo['tx_mindfula11y_scanid'] ?? null;
        }

        // Filter by the current page URL only when scanning a single page (pageLevels = 0).
        // When pageLevels > 0 the scan covers multiple pages and all results should be shown.
        $pageUrlFilter = $pageLevels === 0 ? [(string)$previewUri] : [];

        // Create scan demand only if user can trigger scans
        $createScanDemand = null;
        $crawlScanDemand = null;
        $urlList = [];
        if ($canTriggerScan) {
            // Compute the expected URL list for the current pageLevels setting.
            // Used by the frontend to detect when an existing scan was created with a different
            // set of URLs and needs to be restarted (autoCreate + url_list mode mismatch).
            $urlList = $pageLevels > 0
                ? $this->generalModuleService->generatePageUrls($context->pageId, $context->languageId, $pageLevels, (string)$previewUri)
                : [(string)$previewUri];

            $backendUser = $this->generalModuleService->getBackendUserAuthentication();
            $createScanDemand = new CreateScanDemand(
                userId: (int)$backendUser->user['uid'],
                pageId: $context->pageId,
                previewUrl: (string)$previewUri,
                languageId: $context->languageId,
                workspaceId: $backendUser->workspace,
                pageLevels: $pageLevels,
            );
            // Crawl mode is only available for site root pages (check default-language record)
            if ((bool)($context->pageInfo['is_siteroot'] ?? false)) {
                $crawlScanDemand = new CreateScanDemand(
                    userId: (int)$backendUser->user['uid'],
                    pageId: $context->pageId,
                    previewUrl: (string)$previewUri,
                    languageId: $context->languageId,
                    workspaceId: $backendUser->workspace,
                    crawl: true,
                );
            }
        }

        // Build a signed base URL for the report download (backend route, browser-navigable).
        // JS appends scanId and format before use.
        $reportBaseUrl = (string)$this->backendUriBuilder->buildUriFromRoute('mindfula11y_scanreport');

        // The AI audit toggle is only offered when TSConfig enables it and the
        // user can trigger scans at all; the skill list is shown for transparency.
        $aiAuditAvailable = $canTriggerScan && $this->generalModuleService->hasAiAuditAccess($context->pageTsConfig);
        $context->moduleTemplate->assignMultiple([
            'scanId' => $scanId,
            'createScanDemand' => $createScanDemand,
            'crawlScanDemand' => $crawlScanDemand,
            'autoCreateScan' => $pageLevels === 0 && $this->generalModuleService->isAutoCreateScanEnabled($context->pageTsConfig),
            'pageUrlFilter' => $pageUrlFilter,
            'urlList' => $urlList,
            'reportBaseUrl' => $reportBaseUrl,
            'aiAuditAvailable' => $aiAuditAvailable,
            'aiAuditDefault' => $aiAuditAvailable && $this->generalModuleService->isAiAuditDefaultEnabled($context->pageTsConfig),
        ]);

        $this->pageRenderer->loadJavaScriptModule('@mindfulmarkup/mindfula11y/element/scan/scan.js');

        return $context->moduleTemplate->renderResponse('Backend/Scan');
    }

    private function buildPageLevelsMenu(ModuleContext $context, int $currentPageLevels): ?DropDownButton
    {
        $languageService = $this->generalModuleService->getLanguageService();
        $items = [];
        foreach (self::PAGE_LEVELS_OPTIONS as $pageLevels) {
            $items[] = [
                'title' => $languageService->sL(self::MODULE_LANGUAGE_FILE . 'module.menu.pageLevels.' . $pageLevels),
                'href' => $this->menuBuilder->buildMenuItemUri($context, [
                    'scanPageLevels' => $pageLevels,
                ]),
                'active' => $pageLevels === $currentPageLevels,
            ];
        }

        return $this->menuBuilder->buildDropDown(
            $languageService->sL(self::MODULE_LANGUAGE_FILE . 'module.menu.pageLevels'),
            $items
        );
    }
}
