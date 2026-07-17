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

namespace MindfulMarkup\MindfulA11y\Domain\Repository;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\LimitToTablesRestrictionContainer;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\DataHandling\PlainDataResolver;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Doctrine\DBAL\Exception;
use MindfulMarkup\MindfulA11y\Domain\Model\AltlessFileReference;
use MindfulMarkup\MindfulA11y\Domain\Model\AltlessFileReferenceTable;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Extbase\Persistence\Generic\Mapper\DataMapper;

/**
 * Class AltlessFileReferenceRepository.
 *
 * Retrieves file references from the database that have no alternative text.
 * Queries are built with the core QueryBuilder; Extbase is involved only via
 * the injected DataMapper, which maps result rows to AltlessFileReference
 * models (the extension's single Extbase-mapped entity).
 */
final readonly class AltlessFileReferenceRepository
{
    private const COUNT_FILTER_CHUNK_SIZE = 500;

    public function __construct(
        private ConnectionPool $connectionPool,
        private DataMapper $dataMapper,
        private ResourceFactory $resourceFactory,
    ) {}

    /**
     * Find file references without alternative text.
     * 
     * Query file references and apply all sorts of filters to restrict sys_file_reference records from being shown if the
     * associated records are not accessible to the current user. This is to prevent unintended access to
     * records that the user should not see. On the other side of saving file references they can always
     * be modified via request forgery using e.g. AjaxDataHandler. We cannot prevent this.
     *
     * The FAL file permission filter is applied before paging, streaming
     * matches in chunks so the full result set is never hydrated at once.
     *
     * @param array<AltlessFileReferenceTable> $tables Array of table configurations to select file references by.
     * @param int $languageId The language UID to select file references for.
     * @param int $workspaceId The workspace ID to select file references for.
     * @param callable(\TYPO3\CMS\Core\Resource\FileInterface): bool $fileFilter File-access filter applied before paging.
     * @param int $firstResult The offset for the query.
     * @param int|null $maxResults The maximum number of results to return, or null for no limit.
     * @param bool $filterFileMetaData If true, filter rows if they have alternative text in the file metadata.
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
        int $workspaceId,
        callable $fileFilter,
        int $firstResult = 0,
        ?int $maxResults = 100,
        bool $filterFileMetaData = true
    ): array {
        $selectedReferenceUids = [];
        $accessibleOffset = 0;

        foreach ($this->streamAccessibleReferenceUids($tables, $languageId, $workspaceId, $filterFileMetaData, $fileFilter) as $referenceUid) {
            if ($accessibleOffset++ < $firstResult) {
                continue;
            }

            $selectedReferenceUids[] = $referenceUid;
            if ($maxResults !== null && count($selectedReferenceUids) >= $maxResults) {
                break;
            }
        }

        if (empty($selectedReferenceUids)) {
            return [];
        }

        return $this->dataMapper->map(
            AltlessFileReference::class,
            $this->fetchReferenceRowsForReferenceUids($selectedReferenceUids)
        );
    }

    /**
     * Count file references without alternative text.
     * 
     * Counts without hydrating all matches at once.
     *
     * @param array<AltlessFileReferenceTable> $tables Array of table configurations to select file references by.
     * @param int $languageId The language UID to select file references for.
     * @param int $workspaceId The workspace ID to select file references for.
     * @param callable(\TYPO3\CMS\Core\Resource\FileInterface): bool $fileFilter File-access filter applied before counting.
     * @param bool $filterFileMetaData If true, filter rows if they have alternative text in the file metadata.
     * 
     * @return int The count of file references without alternative text.
     */
    public function countForTables(
        array $tables,
        int $languageId,
        int $workspaceId,
        callable $fileFilter,
        bool $filterFileMetaData = true
    ): int {
        return iterator_count(
            $this->streamAccessibleReferenceUids($tables, $languageId, $workspaceId, $filterFileMetaData, $fileFilter)
        );
    }

    /**
     * Stream the UIDs of references whose file passes the FAL permission filter.
     *
     * Walks the matches in keyset-paginated chunks so the full result set is never hydrated
     * at once, yielding one reference UID per accessible file reference in ascending UID order.
     * Consumers that stop iterating early (e.g. once a page is filled) abandon the generator,
     * so no further chunks are fetched.
     *
     * Workspace-mutable state (the reference's alternative text and decorative
     * flag, the metadata fallback) must be judged on the row the editor's
     * workspace actually renders. The chunk query therefore applies only
     * structural filters to the live/new candidate rows; each chunk is then
     * resolved to its effective workspace rows, and the mutable filters run on
     * those. Yielded UIDs are the effective (possibly version) row UIDs.
     *
     * @param array<AltlessFileReferenceTable> $tables
     * @param callable(\TYPO3\CMS\Core\Resource\FileInterface): bool $fileFilter
     * @return \Generator<int>
     */
    private function streamAccessibleReferenceUids(
        array $tables,
        int $languageId,
        int $workspaceId,
        bool $filterFileMetaData,
        callable $fileFilter
    ): \Generator {
        $lastReferenceUid = 0;

        do {
            $referenceUids = $this->fetchCandidateReferenceUidChunk($tables, $languageId, $workspaceId, $lastReferenceUid);
            if (empty($referenceUids)) {
                break;
            }

            $lastReferenceUid = max($referenceUids);
            $resolvedReferenceUids = $workspaceId > 0 ? $this->resolveWorkspaceReferenceUids($referenceUids, $workspaceId) : $referenceUids;
            if (empty($resolvedReferenceUids)) {
                continue;
            }

            foreach ($this->fetchAltlessFileRowsForReferenceUids($workspaceId, $filterFileMetaData, $resolvedReferenceUids) as $fileRow) {
                $referenceUid = (int)$fileRow['reference_uid'];
                unset($fileRow['reference_uid']);

                if ($fileFilter($this->resourceFactory->getFileObject((int)$fileRow['uid'], $fileRow))) {
                    yield $referenceUid;
                }
            }
        } while (count($referenceUids) === self::COUNT_FILTER_CHUNK_SIZE);
    }

    /**
     * Fetch one keyset chunk of candidate reference UIDs: live rows plus
     * workspace-new rows, filtered only by workspace-immutable structure
     * (parent table/field/page coordinates, language, image extension).
     *
     * @param array<AltlessFileReferenceTable> $tables
     * @return array<int>
     */
    private function fetchCandidateReferenceUidChunk(
        array $tables,
        int $languageId,
        int $workspaceId,
        int $lastReferenceUid
    ): array {
        $queryBuilder = $this->createCandidateQueryBuilder($tables, $languageId, $workspaceId);

        return array_map(
            'intval',
            $queryBuilder
                ->select('sys_file_reference.uid')
                ->andWhere($queryBuilder->expr()->gt('sys_file_reference.uid', $queryBuilder->createNamedParameter($lastReferenceUid, Connection::PARAM_INT)))
                ->orderBy('sys_file_reference.uid', 'ASC')
                ->setMaxResults(self::COUNT_FILTER_CHUNK_SIZE)
                ->executeQuery()
                ->fetchFirstColumn()
        );
    }

    /**
     * @param array<int> $referenceUids
     * @return array<int>
     */
    private function resolveWorkspaceReferenceUids(array $referenceUids, int $workspaceId): array
    {
        $resolver = GeneralUtility::makeInstance(PlainDataResolver::class, 'sys_file_reference', $referenceUids);
        $resolver->setWorkspaceId($workspaceId);
        $resolver->setKeepDeletePlaceholder(false);
        $resolver->setKeepMovePlaceholder(true);
        $resolver->setKeepLiveIds(false);

        return array_map('intval', $resolver->get());
    }

    /**
     * Apply the workspace-mutable filters to already-resolved effective rows
     * and fetch their file rows for the FAL permission filter.
     *
     * The given UIDs are the exact rows the workspace renders (version rows
     * included), so the query must NOT carry a WorkspaceRestriction on
     * sys_file_reference — the default restriction admits only t3ver_oid=0
     * rows and would silently drop every plain workspace version. The
     * metadata join keeps its workspace scoping so at most the one live/new
     * metadata row per file and language matches.
     *
     * @param array<int> $referenceUids
     * @return array<array<string, mixed>>
     */
    private function fetchAltlessFileRowsForReferenceUids(
        int $workspaceId,
        bool $filterFileMetaData,
        array $referenceUids
    ): array {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $queryBuilder
            ->select('mindfula11y_sys_file.*')
            ->addSelect('sys_file_reference.uid AS reference_uid')
            ->from('sys_file_reference')
            ->innerJoin(
                'sys_file_reference',
                'sys_file',
                'mindfula11y_sys_file',
                $queryBuilder->expr()->eq('sys_file_reference.uid_local', $queryBuilder->quoteIdentifier('mindfula11y_sys_file.uid'))
            )->where(
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->isNull('sys_file_reference.alternative'),
                    $queryBuilder->expr()->eq('sys_file_reference.alternative', $queryBuilder->createNamedParameter('', Connection::PARAM_STR))
                ),
                $queryBuilder->expr()->eq(
                    'sys_file_reference.tx_mindfula11y_decorative',
                    $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)
                ),
                $queryBuilder->expr()->in('sys_file_reference.uid', $queryBuilder->createNamedParameter($referenceUids, Connection::PARAM_INT_ARRAY))
            );

        if ($filterFileMetaData) {
            $this->addFilterByFileMetaDataClauses($queryBuilder);
            $queryBuilder->getRestrictions()->add(
                GeneralUtility::makeInstance(LimitToTablesRestrictionContainer::class)
                    ->addForTables(
                        GeneralUtility::makeInstance(WorkspaceRestriction::class, $workspaceId),
                        ['mindfula11y_sys_file_metadata']
                    )
            );
        }

        return $queryBuilder
            ->orderBy('sys_file_reference.uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Fetch the reference rows for UIDs that already passed every filter —
     * effective workspace rows included, hence only the deleted restriction.
     *
     * @param array<int> $referenceUids
     * @return array<array<string, mixed>>
     */
    private function fetchReferenceRowsForReferenceUids(array $referenceUids): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        return $queryBuilder
            ->select('*')
            ->from('sys_file_reference')
            ->where($queryBuilder->expr()->in('uid', $queryBuilder->createNamedParameter($referenceUids, Connection::PARAM_INT_ARRAY)))
            ->orderBy('uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Create the candidate query builder: live/new reference rows matching the
     * workspace-immutable filters only.
     *
     * The alternative-text and decorative filters deliberately do NOT run
     * here: they are workspace-mutable and are applied to the resolved
     * effective rows in fetchAltlessFileRowsForReferenceUids(). The parent
     * authMode conditions do run here and thus judge the live parent row — a
     * parent whose restricting column changed only in the workspace keeps its
     * live visibility, matching what the module's other permission checks see.
     *
     * @param array<AltlessFileReferenceTable> $tables Array of table configurations to select file references by.
     * @param int $languageId The language UID to select file references for.
     * @param int $workspaceId The workspace ID to select file references for.
     *
     * @return QueryBuilder QueryBuilder instance used as a base for the query.
     */
    private function createCandidateQueryBuilder(
        array $tables,
        int $languageId,
        int $workspaceId
    ): QueryBuilder {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');

        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $workspaceId));

        $queryBuilder
            ->select(
                'sys_file_reference.*',
            )->from('sys_file_reference')
            ->innerJoin(
                'sys_file_reference',
                'sys_file',
                'mindfula11y_sys_file',
                $queryBuilder->expr()->eq('sys_file_reference.uid_local', $queryBuilder->quoteIdentifier('mindfula11y_sys_file.uid'))
            )->where(
                $queryBuilder->expr()->in('mindfula11y_sys_file.extension', $queryBuilder->createNamedParameter($this->getImageFileExtensions(), Connection::PARAM_STR_ARRAY)),
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
    private function addFilterByFileMetaDataClauses(QueryBuilder $queryBuilder): QueryBuilder
    {
        $queryBuilder->leftJoin(
            'sys_file_reference',
            'sys_file_metadata',
            'mindfula11y_sys_file_metadata',
            $queryBuilder->expr()->and(
                $queryBuilder->expr()->eq('sys_file_reference.uid_local', $queryBuilder->quoteIdentifier('mindfula11y_sys_file_metadata.file')),
                $queryBuilder->expr()->eq('sys_file_reference.' . $this->getLanguageField('sys_file_reference'), $queryBuilder->quoteIdentifier('mindfula11y_sys_file_metadata.' . $this->getLanguageField('sys_file_metadata')))
            )
        )->andWhere(
            $queryBuilder->expr()->or(
                $queryBuilder->expr()->isNull('mindfula11y_sys_file_metadata.alternative'),
                $queryBuilder->expr()->eq('mindfula11y_sys_file_metadata.alternative', $queryBuilder->createNamedParameter('', Connection::PARAM_STR)),
            )
        );

        return $queryBuilder;
    }

    /**
     * Get image file extensions.
     * 
     * @return array<string> Array of image file extensions.
     */
    private function getImageFileExtensions(): array
    {
        return explode(',', $GLOBALS['TYPO3_CONF_VARS']['GFX']['imagefile_ext'] ?? '');
    }

    /**
     * Get language field for a TCA table.
     * 
     * @param string $tableName The name of the table.
     */
    private function getLanguageField(string $tableName): string
    {
        return $GLOBALS['TCA'][$tableName]['ctrl']['languageField'];
    }
}
