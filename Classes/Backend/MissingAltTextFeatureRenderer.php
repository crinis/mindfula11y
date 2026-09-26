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
use MindfulMarkup\MindfulA11y\Service\ModuleLabelService;
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
 * metadata/decorative filter doc-header menus.
 */
final readonly class MissingAltTextFeatureRenderer
{
    use ModuleNoticeTrait;

    private const ITEMS_PER_PAGE = 100;

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
        $pageLevels = $this->menuBuilder->sanitizePageLevels($context->moduleData->get('pageLevels', 0));
        $tableName = (string)$context->moduleData->get('tableName', '');

        // Ensure tableName is valid. If the table doesn't exist in TCA (e.g. '0' or invalid param),
        // fallback to empty string (All Record Types)
        if ($tableName !== '' && !isset($GLOBALS['TCA'][$tableName])) {
            $tableName = '';
        }

        /**
         * Protect table records from being shown if the user does not have
         * read access to the table. Subsequent methods won't do this.
         * We intentionally ignore "hideTable" as inline records should
         * be shown even if the table is hidden. Checked before any doc-header
         * menu is built, so the denial notice renders without the controls
         * of a view that is not shown.
         */
        if ($tableName !== '' && !$this->permissionService->checkTableReadAccess($tableName)) {
            return $this->noticeResponse($context->moduleTemplate, 'altText.noTableAccess', ContextualFeedbackSeverity::ERROR, 403);
        }

        // Where metadata fallback alt text may count at all, the editor toggle
        // decides per module view.
        $canConsiderFileMetaData = $this->moduleSettingsService->canConsiderFileMetadataAlternative($context->pageTsConfig);
        $filterFileMetaData = $canConsiderFileMetaData && (bool)$context->moduleData->get('filterFileMetaData', true);
        $showDecorative = (bool)$context->moduleData->get('showDecorative', false);
        $showAllReferences = (bool)$context->moduleData->get('showAllReferences', false);

        // Every menu link carries the *whole* view state and overrides only the
        // one value it changes — otherwise following it would silently reset the
        // editor's other filters. Assembled once so a new filter cannot be
        // forgotten at one of the links.
        $menuState = [
            'tableName' => $tableName,
            'pageLevels' => $pageLevels,
            'filterFileMetaData' => $filterFileMetaData,
            'showDecorative' => $showDecorative,
            'showAllReferences' => $showAllReferences,
        ];

        $this->menuBuilder->addDropDown(
            $context->moduleTemplate,
            $this->menuBuilder->buildPageLevelsDropDown($context, 'pageLevels', $pageLevels, $menuState),
            3
        );
        $this->menuBuilder->addDropDown($context->moduleTemplate, $this->buildTableMenu($context, $menuState), 4);

        $context->moduleTemplate->getDocHeaderComponent()->getButtonBar()->addButton(
            $this->buildFilterDropDown($context, $menuState, $canConsiderFileMetaData),
            ButtonBar::BUTTON_POSITION_RIGHT
        );

        // An empty table selection means "all record types".
        $tableFilter = $tableName !== '' ? $tableName : null;
        // One pass counts every match and fetches only the requested page.
        $result = $this->altTextFinderService->findAltlessFileReferencePage(
            $context->pageId,
            $pageLevels,
            $context->languageId,
            $context->pageTsConfig,
            $currentPage,
            self::ITEMS_PER_PAGE,
            $filterFileMetaData,
            $tableFilter,
            $showDecorative,
            $showAllReferences
        );
        $fileReferences = $result['items'];
        $fileReferenceCount = $result['total'];

        // The service clamped the page to the last one the total allows (an
        // out-of-range page would render an empty slice); mirror that clamp so
        // the pagination and its links show the page actually rendered.
        $currentPage = min($currentPage, max(1, (int)ceil($fileReferenceCount / self::ITEMS_PER_PAGE)));

        // The service already fetched exactly the current page;
        // paginate over that slice plus the count instead of null-padding an
        // array with one slot per matching record in the whole page tree.
        $paginator = new SlicePaginator($fileReferences, $fileReferenceCount, $currentPage, self::ITEMS_PER_PAGE);
        $pagination = new SimplePagination($paginator);

        $context->moduleTemplate->assignMultiple([
            // The pagination links carry the resolved view state like the
            // menu links do, never the raw (unclamped) module data.
            'moduleData' => array_merge($context->moduleData->toArray(), [
                'id' => $context->pageId,
                'languageId' => $context->languageId,
                'feature' => $context->feature->value,
                'currentPage' => $currentPage,
                ...$menuState,
            ]),
            'pagination' => $pagination,
            'paginator' => $paginator
        ]);

        $this->pageRenderer->loadJavaScriptModule('@mindfulmarkup/mindfula11y/element/altless-file-reference/altless-file-reference.js');
        $this->pageRenderer->loadJavaScriptModule('@mindfulmarkup/mindfula11y/element/notice/notice.js');
        // Pre-upgrade guard for the server-rendered custom elements above.
        $this->pageRenderer->addCssFile('EXT:mindfula11y/Resources/Public/Css/backend.css');

        return $context->moduleTemplate->renderResponse('Backend/MissingAltText');
    }

    /**
     * @param array{tableName: string, pageLevels: int, filterFileMetaData: bool, showDecorative: bool, showAllReferences: bool} $menuState
     */
    private function buildTableMenu(ModuleContext $context, array $menuState): ?DropDownButton
    {
        $tables = $this->altTextFinderService->getTablesWithFiles($context->pageTsConfig);
        // Add an empty string as the first menu item (for "all tables" option)
        array_unshift($tables, '');
        $items = [];
        foreach ($tables as $tableName) {
            $items[] = [
                'title' => $this->getTableTitle($tableName),
                'href' => $this->menuBuilder->buildMenuItemUri($context, [
                    ...$menuState,
                    'tableName' => $tableName,
                ]),
                'active' => $tableName === $menuState['tableName'],
            ];
        }

        return $this->menuBuilder->buildDropDown(
            $this->getLanguageService()->sL(ModuleLabelService::LANGUAGE_FILE . 'module.menu.tables'),
            $items
        );
    }

    /**
     * The filter menu: one toggle per boolean filter, each linking to the same
     * view with only its own value flipped.
     *
     * @param array{tableName: string, pageLevels: int, filterFileMetaData: bool, showDecorative: bool, showAllReferences: bool} $menuState
     */
    private function buildFilterDropDown(
        ModuleContext $context,
        array $menuState,
        bool $canConsiderFileMetaData,
    ): DropDownButton
    {
        $languageService = $this->getLanguageService();
        $button = $this->menuBuilder->createDropDownButton()
            ->setLabel($languageService->sL(ModuleLabelService::LANGUAGE_FILE . 'module.menu.filter'))
            ->setShowLabelText(true);

        $toggles = [
            'filterFileMetaData' => 'fileMetaData',
            'showDecorative' => 'decorative',
            'showAllReferences' => 'allReferences',
        ];
        // Metadata alt text can only be filtered on where it may count as
        // present at all; the other two are always available.
        if (!$canConsiderFileMetaData) {
            unset($toggles['filterFileMetaData']);
        }

        foreach ($toggles as $filter => $labelSuffix) {
            $isActive = (bool)$menuState[$filter];
            /** @var DropDownToggle $toggle */
            $toggle = GeneralUtility::makeInstance(DropDownToggle::class)
                ->setActive($isActive)
                ->setHref($this->menuBuilder->buildMenuItemUri($context, [
                    ...$menuState,
                    $filter => !$isActive,
                ]))
                ->setLabel($languageService->sL(ModuleLabelService::LANGUAGE_FILE . 'module.menu.filter.' . $labelSuffix))
                ->setIcon(null);

            $button->addItem($toggle);
        }

        return $button;
    }

    /**
     * Get title of a table from TCA.
     */
    private function getTableTitle(string $tableName): string
    {
        if (empty($tableName)) {
            return $this->getLanguageService()->sL(ModuleLabelService::LANGUAGE_FILE . 'module.menu.tables.all');
        }
        if (isset($GLOBALS['TCA'][$tableName]['ctrl']['title'])) {
            return $this->getLanguageService()->sL($GLOBALS['TCA'][$tableName]['ctrl']['title']);
        }
        return $tableName;
    }
}
