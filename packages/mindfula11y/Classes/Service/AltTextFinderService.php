<?php

declare(strict_types=1);

namespace MindfulMarkup\MindfulA11y\Service;

use MindfulMarkup\MindfulA11y\Domain\Model\AltlessFileReferenceTable;
use MindfulMarkup\MindfulA11y\Domain\Repository\AltlessFileReferenceRepository;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Class AltTextFinderService.
 * 
 * This class provides methods to find file references that are missing alternative text
 * and to check user permissions for accessing specific tables and fields in the TYPO3 backend.
 */
class AltTextFinderService
{
    /**
     * Constructor.
     */
    public function __construct(
        protected readonly AltlessFileReferenceRepository $altlessFileReferenceRepository,
        protected readonly PermissionService $permissionService,
    ) {}

    /**
     * Get altless file references.
     * 
     * Query for file references that are missing alternative text and apply filters based on user
     * permissions and Page TSConfig settings. Does not check for language permissions.
     * 
     * @param int $pageId The ID of the page to check.
     * @param int $pageLevels The number of page levels below $pageId to select records from.
     * @param int $languageId The language ID to select records for.
     * @param array<string, mixed> $pageTsConfig The Page TSConfig for the current page.
     * @param int $firstResult The offset for the query.
     * @param int $maxResults The maximum number of results to return.
     * @param bool $filterFileMetaData If true, filter results based on presence of alternative text in file metadata.
     * 
     * @return array<AltlessFileReference> An array of file reference objects that are missing alternative text.
     * 
     * @throws \Exception If there is an error executing the query.
     */
    public function getAltlessFileReferences(
        int $pageId,
        int $pageLevels,
        int $languageId,
        array &$pageTsConfig,
        int $firstResult = 0,
        int $maxResults = 100,
        bool $filterFileMetaData = true
    ): array {
        $tables = [];
        $pageIds = null;
        foreach ($this->getTablesWithFiles($pageTsConfig) as $tableName) {
            if ('pages' === $tableName) {
                $tables[] = $this->createAltlessFileReferenceTable($tableName, $this->permissionService->getPageTreeIds($pageId, $pageLevels, $tableName), $pageTsConfig);
            } else {
                if (null === $pageIds) {
                    $pageIds = $this->permissionService->getPageTreeIds($pageId, $pageLevels, $tableName);
                }
                $tables[] = $this->createAltlessFileReferenceTable($tableName, $pageIds, $pageTsConfig);
            }
        }

        return $this->altlessFileReferenceRepository->findForTables(
            $tables,
            $languageId,
            $this->getBackendUserAuthentication()->workspace,
            $firstResult,
            $maxResults,
            $filterFileMetaData
        );
    }

    /**
     * Query file references from a given table that are missing alternative text.
     * 
     * Queries the database for file references associated with a specific table and page ID and
     * creates FileReference objects for each record. Does not check for table or language permissions.
     * 
     * @param string $tableName The name of the table to query.
     * @param int $pageId The ID of the page to check.
     * @param int $pageLevels The number of page levels below $pageId to select records from.
     * @param int $languageId The language ID to select records for.
     * @param array<string, mixed> $pageTsConfig The Page TSConfig for the current page.
     * @param int $firstResult The offset for the query.
     * @param int $maxResults The maximum number of results to return.
     * @param bool $filterFileMetaData If true, filter results based on presence of alternative text in file metadata.
     * 
     * @return array<AltlessFileReference> An array of file reference objects that are missing alternative text.
     * 
     * @throws \Exception If there is an error executing the query.
     */
    public function getAltlessFileReferencesForTable(
        string $tableName,
        int $pageId,
        int $pageLevels,
        int $languageId,
        array &$pageTsConfig,
        int $firstResult = 0,
        int $maxResults = 100,
        bool $filterFileMetaData = true,
    ): array {
        $pageTreeIds = $this->permissionService->getPageTreeIds($pageId, $pageLevels, $tableName);
        $table = $this->createAltlessFileReferenceTable($tableName, $pageTreeIds, $pageTsConfig);

        return $this->altlessFileReferenceRepository->findForTables(
            [$table],
            $languageId,
            $this->getBackendUserAuthentication()->workspace,
            $firstResult,
            $maxResults,
            $filterFileMetaData
        );
    }

