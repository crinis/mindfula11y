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

namespace MindfulMarkup\MindfulA11y\Controller;

use MindfulMarkup\MindfulA11y\Enum\Feature;
use MindfulMarkup\MindfulA11y\Service\AltTextFinderService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\PreviewUriBuilder;
use TYPO3\CMS\Backend\Template\Components\Buttons\DropDown\DropDownToggle;
use TYPO3\CMS\Backend\Template\Components\Buttons\DropDownButton;
use TYPO3\CMS\Backend\Template\Components\Menu\Menu;
use TYPO3\CMS\Core\Http\Error\MethodNotAllowedException;
use \InvalidArgumentException;
use MindfulMarkup\MindfulA11y\Service\PermissionService;
use TYPO3\CMS\Backend\Module\ModuleData;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\AllowedMethodsTrait;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Pagination\ArrayPaginator;
use TYPO3\CMS\Core\Pagination\SimplePagination;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\TypoScript\TypoScriptService;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Class AccessibilityModuleController.
 *
 * This controller handles the backend module with various accessibility functions.
 */
#[AsController]
class AccessibilityModuleController
{
    use AllowedMethodsTrait;

    /**
     * The current page ID.
     */
    protected int $pageId = 0;

    /**
     * The selected language UID.
     */
    protected int $languageId = 0;

    /**
     * The page information for the current page.
     */
    protected array|bool $pageInfo = false;

    /**
     * The active module feature.
     */
    protected Feature $feature = Feature::GENERAL;

    /**
     * The module template.
     */
    protected ?ModuleTemplate $moduleTemplate = null;

    /**
     * The module data.
     */
    protected ?ModuleData $moduleData = null;

    /**
     * The current request.
     */
    protected ?ServerRequestInterface $request = null;

    /**
     * Page TSConfig.
     * 
     * @var array<string, mixed>
     */
    protected array $pageTsConfig = [];

    /**
     * Constructor.
     */
    public function __construct(
        protected readonly ModuleTemplateFactory $moduleTemplateFactory,
        protected readonly UriBuilder $backendUriBuilder,
        protected readonly TypoScriptService $typoScriptService,
        protected readonly PageRenderer $pageRenderer,
        protected readonly ModuleProvider $moduleProvider,
        protected readonly FlashMessageService $flashMessageService,
        protected readonly PermissionService $permissionService,
        protected readonly AltTextFinderService $altTextFinderService,
    ) {}

