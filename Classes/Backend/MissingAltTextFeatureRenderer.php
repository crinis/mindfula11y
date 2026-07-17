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

use MindfulMarkup\MindfulA11y\Service\AltTextFinderService;
use MindfulMarkup\MindfulA11y\Service\ModuleSettingsService;
use MindfulMarkup\MindfulA11y\Service\PermissionService;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\Buttons\DropDown\DropDownToggle;
use TYPO3\CMS\Backend\Template\Components\Buttons\DropDownButton;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use MindfulMarkup\MindfulA11y\Pagination\SlicePaginator;
use TYPO3\CMS\Core\Pagination\SimplePagination;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Renders the missing-alternative-text feature: the paginated list of file
 * references without alternative text, with its table, page-levels, and
 * metadata-filter doc-header menus.
 */
final readonly class MissingAltTextFeatureRenderer implements FeatureRendererInterface
{
    use ModuleNoticeTrait;

    private const ITEMS_PER_PAGE = 100;

    /** Page-levels choices offered by the menu; other values are rejected. */
    private const PAGE_LEVELS_OPTIONS = [1, 5, 10, 99];

    public function __construct(
        private ModuleSettingsService $moduleSettingsService,
        private AltTextFinderService $altTextFinderService,
        private PermissionService $permissionService,
        private PageRenderer $pageRenderer,
        private FlashMessageService $flashMessageService,
        private DocHeaderMenuBuilder $menuBuilder,
    ) {}

    public function render(ModuleContext $context): ResponseInterface
    {
        if (!$this->moduleSettingsService->hasMissingAltTextAccess($context->pageTsConfig)) {
            return $this->noticeResponse($context->moduleTemplate, 'altText.noAccess', ContextualFeedbackSeverity::ERROR, 403);
        }

        // Module data is GET-writable: clamp the page (it feeds the query
        // OFFSET) and only accept the page-levels values the menu offers.
        $currentPage = max(1, (int)$context->moduleData->get('currentPage', 1));
        $pageLevels = (int)$context->moduleData->get('pageLevels', 1);
        if (!in_array($pageLevels, self::PAGE_LEVELS_OPTIONS, true)) {
            $pageLevels = 1;
        }
        $tableName = (string)$context->moduleData->get('tableName', '');

        // Ensure tableName is valid. If the table doesn't exist in TCA (e.g. '0' or invalid param),
        // fallback to empty string (All Record Types)
        if ($tableName !== '' && !isset($GLOBALS['TCA'][$tableName])) {
            $tableName = '';
        }

        // Metadata fallback alt text is only considered when the TSconfig option allows
        // it AND the user may read it; the editor toggle then decides per module view.
        $canConsiderFileMetaData = !$this->moduleSettingsService->isFileMetadataIgnored($context->pageTsConfig)
            && $this->moduleSettingsService->canReadFileMetadataAlternative();
        $filterFileMetaData = $canConsiderFileMetaData && (bool)$context->moduleData->get('filterFileMetaData', true);

        $this->menuBuilder->addDropDown($context->moduleTemplate, $this->buildPageLevelsMenu($context, $tableName, $pageLevels, $filterFileMetaData), 3);
        $this->menuBuilder->addDropDown($context->moduleTemplate, $this->buildTableMenu($context, $tableName, $pageLevels, $filterFileMetaData), 4);

        if ($canConsiderFileMetaData) {
            $context->moduleTemplate->getDocHeaderComponent()->getButtonBar()->addButton(
                $this->buildFilterDropDown($context, $tableName, $pageLevels, $filterFileMetaData),
                ButtonBar::BUTTON_POSITION_RIGHT
            );
        }

        /**
         * Protect table records from being shown if the user does not have
         * read access to the table. Subsequent methods won't do this.
         * We intentionally ignore "hideTable" as inline records should
         * be shown even if the table is hidden.
         */
        if (!empty($tableName) && !$this->permissionService->checkTableReadAccess($tableName)) {
            return $this->noticeResponse($context->moduleTemplate, 'altText.noTableAccess', ContextualFeedbackSeverity::ERROR, 403);
        }

        $offset = ($currentPage - 1) * self::ITEMS_PER_PAGE;

        // An empty table selection means "all record types".
        $tableFilter = $tableName !== '' ? $tableName : null;
        $fileReferenceCount = $this->altTextFinderService->countAltlessFileReferences(
            $context->pageId,
            $pageLevels,
            $context->languageId,
            $context->pageTsConfig,
            $filterFileMetaData,
            $tableFilter
        );
        $fileReferences = $this->altTextFinderService->getAltlessFileReferences(
            $context->pageId,
            $pageLevels,
            $context->languageId,
            $context->pageTsConfig,
            $offset,
            self::ITEMS_PER_PAGE,
            $filterFileMetaData,
            $tableFilter
        );

        // The service already fetched exactly the current page (LIMIT/OFFSET);
        // paginate over that slice plus the count instead of null-padding an
        // array with one slot per matching record in the whole page tree.
        $paginator = new SlicePaginator($fileReferences, $fileReferenceCount, $currentPage, self::ITEMS_PER_PAGE);
        $pagination = new SimplePagination($paginator);

        $context->moduleTemplate->assignMultiple([
            'moduleData' => array_merge($context->moduleData->toArray(), [
                'id' => $context->pageId,
            ]),
            'pagination' => $pagination,
            'paginator' => $paginator
        ]);

        $this->pageRenderer->loadJavaScriptModule('@mindfulmarkup/mindfula11y/element/altless-file-reference/altless-file-reference.js');
        $this->pageRenderer->loadJavaScriptModule('@mindfulmarkup/mindfula11y/element/notice/notice.js');

        return $context->moduleTemplate->renderResponse('Backend/MissingAltText');
    }

    private function buildTableMenu(ModuleContext $context, string $currentTableName, int $currentPageLevels, bool $filterFileMetaData): ?DropDownButton
    {
        $tables = $this->altTextFinderService->getTablesWithFiles($context->pageTsConfig);
        // Add an empty string as the first menu item (for "all tables" option)
        array_unshift($tables, '');
        $items = [];
        foreach ($tables as $tableName) {
            $items[] = [
                'title' => $this->getTableTitle($tableName),
                'href' => $this->menuBuilder->buildMenuItemUri($context, [
                    'tableName' => $tableName,
                    'pageLevels' => $currentPageLevels,
                    'filterFileMetaData' => $filterFileMetaData,
                ]),
                'active' => $tableName === $currentTableName,
            ];
        }

        return $this->menuBuilder->buildDropDown(
            $this->getLanguageService()->sL(self::MODULE_LANGUAGE_FILE . 'module.menu.tables'),
            $items
        );
    }

    private function buildPageLevelsMenu(ModuleContext $context, string $currentTableName, int $currentPageLevels, bool $filterFileMetaData): ?DropDownButton
    {
        $languageService = $this->getLanguageService();
        $items = [];
        foreach (self::PAGE_LEVELS_OPTIONS as $pageLevels) {
            $items[] = [
                'title' => $languageService->sL(self::MODULE_LANGUAGE_FILE . 'module.menu.pageLevels.' . $pageLevels),
                'href' => $this->menuBuilder->buildMenuItemUri($context, [
                    'tableName' => $currentTableName,
                    'pageLevels' => $pageLevels,
                    'filterFileMetaData' => $filterFileMetaData,
                ]),
                'active' => $pageLevels === $currentPageLevels,
            ];
        }

        return $this->menuBuilder->buildDropDown(
            $languageService->sL(self::MODULE_LANGUAGE_FILE . 'module.menu.pageLevels'),
            $items
        );
    }

    private function buildFilterDropDown(ModuleContext $context, string $currentTableName, int $currentPageLevels, bool $filterFileMetaData): DropDownButton
    {
        $languageService = $this->getLanguageService();
        $button = $this->menuBuilder->createDropDownButton()
            ->setLabel($languageService->sL(self::MODULE_LANGUAGE_FILE . 'module.menu.filter'))
            ->setShowLabelText(true);

        /** @var DropDownToggle $filterFileMetaDataToggle */
        $filterFileMetaDataToggle = GeneralUtility::makeInstance(DropDownToggle::class)
            ->setActive($filterFileMetaData)
            ->setHref($this->menuBuilder->buildMenuItemUri($context, [
                'tableName' => $currentTableName,
                'pageLevels' => $currentPageLevels,
                'filterFileMetaData' => !$filterFileMetaData,
            ]))
            ->setLabel($languageService->sL(self::MODULE_LANGUAGE_FILE . 'module.menu.filter.fileMetaData'))
            ->setIcon(null);

        $button->addItem($filterFileMetaDataToggle);

        return $button;
    }

    /**
     * Get title of a table from TCA.
     */
    private function getTableTitle(string $tableName): string
    {
        if (empty($tableName)) {
            return $this->getLanguageService()->sL(self::MODULE_LANGUAGE_FILE . 'module.menu.tables.all');
        }
        if (isset($GLOBALS['TCA'][$tableName]['ctrl']['title'])) {
            return $this->getLanguageService()->sL($GLOBALS['TCA'][$tableName]['ctrl']['title']);
        }
        return $tableName;
    }
}
