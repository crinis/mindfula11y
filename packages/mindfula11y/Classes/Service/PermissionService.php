<?php

declare(strict_types=1);

namespace MindfulMarkup\MindfulA11y\Service;

use TYPO3\CMS\Backend\Tree\Repository\PageTreeRepository;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Utility\DebuggerUtility;

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
     * with missing alternative text. Check for write permissions on the page content.
     * 
     * @param int $pageId The ID of the selected page.
     * @param int $pageLevels The number of page levels to check.
     * @param string $tableName The name of the table to check.
     * 
     * @return array<int>
     */
    public function getPageTreeIds(int $pageId, int $pageLevels, string $tableName): array
    {
        $backendUser = $this->getBackendUserAuthentication();

        if (null === $backendUser) {
            return [];
        }

        $expressionBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('pages')
            ->expr();

        if ('pages' === $tableName) {
            $permsClause = $expressionBuilder->and(
                $backendUser->getPagePermsClause(Permission::PAGE_EDIT),
            );
        } else {
            $permsClause = $expressionBuilder->and(
                $backendUser->getPagePermsClause(Permission::PAGE_SHOW),
                $backendUser->getPagePermsClause(Permission::CONTENT_EDIT),
            );
        }

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

        if (null === $backendUser || !isset($GLOBALS['TCA'][$tableName]) || ($GLOBALS['TCA'][$tableName]['ctrl']['readonly'] ?? false)) {
            return false;
        }

        if ($backendUser->isAdmin()) {
            return true;
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
     * 
     * @todo check editlock of page
     */
    public function checkRecordEditAccess(string $tableName, array $row, array $columnNames = []): bool
    {
        $backendUser = $this->getBackendUserAuthentication();

        if (null === $backendUser || !$this->checkTableWriteAccess($tableName)) {
            return false;
        }

        if ($backendUser->isAdmin()) {
            return true;
        }

        $pageId = (int)($tableName === 'pages' ? ($row['l10n_parent'] > 0 ?: $row['uid']) : $row['pid']);
        $pageRow = 'pages' !== $tableName || ('pages' === $tableName && $row['l10n_parent'] > 0) ?
            BackendUtility::getRecord('pages', $pageId) : $row;

        if (null === $pageRow) {
            return false;
        }

        $pagePerms = new Permission($backendUser->calcPerms($pageRow));

        if (
            (('pages' === $tableName
                && $pagePerms->editPagePermissionIsGranted())
                || ('pages' !== $tableName && $pagePerms->editContentPermissionIsGranted()))
            && $backendUser->recordEditAccessInternals($tableName, $row)
        ) {
            if (!empty($columnNames) && !$this->checkNonExcludeFields($tableName, $columnNames)) {
                return false;
            }

            return true;
        }

        return false;
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
     * Get backend user authentication.
     * 
     * @return BackendUserAuthentication
     */
    protected function getBackendUserAuthentication(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }
}