    /**
     * Renders the accessibility backend module.
     * 
     * Renders the accessibility backend module with various feature to check and improve
     * accessibility of the selected page.
     * 
     * @param ServerRequestInterface $request
     * 
     * @return ResponseInterface
     * 
     * @throws MethodNotAllowedException If wrong HTTP method is used.
     * @throws InvalidArgumentException If module data is not set.
     * @throws InvalidArgumentException If an invalid feature is selected.
     */
    public function mainAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->request = $request;
        $this->assertAllowedHttpMethod($this->request, 'GET');
        $this->moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $this->moduleTemplate->setTitle($this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:mlang_tabs_tab'));

        $backendUser = $this->getBackendUserAuthentication();
        $this->pageId = (int)($this->request->getParsedBody()['id'] ?? $this->request->getQueryParams()['id'] ?? 0);

        if ($this->pageId === 0) {
            $this->addFlashMessage(
                $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:noPageSelected'),
                $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:noPageSelected.description'),
                ContextualFeedbackSeverity::INFO
            );
            return $this->moduleTemplate->renderResponse('Backend/Info')->withStatus(403);
        }

        $this->pageInfo = BackendUtility::readPageAccess($this->pageId, $backendUser->getPagePermsClause(Permission::PAGE_SHOW));
        if ($this->pageInfo === false) {
            $this->addFlashMessage(
                $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:noPageAccess'),
                $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:noPageAccess.description'),
                ContextualFeedbackSeverity::ERROR
            );
            return $this->moduleTemplate->renderResponse('Backend/Info')->withStatus(403);
        }

        $this->moduleData = $this->request->getAttribute('moduleData', null);
        if (null === $this->moduleData) {
            throw new InvalidArgumentException('Module data is not set.', 1745686754);
        }

        $this->languageId = (int)$this->moduleData->get('languageId', 0);
        if (!$backendUser->checkLanguageAccess($this->languageId)) {
            $this->addFlashMessage(
                $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:noLanguageAccess'),
                $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:noLanguageAccess.description'),
                ContextualFeedbackSeverity::ERROR
            );
            return $this->moduleTemplate->renderResponse('Backend/Info')->withStatus(403);
        }

        $this->feature = Feature::tryFrom($this->moduleData->get('feature', Feature::GENERAL->value)) ?? Feature::GENERAL;
        $this->pageTsConfig = $this->typoScriptService->convertTypoScriptArrayToPlainArray($this->getPageTsConfig());
        $this->moduleTemplate->getDocHeaderComponent()->getMenuRegistry()->addMenu($this->buildFeatureMenu());
        $this->moduleTemplate->getDocHeaderComponent()->getMenuRegistry()->addMenu($this->buildLanguageMenu());
        $this->pageRenderer->addInlineLanguageLabelArray($this->getInlineLanguageLabels());

        switch ($this->feature) {
            case Feature::GENERAL:
                return $this->handleGeneralFeature();
            case Feature::HEADING_STRUCTURE:
                if ($this->pageTsConfig['mod']['mindfula11y_accessibility']['headingStructure']['enable'] ?? false) {
                    return $this->handleHeadingStructureFeature();
                }
                break;
            case Feature::LANDMARK_STRUCTURE:
                if ($this->pageTsConfig['mod']['mindfula11y_accessibility']['landmarkStructure']['enable'] ?? false) {
                    return $this->handleLandmarkStructureFeature();
                }
                break;
            case Feature::MISSING_ALT_TEXT:
                if ($this->pageTsConfig['mod']['mindfula11y_accessibility']['missingAltText']['enable'] ?? false) {
                    return $this->handleMissingAltTextFeature();
                }
                break;
        }
        throw new InvalidArgumentException('Invalid feature: ' . ($this->feature->value ?? ''), 1748518675);
    }

    /**
     * Handle general module features.
     */
    protected function handleGeneralFeature(): ResponseInterface
    {
        if (
            $this->permissionService->checkTableWriteAccess('sys_file_reference')
            && $this->permissionService->checkNonExcludeFields('sys_file_reference', ['alternative'])
        ) {
            $fileReferences = $this->altTextFinderService->getAltlessFileReferences(
                $this->pageId,
                1,
                $this->languageId,
                $this->pageTsConfig,
                0,
                100,
                true
            );
        } else {
            $fileReferences = [];
        }

        $this->moduleTemplate->assignMultiple([
            'moduleData' => array_merge($this->moduleData->toArray(), [
                'id' => $this->pageId,
            ]),
            'fileReferences' => $fileReferences,
            'previewUrl' => (string)PreviewUriBuilder::create($this->pageId)
                ->withLanguage($this->languageId)
                ->buildUri(),
            'enableHeadingStructure' => $this->pageTsConfig['mod']['mindfula11y_accessibility']['headingStructure']['enable'] ?? false,
            'enableLandmarkStructure' => $this->pageTsConfig['mod']['mindfula11y_accessibility']['landmarkStructure']['enable'] ?? false,
            'enableMissingAltText' => $this->pageTsConfig['mod']['mindfula11y_accessibility']['missingAltText']['enable'] ?? false,
        ]);

        $this->pageRenderer->loadJavaScriptModule('@mindfulmarkup/mindfula11y/altless-file-reference.js');
        $this->pageRenderer->loadJavaScriptModule('@mindfulmarkup/mindfula11y/heading-structure.js');
        $this->pageRenderer->loadJavaScriptModule('@mindfulmarkup/mindfula11y/landmark-structure.js');

        return $this->moduleTemplate->renderResponse('Backend/General');
    }

    /**
     * Handle the heading structure feature.
     */
    protected function handleHeadingStructureFeature(): ResponseInterface
    {
        $this->moduleTemplate->assignMultiple([
            'moduleData' => array_merge($this->moduleData->toArray(), [
                'id' => $this->pageId,
            ]),
            'previewUrl' => (string)PreviewUriBuilder::create($this->pageId)
                ->withLanguage($this->languageId)
                ->buildUri(),
        ]);

        $this->pageRenderer->loadJavaScriptModule('@mindfulmarkup/mindfula11y/heading-structure.js');

        return $this->moduleTemplate->renderResponse('Backend/HeadingStructure');
    }

