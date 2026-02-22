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
use MindfulMarkup\MindfulA11y\Service\GeneralModuleService;
use MindfulMarkup\MindfulA11y\Service\PermissionService;
use MindfulMarkup\MindfulA11y\Service\ScanApiService;
use MindfulMarkup\MindfulA11y\Domain\Model\CreateScanDemand;
use TYPO3\CMS\Backend\Controller\Event\ModifyPageLayoutContentEvent;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Backend\Routing\PreviewUriBuilder;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Fluid\View\StandaloneView;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * Event listener to add heading structure analysis to the page module
 */
class WebLayoutInfo
{
    public function __construct(
        protected readonly PermissionService $permissionService,
        protected readonly AltTextFinderService $altTextFinderService,
        protected readonly GeneralModuleService $generalModuleService,
        protected readonly ScanApiService $scanApiService,
        protected readonly UriBuilder $backendUriBuilder,
        protected readonly PageRenderer $pageRenderer,
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
        $languageId = (int)$moduleData->get('language', 0);
        if ($languageId === -1) {
            $languageId = 0;
        }

        $backendUser = $this->generalModuleService->getBackendUserAuthentication();

        if (!$backendUser->check('modules', 'mindfula11y_accessibility') || 0 === $pageId || null === $moduleData || null === $site || !$this->permissionService->checkLanguageAccess($languageId)) {
            return;
        }

        $pageInfo = BackendUtility::readPageAccess($pageId, $backendUser->getPagePermsClause(Permission::PAGE_SHOW));
        if (!$pageInfo) {
            return;
        }

        $localizedPageInfo = $this->generalModuleService->getLocalizedPageRecord($pageId, $languageId);
        $finalPageInfo = $localizedPageInfo ?: $pageInfo;        
        $pageTsConfig = $this->generalModuleService->getConvertedPageTsConfig($pageId);

        if ($pageTsConfig['mod']['web_layout']['mindfula11y']['hideInfo'] ?? false) {
            return;
        }

        $hasMissingAltTextAccess = $this->generalModuleService->hasMissingAltTextAccess($pageTsConfig);
        $hasHeadingStructureAccess = $this->generalModuleService->hasHeadingStructureAccess($pageTsConfig);
        $hasLandmarkStructureAccess = $this->generalModuleService->hasLandmarkStructureAccess($pageTsConfig);
        $hasScanAccess = $this->generalModuleService->hasScanAccess($pageTsConfig);

        // Disable scan access if page is hidden/not visible
        $isPageVisible = $this->generalModuleService->isPageVisible($finalPageInfo);
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
            $filterFileMetaData = $this->permissionService->checkTableReadAccess('sys_file_metadata') && $this->permissionService->checkNonExcludeFields('sys_file_metadata', ['alternative']);
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

        // Prepare scan-related variables
        $scanUri = null;
        $scanId = null;
        $createScanDemand = null;

        if ($hasScanAccess && $this->scanApiService->isConfigured()) {
            // Get existing scan ID from database
            $existingScanId = $finalPageInfo['tx_mindfula11y_scanid'] ?? null;

            $contentChanged = $this->generalModuleService->shouldInvalidateScan($finalPageInfo, (int)($pageInfo['SYS_LASTCHANGED'] ?? 0));

            // Only use existing scan ID if content hasn't changed
            if ($existingScanId && !$contentChanged) {
                $scanId = $existingScanId;
            }

            // Create scan demand for the component
            if (null !== $previewUri) {
                $createScanDemand = new CreateScanDemand(
                    $backendUser->user['uid'],
                    $finalPageInfo['uid'],
                    (string) $previewUri,
                    $languageId,
                    $backendUser->workspace
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

        // Render the template
        $view = GeneralUtility::makeInstance(StandaloneView::class);
        $view->setTemplateRootPaths(['EXT:mindfula11y/Resources/Private/Templates/']);
        $view->setLayoutRootPaths(['EXT:mindfula11y/Resources/Private/Layouts/']);
        $view->setPartialRootPaths(['EXT:mindfula11y/Resources/Private/Partials/']);
        $view->setTemplate('Backend/WebLayout/GeneralAccessibility');
        $view->assignMultiple([
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
            'autoCreateScan' => $this->generalModuleService->isAutoCreateScanEnabled($pageTsConfig),
        ]);

        $renderedContent = $view->render();

        $currentHeaderContent = $event->getHeaderContent();
        $event->setHeaderContent($renderedContent . $currentHeaderContent);

        // Register language labels for JavaScript
        $this->pageRenderer->addInlineLanguageLabelArray($this->generalModuleService->getInlineLanguageLabels());

        // Load the CSS
        $this->pageRenderer->addCssFile('EXT:mindfula11y/Resources/Public/Css/mindfula11y.css');

        // Load the JavaScript modules
        $this->pageRenderer->loadJavaScriptModule('@mindfulmarkup/mindfula11y/structure.js');
        $this->pageRenderer->loadJavaScriptModule('@mindfulmarkup/mindfula11y/scan-issue-count.js');
    }
}
