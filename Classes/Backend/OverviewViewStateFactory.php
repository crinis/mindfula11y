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
use Psr\Http\Message\UriInterface;
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
        $finalPageInfo = ModuleContext::previewPageInfo($pageInfo, $localizedPageInfo);

        // Let PreviewUriBuilder decide if a preview can be built. It returns null when a preview is not available.
        $previewUri = PreviewUriBuilder::create($finalPageInfo)->buildUri();

        $hasMissingAltTextAccess = $this->moduleSettingsService->hasMissingAltTextAccess($pageTsConfig);
        $hasScanAccess = $this->moduleSettingsService->hasScanAccess($pageTsConfig)
            // A hidden/expired page cannot be fetched by the external scanner.
            && $this->pagePreviewService->isPageVisible($finalPageInfo);

        $missingAltTextUri = null;
        $fileReferenceCount = null;
        if ($hasMissingAltTextAccess) {
            $filterFileMetaData = $this->moduleSettingsService->canConsiderFileMetadataAlternative($pageTsConfig);
            $fileReferenceCount = $this->altTextFinderService->countAltlessFileReferences(
                $pageId,
                0,
                $languageId,
                $pageTsConfig,
                $filterFileMetaData
            );
            $missingAltTextUri = $this->buildFeatureUri(Feature::MISSING_ALT_TEXT, $pageId, $languageId);
        }

        // The overview template reads the scan card state only when a scan id
        // or a create demand exists, i.e. only after buildScanCardState().
        $scanUri = null;
        $scanCardState = ['scanId' => null, 'createScanDemand' => null, 'autoCreateScan' => false, 'pageUrlFilter' => []];
        if ($hasScanAccess && $this->scanApiService->isConfigured()) {
            $scanCardState = $this->buildScanCardState($pageId, $pageInfo, $localizedPageInfo, $previewUri, 0, $pageTsConfig);
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
            'scanUri' => $scanUri,
            ...$scanCardState,
        ];
    }

    /**
     * The scan card's state, shared by the overview card and the scan feature
     * (which layers its own additions on top): the effective scan id, the
     * signed demand for creating a scan, and the auto-create/URL-filter
     * switches. The caller has already checked scan access, scanner
     * configuration and page visibility.
     *
     * @param array<string, mixed> $pageInfo Default-language page record (page-permission-checked).
     * @param array<string, mixed>|null $localizedPageInfo Localized overlay, if the translation exists.
     * @param UriInterface|null $previewUri Preview URI of the page the scan targets (null: no preview, no demand).
     * @param int $pageLevels Page levels below $pageId the scan covers (0: this page only).
     * @param array<string, mixed> $pageTsConfig Converted Page TSconfig.
     * @return array{scanId: string|null, createScanDemand: array<string, mixed>|null, autoCreateScan: bool, pageUrlFilter: list<string>}
     */
    public function buildScanCardState(
        int $pageId,
        array $pageInfo,
        ?array $localizedPageInfo,
        ?UriInterface $previewUri,
        int $pageLevels,
        array $pageTsConfig,
    ): array {
        $finalPageInfo = ModuleContext::previewPageInfo($pageInfo, $localizedPageInfo);

        // Reuse the stored scan only while the page content is unchanged —
        // stored per language on $finalPageInfo.
        $scanId = $this->scanStateService->resolveEffectiveScanId(
            $this->scanStateService->withLiveScanState($finalPageInfo),
            (int)($pageInfo['SYS_LASTCHANGED'] ?? 0)
        );

        // The factory signs the language of $finalPageInfo — language 0 when
        // the selected language has no translation of this page — and returns
        // null when the user cannot trigger scans.
        $createScanDemand = null !== $previewUri
            ? $this->scanDemandFactory->create($finalPageInfo, $pageId, (string)$previewUri, pageLevels: $pageLevels)
            : null;

        return [
            'scanId' => $scanId,
            'createScanDemand' => $createScanDemand !== null ? $this->demandSignatureService->serialize($createScanDemand) : null,
            // Auto-creation and the URL filter apply to single-page scans only;
            // a multi-level scan covers many URLs and shows all their results.
            'autoCreateScan' => $pageLevels === 0 && $this->moduleSettingsService->isAutoCreateScanEnabled($pageTsConfig),
            'pageUrlFilter' => $previewUri !== null && $pageLevels === 0 ? [(string)$previewUri] : [],
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
        // Pre-upgrade guard for the server-rendered custom elements above.
        $this->pageRenderer->addCssFile('EXT:mindfula11y/Resources/Public/Css/backend.css');
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
