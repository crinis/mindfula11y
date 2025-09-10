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
            // Heading Structure labels
            'mindfula11y.features.headingStructure.edit' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:headingStructure.edit'),
            'mindfula11y.features.headingStructure.edit.locked' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:headingStructure.edit.locked'),
            'mindfula11y.features.headingStructure.error.loading' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:headingStructure.error.loading'),
            'mindfula11y.features.headingStructure.error.loading.description' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:headingStructure.error.loading.description'),
            'mindfula11y.features.headingStructure.error.store' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:headingStructure.error.store'),
            'mindfula11y.features.headingStructure.error.store.description' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:headingStructure.error.store.description'),
            'mindfula11y.features.headingStructure.relation.descendant' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:headingStructure.relation.descendant'),
            'mindfula11y.features.headingStructure.relation.sibling' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:headingStructure.relation.sibling'),
            'mindfula11y.features.headingStructure.relation.notFound' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:headingStructure.relation.notFound'),
            'mindfula11y.features.headingStructure.relation.notFound.description' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:headingStructure.relation.notFound.description'),
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
            'mindfula11y.features.landmarkStructure.error.duplicateMain' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:landmarkStructure.error.duplicateMain'),
            'mindfula11y.features.landmarkStructure.error.duplicateMain.description' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:landmarkStructure.error.duplicateMain.description'),
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
            // Structure Errors labels
            'mindfula11y.features.structureErrors.viewHeadingStructure' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:structureErrors.viewHeadingStructure'),
            'mindfula11y.features.structureErrors.viewLandmarkStructure' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:structureErrors.viewLandmarkStructure'),
            'mindfula11y.features.structureErrors.headingsTab' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:structureErrors.headingsTab'),
            'mindfula11y.features.structureErrors.landmarksTab' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:structureErrors.landmarksTab'),
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
}