    /**
     * Count altless file references.
     * 
     * Counts file references that are missing alternative text, applying filters based on user
     * permissions and Page TSConfig settings. Does not check for language permissions.
     * 
     * @param int $pageId The ID of the page to check.
     * @param int $pageLevels The number of page levels below $pageId to select records from.
     * @param int $languageId The language ID to select records for.
     * @param array<string, mixed> $pageTsConfig The Page TSConfig for the current page.
     * @param bool $filterFileMetaData If true, filter results based on presence of alternative text in file metadata.
     * 
     * @return int The count of file references missing alternative text.
     * 
     * @throws \Exception If there is an error executing the query.
     */
    public function countAltlessFileReferences(
        int $pageId,
        int $pageLevels,
        int $languageId,
        array &$pageTsConfig,
        bool $filterFileMetaData = true
    ): int {
        $tables = [];
        $pageIds = null;
        foreach ($this->getTablesWithFiles($pageTsConfig) as $tableName) {
            if ('pages' === $tableName) {
                $tables[] = $this->createAltlessFileReferenceTable($tableName, $this->permissionService->getPageTreeIds($pageId, $pageLevels, $tableName), $pageTsConfig);
            } else {
                if (null === $pageIds) {
                    $pageIds = $this->permissionService->getPageTreeIds($pageId, $pageLevels, $tableName);
                }
                $tables[] = $this->createAltlessFileReferenceTable($tableName, $pageIds, $pageTsConfig);
            }
        }

        return $this->altlessFileReferenceRepository->countForTables(
            $tables,
            $languageId,
            $this->getBackendUserAuthentication()->workspace,
            $filterFileMetaData
        );
    }

    /**
     * Count file references from a given table that are missing alternative text.
     * 
     * Counts file references associated with a specific table and page ID that are missing alternative text.
     * Does not check for table or language permissions.
     * 
     * @param string $tableName The name of the table to query.
     * @param int $pageId The ID of the page to check.
     * @param int $pageLevels The number of page levels below $pageId to select records from.
     * @param int $languageId The language ID to select records for.
     * @param array<string, mixed> $pageTsConfig The Page TSConfig for the current page.
     * @param bool $filterFileMetaData If true, filter results based on presence of alternative text in file metadata.
     * 
     * @return int The count of file references missing alternative text.
     * 
     * @throws \Exception If there is an error executing the query.
     */
    public function countAltlessFileReferencesForTable(
        string $tableName,
        int $pageId,
        int $pageLevels,
        int $languageId,
        array &$pageTsConfig,
        bool $filterFileMetaData = true
    ): int {
        $pageTreeIds = $this->permissionService->getPageTreeIds($pageId, $pageLevels, $tableName);
        $table = $this->createAltlessFileReferenceTable($tableName, $pageTreeIds, $pageTsConfig);

        return $this->altlessFileReferenceRepository->countForTables(
            [$table],
            $languageId,
            $this->getBackendUserAuthentication()->workspace,
            $filterFileMetaData
        );
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
    public function createAltlessFileReferenceTable(string $tableName, array $pageIds, array $pageTsConfig): AltlessFileReferenceTable
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
     * ensures that only tables accessible to the user (based on modify
     * permissions) are included. Additionally, it respects Page TSConfig settings
     * that may exclude specific tables or fields from the results.
     *
     * @param array<string, mixed> $pageTsConfig The Page TSConfig for the current page, 
     *                                           used to filter out excluded fields.
     * 
     * @return array<string>
     */
    public function getTablesWithFiles(array &$pageTsConfig): array
    {
        $tableNames = [];
        foreach (array_keys($GLOBALS['TCA']) as $tableName) {
            if (!$this->permissionService->checkTableWriteAccess($tableName)) {
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
    protected function getFileColumns(string $tableName, array &$pageTsConfig): array
    {
        $backendUser = $this->getBackendUserAuthentication();

        if (null === $backendUser) {
            return [];
        }

        $fileColumns = [];
        $ignoreColumns = explode(',', $pageTsConfig['mod.']['mindfula11y_missingalttext.'][$tableName] ?? '');

        foreach ($GLOBALS['TCA'][$tableName]['columns'] as $column => $fieldConfig) {
            if (
                $fieldConfig['config']['type'] === 'file'
                && !in_array($column, $ignoreColumns, true)
            ) {
                if ($backendUser->check('non_exclude_fields', $tableName . ':' . $column)) {
                    $fileColumns[] = $column;
                }
            }
        }

        return $fileColumns;
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
