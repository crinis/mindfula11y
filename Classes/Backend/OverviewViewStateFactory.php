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

use MindfulMarkup\MindfulA11y\Enum\Feature;
use MindfulMarkup\MindfulA11y\Service\AltTextFinderService;
use MindfulMarkup\MindfulA11y\Service\DemandSignatureService;
use MindfulMarkup\MindfulA11y\Service\ModuleSettingsService;
use MindfulMarkup\MindfulA11y\Service\PagePreviewService;
use MindfulMarkup\MindfulA11y\Service\ScanApiService;
use MindfulMarkup\MindfulA11y\Service\ScanDemandFactory;
use MindfulMarkup\MindfulA11y\Service\ScanStateService;
use MindfulMarkup\MindfulA11y\Service\StructureAnalysisFramingService;
use TYPO3\CMS\Backend\Routing\PreviewUriBuilder;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Page\PageRenderer;

/**
 * Assembles the view state of the accessibility overview card.
 *
 * The overview renders in two places — the module's Overview feature and the
 * page-module header via the ModifyPageLayoutContent event — with identical
 * content. This factory is the single implementation of that state (feature
 * gates, alt-text count, preview/framing, scan card) so the two surfaces
 * cannot drift.
 */
final readonly class OverviewViewStateFactory
{
    public function __construct(
        private ModuleSettingsService $moduleSettingsService,
        private PagePreviewService $pagePreviewService,
        private ScanStateService $scanStateService,
        private ScanDemandFactory $scanDemandFactory,
        private DemandSignatureService $demandSignatureService,
        private AltTextFinderService $altTextFinderService,
        private ScanApiService $scanApiService,
        private StructureAnalysisFramingService $framingService,
        private UriBuilder $backendUriBuilder,
        private PageRenderer $pageRenderer,
    ) {}

    /**
     * Build the template variables for the overview card.
     *
     * @param array<string, mixed> $pageInfo Default-language page record (page-permission-checked).
     * @param array<string, mixed>|null $localizedPageInfo Localized overlay for $languageId, if the translation exists.
     * @param array<string, mixed> $pageTsConfig Converted Page TSconfig.
     * @return array<string, mixed>
     */
    public function build(int $pageId, int $languageId, array $pageInfo, ?array $localizedPageInfo, array $pageTsConfig): array
    {
        $finalPageInfo = $localizedPageInfo ?: $pageInfo;

        // Let PreviewUriBuilder decide if a preview can be built. It returns null when a preview is not available.
        $previewUri = PreviewUriBuilder::create($finalPageInfo)->buildUri();
        $this->framingService->allowFraming($previewUri, $pageTsConfig);

        $hasMissingAltTextAccess = $this->moduleSettingsService->hasMissingAltTextAccess($pageTsConfig);
        $hasScanAccess = $this->moduleSettingsService->hasScanAccess($pageTsConfig)
            // A hidden/expired page cannot be fetched by the external scanner.
            && $this->pagePreviewService->isPageVisible($finalPageInfo);

        $missingAltTextUri = null;
        $fileReferenceCount = null;
        if ($hasMissingAltTextAccess) {
            $filterFileMetaData = !$this->moduleSettingsService->isFileMetadataIgnored($pageTsConfig)
                && $this->moduleSettingsService->canReadFileMetadataAlternative();
            $fileReferenceCount = $this->altTextFinderService->countAltlessFileReferences(
                $pageId,
                0,
                $languageId,
                $pageTsConfig,
                $filterFileMetaData
            );
            $missingAltTextUri = $this->buildFeatureUri(Feature::MISSING_ALT_TEXT, $pageId, $languageId);
        }

        $scanUri = null;
        $scanId = null;
        $createScanDemand = null;
        if ($hasScanAccess && $this->scanApiService->isConfigured()) {
            // Reuse the stored scan only while the page content is unchanged.
            $scanId = $this->scanStateService->resolveEffectiveScanId($finalPageInfo, (int)($pageInfo['SYS_LASTCHANGED'] ?? 0));

            if (null !== $previewUri) {
                // The factory signs the language of $finalPageInfo — language 0
                // when the selected language has no translation of this page.
                $createScanDemand = $this->scanDemandFactory->create($finalPageInfo, $pageId, (string)$previewUri);
            }

            $scanUri = $this->buildFeatureUri(Feature::SCAN, $pageId, $languageId);
        }

        return [
            'pageId' => $pageId,
            // The preview is built from $finalPageInfo; when the page has no
            // translation in the selected language it falls back to the
            // default-language record, so the structure analysis must target
            // language 0 to match that preview URL.
            'languageId' => null === $localizedPageInfo ? 0 : $languageId,
            'fileReferenceCount' => $fileReferenceCount,
            'previewUrl' => (null !== $previewUri ? (string)$previewUri : null),
            'missingAltTextUri' => $missingAltTextUri,
            'hasMissingAltTextAccess' => $hasMissingAltTextAccess,
            'hasHeadingStructureAccess' => $this->moduleSettingsService->hasHeadingStructureAccess($pageTsConfig),
            'hasLandmarkStructureAccess' => $this->moduleSettingsService->hasLandmarkStructureAccess($pageTsConfig),
            'hasScanAccess' => $hasScanAccess,
            'scanId' => $scanId,
            'scanUri' => $scanUri,
            'createScanDemand' => $createScanDemand !== null ? $this->demandSignatureService->serialize($createScanDemand) : null,
            'autoCreateScan' => $this->moduleSettingsService->isAutoCreateScanEnabled($pageTsConfig),
            'pageUrlFilter' => $previewUri !== null ? [(string)$previewUri] : [],
        ];
    }

    /**
     * Whether the view state grants access to at least one overview feature.
     *
     * @param array<string, mixed> $viewState A build() result.
     */
    public function hasAnyFeatureAccess(array $viewState): bool
    {
        return ($viewState['hasMissingAltTextAccess'] ?? false)
            || ($viewState['hasHeadingStructureAccess'] ?? false)
            || ($viewState['hasLandmarkStructureAccess'] ?? false)
            || ($viewState['hasScanAccess'] ?? false);
    }

    /**
     * Load the JavaScript modules the overview card's markup requires.
     */
    public function registerJavaScriptModules(): void
    {
        $this->pageRenderer->loadJavaScriptModule('@mindfulmarkup/mindfula11y/element/structure/structure.js');
        $this->pageRenderer->loadJavaScriptModule('@mindfulmarkup/mindfula11y/element/scan-issue-count/scan-issue-count.js');
        $this->pageRenderer->loadJavaScriptModule('@mindfulmarkup/mindfula11y/element/notice/notice.js');
    }

    private function buildFeatureUri(Feature $feature, int $pageId, int $languageId): string
    {
        return (string)$this->backendUriBuilder->buildUriFromRoute(
            'mindfula11y_accessibility',
            [
                'id' => $pageId,
                'feature' => $feature->value,
                'languageId' => $languageId,
            ]
        );
    }
}
