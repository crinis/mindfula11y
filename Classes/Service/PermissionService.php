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

use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Schema\Capability\TcaSchemaCapability;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Versioning\VersionState;

/**
 * Class PermissionService.
 * 
 * This class provides methods to check user permissions and access rights for various tables
 * in the TYPO3 backend.
 */
final readonly class PermissionService
{
    /** Identifier of the accessibility backend module all AJAX endpoints inherit access from. */
    public const MODULE_NAME = 'mindfula11y_accessibility';

    public function __construct(
        private TcaSchemaFactory $tcaSchemaFactory,
        // Constructor-injected on purpose: ModuleProvider is not makeInstance-
        // resolvable on TYPO3 v14 (its ServiceProvider registration was removed
        // and its constructor grew a second dependency), so lazy resolution
        // fatals. This service is always container-wired, never new'ed.
        private ModuleProvider $moduleProvider,
        private BackendUserProvider $backendUserProvider,
    ) {}

    /**
     * Whether the current backend user may access the accessibility module.
     *
     * The single defense-in-depth check behind every AJAX endpoint (their
     * routes already inherit access from the module).
     */
    public function checkModuleAccess(): bool
    {
        $backendUser = $this->backendUserProvider->getAuthenticated();

        return $backendUser !== null
            && $this->moduleProvider->accessGranted(self::MODULE_NAME, $backendUser);
    }

    /**
     * Whether the given table is workspace-aware.
     *
     * Schema API replacement for the deprecated BackendUtility workspace-enabled
     * helper (Deprecation #106393). The Schema API exists in both TYPO3 v13.2+
     * and v14, so no version branch is required. Returns false for tables not
     * present in TCA, matching the behaviour of the previous method.
     */
    private function isTableWorkspaceAware(string $tableName): bool
    {
        return $this->tcaSchemaFactory->has($tableName)
            && $this->tcaSchemaFactory->get($tableName)
                ->hasCapability(TcaSchemaCapability::Workspace);
    }

    /**
     * Get allowed values for each authMode column of a table.
     * 
     * Return an array of allowed authMode values for each column in the given table.
     * Does not check if the user has access to the table itself.
     * 
     * @param string $tableName The name of the table to check.
     *
     * @return array<string,array<string>|null> An array of allowed authMode values for each authMode enabled column.
     */
    public function getAllowedAuthModeValues(string $tableName): array
    {
        $backendUser = $this->backendUserProvider->getAuthenticated();

        if ($backendUser === null || !isset($GLOBALS['TCA'][$tableName])) {
            return [];
        }

        $allowedAuthModeValues = [];

        if (is_array($GLOBALS['TCA'][$tableName]['columns'])) {
            foreach ($GLOBALS['TCA'][$tableName]['columns'] as $columnName => $columnValue) {
                if (
                    ($columnValue['config']['type'] ?? '') === 'select'
                    && ($columnValue['config']['authMode'] ?? false)
                ) {
                    /**
                     * If none exist no point checking.
                     */
                    if (empty($columnValue['config']['items'])) {
                        continue;
                    }

                    $allowedAuthModeValues[$columnName] = [];
                    foreach ($columnValue['config']['items'] as $item) {
                        if ($backendUser->checkAuthMode($tableName, $columnName, $item['value'])) {
                            $allowedAuthModeValues[$columnName][] = $item['value'];
                        }
                    }
                }
            }
        }

        return $allowedAuthModeValues;
    }

    /**
     * Check if user has read access to the given table.
     * 
     * @param string $tableName The name of the table to check.
     * 
     * @return bool True if file references can be listed, false otherwise.
     */
    public function checkTableReadAccess(string $tableName): bool
    {
        $backendUser = $this->backendUserProvider->getAuthenticated();

        if ($backendUser === null || !isset($GLOBALS['TCA'][$tableName])) {
            return false;
        }

        if ($backendUser->isAdmin()) {
            return true;
        }

        if (
            !$backendUser->check('tables_select', $tableName)
            || ($this->isTableWorkspaceAware($tableName) && !$backendUser->workspaceAllowsLiveEditingInTable($tableName))
        ) {
            return false;
        }

        return true;
    }

    /**
     * Check if user has write access to the given table.
     * 
     * @param string $tableName The name of the table to check.
     * 
     * @return bool True if file references can be listed, false otherwise.
     */
    public function checkTableWriteAccess(string $tableName): bool
    {
        $backendUser = $this->backendUserProvider->getAuthenticated();

        if ($backendUser === null
            || !isset($GLOBALS['TCA'][$tableName])
            || ($GLOBALS['TCA'][$tableName]['ctrl']['readOnly'] ?? false)
        ) {
            return false;
        }

        if ($backendUser->isAdmin()) {
            return true;
        }

        if ($GLOBALS['TCA'][$tableName]['ctrl']['adminOnly'] ?? false) {
            return false;
        }

        if (!$backendUser->check('tables_modify', $tableName)) {
            return false;
        }

        // workspaceAllowsLiveEditingInTable() === false in an offline workspace
        // means "writes must create a workspace version", which DataHandler does
        // transparently for workspace-aware tables. Only tables WITHOUT
        // workspace support are unwritable there (unless the workspace permits
        // live editing).
        if (!$this->isTableWorkspaceAware($tableName) && !$backendUser->workspaceAllowsLiveEditingInTable($tableName)) {
            return false;
        }

        return true;
    }

    /**
     * Check record edit access.
     * 
     * Check if the user has edit access to a specific record in the given table. Also
     * checks page, workspace and language permissions. Optionally tests if the provided columns
     * are disallowed non_exclude_fields.
     * 
     * @param string $tableName The name of the table to check.
     * @param array $row The row of the record to check.
     * @param array $columnNames The non_exclude_fields of the record to check. If empty none are checked.
     *
     * @return bool True if the user can edit the record, false otherwise.
     */
    public function checkRecordEditAccess(string $tableName, array $row, array $columnNames = []): bool
    {
        if (!$this->checkTableWriteAccess($tableName)) {
            return false;
        }

        $backendUser = $this->backendUserProvider->getAuthenticated();

        if ($backendUser === null) {
            return false;
        }

        if ($backendUser->isAdmin()) {
            return true;
        }

        BackendUtility::workspaceOL($tableName, $row);

        // Record-level edit lock (e.g. tt_content.editlock): DataHandler denies
        // such writes via recordEditAccessInternals(), so honoring it here keeps
        // offered controls in sync with what a save would actually allow. The
        // page-level editlock is checked against the page row further down.
        $editlockField = (string)($GLOBALS['TCA'][$tableName]['ctrl']['editlock'] ?? '');
        if ($editlockField !== '' && !empty($row[$editlockField])) {
            return false;
        }

        if ($GLOBALS['TCA'][$tableName]['ctrl']['languageField'] ?? false) {
            if (!isset($row[$GLOBALS['TCA'][$tableName]['ctrl']['languageField']])) {
                return false;
            } else {
                $languageId = (int)$row[$GLOBALS['TCA'][$tableName]['ctrl']['languageField']];
                if (!$backendUser->checkLanguageAccess($languageId)) {
                    return false;
                }
            }
        }

        if (is_array($GLOBALS['TCA'][$tableName]['columns'])) {
            foreach ($GLOBALS['TCA'][$tableName]['columns'] as $fieldName => $fieldValue) {
                if (
                    isset($row[$fieldName])
                    && ($fieldValue['config']['type'] ?? '') === 'select'
                    && ($fieldValue['config']['authMode'] ?? false)
                    && !$backendUser->checkAuthMode($tableName, $fieldName, $row[$fieldName])
                ) {
                    return false;
                }
            }
        }

        if (!empty($columnNames) && !$this->checkNonExcludeFields($tableName, $columnNames)) {
            return false;
        }
        
        if ($tableName === 'pages') {
            $l10nParent = isset($row[$GLOBALS['TCA'][$tableName]['ctrl']['transOrigPointerField']]) ? (int)$row[$GLOBALS['TCA'][$tableName]['ctrl']['transOrigPointerField']] : 0;
            $pageRow = $l10nParent > 0 ? BackendUtility::getRecordWSOL($tableName, $l10nParent) : $row;

            if (!is_array($pageRow) || VersionState::tryFrom((int)($pageRow['t3ver_state'] ?? 0)) === VersionState::DELETE_PLACEHOLDER) {
                return false;
            }

            $pagePerms = new Permission($backendUser->calcPerms($pageRow));

            if (!$pagePerms->editPagePermissionIsGranted() || !empty($pageRow[$GLOBALS['TCA']['pages']['ctrl']['editlock']] ?? false)) {
                return false;
            }
        } else {
            $pageRow = BackendUtility::getRecordWSOL('pages', (int)$row['pid']);

            if (!is_array($pageRow) || VersionState::tryFrom((int)($pageRow['t3ver_state'] ?? 0)) === VersionState::DELETE_PLACEHOLDER) {
                return false;
            }

            $pageL10nParent = isset($pageRow[$GLOBALS['TCA']['pages']['ctrl']['transOrigPointerField']]) ? (int)$pageRow[$GLOBALS['TCA']['pages']['ctrl']['transOrigPointerField']] : 0;

            if ($pageL10nParent > 0) {
                $pageRow = BackendUtility::getRecordWSOL('pages', $pageL10nParent);
                if (!is_array($pageRow)) {
                    return false;
                }
            }

            $pagePerms = new Permission($backendUser->calcPerms($pageRow));

            if (!$pagePerms->editContentPermissionIsGranted() || !empty($pageRow[$GLOBALS['TCA']['pages']['ctrl']['editlock']] ?? false)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if given columns are non_exclude_fields.
     * 
     * Takes an array of column names and checks if the user is not excluded from
     * using them by non_exclude_fields. Does not check table access.
     * 
     * @param string $tableName The name of the table to check.
     * @param array $columnNames The columns to check.
     * 
     * @return bool True if the columns are non_exclude_fields, false otherwise.
     */
    public function checkNonExcludeFields(string $tableName, array $columnNames): bool
    {
        $backendUser = $this->backendUserProvider->getAuthenticated();

        if ($backendUser === null) {
            return false;
        }

        if ($backendUser->isAdmin()) {
            return true;
        }

        foreach ($columnNames as $columnName) {
            // Fields that are not marked as exclude in TCA are always accessible — only check
            // the user's granted non_exclude_fields list when the field actually has exclude: true.
            if (!($GLOBALS['TCA'][$tableName]['columns'][$columnName]['exclude'] ?? false)) {
                continue;
            }
            if (!$backendUser->check('non_exclude_fields', $tableName . ':' . $columnName)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check page read access.
     *
     * Check if the user has read access for a specific page record. This includes checking
     * language access, workspace access, resolving translations to their parent page,
     * resolving workspace versions to the live page, and finally checking standard page permissions.
     *
     * @param array $pageRecord The page record to check.
     *
     * @return bool True if the user can read the page, false otherwise.
     */
    public function checkPageReadAccess(array $pageRecord): bool
    {
        $backendUser = $this->backendUserProvider->getAuthenticated();

        if ($backendUser === null) {
            return false;
        }

        if ($backendUser->isAdmin()) {
            return true;
        }

        // Check for delete placeholder
        if (VersionState::tryFrom((int)($pageRecord['t3ver_state'] ?? 0)) === VersionState::DELETE_PLACEHOLDER) {
            return false;
        }

        // Check language access if it is a translated record
        $languageField = $GLOBALS['TCA']['pages']['ctrl']['languageField'] ?? 'sys_language_uid';
        if (isset($pageRecord[$languageField]) && (int)$pageRecord[$languageField] > 0) {
            if (!$backendUser->checkLanguageAccess((int)$pageRecord[$languageField])) {
                return false;
            }
        }

        // Check Workspace Access
        // We rely on backend user workspace check.
        $recordWorkspace = (int)($pageRecord['t3ver_wsid'] ?? 0);
        if ($recordWorkspace > 0 && $backendUser->workspace !== $recordWorkspace) {
            return false;
        }

        // Resolve Tree Page ID for permission check
        // If it's a translation (l10n_parent/transOrigPointerField > 0), verify the parent.
        $transOrigPointerField = $GLOBALS['TCA']['pages']['ctrl']['transOrigPointerField'] ?? 'l10n_parent';
        if (
            isset($pageRecord[$transOrigPointerField])
            && (int)$pageRecord[$transOrigPointerField] > 0
        ) {
            $pageRecord = BackendUtility::getRecordWSOL('pages', (int)$pageRecord[$transOrigPointerField]);
            
            if (!$pageRecord || VersionState::tryFrom((int)($pageRecord['t3ver_state'] ?? 0)) === VersionState::DELETE_PLACEHOLDER) {
                return false;
            }
        }

        // Check Standard Page Access (PAGE_SHOW)
        // We use calcPerms on the passed record (which should be fully overlaid with valid PID)
        // to respect any permission changes made in the workspace version.
        $perms = $backendUser->calcPerms($pageRecord);
        return ($perms & Permission::PAGE_SHOW) === Permission::PAGE_SHOW;
    }

    /**
     * Check if the user has access to the given language ID.
     * Takes admins into account.
     *
     * @param int $languageId The language ID to check.
     * @return bool True if the user has access, false otherwise.
     */
    public function checkLanguageAccess(int $languageId): bool
    {
        $backendUser = $this->backendUserProvider->getAuthenticated();

        if ($backendUser === null) {
            return false;
        }

        // Admins always have access
        if ($backendUser->isAdmin()) {
            return true;
        }

        return $backendUser->checkLanguageAccess($languageId);
    }

    /**
     * Check if the current backend user has read access to a file,
     * including file mount boundary checks.
     *
     * @param FileInterface $file
     * @return bool
     */
    public function checkFileReadAccess(FileInterface $file): bool
    {
        if ($this->backendUserProvider->getAuthenticated() === null) {
            return false;
        }

        // Files in the fallback storage (uid 0) pass for every backend user:
        // core's StoragePermissionsAspect deliberately skips permission
        // evaluation there (!$storage->isFallbackStorage()), and core's own
        // FileMetadataPermissionsAspect applies the same permissive check.
        // This is exact core parity, not a weakened gate.
        return $file->getStorage()->checkFileActionPermission('read', $file);
    }

    /**
     * Check if the current backend user may edit the metadata of a file.
     *
     * Uses the 'editMeta' action which is the permission model TYPO3 core applies to
     * sys_file_metadata via FileMetadataPermissionsAspect. It verifies the file is within
     * a writable file mount boundary.
     *
     * @param FileInterface $file
     * @return bool
     */
    public function checkFileMetaEditAccess(FileInterface $file): bool
    {
        if ($this->backendUserProvider->getAuthenticated() === null) {
            return false;
        }

        return $file->getStorage()->checkFileActionPermission('editMeta', $file);
    }
}
