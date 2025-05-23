<?php

declare(strict_types=1);

namespace MindfulMarkup\MindfulA11y\Controller;

use GuzzleHttp\Psr7\ServerRequest;
use MindfulMarkup\MindfulA11y\Service\AltTextFinderService;
use MindfulMarkup\MindfulA11y\Service\PermissionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\Buttons\DropDown\DropDownToggle;
use TYPO3\CMS\Backend\Template\Components\Buttons\DropDownButton;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Backend\Template\Components\Menu\Menu;
use TYPO3\CMS\Core\Pagination\ArrayPaginator;
use TYPO3\CMS\Core\Pagination\SimplePagination;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

#[AsController]
class AlternativeMissingAltTextModuleController extends AbstractModuleController
{
    /**
     * The amount of page levels to draw records from.
     * 
     * @var int
     */
    protected int $pageLevels = 1;

    /**
     * The selected table name to check for missing alternative text.
     * 
     * @var string
     */
    protected string $tableName = 'tt_content';

    /**
     * Flag to filter file metadata.
     * 
     * @var bool
     */
    protected bool $filterFileMetaData = true;

    /**
     * The current page number.
     * 
     * @var int
     */
    protected int $currentPage = 1;

    /**
     * Constructor.
     */
    public function __construct(
        protected readonly AltTextFinderService $altTextFinderService,
    ) {}

    /**
     * Main action to list records with missing alternative text.
     * 
     * Retrieves all file references that are missing alternative text and lists them. Ensures
     * the user has the appropriate permissions to also be able to edit the records.
     * 
     * @param ServerRequestInterface $request The request object.
     * 
     * @return ResponseInterface
     * 
     * @throws MethodNotAllowedException If wrong HTTP method is used.
     * @throws InvalidArgumentException If module data is not set.
     */
    public function mainAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->initializeModule($request, $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/MissingAltText.xlf:mlang_tabs_tab'))) {
            return $this->moduleTemplate->renderResponse('Backend/Info')
                ->withStatus(403);
        }

        $this->currentPage = (int)$this->moduleData->get('currentPage', 1);
        $this->pageLevels = (int)$this->moduleData->get('pageLevels', 1);
        $this->tableName = $this->moduleData->get('tableName', '');
        $this->filterFileMetaData = (bool)$this->moduleData->get('filterFileMetaData', true);

        $this->moduleTemplate->getDocHeaderComponent()->getMenuRegistry()->addMenu($this->buildModuleMenu());
        $this->moduleTemplate->getDocHeaderComponent()->getMenuRegistry()->addMenu($this->buildLanguageMenu());
        $this->moduleTemplate->getDocHeaderComponent()->getMenuRegistry()->addMenu($this->buildPageLevelsMenu());
        $this->moduleTemplate->getDocHeaderComponent()->getMenuRegistry()->addMenu($this->buildTableMenu());

        if ($this->permissionService->checkTableReadAccess('sys_file_metadata')) {
            $this->moduleTemplate->getDocHeaderComponent()->getButtonBar()->addButton(
                $this->buildFilterDropdown(),
                ButtonBar::BUTTON_POSITION_RIGHT
            );
        } else {
            $this->filterFileMetaData = false;
        }

