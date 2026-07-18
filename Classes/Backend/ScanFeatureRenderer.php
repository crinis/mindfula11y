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

use MindfulMarkup\MindfulA11y\Service\DemandSignatureService;
use MindfulMarkup\MindfulA11y\Tca\TranslationFields;
use MindfulMarkup\MindfulA11y\Service\ModuleSettingsService;
use MindfulMarkup\MindfulA11y\Service\PagePreviewService;
use MindfulMarkup\MindfulA11y\Service\ScanApiService;
use MindfulMarkup\MindfulA11y\Service\ScanDemandFactory;
use MindfulMarkup\MindfulA11y\Service\ScanStateService;
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
        private ModuleSettingsService $moduleSettingsService,
        private PagePreviewService $pagePreviewService,
        private ScanStateService $scanStateService,
        private ScanApiService $scanApiService,
        private ScanDemandFactory $scanDemandFactory,
        private DemandSignatureService $demandSignatureService,
        private UriBuilder $backendUriBuilder,
        private PageRenderer $pageRenderer,
        private FlashMessageService $flashMessageService,
        private DocHeaderMenuBuilder $menuBuilder,
    ) {}

    public function render(ModuleContext $context): ResponseInterface
    {
        if (!$this->moduleSettingsService->hasScanAccess($context->pageTsConfig)) {
            return $this->noticeResponse($context->moduleTemplate, 'scan.noAccess', ContextualFeedbackSeverity::ERROR, 403);
        }

        if (!$this->scanApiService->isConfigured()) {
            return $this->noticeResponse($context->moduleTemplate, 'scan.notConfigured', ContextualFeedbackSeverity::INFO);
        }

        // Deliberately NO synchronous health check here: it blocked every
        // module render on a third-party round-trip (5s timeout). The scan
        // frontend reports unreachability through its localized error notices
        // when a scan is actually created or loaded.

        // Get localized page info for preview URL generation
        $finalPageInfo = $context->getPreviewPageInfo();

        if (!$this->pagePreviewService->isPageVisible($finalPageInfo)) {
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

        $canTriggerScan = $this->scanDemandFactory->canTriggerScan($finalPageInfo);

        // Reuse the stored scan only while the page content is unchanged —
        // stored per language on $finalPageInfo.
        $scanId = $this->scanStateService->resolveEffectiveScanId($finalPageInfo, (int)($context->pageInfo['SYS_LASTCHANGED'] ?? 0));

        // Filter by the current page URL only when scanning a single page (pageLevels = 0).
        // When pageLevels > 0 the scan covers multiple pages and all results should be shown.
        $pageUrlFilter = $pageLevels === 0 ? [(string)$previewUri] : [];

        // Create scan demand only if user can trigger scans
        $createScanDemand = null;
        $crawlScanDemand = null;
        $urlList = [];
        if ($canTriggerScan) {
            // The demand signs the language of $finalPageInfo (the selected
            // language, or 0 when that translation does not exist) — generate
            // the expected URL list for the same language, since redemption
            // rebuilds it from the signed demand.
            $previewLanguageId = TranslationFields::languageId('pages', $finalPageInfo);
            // Compute the expected URL list for the current pageLevels setting.
            // Used by the frontend to detect when an existing scan was created with a different
            // set of URLs and needs to be restarted (autoCreate + url_list mode mismatch).
            $urlList = $pageLevels > 0
                ? $this->pagePreviewService->generatePageUrls($context->pageId, $previewLanguageId, $pageLevels, (string)$previewUri)
                : [(string)$previewUri];

            $createScanDemand = $this->scanDemandFactory->create(
                $finalPageInfo,
                $context->pageId,
                (string)$previewUri,
                pageLevels: $pageLevels,
            );
            // Crawl mode is only available for site root pages (check default-language record)
            if ((bool)($context->pageInfo['is_siteroot'] ?? false)) {
                $crawlScanDemand = $this->scanDemandFactory->create(
                    $finalPageInfo,
                    $context->pageId,
                    (string)$previewUri,
                    crawl: true,
                );
            }
        }

        // Build a signed base URL for the report download (backend route, browser-navigable).
        // JS appends scanId and format before use.
        $reportBaseUrl = (string)$this->backendUriBuilder->buildUriFromRoute('mindfula11y_scanreport');

        // The AI audit toggle is only offered when TSConfig enables it and the
        // user can trigger scans at all; the skill list is shown for transparency.
        $aiAuditAvailable = $canTriggerScan && $this->moduleSettingsService->hasAiAuditAccess($context->pageTsConfig);
        $context->moduleTemplate->assignMultiple([
            'scanId' => $scanId,
            'createScanDemand' => $createScanDemand !== null ? $this->demandSignatureService->serialize($createScanDemand) : null,
            'crawlScanDemand' => $crawlScanDemand !== null ? $this->demandSignatureService->serialize($crawlScanDemand) : null,
            'autoCreateScan' => $pageLevels === 0 && $this->moduleSettingsService->isAutoCreateScanEnabled($context->pageTsConfig),
            'pageUrlFilter' => $pageUrlFilter,
            'urlList' => $urlList,
            'reportBaseUrl' => $reportBaseUrl,
            'aiAuditAvailable' => $aiAuditAvailable,
            'aiAuditDefault' => $aiAuditAvailable && $this->moduleSettingsService->isAiAuditDefaultEnabled($context->pageTsConfig),
        ]);

        $this->pageRenderer->loadJavaScriptModule('@mindfulmarkup/mindfula11y/element/scan/scan.js');

        return $context->moduleTemplate->renderResponse('Backend/Scan');
    }

    private function buildPageLevelsMenu(ModuleContext $context, int $currentPageLevels): ?DropDownButton
    {
        $languageService = $this->getLanguageService();
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
