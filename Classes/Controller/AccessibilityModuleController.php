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

use InvalidArgumentException;
use MindfulMarkup\MindfulA11y\Domain\Model\CreateScanDemand;
use MindfulMarkup\MindfulA11y\Enum\Feature;
use MindfulMarkup\MindfulA11y\Service\AltTextFinderService;
use MindfulMarkup\MindfulA11y\Service\GeneralModuleService;
use MindfulMarkup\MindfulA11y\Service\ScanApiService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\PreviewUriBuilder;
use TYPO3\CMS\Backend\Template\Components\Buttons\DropDown\DropDownToggle;
use TYPO3\CMS\Backend\Template\Components\Buttons\DropDownButton;
use TYPO3\CMS\Backend\Template\Components\Menu\Menu;
use TYPO3\CMS\Core\Http\RedirectResponse;
use MindfulMarkup\MindfulA11y\Service\PermissionService;
use TYPO3\CMS\Backend\Module\ModuleData;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Http\AllowedMethodsTrait;
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
     * The localized page information for the current page and language.
     */
    protected ?array $localizedPageInfo = null;

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
        protected readonly ScanApiService $scanApiService,
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
                $this->generalModuleService->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:module.noPageSelected'),
                $this->generalModuleService->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:module.noPageSelected.description'),
                ContextualFeedbackSeverity::INFO
            );
            return $this->moduleTemplate->renderResponse('Backend/Info')->withStatus(200);
        }

        $this->pageInfo = BackendUtility::readPageAccess($this->pageId, $backendUser->getPagePermsClause(Permission::PAGE_SHOW));
        if (false === $this->pageInfo) {
            $this->addFlashMessage(
                $this->generalModuleService->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:error.noPageAccess'),
                $this->generalModuleService->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:error.noPageAccess.description'),
                ContextualFeedbackSeverity::ERROR
            );
            return $this->moduleTemplate->renderResponse('Backend/Info')->withStatus(403);
        }

        $this->moduleData = $this->request->getAttribute('moduleData', null);
        if (null === $this->moduleData) {
            throw new InvalidArgumentException('Module data is not set.', 1745686754);
        }

        $this->languageId = (int)$this->moduleData->get('languageId', 0);
        $this->localizedPageInfo = $this->generalModuleService->getLocalizedPageRecord($this->pageId, $this->languageId);

        $languageToCheck = $this->localizedPageInfo === null ? 0 : $this->languageId;
        if (!$this->permissionService->checkLanguageAccess($languageToCheck)) {
            // Try to find the first available language that the user has access to
            $availableLanguageId = $this->getFirstAvailableLanguageId($backendUser);
            if (null !== $availableLanguageId) {
                // Redirect to the first available language
                $uri = $this->backendUriBuilder->buildUriFromRoute(
                    'mindfula11y_accessibility',
                    [
                        'id' => $this->pageId,
                        'languageId' => $availableLanguageId,
                        'feature' => $this->moduleData->get('feature', Feature::GENERAL->value),
                    ]
                );

                return new RedirectResponse($uri, 302);
            }

            // No languages available, show error
            $this->addFlashMessage(
                $this->generalModuleService->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:module.noLanguageAccess'),
                $this->generalModuleService->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:module.noLanguageAccess.description'),
                ContextualFeedbackSeverity::ERROR
            );
            return $this->moduleTemplate->renderResponse('Backend/Info')->withStatus(403);
        }

        $this->feature = Feature::tryFrom($this->moduleData->get('feature', Feature::GENERAL->value)) ?? Feature::GENERAL;
        $this->pageTsConfig = $this->generalModuleService->getConvertedPageTsConfig($this->pageId);
        $this->moduleTemplate->getDocHeaderComponent()->getMenuRegistry()->addMenu($this->buildFeatureMenu());
        $this->moduleTemplate->getDocHeaderComponent()->getMenuRegistry()->addMenu($this->buildLanguageMenu());
        $this->pageRenderer->addInlineLanguageLabelArray($this->generalModuleService->getInlineLanguageLabels());

        $this->pageRenderer->addCssFile('EXT:mindfula11y/Resources/Public/Css/mindfula11y.css');

        switch ($this->feature) {
            case Feature::GENERAL:
                return $this->handleGeneralFeature();
            case Feature::MISSING_ALT_TEXT:
                return $this->handleMissingAltTextFeature();
            case Feature::SCAN:
                return $this->handleAccessibilityScannerFeature();
                break;
        }
        throw new InvalidArgumentException('Invalid feature: ' . ($this->feature->value ?? ''), 1748518675);
    }

    /**
     * Handle general module features.
     */
    protected function handleGeneralFeature(): ResponseInterface
    {
        // Get localized page info for preview URL generation
        $finalPageInfo = $this->localizedPageInfo ?: $this->pageInfo;

        // Let PreviewUriBuilder decide if a preview can be built. It returns null when a preview is not available.
        $previewUri = PreviewUriBuilder::create($finalPageInfo)
            ->buildUri();

        $missingAltTextUri = null;
        $fileReferenceCount = null;

        $hasMissingAltTextAccess = $this->generalModuleService->hasMissingAltTextAccess($this->pageTsConfig);
        $hasHeadingStructureAccess = $this->generalModuleService->hasHeadingStructureAccess($this->pageTsConfig);
        $hasLandmarkStructureAccess = $this->generalModuleService->hasLandmarkStructureAccess($this->pageTsConfig);
        $hasScanAccess = $this->generalModuleService->hasScanAccess($this->pageTsConfig);

        // Disable scan access if page is hidden/not visible
        $isPageVisible = $this->generalModuleService->isPageVisible($finalPageInfo);
        if ($hasScanAccess && !$isPageVisible) {
            $hasScanAccess = false;
        }

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

        // Prepare scan-related variables
        $scanUri = null;
        $scanId = null;
        $createScanDemand = null;

        if ($hasScanAccess && $this->scanApiService->isConfigured()) {
            // Get existing scan ID — stored per language on whichever record ($finalPageInfo) was scanned
            $existingScanId = $finalPageInfo['tx_mindfula11y_scanid'] ?? null;

            // Check if content has changed since last scan
            $contentChanged = $this->generalModuleService->shouldInvalidateScan($finalPageInfo, (int)($this->pageInfo['SYS_LASTCHANGED'] ?? 0));

            // Only use existing scan ID if content hasn't changed
            if ($existingScanId && !$contentChanged) {
                $scanId = $existingScanId;
            }

            // Create scan demand for the component
            if (null !== $previewUri) {
                $backendUser = $this->generalModuleService->getBackendUserAuthentication();
                $createScanDemand = new CreateScanDemand(
                    $backendUser->user['uid'],
                    $this->pageId,
                    (string) $previewUri,
                    $this->languageId,
                    $backendUser->workspace
                );
            }

            // Create URI to the scan feature
            $scanUri = $this->backendUriBuilder->buildUriFromRoute(
                'mindfula11y_accessibility',
                [
                    'id' => $this->pageId,
                    'feature' => Feature::SCAN->value,
                    'languageId' => $this->languageId,
                ]
            );
        }

        $this->moduleTemplate->assignMultiple([
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
            'autoCreateScan' => $this->generalModuleService->isAutoCreateScanEnabled($this->pageTsConfig),
        ]);

        $this->pageRenderer->loadJavaScriptModule('@mindfulmarkup/mindfula11y/structure.js');
        $this->pageRenderer->loadJavaScriptModule('@mindfulmarkup/mindfula11y/scan-issue-count.js');

        return $this->moduleTemplate->renderResponse('Backend/General');
    }

    /**
     * Handle the missing alt text feature.
     */
    protected function handleMissingAltTextFeature(): ResponseInterface
    {
        if (!$this->generalModuleService->hasMissingAltTextAccess($this->pageTsConfig)) {
            $this->addFlashMessage(
                $this->generalModuleService->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:altText.noAccess'),
                $this->generalModuleService->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:altText.noAccess.description'),
                ContextualFeedbackSeverity::ERROR
            );
            return $this->moduleTemplate->renderResponse('Backend/Info')->withStatus(403);
        }

        $currentPage = (int)$this->moduleData->get('currentPage', 1);
        $pageLevels = (int)$this->moduleData->get('pageLevels', 1);
        $tableName = (string)$this->moduleData->get('tableName', '');

        // Ensure tableName is valid. If the table doesn't exist in TCA (e.g. '0' or invalid param),
        // fallback to empty string (All Record Types)
        if ($tableName !== '' && !isset($GLOBALS['TCA'][$tableName])) {
            $tableName = '';
        }

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
        if (!empty($tableName) && !$this->permissionService->checkTableReadAccess($tableName)) {
            $this->addFlashMessage(
                $this->generalModuleService->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:altText.noTableAccess'),
                $this->generalModuleService->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:altText.noTableAccess.description'),
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
     * Handle the accessibility scanner feature.
     */
    protected function handleAccessibilityScannerFeature(): ResponseInterface
    {
        if (!$this->generalModuleService->hasScanAccess($this->pageTsConfig)) {
            $this->addFlashMessage(
                $this->generalModuleService->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.noAccess'),
                $this->generalModuleService->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.noAccess.description'),
                ContextualFeedbackSeverity::ERROR
            );
            return $this->moduleTemplate->renderResponse('Backend/Info')->withStatus(403);
        }

        if (!$this->scanApiService->isConfigured()) {
            $this->addFlashMessage(
                $this->generalModuleService->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.notConfigured'),
                $this->generalModuleService->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.notConfigured.description'),
                ContextualFeedbackSeverity::INFO
            );
            return $this->moduleTemplate->renderResponse('Backend/Info');
        }

        // Get localized page info for preview URL generation
        $finalPageInfo = $this->localizedPageInfo ?: $this->pageInfo;

        if (!$this->generalModuleService->isPageVisible($finalPageInfo)) {
            $this->addFlashMessage(
                $this->generalModuleService->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.error.pageVisible'),
                $this->generalModuleService->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.error.pageVisible.description'),
                ContextualFeedbackSeverity::INFO
            );
            return $this->moduleTemplate->renderResponse('Backend/Info');
        }

        // Let PreviewUriBuilder decide if a preview can be built. It returns null when a preview is not available.
        $previewUri = PreviewUriBuilder::create($finalPageInfo)
            ->buildUri();

        if (null === $previewUri) {
            $this->addFlashMessage(
                $this->generalModuleService->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.previewNotEnabled'),
                $this->generalModuleService->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.previewNotEnabled.description'),
                ContextualFeedbackSeverity::INFO
            );
            return $this->moduleTemplate->renderResponse('Backend/Info');
        }

        $pageLevels = (int)$this->moduleData->get('scanPageLevels', 0);
        // Guard against arbitrary values set via URL manipulation; only accept the menu's values
        if (!in_array($pageLevels, [0, 1, 5, 10, 99], true)) {
            $pageLevels = 0;
        }

        $this->moduleTemplate->getDocHeaderComponent()->getMenuRegistry()->addMenu($this->buildScanPageLevelsMenu($pageLevels));

        // Check if user has edit access to the page record (needed to trigger new scans)
        $canTriggerScan = $this->permissionService->checkRecordEditAccess('pages', $finalPageInfo, ['tx_mindfula11y_scanid', 'tx_mindfula11y_scanupdated']);

        // Check if content has changed since last scan
        $contentChanged = $this->generalModuleService->shouldInvalidateScan($finalPageInfo, (int)($this->pageInfo['SYS_LASTCHANGED'] ?? 0));

        // Only use existing scan ID if content hasn't changed — stored per language on $finalPageInfo
        $scanId = null;
        if ($finalPageInfo['tx_mindfula11y_scanid'] ?? false && !$contentChanged) {
            $scanId = $finalPageInfo['tx_mindfula11y_scanid'] ?? null;
        }

        // Filter by the current page URL only when scanning a single page (pageLevels = 0).
        // When pageLevels > 0 the scan covers multiple pages and all results should be shown.
        $pageUrlFilter = $pageLevels === 0 ? [(string)$previewUri] : [];

        // Create scan demand only if user can trigger scans
        $createScanDemand = null;
        $crawlScanDemand = null;
        if ($canTriggerScan) {
            $backendUser = $this->generalModuleService->getBackendUserAuthentication();
            $createScanDemand = new CreateScanDemand(
                $backendUser->user['uid'],
                $this->pageId,
                (string) $previewUri,
                $this->languageId,
                $backendUser->workspace,
                $pageLevels
            );
            // Crawl mode is only available for site root pages (check default-language record)
            if ((bool)($this->pageInfo['is_siteroot'] ?? false)) {
                $crawlScanDemand = new CreateScanDemand(
                    $backendUser->user['uid'],
                    $this->pageId,
                    (string) $previewUri,
                    $this->languageId,
                    $backendUser->workspace,
                    0,
                    true
                );
            }
        }

        // Build a signed base URL for the report download (backend route, browser-navigable).
        // JS appends scanId and format before use.
        $reportBaseUrl = (string)$this->backendUriBuilder->buildUriFromRoute('mindfula11y_scanreport');

        $this->moduleTemplate->assignMultiple([
            'scanId' => $scanId,
            'createScanDemand' => $createScanDemand,
            'crawlScanDemand' => $crawlScanDemand,
            'autoCreateScan' => $this->generalModuleService->isAutoCreateScanEnabled($this->pageTsConfig),
            'pageUrlFilter' => $pageUrlFilter,
            'reportBaseUrl' => $reportBaseUrl,
        ]);

        $this->pageRenderer->loadJavaScriptModule('@mindfulmarkup/mindfula11y/scan.js');

        return $this->moduleTemplate->renderResponse('Backend/Scan');
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

    protected function buildFeatureMenu(): Menu
    {
        $menu = $this->moduleTemplate->getDocHeaderComponent()->getMenuRegistry()->makeMenu()
            ->setIdentifier('MindfulA11yFeatures')
            ->setLabel($this->generalModuleService->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:module.menu.features'));
        foreach ([Feature::GENERAL, Feature::MISSING_ALT_TEXT, Feature::SCAN] as $feature) {
            $enabled = match ($feature) {
                Feature::GENERAL => true,
                Feature::MISSING_ALT_TEXT => $this->generalModuleService->hasMissingAltTextAccess($this->pageTsConfig),
                Feature::SCAN => $this->generalModuleService->hasScanAccess($this->pageTsConfig),
            };
            if ($enabled) {
                $menuItem = $menu->makeMenuItem()->setTitle(
                    $this->generalModuleService->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:module.menu.features.' . $feature->value)
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
                'LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:module.menu.tables'
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
                'LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:module.menu.pageLevels'
            );

        foreach ([1, 5, 10, 99] as $pageLevels) {
            $menuItem = $menu->makeMenuItem()->setTitle(
                $this->generalModuleService->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:module.menu.pageLevels.' . $pageLevels)
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
     * Build page level menu for the scan feature.
     *
     * @param int $currentPageLevels The current page levels.
     */
    protected function buildScanPageLevelsMenu(int $currentPageLevels): Menu
    {
        $menu = $this->moduleTemplate->getDocHeaderComponent()->getMenuRegistry()->makeMenu();
        $menu->setIdentifier('MindfulA11yScanPageLevels')
            ->setLabel(
                'LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:module.menu.pageLevels'
            );

        foreach ([0, 1, 5, 10, 99] as $pageLevels) {
            $menuItem = $menu->makeMenuItem()->setTitle(
                $this->generalModuleService->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:module.menu.pageLevels.' . $pageLevels)
            )->setHref(
                $this->getMenuItemUri(
                    [
                        'scanPageLevels' => $pageLevels,
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
            ->setLabel($this->generalModuleService->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:module.menu.filter'))
            ->setShowLabelText(true);

        /** @var DropDownToggle $filterFileMetaDataToggle */
        $filterFileMetaDataToggle = GeneralUtility::makeInstance(DropDownToggle::class)
            ->setActive($filterFileMetaData)
            ->setHref($this->getMenuItemUri([
                'tableName' => $currentTableName,
                'pageLevels' => $currentPageLevels,
                'filterFileMetaData' => !$filterFileMetaData,
            ]))->setLabel($this->generalModuleService->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:module.menu.filter.fileMetaData'))
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

        $availableLanguageIds = $this->getAvailableLanguageIds();

        $menu = $this->moduleTemplate->getDocHeaderComponent()->getMenuRegistry()->makeMenu();
        $menu->setIdentifier('MindfulA11yAccessibilityLanguage')
            ->setLabel(
                'LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:module.menu.language'
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
            return $this->generalModuleService->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:module.menu.tables.all');
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

    /**
     * Get available language IDs for the current page (including default language).
     * 
     * @return array<int>
     */
    protected function getAvailableLanguageIds(): array
    {
        $pageTranslations = BackendUtility::getExistingPageTranslations($this->pageId);
        $availableLanguageIds = [0]; // Default language is always available
        foreach ($pageTranslations as $pageTranslation) {
            $languageId = $pageTranslation[$GLOBALS['TCA']['pages']['ctrl']['languageField']] ?? null;
            if (null !== $languageId) {
                $availableLanguageIds[] = (int)$languageId;
            }
        }
        return array_unique($availableLanguageIds);
    }

    /**
     * Get the first available language ID that the user has access to and the page is translated to.
     *      * 
     * @return int|null The language ID or null if no language is available.
     */
    protected function getFirstAvailableLanguageId(): ?int
    {
        $allowedLanguages = $this->getAllowedSiteLanguages();

        if (empty($allowedLanguages)) {
            return null;
        }

        $availableLanguageIds = $this->getAvailableLanguageIds();

        foreach ($allowedLanguages as $language) {
            $languageId = $language->getLanguageId();
            // User access is already checked by getAllowedSiteLanguages(), just check if page is translated
            if (in_array($languageId, $availableLanguageIds, true)) {
                return $languageId;
            }
        }

        return null;
    }
}
