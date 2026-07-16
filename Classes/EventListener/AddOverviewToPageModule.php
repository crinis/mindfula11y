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

use MindfulMarkup\MindfulA11y\Backend\OverviewViewStateFactory;
use MindfulMarkup\MindfulA11y\Service\BackendUserProvider;
use MindfulMarkup\MindfulA11y\Service\ModuleLabelService;
use MindfulMarkup\MindfulA11y\Service\ModuleSettingsService;
use MindfulMarkup\MindfulA11y\Service\PagePreviewService;
use MindfulMarkup\MindfulA11y\Service\PermissionService;
use TYPO3\CMS\Backend\Controller\Event\ModifyPageLayoutContentEvent;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * Event listener to add the accessibility overview card to the page module.
 */
final readonly class AddOverviewToPageModule
{
    public function __construct(
        private PermissionService $permissionService,
        private BackendUserProvider $backendUserProvider,
        private ModuleSettingsService $moduleSettingsService,
        private PagePreviewService $pagePreviewService,
        private OverviewViewStateFactory $viewStateFactory,
        private ModuleLabelService $moduleLabelService,
        private PageRenderer $pageRenderer,
        private ViewFactoryInterface $viewFactory,
    ) {}

    public function __invoke(ModifyPageLayoutContentEvent $event): void
    {
        $request = $event->getRequest();

        $pageId = (int)($request->getParsedBody()['id'] ?? $request->getQueryParams()['id'] ?? 0);
        $site = $request->getAttribute('site', null);
        $moduleData = $request->getAttribute('moduleData', null);

        if (!$this->permissionService->checkModuleAccess() || 0 === $pageId || null === $moduleData || null === $site) {
            return;
        }

        // The page module's "all languages" view (-1) carries no single
        // language to analyze; fall back to the default language.
        $languageId = max(0, (int)$moduleData->get('language', 0));

        if (!$this->permissionService->checkLanguageAccess($languageId)) {
            return;
        }

        $pageInfo = BackendUtility::readPageAccess($pageId, $this->backendUserProvider->get()->getPagePermsClause(Permission::PAGE_SHOW));
        if (!$pageInfo) {
            return;
        }

        $pageTsConfig = $this->moduleSettingsService->getConvertedPageTsConfig($pageId);
        if ($pageTsConfig['mod']['web_layout']['mindfula11y']['hideInfo'] ?? false) {
            return;
        }

        $localizedPageInfo = $this->pagePreviewService->getLocalizedPageRecord($pageId, $languageId);
        $viewState = $this->viewStateFactory->build($pageId, $languageId, $pageInfo, $localizedPageInfo, $pageTsConfig);

        // If no access to any feature, don't render
        if (!$this->viewStateFactory->hasAnyFeatureAccess($viewState)) {
            return;
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
        $view->assignMultiple($viewState);

        $renderedContent = $view->render('Backend/WebLayout/Overview');

        $currentHeaderContent = $event->getHeaderContent();
        $event->setHeaderContent($renderedContent . $currentHeaderContent);

        // Register language labels for JavaScript
        $this->pageRenderer->addInlineLanguageLabelArray($this->moduleLabelService->getInlineLanguageLabels());

        // Load the JavaScript modules; all styling lives in the components'
        // shadow roots, so no global CSS file is needed here.
        $this->viewStateFactory->registerJavaScriptModules();
    }
}
