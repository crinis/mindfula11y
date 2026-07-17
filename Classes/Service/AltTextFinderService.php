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

use MindfulMarkup\MindfulA11y\Domain\Model\AltlessFileReference;
use MindfulMarkup\MindfulA11y\Domain\Model\AltlessFileReferenceTable;
use MindfulMarkup\MindfulA11y\Domain\Repository\AltlessFileReferenceRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Class AltTextFinderService.
 * 
 * This class provides methods to find file references that are missing alternative text
 * and to check user permissions for accessing specific tables and fields in the TYPO3 backend.
 */
final readonly class AltTextFinderService
{
    /**
     * Constructor.
     */
    public function __construct(
        private AltlessFileReferenceRepository $altlessFileReferenceRepository,
        private PermissionService $permissionService,
        private BackendUserProvider $backendUserProvider,
        private PageTreeIdResolver $pageTreeIdResolver,
    ) {}

    /**
     * Get altless file references.
     * 
     * Query for file references that are missing alternative text and apply filters based on user
     * permissions and Page TSConfig settings. Does not check for table or language permissions.
     * 
     * @param int $pageId The ID of the page to check.
     * @param int $pageLevels The number of page levels below $pageId to select records from.
     * @param int $languageId The language ID to select records for.
     * @param array<string, mixed> $pageTsConfig The Page TSConfig for the current page.
     * @param int $firstResult The offset for the query.
     * @param int $maxResults The maximum number of results to return.
     * @param bool $filterFileMetaData If true, filter results based on presence of alternative text in file metadata.
     * @param string|null $tableName Restrict to a single table; null queries every table with file columns.
     * 
     * @return array<AltlessFileReference> An array of file reference objects that are missing alternative text.
     * 
     * @throws \Exception If there is an error executing the query.
     */
    public function getAltlessFileReferences(
        int $pageId,
        int $pageLevels,
        int $languageId,
        array $pageTsConfig,
        int $firstResult = 0,
        int $maxResults = 100,
        bool $filterFileMetaData = true,
        ?string $tableName = null,
    ): array {
        $tables = $this->buildTables($tableName, $pageId, $pageLevels, $pageTsConfig);
        // Fail closed: the table configurations carry every parent-table,
        // field, page-id and authMode predicate. Querying without them would
        // silently drop the whole scope instead of narrowing it.
        if ([] === $tables) {
            return [];
        }

        return $this->altlessFileReferenceRepository->findForTables(
            $tables,
            $languageId,
            $this->backendUserProvider->get()->workspace,
            $this->permissionService->checkFileReadAccess(...),
            $firstResult,
            $maxResults,
            $filterFileMetaData
        );
    }

    /**
     * Count altless file references.
     * 
     * Counts file references that are missing alternative text, applying filters based on user
     * permissions and Page TSConfig settings. Does not check for table or language permissions.
     * 
     * @param int $pageId The ID of the page to check.
     * @param int $pageLevels The number of page levels below $pageId to select records from.
     * @param int $languageId The language ID to select records for.
     * @param array<string, mixed> $pageTsConfig The Page TSConfig for the current page.
     * @param bool $filterFileMetaData If true, filter results based on presence of alternative text in file metadata.
     * @param string|null $tableName Restrict to a single table; null counts every table with file columns.
     * 
     * @return int The count of file references missing alternative text.
     * 
     * @throws \Exception If there is an error executing the query.
     */
    public function countAltlessFileReferences(
        int $pageId,
        int $pageLevels,
        int $languageId,
        array $pageTsConfig,
        bool $filterFileMetaData = true,
        ?string $tableName = null,
    ): int {
        $tables = $this->buildTables($tableName, $pageId, $pageLevels, $pageTsConfig);
        // Fail closed — see getAltlessFileReferences().
        if ([] === $tables) {
            return 0;
        }

        return $this->altlessFileReferenceRepository->countForTables(
            $tables,
            $languageId,
            $this->backendUserProvider->get()->workspace,
            $this->permissionService->checkFileReadAccess(...),
            $filterFileMetaData
        );
    }

    /**
     * Build the table configurations a query should cover.
     * 
     * @return array<AltlessFileReferenceTable> Empty when no table qualifies (the page-tree lookup is skipped then).
     */
    private function buildTables(?string $tableName, int $pageId, int $pageLevels, array $pageTsConfig): array
    {
        $tableNames = $tableName !== null ? [$tableName] : $this->getTablesWithFiles($pageTsConfig);
        if ([] === $tableNames) {
            return [];
        }

        $pageIds = $this->pageTreeIdResolver->getPageTreeIds($pageId, $pageLevels);
        $tables = [];
        foreach ($tableNames as $name) {
            $tables[] = $this->createAltlessFileReferenceTable($name, $pageIds, $pageTsConfig);
        }

        return $tables;
    }

    /**
     * Create an altless file reference table configuration.
     * 
     * Creates a table configuration object used for querying altless file references
     * finding file and authMode columns. Returns null if no file columns are found.
     * 
     * @param string $tableName The name of the table to create the configuration for.
     * @param array<int> $pageIds The page IDs to select records from.
     * @param array<string, mixed> $pageTsConfig The Page TSConfig for the current page.
     * 
     * @return AltlessFileReferenceTable The table configuration object.
     */
    private function createAltlessFileReferenceTable(string $tableName, array $pageIds, array $pageTsConfig): AltlessFileReferenceTable
    {
        $fileColumnNames = $this->getFileColumns($tableName, $pageTsConfig);
        $authModeColumns = $this->permissionService->getAllowedAuthModeValues($tableName);

        return new AltlessFileReferenceTable(
            $tableName,
            $fileColumnNames,
            $authModeColumns,
            $pageIds
        );
    }

    /**
     * Get allowed tables with file reference columns.
     *
     * This method identifies all database tables that contain file reference fields
     * and returns them as an array. The method
     * ensures that only tables accessible to the user (based on read
     * permissions) are included. Additionally, it respects Page TSConfig settings
     * that may exclude specific tables or fields from the results.
     *
     * @param array<string, mixed> $pageTsConfig The Page TSConfig for the current page, 
     *                                           used to filter out excluded fields.
     * 
     * @return array<string>
     */
    public function getTablesWithFiles(array $pageTsConfig): array
    {
        $tableNames = [];
        foreach (array_keys($GLOBALS['TCA']) as $tableName) {
            if (!$this->permissionService->checkTableReadAccess($tableName)) {
                continue;
            }

            $fileColumns = $this->getFileColumns($tableName, $pageTsConfig);
            if (!empty($fileColumns)) {
                $tableNames[] = $tableName;
            }
        }

        return $tableNames;
    }

    /**
     * Get allowed file reference columns for the given table.
     * 
     * Search for all file reference columns in the given table and check if the user has
     * access to it. The method also respects Page TSConfig settings that may exclude
     * specific fields from the results.
     * 
     * @param string $tableName The name of the table to check.
     * @param array<string, mixed> $pageTsConfig The Page TSConfig for the current page.
     * 
     * @return array<string> Array of file columns.
     */
    private function getFileColumns(string $tableName, array $pageTsConfig): array
    {
        $fileColumns = [];
        // $pageTsConfig is the converted (dot-free) form; the legacy read keeps
        // TSconfig from installs that used the undocumented pre-0.12 path working.
        $ignoreColumns = array_merge(
            GeneralUtility::trimExplode(',', (string)($pageTsConfig['mod']['mindfula11y_accessibility']['missingAltText']['ignoreColumns'][$tableName] ?? ''), true),
            GeneralUtility::trimExplode(',', (string)($pageTsConfig['mod']['mindfula11y_missingalttext'][$tableName] ?? ''), true),
        );

        foreach ($GLOBALS['TCA'][$tableName]['columns'] ?? [] as $column => $fieldConfig) {
            if (
                $fieldConfig['config']['type'] === 'file'
                && !in_array($column, $ignoreColumns, true)
            ) {
                if ($this->permissionService->checkNonExcludeFields($tableName, [$column])) {
                    $fileColumns[] = $column;
                }
            }
        }

        return $fileColumns;
    }
}
