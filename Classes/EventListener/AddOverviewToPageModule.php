<?php

declare(strict_types=1);

/*
 * Mindful A11y extension for TYPO3 integrating accessibility tools into the backend.
 * Copyright (C) 2025  Mindful Markup, Felix Spittel
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

namespace MindfulMarkup\MindfulA11y\EventListener;

use MindfulMarkup\MindfulA11y\Service\AltTextFinderService;
use MindfulMarkup\MindfulA11y\Service\ModuleLabelService;
use MindfulMarkup\MindfulA11y\Service\ModuleSettingsService;
use MindfulMarkup\MindfulA11y\Service\PagePreviewService;
use MindfulMarkup\MindfulA11y\Service\PermissionService;
use MindfulMarkup\MindfulA11y\Service\ScanApiService;
use MindfulMarkup\MindfulA11y\Service\ScanStateService;
use MindfulMarkup\MindfulA11y\Domain\Model\CreateScanDemand;
use TYPO3\CMS\Backend\Controller\Event\ModifyPageLayoutContentEvent;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Backend\Routing\PreviewUriBuilder;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Event listener to add heading structure analysis to the page module
 */
class AddOverviewToPageModule
{
    public function __construct(
        protected readonly PermissionService $permissionService,
        protected readonly AltTextFinderService $altTextFinderService,
        protected readonly ModuleSettingsService $moduleSettingsService,
        protected readonly PagePreviewService $pagePreviewService,
        protected readonly ScanStateService $scanStateService,
        protected readonly ModuleLabelService $moduleLabelService,
        protected readonly ScanApiService $scanApiService,
        protected readonly UriBuilder $backendUriBuilder,
        protected readonly PageRenderer $pageRenderer,
        protected readonly ViewFactoryInterface $viewFactory,
    ) {}

    /**
     * Modify the page layout content to add accessibility notice
     *
     * @param ModifyPageLayoutContentEvent $event
     * @return void
     */
    public function __invoke(ModifyPageLayoutContentEvent $event): void
    {
        $request = $event->getRequest();

        $pageId = (int)($request->getParsedBody()['id'] ?? $request->getQueryParams()['id'] ?? 0);
        $site = $request->getAttribute('site', null);
        $moduleData = $request->getAttribute('moduleData', null);

        $backendUser = $this->getBackendUserAuthentication();

        if (!$this->permissionService->checkModuleAccess() || 0 === $pageId || null === $moduleData || null === $site) {
            return;
        }

        $languageId = (int)$moduleData->get('language', 0);
        if ($languageId === -1) {
            $languageId = 0;
        }

        if (!$this->permissionService->checkLanguageAccess($languageId)) {
            return;
        }

        $pageInfo = BackendUtility::readPageAccess($pageId, $backendUser->getPagePermsClause(Permission::PAGE_SHOW));
        if (!$pageInfo) {
            return;
        }

        $localizedPageInfo = $this->pagePreviewService->getLocalizedPageRecord($pageId, $languageId);
        $finalPageInfo = $localizedPageInfo ?: $pageInfo;        
        $pageTsConfig = $this->moduleSettingsService->getConvertedPageTsConfig($pageId);

        if ($pageTsConfig['mod']['web_layout']['mindfula11y']['hideInfo'] ?? false) {
            return;
        }

        $hasMissingAltTextAccess = $this->moduleSettingsService->hasMissingAltTextAccess($pageTsConfig);
        $hasHeadingStructureAccess = $this->moduleSettingsService->hasHeadingStructureAccess($pageTsConfig);
        $hasLandmarkStructureAccess = $this->moduleSettingsService->hasLandmarkStructureAccess($pageTsConfig);
        $hasScanAccess = $this->moduleSettingsService->hasScanAccess($pageTsConfig);

        // Disable scan access if page is hidden/not visible
        $isPageVisible = $this->pagePreviewService->isPageVisible($finalPageInfo);
        if ($hasScanAccess && !$isPageVisible) {
            $hasScanAccess = false;
        }

        // If no access to any feature, don't render
        if (!$hasMissingAltTextAccess && !$hasHeadingStructureAccess && !$hasLandmarkStructureAccess && !$hasScanAccess) {
            return;
        }

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

            $missingAltTextUri = $this->backendUriBuilder->buildUriFromRoute(
                'mindfula11y_accessibility',
                [
                    'id' => $pageId,
                    'feature' => 'missingAltText',
                    'languageId' => $languageId,
                ]
            );
        }