        /**
         * Protect table records from being shown if the user does not have
         * write access to the table. Subsequent methods won't do this.
         * We intentionally ignore "hideTable" as inline records should
         * be shown even if the table is hidden.
         */
        if (!empty($this->tableName) && !$this->permissionService->checkTableWriteAccess($this->tableName)) {
            $this->addFlashMessage(
                $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/MissingAltText.xlf:noTableAccess'),
                $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/MissingAltText.xlf:noTableAccess.description'),
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
            || !$this->getBackendUserAuthentication()->check('non_exclude_fields', 'sys_file_reference:alternative')
        ) {
            $this->addFlashMessage(
                $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/MissingAltText.xlf:noFileReferenceAccess'),
                $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/MissingAltText.xlf:noFileReferenceAccess.description'),
                ContextualFeedbackSeverity::ERROR
            );
            return $this->moduleTemplate->renderResponse('Backend/Info')->withStatus(403);
        }

        $itemsPerPage = 100;
        $offset = ($this->currentPage - 1) * $itemsPerPage;

        if (!empty($this->tableName)) {
            $fileReferenceCount = $this->altTextFinderService->countAltlessFileReferencesForTable(
                $this->tableName,
                $this->pageId,
                $this->pageLevels,
                $this->languageId,
                $this->pageTsConfig,
                $this->filterFileMetaData
            );
            $fileReferences = $this->altTextFinderService->getAltlessFileReferencesForTable(
                $this->tableName,
                $this->pageId,
                $this->pageLevels,
                $this->languageId,
                $this->pageTsConfig,
                $offset,
                $itemsPerPage,
                $this->filterFileMetaData
            );
        } else {
            $fileReferenceCount = $this->altTextFinderService->countAltlessFileReferences(
                $this->pageId,
                $this->pageLevels,
                $this->languageId,
                $this->pageTsConfig,
                $this->filterFileMetaData
            );
            $fileReferences = $this->altTextFinderService->getAltlessFileReferences(
                $this->pageId,
                $this->pageLevels,
                $this->languageId,
                $this->pageTsConfig,
                $offset,
                $itemsPerPage,
                $this->filterFileMetaData
            );
        }

        // Not using extbase queries: fill with null, then insert fileReferences at the correct offset
        $paginatorItems = array_fill(0, $fileReferenceCount, null);
        foreach ($fileReferences as $idx => $fileReference) {
            $paginatorItems[$offset + $idx] = $fileReference;
        }

        $paginator = new ArrayPaginator($paginatorItems, $this->currentPage, $itemsPerPage);
        $pagination = new SimplePagination($paginator);

        $this->moduleTemplate->assignMultiple([
            'moduleData' => array_merge($this->moduleData->toArray(), [
                'id' => $this->pageId,
            ]),
            'pagination' => $pagination,
            'paginator' => $paginator,
        ]);

        $this->pageRenderer->addInlineLanguageLabelArray([
            'mindfula11y.modules.missingAltText.generate.button' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/MissingAltText.xlf:generate.button'),
            'mindfula11y.modules.missingAltText.generate.loading' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/MissingAltText.xlf:generate.loading'),
            'mindfula11y.modules.missingAltText.generate.success' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/MissingAltText.xlf:generate.success'),
            'mindfula11y.modules.missingAltText.generate.success.description' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/MissingAltText.xlf:generate.success.description'),
            'mindfula11y.modules.missingAltText.generate.error.unknown' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/MissingAltText.xlf:generate.error.unknown'),
            'mindfula11y.modules.missingAltText.generate.error.unknown.description' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/MissingAltText.xlf:generate.error.unknown.description'),
            'mindfula11y.modules.missingAltText.generate.error.openAIConnection' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/MissingAltText.xlf:generate.error.openAIConnection'),
            'mindfula11y.modules.missingAltText.generate.error.openAIConnection.description' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/MissingAltText.xlf:generate.error.openAIConnection.description'),
            'mindfula11y.modules.missingAltText.altLabel' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/MissingAltText.xlf:altLabel'),
            'mindfula11y.modules.missingAltText.altPlaceholder' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/MissingAltText.xlf:altPlaceholder'),
            'mindfula11y.modules.missingAltText.save' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/MissingAltText.xlf:save'),
            'mindfula11y.modules.missingAltText.imagePreview' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/MissingAltText.xlf:imagePreview'),
            'mindfula11y.modules.missingAltText.editRecord' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/MissingAltText.xlf:editRecord.label'),
            'mindfula11y.modules.missingAltText.fallbackAltLabel' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/MissingAltText.xlf:fallbackAltLabel'),
        ]);
        $this->pageRenderer->loadJavaScriptModule('@mindfulmarkup/mindfula11y/altless-file-reference.js');

        return $this->moduleTemplate->renderResponse('Backend/AlternativeMissingAltText');
    }

