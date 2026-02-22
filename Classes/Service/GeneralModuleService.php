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

namespace MindfulMarkup\MindfulA11y\Service;

use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\TypoScript\TypoScriptService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use MindfulMarkup\MindfulA11y\Service\PermissionService;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Utility\RootlineUtility;
use TYPO3\CMS\Backend\Routing\PreviewUriBuilder;

/**
 * Service for general accessibility module functionality
 */
class GeneralModuleService
{
    public function __construct(
        protected readonly PermissionService $permissionService,
        protected readonly TypoScriptService $typoScriptService,
        protected readonly ConnectionPool $connectionPool
    ) {}

    public function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }

    /**
     * Returns all inline language labels used in the accessibility module.
     *
     * @return array
     */
    public function getInlineLanguageLabels(): array
    {
        $labels = [
            // Structure / headings / landmarks
            'mindfula11y.structureErrors' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:structureErrors'),
            'mindfula11y.structure' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:structure'),
            'mindfula11y.structure.headings' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:structure.headings'),
            'mindfula11y.structure.headings.error.missingH1' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:structure.headings.error.missingH1'),
            'mindfula11y.structure.headings.error.missingH1.description' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:structure.headings.error.missingH1.description'),
            'mindfula11y.structure.headings.error.multipleH1' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:structure.headings.error.multipleH1'),
            'mindfula11y.structure.headings.error.multipleH1.description' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:structure.headings.error.multipleH1.description'),
            'mindfula11y.structure.headings.error.emptyHeadings' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:structure.headings.error.emptyHeadings'),
            'mindfula11y.structure.headings.error.emptyHeadings.description' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:structure.headings.error.emptyHeadings.description'),
            'mindfula11y.structure.headings.error.skippedLevel' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:structure.headings.error.skippedLevel'),
            'mindfula11y.structure.headings.error.skippedLevel.description' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:structure.headings.error.skippedLevel.description'),
            'mindfula11y.structure.headings.error.skippedLevel.inline' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:structure.headings.error.skippedLevel.inline'),
            'mindfula11y.general.error.loading' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:general.error.loading'),
            'mindfula11y.general.error.loading.description' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:general.error.loading.description'),
            'mindfula11y.structure.headings.noHeadings' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:structure.headings.noHeadings'),
            'mindfula11y.structure.headings.noHeadings.description' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:structure.headings.noHeadings.description'),
            'mindfula11y.structure.headings.unlabeled' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:structure.headings.unlabeled'),
            'mindfula11y.structure.headings.edit' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:structure.headings.edit'),
            'mindfula11y.structure.headings.edit.locked' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:structure.headings.edit.locked'),
            'mindfula11y.structure.headings.type' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:structure.headings.type'),
            'mindfula11y.structure.headings.relation.descendant' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:structure.headings.relation.descendant'),
            'mindfula11y.structure.headings.relation.sibling' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:structure.headings.relation.sibling'),
            'mindfula11y.structure.headings.relation.notFound' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:structure.headings.relation.notFound'),
            'mindfula11y.structure.headings.relation.notFound.description' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:structure.headings.relation.notFound.description'),
            'mindfula11y.structure.headings.error.store' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:structure.headings.error.store'),
            'mindfula11y.structure.headings.error.store.description' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:structure.headings.error.store.description'),

            // Landmarks
            'mindfula11y.structure.landmarks' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:structure.landmarks'),
            'mindfula11y.structure.landmarks.nested' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:structure.landmarks.nested'),
            'mindfula11y.structure.landmarks.noLandmarks' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:structure.landmarks.noLandmarks'),
            'mindfula11y.structure.landmarks.noLandmarks.description' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:structure.landmarks.noLandmarks.description'),
            'mindfula11y.structure.landmarks.error.missingMain' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:structure.landmarks.error.missingMain'),
            'mindfula11y.structure.landmarks.error.missingMain.description' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:structure.landmarks.error.missingMain.description'),
            'mindfula11y.structure.landmarks.error.duplicateMain' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:structure.landmarks.error.duplicateMain'),
            'mindfula11y.structure.landmarks.error.duplicateMain.description' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:structure.landmarks.error.duplicateMain.description'),
            'mindfula11y.structure.landmarks.error.duplicateSameLabel' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:structure.landmarks.error.duplicateSameLabel'),
            'mindfula11y.structure.landmarks.error.duplicateSameLabel.description' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:structure.landmarks.error.duplicateSameLabel.description'),
            'mindfula11y.structure.landmarks.error.multipleUnlabeledLandmarks' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:structure.landmarks.error.multipleUnlabeledLandmarks'),
            'mindfula11y.structure.landmarks.error.multipleUnlabeledLandmarks.description' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:structure.landmarks.error.multipleUnlabeledLandmarks.description'),
            'mindfula11y.structure.landmarks.unlabelledLandmark' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:structure.landmarks.unlabelledLandmark'),
            'mindfula11y.structure.landmarks.edit' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:structure.landmarks.edit'),
            'mindfula11y.structure.landmarks.edit.locked' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:structure.landmarks.edit.locked'),
            'mindfula11y.structure.landmarks.role' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:structure.landmarks.role'),
            'mindfula11y.structure.landmarks.role.none' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:structure.landmarks.role.none'),
            'mindfula11y.structure.landmarks.role.banner' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:structure.landmarks.role.banner'),
            'mindfula11y.structure.landmarks.role.main' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:structure.landmarks.role.main'),
            'mindfula11y.structure.landmarks.role.navigation' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:structure.landmarks.role.navigation'),
            'mindfula11y.structure.landmarks.role.complementary' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:structure.landmarks.role.complementary'),
            'mindfula11y.structure.landmarks.role.contentinfo' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:structure.landmarks.role.contentinfo'),
            'mindfula11y.structure.landmarks.role.region' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:structure.landmarks.role.region'),
            'mindfula11y.structure.landmarks.role.search' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:structure.landmarks.role.search'),
            'mindfula11y.structure.landmarks.role.form' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:structure.landmarks.role.form'),
            'mindfula11y.structure.landmarks.error.roleSelect' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:structure.landmarks.error.roleSelect'),
            'mindfula11y.structure.landmarks.error.store' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:structure.landmarks.error.store'),
            'mindfula11y.structure.landmarks.error.store.description' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:structure.landmarks.error.store.description'),

            // Missing alt text
            'mindfula11y.altText.generate.loading' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:altText.generate.loading'),
            'mindfula11y.altText.generate.success' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:altText.generate.success'),
            'mindfula11y.altText.generate.success.description' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:altText.generate.success.description'),
            'mindfula11y.altText.generate.error.unknown' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:altText.generate.error.unknown'),
            'mindfula11y.altText.generate.error.unknown.description' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:altText.generate.error.unknown.description'),
            'mindfula11y.altText.generate.button' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:altText.generate.button'),
            'mindfula11y.altText.altLabel' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:altText.altLabel'),
            'mindfula11y.altText.save' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:altText.save'),
            'mindfula11y.altText.imagePreview' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:altText.imagePreview'),
            'mindfula11y.altText.altPlaceholder' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:altText.altPlaceholder'),
            'mindfula11y.altText.fallbackAltLabel' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:altText.fallbackAltLabel'),

            // Accessibility Scanner labels (only keys used by JS)
            'mindfula11y.scan.loading' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.loading'),
            'mindfula11y.scan.error.loading' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.error.loading'),
            'mindfula11y.scan.status.pending' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.status.pending'),
            'mindfula11y.scan.status.running' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.status.running'),
            'mindfula11y.scan.status.failed' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.status.failed'),
            'mindfula11y.scan.status.failed.description' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.status.failed.description'),
            'mindfula11y.scan.issuesFound' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.issuesFound'),
            'mindfula11y.scan.noIssues' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.noIssues'),
            'mindfula11y.scan.issueCount' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.issueCount'),
            'mindfula11y.scan.issuesCount' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.issuesCount'),
            'mindfula11y.scan.helpLinks' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.helpLinks'),
            'mindfula11y.scan.screenshot' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.screenshot'),
            'mindfula11y.scan.viewScreenshot' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.viewScreenshot'),
            'mindfula11y.scan' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan'),
            'mindfula11y.scan.processing' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.processing'),
            'mindfula11y.scan.refresh' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.refresh'),
            'mindfula11y.scan.start' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.start'),
            'mindfula11y.scan.selector' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.selector'),
            'mindfula11y.scan.context' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.context'),
            'mindfula11y.scan.pageUrl' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.pageUrl'),
            'mindfula11y.scan.updatedAt' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.updatedAt'),
            'mindfula11y.scan.crawl.start' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.crawl.start'),
            'mindfula11y.scan.crawl.refresh' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.crawl.refresh'),
            'mindfula11y.scan.crawl.progress' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.crawl.progress'),
            'mindfula11y.scan.tab.scan' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.tab.scan'),
            'mindfula11y.scan.tab.crawl' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.tab.crawl'),
            'mindfula11y.scan.tab.scan.description' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.tab.scan.description'),
            'mindfula11y.scan.tab.crawl.description' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.tab.crawl.description'),
            'mindfula11y.scan.multiPage.manualScan' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.multiPage.manualScan'),
            'mindfula11y.scan.multiPage.manualScan.description' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.multiPage.manualScan.description'),
            'mindfula11y.scan.scopeExpanded' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.scopeExpanded'),
            'mindfula11y.scan.scopeExpanded.description' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.scopeExpanded.description'),
            'mindfula11y.scan.crawl.idle.title' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.crawl.idle.title'),
            'mindfula11y.scan.crawl.idle.description' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.crawl.idle.description'),
            'mindfula11y.scan.report.html' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.report.html'),
            'mindfula11y.scan.report.pdf' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.report.pdf'),

            // View / common
            'mindfula11y.general.viewDetails' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:general.viewDetails'),

            // Severity labels used in JS (map 'notice' to XLF 'severity.info')
            'mindfula11y.severity.error' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:severity.error'),
            'mindfula11y.severity.warning' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:severity.warning'),
            'mindfula11y.severity.info' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:severity.info'),
            // Axe-core impact levels used in scanner violations
            'mindfula11y.severity.critical' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:severity.critical'),
            'mindfula11y.severity.serious' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:severity.serious'),
            'mindfula11y.severity.moderate' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:severity.moderate'),
            'mindfula11y.severity.minor' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:severity.minor'),
        ];
        return $labels;
    }

    /**
     * Get converted page TSconfig for the given page.
     *
     * @param int $pageId
     * @return array
     */
    public function getConvertedPageTsConfig(int $pageId): array
    {
        $rawTsConfig = BackendUtility::getPagesTSconfig($pageId);
        return $this->typoScriptService->convertTypoScriptArrayToPlainArray($rawTsConfig);
    }

    /**
     * Check if the user has access to the missing alt text feature.
     *
     * @param array &$pageTsConfig The page TSconfig array (passed by reference)
     * @return bool
     */
    public function hasMissingAltTextAccess(array &$pageTsConfig): bool
    {
        return $this->permissionService->checkTableReadAccess('sys_file_reference')
            && $this->permissionService->checkNonExcludeFields('sys_file_reference', ['alternative'])
            && !!($pageTsConfig['mod']['mindfula11y_accessibility']['missingAltText']['enable'] ?? false);
    }

    /**
     * Check if the user has access to the heading structure feature.
     *
     * @param array &$pageTsConfig The page TSconfig array (passed by reference)
     * @return bool
     */
    public function hasHeadingStructureAccess(array &$pageTsConfig): bool
    {
        return !!($pageTsConfig['mod']['mindfula11y_accessibility']['headingStructure']['enable'] ?? false);
    }

    /**
     * Check if the user has access to the landmark structure feature.
     *
     * @param array &$pageTsConfig The page TSconfig array (passed by reference)
     * @return bool
     */
    public function hasLandmarkStructureAccess(array &$pageTsConfig): bool
    {
        return !!($pageTsConfig['mod']['mindfula11y_accessibility']['landmarkStructure']['enable'] ?? false);
    }

    /**
     * Check if the user has access to the accessibility scanner feature.
     *
     * @param array &$pageTsConfig The page TSconfig array (passed by reference)
     * @return bool
     */
    public function hasScanAccess(array &$pageTsConfig): bool
    {
        return $this->permissionService->checkTableReadAccess('pages')
            && $this->permissionService->checkNonExcludeFields('pages', ['tx_mindfula11y_scanid', 'tx_mindfula11y_scanupdated'])
            && !!($pageTsConfig['mod']['mindfula11y_accessibility']['scan']['enable'] ?? false);
    }

    /**
     * Determine if a scan should be invalidated based on page info.
     *
     * @param array $pageInfo The page info array containing SYS_LASTCHANGED and tx_mindfula11y_scanupdated.
     * @param int $fallbackSysLastChanged Fallback SYS_LASTCHANGED from the default-language page record.
     *   Pass this when $pageInfo is a translation overlay, as overlays may not have SYS_LASTCHANGED updated.
     * @return bool True if the scan should be invalidated (new scan needed), false otherwise.
     */
    public function shouldInvalidateScan(array &$pageInfo, int $fallbackSysLastChanged = 0): bool
    {
        $sysLastChanged = max((int)($pageInfo['SYS_LASTCHANGED'] ?? 0), $fallbackSysLastChanged);
        $scanUpdated = $pageInfo['tx_mindfula11y_scanupdated'] ?? null;

        // If no scan has been done yet, scan should be invalidated (new scan needed)
        if (!$scanUpdated) {
            return true;
        }

        // If scan exists, check if content has been modified since last scan
        return $sysLastChanged > 0 && $sysLastChanged > $scanUpdated;
    }

    /**
     * Check if auto-create scan is enabled in TSconfig.
     *
     * @param array &$pageTsConfig The page TSconfig array (passed by reference)
     * @return bool
     */
    public function isAutoCreateScanEnabled(array &$pageTsConfig): bool
    {
        return !!($pageTsConfig['mod']['mindfula11y_accessibility']['scan']['autoCreate'] ?? true);
    }

    /**
     * Check if preview is enabled for a given doktype.
     *
     * @param int $doktype
     * @param array &$pageTsConfig The page TSconfig array (passed by reference)
     * @return bool
     */
    public function isPreviewEnabledForDoktype(int $doktype, array &$pageTsConfig): bool
    {
        if (isset($pageTsConfig['TCEMAIN']['preview']['disableButtonForDokType'])) {
            return !in_array($doktype, GeneralUtility::intExplode(',', (string)$pageTsConfig['TCEMAIN']['preview']['disableButtonForDokType'], true));
        } else {
            return !in_array($doktype, [PageRepository::DOKTYPE_SYSFOLDER, PageRepository::DOKTYPE_SPACER, PageRepository::DOKTYPE_LINK]);
        }
    }

    /**
     * Check if a page record is visible (not hidden and within start/end time).
     * Uses TCA configuration to determine the correct fields.
     *
     * @param array $pageRecord The page record to check.
     * @return bool True if the page is visible, false otherwise.
     */
    public function isPageVisible(array $pageRecord): bool
    {
        $ctrl = $GLOBALS['TCA']['pages']['ctrl'];
        $enableColumns = $ctrl['enablecolumns'] ?? [];

        // Check disabled/hidden
        $disabledField = $enableColumns['disabled'] ?? 'hidden';
        if (isset($pageRecord[$disabledField]) && (int)$pageRecord[$disabledField] === 1) {
            return false;
        }

        $now = $GLOBALS['EXEC_TIME'] ?? time();

        // Check starttime
        $starttimeField = $enableColumns['starttime'] ?? 'starttime';
        if (isset($pageRecord[$starttimeField]) && (int)$pageRecord[$starttimeField] > $now) {
            return false;
        }

        // Check endtime
        $endtimeField = $enableColumns['endtime'] ?? 'endtime';
        if (isset($pageRecord[$endtimeField]) && (int)$pageRecord[$endtimeField] !== 0 && (int)$pageRecord[$endtimeField] <= $now) {
            return false;
        }

        return true;
    }

    /**
     * Check if a page record is accessible on the frontend (visible and not restricted by fe_group).
     *
     * Also checks ancestor pages for inherited restrictions via extendToSubpages: when a parent page
     * has extendToSubpages=1, its hidden, starttime, endtime, and fe_group restrictions cascade to
     * all descendant pages.
     *
     * @param array $pageRecord The page record to check.
     * @return bool True if the page is publicly accessible on the frontend, false otherwise.
     */
    public function isPageFrontendAccessible(array $pageRecord): bool
    {
        if (!$this->isPageVisible($pageRecord)) {
            return false;
        }

        $feGroup = (string)($pageRecord['fe_group'] ?? '');
        if ($feGroup !== '' && $feGroup !== '0') {
            return false;
        }

        // Check ancestor pages for inherited restrictions via extendToSubpages.
        // Use the original-language uid (l10n_parent) when the record is a translation overlay,
        // since RootlineUtility is designed for default-language page uids.
        $pageId = (int)(($pageRecord['l10n_parent'] ?? 0) ?: ($pageRecord['uid'] ?? 0));
        if ($pageId <= 0) {
            return true;
        }

        try {
            $rootline = GeneralUtility::makeInstance(RootlineUtility::class, $pageId)->get();
            foreach ($rootline as $ancestor) {
                if ((int)$ancestor['uid'] === $pageId) {
                    continue; // Skip current page, already checked above
                }
                // Only evaluate ancestors that extend their restrictions to subpages
                if (!($ancestor['extendToSubpages'] ?? false)) {
                    continue;
                }
                $ancestorFeGroup = (string)($ancestor['fe_group'] ?? '');
                if ($ancestorFeGroup !== '' && $ancestorFeGroup !== '0') {
                    return false;
                }
                if (!$this->isPageVisible($ancestor)) {
                    return false;
                }
            }
        } catch (\Exception $e) {
            // If the rootline cannot be resolved, treat as inaccessible to be safe
            return false;
        }

        return true;
    }

    /**
     * Get backend user authentication.
     */
    public function getBackendUserAuthentication(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }

    public function getLocalizedPageRecord(int $pageId, int $languageId): ?array
    {
        if ($languageId === 0) {
            return null;
        }
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $this->getBackendUserAuthentication()->workspace));
        $overlayRecord = $queryBuilder
            ->select('*')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq(
                    $GLOBALS['TCA']['pages']['ctrl']['transOrigPointerField'],
                    $queryBuilder->createNamedParameter($pageId, Connection::PARAM_INT)
                ),
                $queryBuilder->expr()->eq(
                    $GLOBALS['TCA']['pages']['ctrl']['languageField'],
                    $queryBuilder->createNamedParameter($languageId, Connection::PARAM_INT)
                )
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();
        if ($overlayRecord) {
            BackendUtility::workspaceOL('pages', $overlayRecord, $this->getBackendUserAuthentication()->workspace);
        }
        return is_array($overlayRecord) ? $overlayRecord : null;
    }

    /**
     * Get page record by scan ID.
     *
     * @param string $scanId The scan ID.
     *
     * @return array|null The page record or null if not found.
     */
    public function getPageRecordByScanId(string $scanId): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, (int)$this->getBackendUserAuthentication()->workspace));

        $result = $queryBuilder
            ->select('uid', 'pid', 't3ver_oid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('tx_mindfula11y_scanid', $queryBuilder->createNamedParameter($scanId))
            )
            ->executeQuery()
            ->fetchAssociative();

        if (!$result) {
            return null;
        }

        // Resolve the Live UID:
        // If we found a workspace version (pid = -1), the valid record to load is its Live parent (t3ver_oid).
        // If we found a live record, we use its own uid.
        $liveUid = ((int)($result['pid'] ?? 0) === -1)
            ? (int)($result['t3ver_oid'] ?? 0)
            : (int)($result['uid'] ?? 0);

        if ($liveUid <= 0) {
            return null;
        }

        // Use standard TYPO3 utility to fetch and overlay the record correctly.
        // This handles workspace logic ("move placeholders", permissions, etc.) automatically.
        $pageRecord = BackendUtility::getRecordWSOL('pages', $liveUid);

        return is_array($pageRecord) ? $pageRecord : null;
    }

    /**
     * Generate frontend URLs for pages in the page tree.
     *
     * Resolves the page tree from the given root page, filters to only pages that are
     * visible and publicly accessible (no fe_group restrictions), and generates
     * frontend preview URLs for each.
     *
     * @param int $pageId The root page ID.
     * @param int $languageId The language ID.
     * @param int $pageLevels The number of page tree levels to include.
     * @param string $fallbackUrl URL to return when no pages are found (typically the current page preview URL).
     *
     * @return string[] Array of frontend URLs.
     */
    public function generatePageUrls(int $pageId, int $languageId, int $pageLevels, string $fallbackUrl = ''): array
    {
        $pageTreeIds = $this->permissionService->getPageTreeIds($pageId, $pageLevels);
        $urls = [];

        foreach ($pageTreeIds as $treePageId) {
            $pageRecord = BackendUtility::getRecordWSOL('pages', $treePageId);
            if (!is_array($pageRecord)) {
                continue;
            }

            if ($languageId > 0) {
                $localizedPage = $this->getLocalizedPageRecord($treePageId, $languageId);
                if (null === $localizedPage) {
                    continue;
                }
                $pageRecord = $localizedPage;
            }

            if (!$this->isPageFrontendAccessible($pageRecord)) {
                continue;
            }

            $pageTsConfig = $this->getConvertedPageTsConfig($treePageId);
            if (!$this->isPreviewEnabledForDoktype((int)($pageRecord['doktype'] ?? 0), $pageTsConfig)) {
                continue;
            }

            $previewUri = PreviewUriBuilder::create($pageRecord)->buildUri();
            if (null !== $previewUri) {
                $urls[] = (string)$previewUri;
            }
        }

        $urls = array_unique($urls);

        if (empty($urls) && $fallbackUrl !== '') {
            return [$fallbackUrl];
        }

        return $urls;
    }
}