        // Let PreviewUriBuilder decide if a preview can be built. It returns null when a preview is not available.
        $previewUri = PreviewUriBuilder::create($finalPageInfo)->buildUri();
        $this->moduleSettingsService->allowStructureAnalysisFraming($previewUri, $pageTsConfig);

        // Prepare scan-related variables
        $scanUri = null;
        $scanId = null;
        $createScanDemand = null;

        if ($hasScanAccess && $this->scanApiService->isConfigured()) {
            // Get existing scan ID from database
            $existingScanId = $finalPageInfo['tx_mindfula11y_scanid'] ?? null;

            $contentChanged = $this->scanStateService->shouldInvalidateScan($finalPageInfo, (int)($pageInfo['SYS_LASTCHANGED'] ?? 0));

            // Only use existing scan ID if content hasn't changed
            if ($existingScanId && !$contentChanged) {
                $scanId = $existingScanId;
            }

            // Create scan demand for the component only when redeeming it could
            // succeed ("signed => authorized at issuance"): in the live workspace
            // (the external scanner cannot fetch workspace previews, and storing
            // the scan id must not version the page) and with edit access to the
            // page record the scan id is stored on.
            if (null !== $previewUri
                && $backendUser->workspace === 0
                && $this->permissionService->checkRecordEditAccess('pages', $finalPageInfo)
            ) {
                $createScanDemand = new CreateScanDemand(
                    userId: (int)$backendUser->user['uid'],
                    pageId: (int)$finalPageInfo['uid'],
                    previewUrl: (string)$previewUri,
                    languageId: $languageId,
                    workspaceId: $backendUser->workspace,
                );
            }

            // Create URI to the scan feature
            $scanUri = $this->backendUriBuilder->buildUriFromRoute(
                'mindfula11y_accessibility',
                [
                    'id' => $pageId,
                    'feature' => 'scan',
                    'languageId' => $languageId,
                ]
            );
        }

        // Render the template.
        // StandaloneView was removed in TYPO3 v14 (Breaking #105377); the generic
        // ViewFactoryInterface (introduced in v13.3, Feature #104773) is the
        // version-agnostic replacement and works on both v13.4 and v14.
        $viewFactoryData = new ViewFactoryData(
            templateRootPaths: ['EXT:mindfula11y/Resources/Private/Templates/'],
            partialRootPaths: ['EXT:mindfula11y/Resources/Private/Partials/'],
            layoutRootPaths: ['EXT:mindfula11y/Resources/Private/Layouts/'],
            request: $request,
        );
        $view = $this->viewFactory->create($viewFactoryData);
        $view->assignMultiple([
            'pageId' => $pageId,
            // See AccessibilityModuleController: the preview falls back to the
            // default-language record when no translation exists, so target
            // language 0 to keep the structure analysis URL/language in sync.
            'languageId' => null === $localizedPageInfo ? 0 : $languageId,
            'fileReferenceCount' => $fileReferenceCount,
            'previewUrl' => (null !== $previewUri ? (string) $previewUri : null),
            'missingAltTextUri' => $missingAltTextUri,
            'hasMissingAltTextAccess' => $hasMissingAltTextAccess,
            'hasHeadingStructureAccess' => $hasHeadingStructureAccess,
            'hasLandmarkStructureAccess' => $hasLandmarkStructureAccess,
            'hasScanAccess' => $hasScanAccess,
            'scanId' => $scanId,
            'scanUri' => $scanUri,
            'createScanDemand' => $createScanDemand,
            'autoCreateScan' => $this->moduleSettingsService->isAutoCreateScanEnabled($pageTsConfig),
            'pageUrlFilter' => null !== $previewUri ? [(string) $previewUri] : [],
        ]);

        $renderedContent = $view->render('Backend/WebLayout/Overview');

        $currentHeaderContent = $event->getHeaderContent();
        $event->setHeaderContent($renderedContent . $currentHeaderContent);

        // Register language labels for JavaScript
        $this->pageRenderer->addInlineLanguageLabelArray($this->moduleLabelService->getInlineLanguageLabels());

        // Load the JavaScript modules; all styling lives in the components'
        // shadow roots, so no global CSS file is needed here.
        $this->pageRenderer->loadJavaScriptModule('@mindfulmarkup/mindfula11y/element/structure/structure.js');
        $this->pageRenderer->loadJavaScriptModule('@mindfulmarkup/mindfula11y/element/scan-issue-count/scan-issue-count.js');
        $this->pageRenderer->loadJavaScriptModule('@mindfulmarkup/mindfula11y/element/notice/notice.js');
    }

    protected function getBackendUserAuthentication(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }
}
