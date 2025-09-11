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

        if (!$backendUser->check('modules', 'mindfula11y_accessibility') || 0 === $pageId || null === $moduleData || null === $site || !$backendUser->checkLanguageAccess($languageId)) {
            return;
        }

        $pageInfo = BackendUtility::readPageAccess($pageId, $backendUser->getPagePermsClause(Permission::PAGE_SHOW));
        if (!$pageInfo) {
            return;
        }

        $localizedPageInfo = $this->generalModuleService->getLocalizedPageRecord($pageId, $languageId);
        $pageTsConfig = $this->generalModuleService->getConvertedPageTsConfig($pageId);

        if ($pageTsConfig['mod']['web_layout']['mindfula11y']['hideInfo'] ?? false) {
            return;
        }

        $hasMissingAltTextAccess = $this->generalModuleService->hasMissingAltTextAccess($pageTsConfig);
        $hasHeadingStructureAccess = $this->generalModuleService->hasHeadingStructureAccess($pageTsConfig);
        $hasLandmarkStructureAccess = $this->generalModuleService->hasLandmarkStructureAccess($pageTsConfig);

        // If no access to any feature, don't render
        if (!$hasMissingAltTextAccess && !$hasHeadingStructureAccess && !$hasLandmarkStructureAccess) {
            return;
        }

        $doktype = $localizedPageInfo['doktype'] ?? $pageInfo['doktype'] ?? PageRepository::DOKTYPE_DEFAULT;
        $previewEnabled = $this->generalModuleService->isPreviewEnabledForDoktype($doktype, $pageTsConfig);

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

        $previewUrl = $previewEnabled ? (string)PreviewUriBuilder::create($pageId)->withLanguage($languageId)->buildUri() : null;

        // Render the template
        $view = GeneralUtility::makeInstance(StandaloneView::class);
        $view->setTemplateRootPaths(['EXT:mindfula11y/Resources/Private/Templates/']);
        $view->setLayoutRootPaths(['EXT:mindfula11y/Resources/Private/Layouts/']);
        $view->setPartialRootPaths(['EXT:mindfula11y/Resources/Private/Partials/']);
        $view->setTemplate('Backend/WebLayout/GeneralAccessibility');
        $view->assignMultiple([
            'fileReferenceCount' => $fileReferenceCount,
            'previewUrl' => $previewUrl,
            'missingAltTextUri' => $missingAltTextUri,
            'hasMissingAltTextAccess' => $hasMissingAltTextAccess,
            'hasHeadingStructureAccess' => $hasHeadingStructureAccess,
            'hasLandmarkStructureAccess' => $hasLandmarkStructureAccess,
        ]);

        $renderedContent = $view->render();

        $currentHeaderContent = $event->getHeaderContent();
        $event->setHeaderContent($renderedContent . $currentHeaderContent);

        // Register language labels for JavaScript
        $this->pageRenderer->addInlineLanguageLabelArray($this->generalModuleService->getInlineLanguageLabels());

        // Load the JavaScript module
        $this->pageRenderer->loadJavaScriptModule('@mindfulmarkup/mindfula11y/structure.js');
    }
}
