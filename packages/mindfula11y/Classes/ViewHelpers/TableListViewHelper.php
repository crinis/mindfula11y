<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace MindfulMarkup\MindfulA11y\ViewHelpers;

use MindfulMarkup\MindfulA11y\RecordList\AltLessRecordList;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Module\ModuleData;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Fluid\ViewHelpers\Be\AbstractBackendViewHelper;

/**
 * ViewHelper which renders a record list as known from the TYPO3 list module.
 *
 * .. note::
 *    This feature is experimental!
 *
 * Examples
 * ========
 *
 * Minimal::
 *
 *    <f:be.tableList tableName="fe_users" />
 *
 * List of all "Website user" records stored in the configured storage PID.
 * Records will be editable, if the current backend user has got edit rights for the table ``fe_users``.
 *
 * Only the title column (username) will be shown.
 *
 * Context menu is active.
 *
 * Full::
 *
 *    <f:be.tableList tableName="fe_users" fieldList="{0: 'name', 1: 'email'}"
 *        storagePid="1"
 *        levels="2"
 *        filter="foo"
 *        itemsPerPage="10"
 *        sortField="name"
 *        sortDescending="true"
 *        readOnly="true"
 *        enableClickMenu="false"
 *        enableControlPanels="true"
 *        clickTitleMode="info"
 *        />
 *
 * List of "Website user" records with a text property of ``foo`` stored on PID ``1`` and two levels down.
 * Clicking on a username will open the TYPO3 info popup for the respective record
 */
class TableListViewHelper extends AbstractBackendViewHelper
{
    /**
     * As this ViewHelper renders HTML, the output must not be escaped.
     *
     * @var bool
     */
    protected $escapeOutput = false;

    protected readonly ConfigurationManagerInterface $configurationManager;

    public function injectConfigurationManager(ConfigurationManagerInterface $configurationManager): void
    {
        $this->configurationManager = $configurationManager;
    }

    public function initializeArguments(): void
    {
        parent::initializeArguments();
        $this->registerArgument('tableName', 'string', 'name of the database table', true);
        $this->registerArgument('fieldList', 'array', 'list of fields to be displayed. If empty, only the title column (configured in $TCA[$tableName][\'ctrl\'][\'title\']) is shown', false, []);
        $this->registerArgument('storagePid', 'int', 'by default, records are fetched from the storage PID configured in persistence.storagePid. With this argument, the storage PID can be overwritten');
        $this->registerArgument('levels', 'int', 'corresponds to the level selector of the TYPO3 list module. By default only records from the current storagePid are fetched', false, 0);
        $this->registerArgument('filter', 'string', 'corresponds to the "Search String" textbox of the TYPO3 list module. If not empty, only records matching the string will be fetched', false, '');
        $this->registerArgument('itemsPerPage', 'int', 'amount of records to be displayed at once. Defaults to $TCA[$tableName][\'interface\'][\'maxSingleDBListItems\'] or (if that\'s not set) to 100', false, 0);
        $this->registerArgument('sortField', 'string', 'table field to sort the results by', false, '');
        $this->registerArgument('sortDescending', 'bool', 'if TRUE records will be sorted in descending order', false, false);
        $this->registerArgument('readOnly', 'bool', 'if TRUE, the edit icons won\'t be shown. Otherwise edit icons will be shown, if the current BE user has edit rights for the specified table!', false, false);
        $this->registerArgument('enableClickMenu', 'bool', 'enables context menu', false, true);
        $this->registerArgument('enableControlPanels', 'bool', 'enables control panels', false, false);
        $this->registerArgument('clickTitleMode', 'string', 'one of "edit", "show" (only pages, tt_content), "info', false, '');
        /**
         * Addded for MindfulA11y.
         */
        $this->registerArgument('languageId', 'int', 'language uid to be used for the record list', false, 0);
        $this->registerArgument('fileReferenceFields', 'array', 'List of file reference fields to be used for the record list', false, []);
        $this->registerArgument('ignoreFileMetadata', 'bool', 'if TRUE, file metadata will be ignored', false, false);
    }