    /**
     * Handle the landmark structure feature.
     */
    protected function handleLandmarkStructureFeature(): ResponseInterface
    {
        $this->moduleTemplate->assignMultiple([
            'moduleData' => array_merge($this->moduleData->toArray(), [
                'id' => $this->pageId,
            ]),
            'previewUrl' => (string)PreviewUriBuilder::create($this->pageId)
                ->withLanguage($this->languageId)
                ->buildUri(),
        ]);

        $this->pageRenderer->loadJavaScriptModule('@mindfulmarkup/mindfula11y/landmark-structure.js');

        return $this->moduleTemplate->renderResponse('Backend/LandmarkStructure');
    }

    /**
     * Handle the missing alt text feature.
     */
    protected function handleMissingAltTextFeature(): ResponseInterface
    {
        $currentPage = (int)$this->moduleData->get('currentPage', 1);
        $pageLevels = (int)$this->moduleData->get('pageLevels', 1);
        $tableName = $this->moduleData->get('tableName', '');
        $filterFileMetaData = (bool)$this->moduleData->get('filterFileMetaData', true);

        $this->moduleTemplate->getDocHeaderComponent()->getMenuRegistry()->addMenu($this->buildPageLevelsMenu($tableName, $pageLevels, $filterFileMetaData));
        $this->moduleTemplate->getDocHeaderComponent()->getMenuRegistry()->addMenu($this->buildTableMenu(
            $tableName,
            $pageLevels,
            $filterFileMetaData
        ));

        if (
            $this->permissionService->checkTableReadAccess('sys_file_metadata') &&
            $this->permissionService->checkNonExcludeFields('sys_file_metadata', ['alternative'])
        ) {
            $this->moduleTemplate->getDocHeaderComponent()->getButtonBar()->addButton(
                $this->buildFilterDropdown(
                    $tableName,
                    $pageLevels,
                    $filterFileMetaData
                ),
                ButtonBar::BUTTON_POSITION_RIGHT
            );
        } else {
            $filterFileMetaData = false;
        }

        /**
         * Protect table records from being shown if the user does not have
         * write access to the table. Subsequent methods won't do this.
         * We intentionally ignore "hideTable" as inline records should
         * be shown even if the table is hidden.
         */
        if (!empty($tableName) && !$this->permissionService->checkTableWriteAccess($tableName)) {
            $this->addFlashMessage(
                $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:missingAltText.noTableAccess'),
                $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:missingAltText.noTableAccess.description'),
                ContextualFeedbackSeverity::ERROR
            );
            return $this->moduleTemplate->renderResponse('Backend/Info')->withStatus(403);
        }

        /**
         * If the user does not have write access to the sys_file_reference table
         * we cannot show any file references.
         */
        if (
            !$this->permissionService->checkTableWriteAccess('sys_file_reference')
            || !$this->permissionService->checkNonExcludeFields('sys_file_reference', ['alternative'])
        ) {
            $this->addFlashMessage(
                $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:missingAltText.noFileReferenceAccess'),
                $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:missingAltText.noFileReferenceAccess.description'),
                ContextualFeedbackSeverity::ERROR
            );
            return $this->moduleTemplate->renderResponse('Backend/Info')->withStatus(403);
        }

        $itemsPerPage = 100;
        $offset = ($currentPage - 1) * $itemsPerPage;

        if (!empty($tableName)) {
            $fileReferenceCount = $this->altTextFinderService->countAltlessFileReferencesForTable(
                $tableName,
                $this->pageId,
                $pageLevels,
                $this->languageId,
                $this->pageTsConfig,
                $filterFileMetaData
            );
            $fileReferences = $this->altTextFinderService->getAltlessFileReferencesForTable(
                $tableName,
                $this->pageId,
                $pageLevels,
                $this->languageId,
                $this->pageTsConfig,
                $offset,
                $itemsPerPage,
                $filterFileMetaData
            );
        } else {
            $fileReferenceCount = $this->altTextFinderService->countAltlessFileReferences(
                $this->pageId,
                $pageLevels,
                $this->languageId,
                $this->pageTsConfig,
                $filterFileMetaData
            );
            $fileReferences = $this->altTextFinderService->getAltlessFileReferences(
                $this->pageId,
                $pageLevels,
                $this->languageId,
                $this->pageTsConfig,
                $offset,
                $itemsPerPage,
                $filterFileMetaData
            );
        }

        // Not using extbase queries: fill with null, then insert fileReferences at the correct offset
        $paginatorItems = array_fill(0, $fileReferenceCount, null);
        foreach ($fileReferences as $idx => $fileReference) {
            $paginatorItems[$offset + $idx] = $fileReference;
        }

        $paginator = new ArrayPaginator($paginatorItems, $currentPage, $itemsPerPage);
        $pagination = new SimplePagination($paginator);

        $this->moduleTemplate->assignMultiple([
            'moduleData' => array_merge($this->moduleData->toArray(), [
                'id' => $this->pageId,
            ]),
            'pagination' => $pagination,
            'paginator' => $paginator,
        ]);

        $this->pageRenderer->loadJavaScriptModule('@mindfulmarkup/mindfula11y/altless-file-reference.js');

        return $this->moduleTemplate->renderResponse('Backend/MissingAltText');
    }