    /**
     * Build table menu for the module.
     *
     * @return Menu
     */
    protected function buildTableMenu(): Menu
    {
        $tables = $this->altTextFinderService->getTablesWithFiles($this->pageTsConfig);
        // Add an empty string as the first menu item (for "all tables" option)
        array_unshift($tables, '');
        $menu = $this->moduleTemplate->getDocHeaderComponent()->getMenuRegistry()->makeMenu();
        $menu->setIdentifier('MindfulA11yMissingAltTextTable')
            ->setLabel(
                'LLL:EXT:mindfula11y/Resources/Private/Language/Modules/MissingAltText.xlf:menu.tables'
            );
        foreach ($tables as $tableName) {
            $menuItem = $menu->makeMenuItem()->setTitle(
                $this->getTableTitle($tableName)
            )->setHref(
                $this->getMenuItemUri(
                    [
                        'tableName' => $tableName,
                    ]
                )
            )->setActive($tableName === $this->tableName);
            $menu->addMenuItem($menuItem);
        }

        return $menu;
    }

    /**
     * Build page level menu for the module.
     * 
     * @return Menu
     */
    protected function buildPageLevelsMenu(): Menu
    {
        $menu = $this->moduleTemplate->getDocHeaderComponent()->getMenuRegistry()->makeMenu();
        $menu->setIdentifier('MindfulA11yMissingAltTextPageLevels')
            ->setLabel(
                'LLL:EXT:mindfula11y/Resources/Private/Language/Modules/MissingAltText.xlf:menu.pageLevels'
            );

        foreach ([1, 5, 10, 99] as $pageLevels) {
            $menuItem = $menu->makeMenuItem()->setTitle(
                $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/MissingAltText.xlf:menu.pageLevels.' . $pageLevels)
            )->setHref(
                $this->getMenuItemUri(
                    [
                        'pageLevels' => $pageLevels,
                    ]
                )
            )->setActive($pageLevels === $this->pageLevels);
            $menu->addMenuItem($menuItem);
        }

        return $menu;
    }

    /**
     * Build filter dropdown for the module.
     */
    protected function buildFilterDropdown(): DropDownButton
    {
        $button = $this->moduleTemplate->getDocHeaderComponent()->getButtonBar()->makeDropDownButton()
            ->setLabel($this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/MissingAltText.xlf:menu.filter'))
            ->setShowLabelText(true);

        $filterFileMetaData = GeneralUtility::makeInstance(DropDownToggle::class)
            ->setActive($this->filterFileMetaData)
            ->setHref($this->getMenuItemUri([
                'filterFileMetaData' => !$this->filterFileMetaData,
            ]))->setLabel($this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/MissingAltText.xlf:menu.filter.fileMetaData'))
            ->setIcon(null);

        $button->addItem($filterFileMetaData);

        return $button;
    }

    /**
     * Get the URI for a menu item as a string.
     * 
     * @param array $changedParams The changed parameters.
     * 
     * @return string
     */
    protected function getMenuItemUri(array $changedParams): string
    {
        $params = array_replace([
            'id' => $this->pageId,
            'languageId' => $this->languageId,
            'pageLevels' => $this->pageLevels,
            'tableName' => $this->tableName,
            'currentPage' => 1,
            'filterFileMetaData' => $this->filterFileMetaData,
        ], $changedParams);

        return (string)$this->backendUriBuilder->buildUriFromRoute(
            'mindfula11y_alternativemissingalttext',
            $params
        );
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
            return $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/MissingAltText.xlf:menu.tables.all');
        }
        if (isset($GLOBALS['TCA'][$tableName]['ctrl']['title'])) {
            return $this->getLanguageService()->sL($GLOBALS['TCA'][$tableName]['ctrl']['title']);
        }
        return $tableName;
    }
}