    /**
     * Renders a record list as known from the TYPO3 list module
     * Note: This feature is experimental!
     *
     * @see DatabaseRecordList
     */
    public function render(): string
    {
        $tableName = $this->arguments['tableName'];
        $fieldList = $this->arguments['fieldList'];
        $storagePid = $this->arguments['storagePid'];
        $levels = $this->arguments['levels'];
        $filter = $this->arguments['filter'];
        $itemsPerPage = $this->arguments['itemsPerPage'];
        $sortField = $this->arguments['sortField'];
        $sortDescending = $this->arguments['sortDescending'];
        $readOnly = $this->arguments['readOnly'];
        $enableClickMenu = $this->arguments['enableClickMenu'];
        $clickTitleMode = $this->arguments['clickTitleMode'];
        $enableControlPanels = $this->arguments['enableControlPanels'];

        $backendUser = $this->getBackendUser();
        if (!$this->renderingContext->hasAttribute(ServerRequestInterface::class)) {
            // All views in backend have at least ServerRequestInterface, no matter if created by
            // old StandaloneView via BackendViewFactory. Should be fine to assume having a request
            // here, the early return is just sanitation.
            return '';
        }
        $request = $this->renderingContext->getAttribute(ServerRequestInterface::class);

        $this->getPageRenderer()->loadJavaScriptModule('@typo3/backend/recordlist.js');
        $this->getPageRenderer()->loadJavaScriptModule('@typo3/backend/record-download-button.js');
        $this->getPageRenderer()->loadJavaScriptModule('@typo3/backend/action-dispatcher.js');
        if ($enableControlPanels === true) {
            $this->getPageRenderer()->loadJavaScriptModule('@typo3/backend/multi-record-selection.js');
            $this->getPageRenderer()->loadJavaScriptModule('@typo3/backend/multi-record-selection-delete-action.js');
            $this->getPageRenderer()->loadJavaScriptModule('@typo3/backend/context-menu.js');
        }

        $pageId = (int)($request->getParsedBody()['id'] ?? $request->getQueryParams()['id'] ?? 0);
        $pointer = (int)($request->getParsedBody()['pointer'] ?? $request->getQueryParams()['pointer'] ?? 0);
        $pageInfo = BackendUtility::readPageAccess($pageId, $backendUser->getPagePermsClause(Permission::PAGE_SHOW)) ?: [];

        if (!isset($pageInfo['uid'])) {
            return '';
        }

        $existingModuleData = $backendUser->getModuleData('web_list');
        $moduleData = new ModuleData('web_list', is_array($existingModuleData) ? $existingModuleData : []);

        // Changed next line for MindfulA11y.
        $dbList = $this->createDatabaseRecordList($request, $this->arguments);

        $dbList->setRequest($request);
        $dbList->setModuleData($moduleData);
        $dbList->pageRow = $pageInfo;

        if ($readOnly) {
            $dbList->setIsEditable(false);
        } else {
            $dbList->calcPerms = new Permission($backendUser->calcPerms($pageInfo));
        }
        $dbList->disableSingleTableView = true;
        $dbList->clickTitleMode = $clickTitleMode;
        $dbList->clickMenuEnabled = $enableClickMenu;
        if ($storagePid === null) {
            $frameworkConfiguration = $this->configurationManager->getConfiguration(ConfigurationManagerInterface::CONFIGURATION_TYPE_FRAMEWORK);
            $storagePid = $frameworkConfiguration['persistence']['storagePid'];
        }
        $dbList->start($storagePid, $tableName, $pointer, $filter, $levels, $itemsPerPage);
        // Column selector is disabled since fields are defined by the "fieldList" argument
        $dbList->displayColumnSelector = false;
        $dbList->setFields = [$tableName => $fieldList];
        $dbList->noControlPanels = !$enableControlPanels;
        $dbList->sortField = $sortField;
        $dbList->sortRev = $sortDescending;

        return $dbList->generateList();
    }

    /**
     * Create a new instance of the AltLessRecordList class.
     * 
     * Creates a new instance of the AltLessRecordList class and initializes it with the
     * provided arguments. This method is mainly used to separate our custom logic from the
     * original TableListViewHelper to ease the upgrade process.
     * 
     * @param ServerRequestInterface $request The current request object.
     * @param array $arguments The arguments passed to the view helper.
     * 
     * @return AltLessRecordList The created database record list instance.
     */
    protected function createDatabaseRecordList(ServerRequestInterface $request, array &$arguments): AltLessRecordList
    {
        $dbList = GeneralUtility::makeInstance(AltLessRecordList::class);

        /**
         * Do not show translations as these are selected in the module menu.
         */
        $dbList->hideTranslations = '*';
        $dbList->setFileReferenceFields($arguments['fileReferenceFields']);
        $siteLanguages = $request->getAttribute('site')->getAvailableLanguages($this->getBackendUser(), false, $arguments['storagePid']);
        $dbList->setLanguageId($arguments['languageId']);
        $dbList->setLanguagesAllowedForUser($siteLanguages);
        $dbList->setIgnoreFileMetadata($arguments['ignoreFileMetadata']);
        $arguments['fieldList'] = array_merge($this->arguments['fieldList'], $dbList->getFileReferenceFields());

        return $dbList;
    }

    /**
     * Get the backend user authentication instance.
     * 
     * @return BackendUserAuthentication
     */
    protected function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }

    /**
     * Get the language service instance.
     * 
     * @return LanguageService
     */
    protected function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
