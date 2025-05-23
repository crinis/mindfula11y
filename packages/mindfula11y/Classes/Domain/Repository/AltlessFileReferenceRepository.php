<?php

declare(strict_types=1);

namespace MindfulMarkup\MindfulA11y\Domain\Repository;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Doctrine\DBAL\Exception;
use MindfulMarkup\MindfulA11y\Domain\Model\AltlessFileReference;
use MindfulMarkup\MindfulA11y\Domain\Model\AltlessFileReferenceTable;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Extbase\Persistence\Generic\Mapper\DataMapper;
use TYPO3\CMS\Extbase\Persistence\Repository;

/**
 * Class AltlessFileReferenceRepository.
 * 
 * This class is responsible for retrieving file references from the database that have no alternative text. It is not using Extbase
 * as we want plain arrays and are using the QueryBuilder.
 */
class AltlessFileReferenceRepository extends Repository
{
    protected ConnectionPool $connectionPool;

    protected DataMapper $dataMapper;

    public function injectConnectionPool(ConnectionPool $connectionPool): void
    {
        $this->connectionPool = $connectionPool;
    }

    public function injectDataMapper(DataMapper $dataMapper): void
    {
        $this->dataMapper = $dataMapper;
    }

    /**
     * Find file references without alternative text.
     * 
     * Query file references and apply all sorts of filters to restrict sys_file_reference records from being shown if the
     * associated records are not accessible to the current user. This is to prevent unintended access to
     * records that the user should not see. On the other side of saving file references they can always
     * be modified via request forgery using e.g. AjaxDataHandler. We cannot prevent this.
     *
     * @param array<AltlessFileReferenceTable> $tables Array of table configurations to select file references by.
     * @param int $languageId The language UID to select file references for.
     * @param int $workspaceId The workspace ID to select file references for.
     * @param int $firstResult The offset for the query.
     * @param int $maxResults The maximum number of results to return.
     * @param bool $filterMetaAlternative If true, filter rows if they have alternative text in the file metadata.
     * 
     * @return array<AltlessFileReference> An array of file reference rows.
     * 
     * @throws Exception If there is an error executing the query.
     * 
     * @todo Check for language fallbacks and respect transOrig field and pass an appropriate array of language IDs.
     */
    public function findForTables(
        array $tables,
        int $languageId,
        int $workspaceId = 0,
        int $firstResult = 0,
        int $maxResults = 100,
        bool $filterFileMetaData = true
    ): array {
        $queryBuilder = $this->createQueryBuilderForTables($tables, $languageId, $workspaceId)
            ->setMaxResults($maxResults)
            ->setFirstResult($firstResult);

        if ($filterFileMetaData) {
            $this->addFilterByFileMetaDataClauses($queryBuilder);
        }

        $rows = $queryBuilder->executeQuery()->fetchAllAssociative();
        return $this->dataMapper->map(AltlessFileReference::class, $rows);
    }

    /**
     * Count file references without alternative text.
     * 
     * @param array<AltlessFileReferenceTable> $tables Array of table configurations to select file references by.
     * @param int $languageId The language UID to select file references for.
     * @param int $workspaceId The workspace ID to select file references for.
     * @param bool $filterFileMetaData If true, filter rows if they have alternative text in the file metadata.
     * 
     * @return int The count of file references without alternative text.
     */
    public function countForTables(
        array $tables,
        int $languageId,
        int $workspaceId = 0,
        bool $filterFileMetaData = true
    ): int {
        $queryBuilder = $this->createQueryBuilderForTables($tables, $languageId, $workspaceId);

        if ($filterFileMetaData) {
            $this->addFilterByFileMetaDataClauses($queryBuilder);
        }

        return (int)$queryBuilder->count('*')->executeQuery()->fetchOne();
    }

