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

use TYPO3\CMS\Backend\Tree\Repository\PageTreeRepository;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Versioning\VersionState;

/**
 * Class PermissionService.
 * 
 * This class provides methods to check user permissions and access rights for various tables
 * in the TYPO3 backend.
 */
class PermissionService
{
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
        $backendUser = $this->getBackendUserAuthentication();

        if (!isset($GLOBALS['TCA'][$tableName])) {
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
                        if (null !== $backendUser && $backendUser->checkAuthMode($tableName, $columnName, $item['value'])) {
                            $allowedAuthModeValues[$columnName][] = $item['value'];
                        }
                    }
                }
            }
        }

        return $allowedAuthModeValues;
    }

    /**
     * Get the list of pages to check for records with missing alternative text.
     * 
     * Find all page IDs that are accessible to the current user and have file references
     * with missing alternative text. Check for write permissions on the page content. Page IDs will be the original IDs not workspace
     * or localized IDs.
     * 
     * @param int $pageId The ID of the selected page.
     * @param int $pageLevels The number of page levels to check.
     * 
     * @return array<int>
     */
    public function getPageTreeIds(int $pageId, int $pageLevels): array
    {
        $backendUser = $this->getBackendUserAuthentication();

        if (null === $backendUser) {
            return [];
        }

        $expressionBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('pages')
            ->expr();

        $permsClause = $expressionBuilder->and(
            $backendUser->getPagePermsClause(Permission::PAGE_SHOW),
        );
        
        // This will hide records from display - it has nothing to do with user rights!!
        $hiddenPidList = GeneralUtility::intExplode(',', (string)($backendUser->getTSConfig()['options.']['hideRecords.']['pages'] ?? ''), true);
        if (!empty($hiddenPidList)) {
            $permsClause = $permsClause->with($expressionBuilder->notIn('pages.uid', $hiddenPidList));
        }
        $perms_clause = (string)$permsClause;

        if (!$backendUser->isAdmin() && $pageId === 0) {
            $mountPoints = $backendUser->getWebmounts();
        } else {
            $mountPoints = [$pageId];
        }

        $repository = GeneralUtility::makeInstance(PageTreeRepository::class);
        $repository->setAdditionalWhereClause($perms_clause);
        $pages = $repository->getFlattenedPages($mountPoints, $pageLevels);
        $idList = [];
        foreach ($pages as $page) {
            $idList[] = (int)$page['uid'];
        }

        return array_unique($idList);
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
        $backendUser = $this->getBackendUserAuthentication();

        if (null === $backendUser || !isset($GLOBALS['TCA'][$tableName])) {
            return false;
        }

        if ($backendUser->isAdmin()) {
            return true;
        }

        if (
            !$backendUser->check('tables_select', $tableName)
            || (BackendUtility::isTableWorkspaceEnabled($tableName) && !$backendUser->workspaceAllowsLiveEditingInTable($tableName))
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
        $backendUser = $this->getBackendUserAuthentication();

        if (null === $backendUser) {
            return false;
        }

        if (!isset($GLOBALS['TCA'][$tableName]) || ($GLOBALS['TCA'][$tableName]['ctrl']['readonly'] ?? false)) {
            return false;
        }

        if ($backendUser->isAdmin()) {
            return true;
        }

        if ($GLOBALS['TCA'][$tableName]['ctrl']['adminOnly'] ?? false) {
            return false;
        }

        if (
            !$backendUser->check('tables_modify', $tableName)
            || (BackendUtility::isTableWorkspaceEnabled($tableName) && !$backendUser->workspaceAllowsLiveEditingInTable($tableName))
        ) {
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

        $backendUser = $this->getBackendUserAuthentication();

        if ($backendUser->isAdmin()) {
            return true;
        }

        BackendUtility::workspaceOL($tableName, $row);

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
        $backendUser = $this->getBackendUserAuthentication();

        if (null === $backendUser) {
            return false;
        }

        if ($backendUser->isAdmin()) {
            return true;
        }

        foreach ($columnNames as $columnName) {
            if (
                !$backendUser->check('non_exclude_fields', $tableName . ':' . $columnName)
            ) {
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
        $backendUser = $this->getBackendUserAuthentication();

        if (null === $backendUser) {
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
        $backendUser = $this->getBackendUserAuthentication();
        if (null === $backendUser) {
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
        return $file->getStorage()->checkFileActionPermission('editMeta', $file);
    }

    /**
     * Get backend user authentication.
     * 
     * @return BackendUserAuthentication
     */
    protected function getBackendUserAuthentication(): ?BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'] ?? null;
    }
}
