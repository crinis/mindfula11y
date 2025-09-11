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
use MindfulMarkup\MindfulA11y\Service\GeneralModuleService;
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
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Http\AllowedMethodsTrait;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Pagination\ArrayPaginator;
use TYPO3\CMS\Core\Pagination\SimplePagination;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;

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
        protected readonly PageRenderer $pageRenderer,
        protected readonly ModuleProvider $moduleProvider,
        protected readonly FlashMessageService $flashMessageService,
        protected readonly PermissionService $permissionService,
        protected readonly AltTextFinderService $altTextFinderService,
        protected readonly ConnectionPool $connectionPool,
        protected readonly GeneralModuleService $generalModuleService,
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
        $this->moduleTemplate->setTitle($this->generalModuleService->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:mlang_tabs_tab'));

        $backendUser = $this->generalModuleService->getBackendUserAuthentication();
        $this->pageId = (int)($this->request->getParsedBody()['id'] ?? $this->request->getQueryParams()['id'] ?? 0);

        if (0 === $this->pageId) {
            $this->addFlashMessage(
                $this->generalModuleService->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:noPageSelected'),
                $this->generalModuleService->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:noPageSelected.description'),
                ContextualFeedbackSeverity::INFO
            );
            return $this->moduleTemplate->renderResponse('Backend/Info')->withStatus(403);
        }

        $this->pageInfo = BackendUtility::readPageAccess($this->pageId, $backendUser->getPagePermsClause(Permission::PAGE_SHOW));
        if (false === $this->pageInfo) {
            $this->addFlashMessage(
                $this->generalModuleService->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:noPageAccess'),
                $this->generalModuleService->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:noPageAccess.description'),
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
                $this->generalModuleService->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:noLanguageAccess'),
                $this->generalModuleService->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:noLanguageAccess.description'),
                ContextualFeedbackSeverity::ERROR
            );
            return $this->moduleTemplate->renderResponse('Backend/Info')->withStatus(403);
        }

        $this->feature = Feature::tryFrom($this->moduleData->get('feature', Feature::GENERAL->value)) ?? Feature::GENERAL;
        $this->pageTsConfig = $this->generalModuleService->getConvertedPageTsConfig($this->pageId);
        $this->moduleTemplate->getDocHeaderComponent()->getMenuRegistry()->addMenu($this->buildFeatureMenu());
        $this->moduleTemplate->getDocHeaderComponent()->getMenuRegistry()->addMenu($this->buildLanguageMenu());
        $this->pageRenderer->addInlineLanguageLabelArray($this->generalModuleService->getInlineLanguageLabels());

        switch ($this->feature) {
            case Feature::GENERAL:
                return $this->handleGeneralFeature();
            case Feature::MISSING_ALT_TEXT:
                return $this->handleMissingAltTextFeature();
                break;
        }
        throw new InvalidArgumentException('Invalid feature: ' . ($this->feature->value ?? ''), 1748518675);
    }

    /**
     * Handle general module features.
     */
    protected function handleGeneralFeature(): ResponseInterface
    {
        $localizedPageInfo = $this->generalModuleService->getLocalizedPageRecord($this->pageId, $this->languageId);
        $doktype = $localizedPageInfo['doktype'] ?? $this->pageInfo['doktype'] ?? PageRepository::DOKTYPE_DEFAULT;
        $previewEnabled = $this->generalModuleService->isPreviewEnabledForDoktype($doktype, $this->pageTsConfig);

        $missingAltTextUri = null;
        $fileReferenceCount = null;

        $hasMissingAltTextAccess = $this->generalModuleService->hasMissingAltTextAccess($this->pageTsConfig);
        $hasHeadingStructureAccess = $this->generalModuleService->hasHeadingStructureAccess($this->pageTsConfig);
        $hasLandmarkStructureAccess = $this->generalModuleService->hasLandmarkStructureAccess($this->pageTsConfig);

        if (
            $hasMissingAltTextAccess
        ) {
            $filterFileMetaData = $this->permissionService->checkTableReadAccess('sys_file_metadata') && $this->permissionService->checkNonExcludeFields('sys_file_metadata', ['alternative']);
            $fileReferenceCount = $this->altTextFinderService->countAltlessFileReferences(
                $this->pageId,
                0,
                $this->languageId,
                $this->pageTsConfig,
                $filterFileMetaData
            );

            $missingAltTextUri = $this->backendUriBuilder->buildUriFromRoute(
                'mindfula11y_accessibility',
                [
                    'id' => $this->pageId,
                    'feature' => Feature::MISSING_ALT_TEXT->value,
                    'languageId' => $this->languageId,
                ]
            );
        }

        $this->moduleTemplate->assignMultiple([
            'moduleData' => array_merge($this->moduleData->toArray(), [
                'id' => $this->pageId,
            ]),
            'fileReferenceCount' => $fileReferenceCount,
            'previewUrl' => $previewEnabled ? (string)PreviewUriBuilder::create($this->pageId)
                ->withLanguage($this->languageId)
                ->buildUri() : null,
            'missingAltTextUri' => $missingAltTextUri,
            'hasMissingAltTextAccess' => $hasMissingAltTextAccess,
            'hasHeadingStructureAccess' => $hasHeadingStructureAccess,
            'hasLandmarkStructureAccess' => $hasLandmarkStructureAccess,
        ]);

        $this->pageRenderer->loadJavaScriptModule('@mindfulmarkup/mindfula11y/structure.js');

        return $this->moduleTemplate->renderResponse('Backend/General');
    }

    /**
     * Handle the missing alt text feature.
     */
    protected function handleMissingAltTextFeature(): ResponseInterface
    {
        if (!$this->generalModuleService->hasMissingAltTextAccess($this->pageTsConfig)) {
            $this->addFlashMessage(
                $this->generalModuleService->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:missingAltText.noAccess'),
                $this->generalModuleService->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:missingAltText.noAccess.description'),
                ContextualFeedbackSeverity::ERROR
            );
            return $this->moduleTemplate->renderResponse('Backend/Info')->withStatus(403);
        }

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
            $this->permissionService->checkTableReadAccess('sys_file_metadata')
            && $this->permissionService->checkNonExcludeFields('sys_file_metadata', ['alternative'])
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
         * read access to the table. Subsequent methods won't do this.
         * We intentionally ignore "hideTable" as inline records should
         * be shown even if the table is hidden.
         */
        if (empty($tableName) && !$this->permissionService->checkTableReadAccess($tableName)) {
            $this->addFlashMessage(
                $this->generalModuleService->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:missingAltText.noTableAccess'),
                $this->generalModuleService->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:missingAltText.noTableAccess.description'),
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
            'paginator' => $paginator
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
            ->setLabel($this->generalModuleService->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:menu.features'));
        foreach ([Feature::GENERAL, Feature::MISSING_ALT_TEXT] as $feature) {
            $enabled = match ($feature) {
                Feature::GENERAL => true,
                Feature::MISSING_ALT_TEXT => $this->generalModuleService->hasMissingAltTextAccess($this->pageTsConfig),
            };
            if ($enabled) {
                $menuItem = $menu->makeMenuItem()->setTitle(
                    $this->generalModuleService->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:menu.features.' . $feature->value)
                )->setHref(
                    $this->getMenuItemUri(['feature' => $feature->value])
                )->setActive(
                    $this->feature === $feature
                );
                $menu->addMenuItem($menuItem);
            }
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
                $this->generalModuleService->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:menu.pageLevels.' . $pageLevels)
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
            ->setLabel($this->generalModuleService->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:menu.filter'))
            ->setShowLabelText(true);

        /** @var DropDownToggle $filterFileMetaDataToggle */
        $filterFileMetaDataToggle = GeneralUtility::makeInstance(DropDownToggle::class)
            ->setActive($filterFileMetaData)
            ->setHref($this->getMenuItemUri([
                'tableName' => $currentTableName,
                'pageLevels' => $currentPageLevels,
                'filterFileMetaData' => !$filterFileMetaData,
            ]))->setLabel($this->generalModuleService->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:menu.filter.fileMetaData'))
            ->setIcon(null);

        $button->addItem($filterFileMetaDataToggle);

        return $button;
    }

    /**
     * Build a language menu for the module.
     * 
     * Add menu listing all available languages for the user based on the active site
     * configuration for the current page, but only for languages where the page is translated.
     * 
     * @return Menu|null The language menu or null if no languages are available.
     */
    protected function buildLanguageMenu(): ?Menu
    {
        $allowedLanguages = $this->getAllowedSiteLanguages();

        if (empty($allowedLanguages) || 0 === $this->pageId) {
            return null;
        }

        $pageTranslations = BackendUtility::getExistingPageTranslations($this->pageId);

        $availableLanguageIds = [];
        foreach ($pageTranslations as $pageTranslation) {
            $languageId = $pageTranslation[$GLOBALS['TCA']['pages']['ctrl']['languageField']] ?? null;

            if (null === $languageId) {
                continue;
            }

            $availableLanguageIds[] = (int)$languageId;
        }

        $menu = $this->moduleTemplate->getDocHeaderComponent()->getMenuRegistry()->makeMenu();
        $menu->setIdentifier('MindfulA11yAccessibilityLanguage')
            ->setLabel(
                'LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:menu.language'
            );

        foreach ($allowedLanguages as $language) {
            // Only show languages where the page is actually translated
            if (0 !== $language->getLanguageId() && !in_array($language->getLanguageId(), $availableLanguageIds, true)) {
                continue;
            }

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
            return $this->generalModuleService->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:menu.tables.all');
        }
        if (isset($GLOBALS['TCA'][$tableName]['ctrl']['title'])) {
            return $this->generalModuleService->getLanguageService()->sL($GLOBALS['TCA'][$tableName]['ctrl']['title']);
        }
        return $tableName;
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
     * Get allowed site languages for the backend user.
     * 
     * @return array
     */
    protected function getAllowedSiteLanguages(): array
    {
        $site = $this->request->getAttribute('site', null);
        if (null === $site) {
            return [];
        }

        return $site->getAvailableLanguages($this->generalModuleService->getBackendUserAuthentication(), false, $this->pageId);
    }
}