    /**
     * Create query builder instance to list file references for a given table.
     * 
     * @param array<AltlessFileReferenceTable> $tables Array of table configurations to select file references by.
     * @param int $languageId The language UID to select file references for.
     * @param int $workspaceId The workspace ID to select file references for.
     * 
     * @return QueryBuilder QueryBuilder instance used as a base for the query.
     */
    protected function createQueryBuilderForTables(
        array $tables,
        int $languageId,
        int $workspaceId = 0
    ): QueryBuilder {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');

        $queryBuilder->getRestrictions()->removeAll()->add(
            GeneralUtility::makeInstance(DeletedRestriction::class),
            GeneralUtility::makeInstance(WorkspaceRestriction::class, $workspaceId)
        );

        $queryBuilder
            ->select(
                'sys_file_reference.*',
            )->from('sys_file_reference')
            ->innerJoin(
                'sys_file_reference',
                'sys_file',
                'sys_file',
                $queryBuilder->expr()->eq('sys_file_reference.uid_local', $queryBuilder->quoteIdentifier('sys_file.uid'))
            )->where(
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->isNull('sys_file_reference.alternative'),
                    $queryBuilder->expr()->eq('sys_file_reference.alternative', $queryBuilder->createNamedParameter('', Connection::PARAM_STR))
                ),
                $queryBuilder->expr()->in('sys_file.extension', $queryBuilder->createNamedParameter($this->getImageFileExtensions(), Connection::PARAM_STR_ARRAY)),
                $queryBuilder->expr()->eq(
                    'sys_file_reference.' . $this->getLanguageField('sys_file_reference'),
                    $queryBuilder->createNamedParameter($languageId, Connection::PARAM_INT)
                )
            );

        $tableClauses = [];
        foreach ($tables as $table) {
            $queryBuilder->leftJoin(
                'sys_file_reference',
                $table->getTableName(),
                $table->getTableName(),
                (string)$queryBuilder->expr()->and(
                    $queryBuilder->expr()->eq('sys_file_reference.uid_foreign', $queryBuilder->quoteIdentifier($table->getTableName() . '.uid')),
                    $queryBuilder->expr()->eq('sys_file_reference.tablenames', $queryBuilder->createNamedParameter($table->getTableName(), Connection::PARAM_STR)),
                )
            );

            $authModeClauses = [];
            foreach ($table->getAuthModeColumns() as $columnName => $allowedValues) {
                $authModeClauses[] = $queryBuilder->expr()->in(
                    $table->getTableName() . '.' . $columnName,
                    $queryBuilder->createNamedParameter($allowedValues, Connection::PARAM_STR_ARRAY)
                );
            }

            $tableClauses[] = $queryBuilder->expr()->and(
                $queryBuilder->expr()->eq('sys_file_reference.tablenames', $queryBuilder->createNamedParameter($table->getTableName(), Connection::PARAM_STR)),
                $queryBuilder->expr()->in('sys_file_reference.fieldname', $queryBuilder->createNamedParameter($table->getFileColumnNames(), Connection::PARAM_STR_ARRAY)),
                $queryBuilder->expr()->in('sys_file_reference.pid', $queryBuilder->createNamedParameter($table->getPageIds(), Connection::PARAM_INT_ARRAY)),
                !empty($authModeClauses) ? $queryBuilder->expr()->and(...$authModeClauses) : null
            );
        }

        if (!empty($tableClauses)) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->or(...$tableClauses)
            );
        }

        return $queryBuilder;
    }

    /**
     * Add filter by file metadata clauses.
     * 
     * @param QueryBuilder $queryBuilder The query builder instance.
     */
    protected function addFilterByFileMetaDataClauses(QueryBuilder $queryBuilder): QueryBuilder
    {
        $queryBuilder->leftJoin(
            'sys_file_reference',
            'sys_file_metadata',
            'sys_file_metadata',
            $queryBuilder->expr()->and(
                $queryBuilder->expr()->eq('sys_file_reference.uid_local', $queryBuilder->quoteIdentifier('sys_file_metadata.file')),
                $queryBuilder->expr()->eq('sys_file_reference.' . $this->getLanguageField('sys_file_reference'), $queryBuilder->quoteIdentifier('sys_file_metadata.' . $this->getLanguageField('sys_file_metadata')))
            )
        )->andWhere(
            $queryBuilder->expr()->or(
                $queryBuilder->expr()->isNull('sys_file_metadata.alternative'),
                $queryBuilder->expr()->eq('sys_file_metadata.alternative', $queryBuilder->createNamedParameter('', Connection::PARAM_STR)),
            )
        );

        return $queryBuilder;
    }

    /**
     * Get image file extensions.
     * 
     * @return array<string> Array of image file extensions.
     */
    protected function getImageFileExtensions(): array
    {
        return explode(',', $GLOBALS['TYPO3_CONF_VARS']['GFX']['imagefile_ext'] ?? []);
    }

    /**
     * Get language field for a TCA table.
     * 
     * @param string $tableName The name of the table.
     */
    protected function getLanguageField(string $tableName): string
    {
        return $GLOBALS['TCA'][$tableName]['ctrl']['languageField'];
    }
}