    /**
     * Get the URI for a menu item as a string.
     * 
     * @param array $additionalParams The additional parameters.
     */
    protected function getMenuItemUri(array $additionalParams): string
    {
        $params = array_replace([
            'id' => $this->pageId,
            'languageId' => $this->languageId,
            'feature' => $this->feature->value,
        ], $additionalParams);

        return (string)$this->backendUriBuilder->buildUriFromRoute(
            'mindfula11y_accessibility',
            $params
        );
    }

    /**
     * Build the feature menu for the module.
     */
    protected function buildFeatureMenu(): Menu
    {
        $menu = $this->moduleTemplate->getDocHeaderComponent()->getMenuRegistry()->makeMenu()
            ->setIdentifier('MindfulA11yFeatures')
            ->setLabel($this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:menu.features'));
        foreach ([Feature::GENERAL, Feature::HEADING_STRUCTURE, Feature::LANDMARK_STRUCTURE, Feature::MISSING_ALT_TEXT] as $feature) {
            $menuItem = $menu->makeMenuItem()->setTitle(
                $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:menu.features.' . $feature->value)
            )->setHref(
                $this->getMenuItemUri(['feature' => $feature->value])
            )->setActive(
                $this->feature === $feature
            );
            $menu->addMenuItem($menuItem);
        }

        return $menu;
    }

    /**
     * Build table menu for the module.
     *
     * @param string $currentTableName The current table name.
     * @param int $currentPageLevels The current page levels.
     * @param bool $filterFileMetaData Whether to filter file metadata.
     */
    protected function buildTableMenu(string $currentTableName, int $currentPageLevels, bool $filterFileMetaData): Menu
    {
        $tables = $this->altTextFinderService->getTablesWithFiles($this->pageTsConfig);
        // Add an empty string as the first menu item (for "all tables" option)
        array_unshift($tables, '');
        $menu = $this->moduleTemplate->getDocHeaderComponent()->getMenuRegistry()->makeMenu();
        $menu->setIdentifier('MindfulA11yMissingAltTextTable')
            ->setLabel(
                'LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:menu.tables'
            );
        foreach ($tables as $tableName) {
            $menuItem = $menu->makeMenuItem()->setTitle(
                $this->getTableTitle($tableName)
            )->setHref(
                $this->getMenuItemUri(
                    [
                        'tableName' => $tableName,
                        'pageLevels' => $currentPageLevels,
                        'filterFileMetaData' => $filterFileMetaData,
                    ]
                )
            )->setActive($tableName === $currentTableName);
            $menu->addMenuItem($menuItem);
        }

        return $menu;
    }

    /**
     * Build page level menu for the module.
     * 
     * @param string $currentTableName The current table name.
     * @param int $currentPageLevels The current page levels.
     * @param bool $filterFileMetaData Whether to filter file metadata.
     */
    protected function buildPageLevelsMenu(string $currentTableName, int $currentPageLevels, bool $filterFileMetaData): Menu
    {
        $menu = $this->moduleTemplate->getDocHeaderComponent()->getMenuRegistry()->makeMenu();
        $menu->setIdentifier('MindfulA11yMissingAltTextPageLevels')
            ->setLabel(
                'LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:menu.pageLevels'
            );

        foreach ([1, 5, 10, 99] as $pageLevels) {
            $menuItem = $menu->makeMenuItem()->setTitle(
                $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:menu.pageLevels.' . $pageLevels)
            )->setHref(
                $this->getMenuItemUri(
                    [
                        'tableName' => $currentTableName,
                        'pageLevels' => $pageLevels,
                        'filterFileMetaData' => $filterFileMetaData,
                    ]
                )
            )->setActive($pageLevels === $currentPageLevels);
            $menu->addMenuItem($menuItem);
        }

        return $menu;
    }

    /**
     * Build filter dropdown for the module.
     * 
     * @param string $currentTableName The current table name.
     * @param int $currentPageLevels The current page levels.
     * @param bool $filterFileMetaData Whether to filter file metadata.
     */
    protected function buildFilterDropdown(string $currentTableName, int $currentPageLevels, bool $filterFileMetaData): DropDownButton
    {
        $button = $this->moduleTemplate->getDocHeaderComponent()->getButtonBar()->makeDropDownButton()
            ->setLabel($this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:menu.filter'))
            ->setShowLabelText(true);

        /** @var DropDownToggle $filterFileMetaDataToggle */
        $filterFileMetaDataToggle = GeneralUtility::makeInstance(DropDownToggle::class)
            ->setActive($filterFileMetaData)
            ->setHref($this->getMenuItemUri([
                'tableName' => $currentTableName,
                'pageLevels' => $currentPageLevels,
                'filterFileMetaData' => !$filterFileMetaData,
            ]))->setLabel($this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:menu.filter.fileMetaData'))
            ->setIcon(null);

        $button->addItem($filterFileMetaDataToggle);

        return $button;
    }

    /**
     * Build a language menu for the module.
     * 
     * Add menu listing all available languages for the user based on the active site
     * configuration for the current page. This menu must be manually added by subclasses as
     * it might need additional parameters.
     * 
     * @return Menu|null The language menu or null if no languages are available.
     */
    protected function buildLanguageMenu(): ?Menu
    {
        $allowedLanguages = $this->getAllowedSiteLanguages();

        if (empty($allowedLanguages)) {
            return null;
        }

        $menu = $this->moduleTemplate->getDocHeaderComponent()->getMenuRegistry()->makeMenu();
        $menu->setIdentifier('MindfulA11yAccessibilityLanguage')
            ->setLabel(
                'LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:menu.language'
            );

        foreach ($allowedLanguages as $language) {
            $menuItem = $menu->makeMenuItem()->setTitle(
                $language->getTitle()
            )->setHref(
                $this->getMenuItemUri(
                    [
                        'languageId' => $language->getLanguageId(),
                    ]
                )
            )->setActive($language->getLanguageId() === $this->languageId);
            $menu->addMenuItem($menuItem);
        }

        return $menu;
    }

    /**
     * Get title of a table from TCA.
     * 
     * @param string $tableName The name of the table.
     * 
     * @return string
     */
    protected function getTableTitle(string $tableName): string
    {
        if (empty($tableName)) {
            return $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:menu.tables.all');
        }
        if (isset($GLOBALS['TCA'][$tableName]['ctrl']['title'])) {
            return $this->getLanguageService()->sL($GLOBALS['TCA'][$tableName]['ctrl']['title']);
        }
        return $tableName;
    }

    /**
     * Returns all inline language labels used in the module.
     *
     * @return array
     */
    protected function getInlineLanguageLabels(): array
    {
        $labels = [
            // Heading Structure labels
            'mindfula11y.features.headingStructure.edit' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:headingStructure.edit'),
            'mindfula11y.features.headingStructure.edit.locked' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:headingStructure.edit.locked'),
            'mindfula11y.features.headingStructure.error.loading' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:headingStructure.error.loading'),
            'mindfula11y.features.headingStructure.error.loading.description' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:headingStructure.error.loading.description'),
            'mindfula11y.features.headingStructure.error.store' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:headingStructure.error.store'),
            'mindfula11y.features.headingStructure.error.store.description' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:headingStructure.error.store.description'),
            'mindfula11y.features.headingStructure.error.skippedLevel' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:headingStructure.error.skippedLevel'),
            'mindfula11y.features.headingStructure.error.skippedLevel.description' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:headingStructure.error.skippedLevel.description'),
            'mindfula11y.features.headingStructure.error.skippedLevel.inline' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:headingStructure.error.skippedLevel.inline'),
            'mindfula11y.features.headingStructure.error.missingH1' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:headingStructure.error.missingH1'),
            'mindfula11y.features.headingStructure.error.missingH1.description' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:headingStructure.error.missingH1.description'),
            'mindfula11y.features.headingStructure.error.multipleH1' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:headingStructure.error.multipleH1'),
            'mindfula11y.features.headingStructure.error.multipleH1.description' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:headingStructure.error.multipleH1.description'),
            'mindfula11y.features.headingStructure.error.emptyHeadings' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:headingStructure.error.emptyHeadings'),
            'mindfula11y.features.headingStructure.error.emptyHeadings.description' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:headingStructure.error.emptyHeadings.description'),
            // Global severity labels (used by multiple components)
            'mindfula11y.features.severity.error' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:severity.error'),
            'mindfula11y.features.severity.warning' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:severity.warning'),
            // Unified error messages
            'mindfula11y.features.accessibility.error.loading' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:accessibility.error.loading'),
            'mindfula11y.features.accessibility.error.loading.description' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:accessibility.error.loading.description'),
            'mindfula11y.features.headingStructure.unlabeled' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:headingStructure.unlabeled'),
            'mindfula11y.features.headingStructure.type.label' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:headingStructure.type.label'),
            'mindfula11y.features.headingStructure.noHeadings' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:headingStructure.noHeadings'),
            'mindfula11y.features.headingStructure.noHeadings.description' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:headingStructure.noHeadings.description'),
            // Landmark Structure labels
            'mindfula11y.features.landmarkStructure.edit' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:landmarkStructure.edit'),
            'mindfula11y.features.landmarkStructure.edit.locked' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:landmarkStructure.edit.locked'),
            'mindfula11y.features.landmarkStructure.nestedLandmarks' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:landmarkStructure.nestedLandmarks'),
            'mindfula11y.features.landmarkStructure.role' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:landmarkStructure.role'),
            'mindfula11y.features.landmarkStructure.unlabelledLandmark' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:landmarkStructure.unlabelledLandmark'),
            'mindfula11y.features.landmarkStructure.error.loading' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:landmarkStructure.error.loading'),
            'mindfula11y.features.landmarkStructure.error.loading.description' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:landmarkStructure.error.loading.description'),
            'mindfula11y.features.landmarkStructure.error.store' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:landmarkStructure.error.store'),
            'mindfula11y.features.landmarkStructure.error.store.description' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:landmarkStructure.error.store.description'),
            'mindfula11y.features.landmarkStructure.error.missingMain' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:landmarkStructure.error.missingMain'),
            'mindfula11y.features.landmarkStructure.error.missingMain.description' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:landmarkStructure.error.missingMain.description'),
            'mindfula11y.features.landmarkStructure.error.duplicateLandmark' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:landmarkStructure.error.duplicateLandmark'),
            'mindfula11y.features.landmarkStructure.error.duplicateLandmark.description' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:landmarkStructure.error.duplicateLandmark.description'),
            'mindfula11y.features.landmarkStructure.error.duplicateSameLabel' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:landmarkStructure.error.duplicateSameLabel'),
            'mindfula11y.features.landmarkStructure.error.duplicateSameLabel.description' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:landmarkStructure.error.duplicateSameLabel.description'),
            'mindfula11y.features.landmarkStructure.error.multipleUnlabeledLandmarks' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:landmarkStructure.error.multipleUnlabeledLandmarks'),
            'mindfula11y.features.landmarkStructure.error.multipleUnlabeledLandmarks.description' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:landmarkStructure.error.multipleUnlabeledLandmarks.description'),
            // Default landmark labels
            'mindfula11y.features.landmarkStructure.role.banner' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:landmarkStructure.role.banner'),
            'mindfula11y.features.landmarkStructure.role.main' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:landmarkStructure.role.main'),
            'mindfula11y.features.landmarkStructure.role.navigation' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:landmarkStructure.role.navigation'),
            'mindfula11y.features.landmarkStructure.role.complementary' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:landmarkStructure.role.complementary'),
            'mindfula11y.features.landmarkStructure.role.contentinfo' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:landmarkStructure.role.contentinfo'),
            'mindfula11y.features.landmarkStructure.role.region' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:landmarkStructure.role.region'),
            'mindfula11y.features.landmarkStructure.role.search' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:landmarkStructure.role.search'),
            'mindfula11y.features.landmarkStructure.role.form' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:landmarkStructure.role.form'),
            'mindfula11y.features.landmarkStructure.role.none' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:landmarkStructure.role.none'),
            // No landmarks message
            'mindfula11y.features.landmarkStructure.noLandmarks.title' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:landmarkStructure.noLandmarks.title'),
            'mindfula11y.features.landmarkStructure.noLandmarks.description' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:landmarkStructure.noLandmarks.description'),
            // Missing Alt Text labels
            'mindfula11y.features.missingAltText.generate.button' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:missingAltText.generate.button'),
            'mindfula11y.features.missingAltText.generate.loading' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:missingAltText.generate.loading'),
            'mindfula11y.features.missingAltText.generate.success' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:missingAltText.generate.success'),
            'mindfula11y.features.missingAltText.generate.success.description' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:missingAltText.generate.success.description'),
            'mindfula11y.features.missingAltText.generate.error.unknown' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:missingAltText.generate.error.unknown'),
            'mindfula11y.features.missingAltText.generate.error.unknown.description' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:missingAltText.generate.error.unknown.description'),
            'mindfula11y.features.missingAltText.generate.error.openAIConnection' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:missingAltText.generate.error.openAIConnection'),
            'mindfula11y.features.missingAltText.generate.error.openAIConnection.description' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:missingAltText.generate.error.openAIConnection.description'),
            'mindfula11y.features.missingAltText.altLabel' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:missingAltText.altLabel'),
            'mindfula11y.features.missingAltText.altPlaceholder' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:missingAltText.altPlaceholder'),
            'mindfula11y.features.missingAltText.save' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:missingAltText.save'),
            'mindfula11y.features.missingAltText.imagePreview' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:missingAltText.imagePreview'),
            'mindfula11y.features.missingAltText.fallbackAltLabel' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:missingAltText.fallbackAltLabel'),
            // Error badge label
            'mindfula11y.features.landmarkStructure.error.badge' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:landmarkStructure.error.badge'),
            'mindfula11y.features.landmarkStructure.intro' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:landmarkStructure.intro'),
            'mindfula11y.features.landmarkStructure.intro.roles' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:landmarkStructure.intro.roles'),
            // Individual landmark callout messages (shown on specific landmarks)
            'mindfula11y.features.landmarkStructure.callout.duplicateMain' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:landmarkStructure.callout.duplicateMain'),
            'mindfula11y.features.landmarkStructure.callout.duplicateRoleSameLabel' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:landmarkStructure.callout.duplicateRoleSameLabel'),
            'mindfula11y.features.landmarkStructure.callout.multipleUnlabeledSameRole' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:landmarkStructure.callout.multipleUnlabeledSameRole'),
        ];

        return $labels;
    }

    /**
     * Add a flash message.
     * 
     * @param string $title The title of the message.
     * @param string $message The message content.
     * @param ContextualFeedbackSeverity $severity The severity of the message.
     */
    protected function addFlashMessage(string $title, string $message, ContextualFeedbackSeverity $severity): void
    {
        $flashMessage = GeneralUtility::makeInstance(
            FlashMessage::class,
            $message,
            $title,
            $severity
        );

        $this->flashMessageService->getMessageQueueByIdentifier()->addMessage($flashMessage);
    }

    /**
     * Get backend user authentication.
     */
    protected function getBackendUserAuthentication(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }

    /**
     * Get page tsconfig.
     */
    protected function getPageTsConfig(): array
    {
        return BackendUtility::getPagesTSconfig($this->pageId) ?? [];
    }

    /**
     * Get language service.
     */
    protected function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }

    /**
     * Get allowed site languages for the backend user.
     * 
     * @return array
     */
    protected function getAllowedSiteLanguages(): array
    {
        $site = $this->request->getAttribute('site');
        if (null === $site) {
            return [];
        }

        return $site->getAvailableLanguages($this->getBackendUserAuthentication(), false, $this->pageId);
    }
}
