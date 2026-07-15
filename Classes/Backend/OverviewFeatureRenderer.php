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
use MindfulMarkup\MindfulA11y\Enum\Feature;
use MindfulMarkup\MindfulA11y\Service\AltTextFinderService;
use MindfulMarkup\MindfulA11y\Service\GeneralModuleService;
use MindfulMarkup\MindfulA11y\Service\ScanApiService;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Routing\PreviewUriBuilder;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Page\PageRenderer;

/**
 * Renders the overview feature: the structure analysis plus status cards for
 * missing alternative texts and the accessibility scanner.
 */
final readonly class OverviewFeatureRenderer implements FeatureRendererInterface
{
    public function __construct(
        private GeneralModuleService $generalModuleService,
        private AltTextFinderService $altTextFinderService,
        private ScanApiService $scanApiService,
        private UriBuilder $backendUriBuilder,
        private PageRenderer $pageRenderer,
    ) {}

    public function render(ModuleContext $context): ResponseInterface
    {
        $finalPageInfo = $context->getPreviewPageInfo();
        $pageTsConfig = $context->pageTsConfig;

        // Let PreviewUriBuilder decide if a preview can be built. It returns null when a preview is not available.
        $previewUri = PreviewUriBuilder::create($finalPageInfo)
            ->buildUri();

        $missingAltTextUri = null;
        $fileReferenceCount = null;

        $hasMissingAltTextAccess = $this->generalModuleService->hasMissingAltTextAccess($pageTsConfig);
        $hasHeadingStructureAccess = $this->generalModuleService->hasHeadingStructureAccess($pageTsConfig);
        $hasLandmarkStructureAccess = $this->generalModuleService->hasLandmarkStructureAccess($pageTsConfig);
        $hasScanAccess = $this->generalModuleService->hasScanAccess($pageTsConfig);
        $this->generalModuleService->allowStructureAnalysisFraming($previewUri, $pageTsConfig);

        // Disable scan access if page is hidden/not visible
        $isPageVisible = $this->generalModuleService->isPageVisible($finalPageInfo);
        if ($hasScanAccess && !$isPageVisible) {
            $hasScanAccess = false;
        }

        if ($hasMissingAltTextAccess) {
            $filterFileMetaData = !$this->generalModuleService->isFileMetadataIgnored($pageTsConfig)
                && $this->generalModuleService->canReadFileMetadataAlternative();
            $fileReferenceCount = $this->altTextFinderService->countAltlessFileReferences(
                $context->pageId,
                0,
                $context->languageId,
                $pageTsConfig,
                $filterFileMetaData
            );

            $missingAltTextUri = $this->backendUriBuilder->buildUriFromRoute(
                'mindfula11y_accessibility',
                [
                    'id' => $context->pageId,
                    'feature' => Feature::MISSING_ALT_TEXT->value,
                    'languageId' => $context->languageId,
                ]
            );
        }

        // Prepare scan-related variables
        $scanUri = null;
        $scanId = null;
        $createScanDemand = null;

        if ($hasScanAccess && $this->scanApiService->isConfigured()) {
            // Get existing scan ID — stored per language on whichever record ($finalPageInfo) was scanned
            $existingScanId = $finalPageInfo['tx_mindfula11y_scanid'] ?? null;

            // Check if content has changed since last scan
            $contentChanged = $this->generalModuleService->shouldInvalidateScan($finalPageInfo, (int)($context->pageInfo['SYS_LASTCHANGED'] ?? 0));

            // Only use existing scan ID if content hasn't changed
            if ($existingScanId && !$contentChanged) {
                $scanId = $existingScanId;
            }

            // Create scan demand for the component
            if (null !== $previewUri) {
                $backendUser = $this->generalModuleService->getBackendUserAuthentication();
                $createScanDemand = new CreateScanDemand(
                    userId: (int)$backendUser->user['uid'],
                    pageId: $context->pageId,
                    previewUrl: (string)$previewUri,
                    languageId: $context->languageId,
                    workspaceId: $backendUser->workspace,
                );
            }

            // Create URI to the scan feature
            $scanUri = $this->backendUriBuilder->buildUriFromRoute(
                'mindfula11y_accessibility',
                [
                    'id' => $context->pageId,
                    'feature' => Feature::SCAN->value,
                    'languageId' => $context->languageId,
                ]
            );
        }

        $context->moduleTemplate->assignMultiple([
            'pageId' => $context->pageId,
            // The preview is built from $finalPageInfo; when the page has no
            // translation in the selected language it falls back to the
            // default-language record, so the structure analysis must target
            // language 0 to match that preview URL.
            'languageId' => null === $context->localizedPageInfo ? 0 : $context->languageId,
            'fileReferenceCount' => $fileReferenceCount,
            'previewUrl' => (null !== $previewUri ? (string)$previewUri : null),
            'missingAltTextUri' => $missingAltTextUri,
            'hasMissingAltTextAccess' => $hasMissingAltTextAccess,
            'hasHeadingStructureAccess' => $hasHeadingStructureAccess,
            'hasLandmarkStructureAccess' => $hasLandmarkStructureAccess,
            'hasScanAccess' => $hasScanAccess,
            'scanId' => $scanId,
            'scanUri' => $scanUri,
            'createScanDemand' => $createScanDemand,
            'autoCreateScan' => $this->generalModuleService->isAutoCreateScanEnabled($pageTsConfig),
            'pageUrlFilter' => $previewUri !== null ? [(string)$previewUri] : [],
        ]);

        $this->pageRenderer->loadJavaScriptModule('@mindfulmarkup/mindfula11y/element/structure/structure.js');
        $this->pageRenderer->loadJavaScriptModule('@mindfulmarkup/mindfula11y/element/scan-issue-count/scan-issue-count.js');
        $this->pageRenderer->loadJavaScriptModule('@mindfulmarkup/mindfula11y/element/notice/notice.js');

        return $context->moduleTemplate->renderResponse('Backend/Overview');
    }
}
