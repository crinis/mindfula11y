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

use MindfulMarkup\MindfulA11y\Tca\TranslationFields;
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
use TYPO3\CMS\Core\Versioning\VersionState;
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
    private const CHUNK_SIZE = 500;

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
     * @param int $maxResults The maximum number of results to return.
     * @param bool $filterFileMetaData If true, filter rows if they have alternative text in the file metadata.
     * @param bool $includeDecorative If true, include references marked as decorative.
     * @param bool $includeAllReferences If true, include references that already have reference-level alternative text.
     * 
     * @return array<AltlessFileReference> An array of file reference rows.
     * 
     * @throws Exception If there is an error executing the query.
     */
    public function findForTables(
        array $tables,
        int $languageId,
        int $workspaceId,
        callable $fileFilter,
        int $firstResult = 0,
        int $maxResults = 100,
        bool $filterFileMetaData = true,
        bool $includeDecorative = false,
        bool $includeAllReferences = false,
    ): array {
        $selectedReferenceUids = [];
        $accessibleOffset = 0;

        foreach ($this->streamAccessibleReferenceUids($tables, $languageId, $workspaceId, $filterFileMetaData, $includeDecorative, $includeAllReferences, $fileFilter) as $referenceUid) {
            if ($accessibleOffset++ < $firstResult) {
                continue;
            }

            $selectedReferenceUids[] = $referenceUid;
            if (count($selectedReferenceUids) >= $maxResults) {
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
     * One page of file references without alternative text plus the total.
     *
     * Consumes the permission-filtered stream exactly once: every accessible
     * reference is counted (its file hydrated for the FAL check anyway), but
     * only the requested page is mapped to models. The page is clamped to the
     * last one the total allows before the offset is derived, so an
     * out-of-range or extreme page yields the last page's slice instead of an
     * empty one or an overflowing offset.
     *
     * @param array<AltlessFileReferenceTable> $tables Array of table configurations to select file references by.
     * @param callable(\TYPO3\CMS\Core\Resource\FileInterface): bool $fileFilter File-access filter applied before paging.
     * @param int $currentPage 1-based page number, clamped to [1, last page].
     * @param int<1, max> $itemsPerPage
     * @return array{items: list<AltlessFileReference>, total: int}
     *
     * @throws Exception If there is an error executing the query.
     */
    public function findPageForTables(
        array $tables,
        int $languageId,
        int $workspaceId,
        callable $fileFilter,
        int $currentPage,
        int $itemsPerPage,
        bool $filterFileMetaData = true,
        bool $includeDecorative = false,
        bool $includeAllReferences = false,
    ): array {
        $referenceUids = iterator_to_array(
            $this->streamAccessibleReferenceUids($tables, $languageId, $workspaceId, $filterFileMetaData, $includeDecorative, $includeAllReferences, $fileFilter),
            false
        );
        $total = count($referenceUids);
        $itemsPerPage = max(1, $itemsPerPage);
        $lastPage = max(1, (int)ceil($total / $itemsPerPage));
        $currentPage = min(max(1, $currentPage), $lastPage);
        $selectedReferenceUids = array_slice($referenceUids, ($currentPage - 1) * $itemsPerPage, $itemsPerPage);

        return [
            'items' => $selectedReferenceUids === []
                ? []
                : array_values($this->dataMapper->map(
                    AltlessFileReference::class,
                    $this->fetchReferenceRowsForReferenceUids($selectedReferenceUids)
                )),
            'total' => $total,
        ];
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
     * @param bool $includeDecorative If true, include references marked as decorative.
     * @param bool $includeAllReferences If true, include references that already have reference-level alternative text.
     * 
     * @return int The count of file references without alternative text.
     */
    public function countForTables(
        array $tables,
        int $languageId,
        int $workspaceId,
        callable $fileFilter,
        bool $filterFileMetaData = true,
        bool $includeDecorative = false,
        bool $includeAllReferences = false,
    ): int {
        return iterator_count(
            $this->streamAccessibleReferenceUids($tables, $languageId, $workspaceId, $filterFileMetaData, $includeDecorative, $includeAllReferences, $fileFilter)
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
        bool $includeDecorative,
        bool $includeAllReferences,
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

            foreach ($this->fetchFileRowsForReferenceUids($resolvedReferenceUids, $workspaceId, $filterFileMetaData, $includeDecorative, $includeAllReferences) as $fileRow) {
                $referenceUid = (int)$fileRow['reference_uid'];
                unset($fileRow['reference_uid']);

                if ($fileFilter($this->resourceFactory->getFileObject((int)$fileRow['uid'], $fileRow))) {
                    yield $referenceUid;
                }
            }
        } while (count($referenceUids) === self::CHUNK_SIZE);
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
                ->setMaxResults(self::CHUNK_SIZE)
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
     * metadata row per file and language matches; in a workspace the
     * alternative-text verdict on that row is then taken from its version
     * row (metadata is workspace-mutable state too), so the SQL predicate
     * only applies in the live workspace.
     *
     * @param array<int> $referenceUids
     * @return array<array<string, mixed>>
     */
    private function fetchFileRowsForReferenceUids(
        array $referenceUids,
        int $workspaceId,
        bool $filterFileMetaData,
        bool $includeDecorative,
        bool $includeAllReferences
    ): array {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $mutableClauses = [
            $queryBuilder->expr()->in('sys_file_reference.uid', $queryBuilder->createNamedParameter($referenceUids, Connection::PARAM_INT_ARRAY)),
        ];
        if (!$includeAllReferences) {
            $mutableClauses[] = $queryBuilder->expr()->or(
                $queryBuilder->expr()->isNull('sys_file_reference.alternative'),
                $queryBuilder->expr()->eq('sys_file_reference.alternative', $queryBuilder->createNamedParameter('', Connection::PARAM_STR))
            );
        }
        if (!$includeDecorative) {
            $mutableClauses[] = $queryBuilder->expr()->eq(
                'sys_file_reference.tx_mindfula11y_decorative',
                $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)
            );
        }

        $queryBuilder
            ->select('mindfula11y_sys_file.*')
            ->addSelect('sys_file_reference.uid AS reference_uid')
            ->from('sys_file_reference')
            ->innerJoin(
                'sys_file_reference',
                'sys_file',
                'mindfula11y_sys_file',
                $queryBuilder->expr()->eq('sys_file_reference.uid_local', $queryBuilder->quoteIdentifier('mindfula11y_sys_file.uid'))
            )->where(...$mutableClauses);

        if ($filterFileMetaData) {
            // The restriction MUST be registered before the join is created:
            // QueryBuilder materializes joined-table restrictions into the ON
            // clause at join() time, so a container added afterwards silently
            // never applies and every workspace's metadata rows would join.
            $queryBuilder->getRestrictions()->add(
                GeneralUtility::makeInstance(LimitToTablesRestrictionContainer::class)
                    ->addForTables(
                        GeneralUtility::makeInstance(WorkspaceRestriction::class, $workspaceId),
                        ['mindfula11y_sys_file_metadata']
                    )
            );
            $this->addFileMetaDataJoin($queryBuilder);
            // Branch on "is this a real workspace", NOT on "is this live":
            // BackendUserAuthentication::$workspace is -99 for a user who may
            // neither work live nor reach any workspace, and testing === 0 here
            // would leave such a user with no metadata filter at all. Must stay
            // in step with the version-resolution condition below.
            if ($workspaceId > 0) {
                // The joined row is the live (or workspace-new) one; its
                // version row decides in a workspace. Select its identity and
                // filter after resolving the versions in PHP.
                $queryBuilder->addSelect(
                    'mindfula11y_sys_file_metadata.uid AS metadata_uid',
                    'mindfula11y_sys_file_metadata.alternative AS metadata_alternative'
                );
            } else {
                $queryBuilder->andWhere($this->createMetaDataAlternativeMissingClause($queryBuilder));
            }
        }

        $fileRows = $queryBuilder
            ->orderBy('sys_file_reference.uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        if ($filterFileMetaData && $workspaceId > 0) {
            $fileRows = $this->filterRowsByEffectiveMetaDataAlternative($fileRows, $workspaceId);
        }

        return $fileRows;
    }

    /**
     * Keep only rows whose *effective* metadata carries no alternative text.
     *
     * The joined metadata row is the live/new one; an edit in a workspace
     * lives in a version row (t3ver_oid = live uid) that the join's
     * WorkspaceRestriction rightly excludes. Resolve those versions in one
     * query and judge the alternative on them — a draft that adds alternative
     * text hides the reference, a draft that clears it (or deletes the
     * metadata) surfaces it. Strips the metadata_* helper columns so callers
     * receive plain sys_file rows.
     *
     * @param array<array<string, mixed>> $fileRows
     * @return array<array<string, mixed>>
     */
    private function filterRowsByEffectiveMetaDataAlternative(array $fileRows, int $workspaceId): array
    {
        $metadataUids = [];
        foreach ($fileRows as $fileRow) {
            if ((int)($fileRow['metadata_uid'] ?? 0) > 0) {
                $metadataUids[] = (int)$fileRow['metadata_uid'];
            }
        }

        $versionRows = $this->fetchMetaDataVersionRows($metadataUids, $workspaceId);

        $filteredRows = [];
        foreach ($fileRows as $fileRow) {
            $metadataUid = (int)($fileRow['metadata_uid'] ?? 0);
            $alternative = $fileRow['metadata_alternative'] ?? null;
            unset($fileRow['metadata_uid'], $fileRow['metadata_alternative']);

            $alternative = $this->applyMetaDataVersion($alternative, $versionRows[$metadataUid] ?? null);

            if ($alternative === null || $alternative === '') {
                $filteredRows[] = $fileRow;
            }
        }

        return $filteredRows;
    }

    /**
     * The workspace version rows for the given live metadata uids, keyed by the
     * live uid they version.
     *
     * The join in the listing query is workspace-RESTRICTED, so it supplies the
     * live (or workspace-new) row and never the version of an edited one; those
     * are resolved here. Batched because the listing needs a whole page of rows
     * at once — the single-file caller passes a one-element list rather than
     * growing a second query shape.
     *
     * @param list<int> $liveUids
     * @return array<int, array<string, mixed>>
     */
    private function fetchMetaDataVersionRows(array $liveUids, int $workspaceId): array
    {
        if (empty($liveUids)) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_metadata');
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $result = $queryBuilder
            ->select('t3ver_oid', 't3ver_state', 'alternative')
            ->from('sys_file_metadata')
            ->where(
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter($workspaceId, Connection::PARAM_INT)),
                $queryBuilder->expr()->in('t3ver_oid', $queryBuilder->createNamedParameter(array_values(array_unique($liveUids)), Connection::PARAM_INT_ARRAY))
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $versionRows = [];
        foreach ($result as $versionRow) {
            $versionRows[(int)$versionRow['t3ver_oid']] = $versionRow;
        }

        return $versionRows;
    }

    /**
     * Apply a metadata version row on top of the live alternative.
     *
     * The single definition of "which alternative text does this file's
     * metadata effectively carry", shared by the listing filter and by
     * findEffectiveMetaDataAlternative() so the module cannot list a reference
     * as missing while the rendered row advertises the live text as inherited.
     * A delete placeholder means the draft has no metadata row at all, so it
     * contributes no fallback rather than the value it still stores.
     *
     * @param array<string, mixed>|null $versionRow
     */
    private function applyMetaDataVersion(?string $liveAlternative, ?array $versionRow): ?string
    {
        if ($versionRow === null) {
            return $liveAlternative;
        }

        if (VersionState::tryFrom((int)$versionRow['t3ver_state']) === VersionState::DELETE_PLACEHOLDER) {
            return null;
        }

        return $versionRow['alternative'];
    }

    /**
     * The alternative text a file's metadata effectively carries in the given
     * workspace, or null when it carries none.
     *
     * The rendering path needs the same answer the listing filter computes, but
     * for a single file: FAL resolves metadata through WorkspaceRestriction,
     * which returns the LIVE row for a file whose metadata was edited in a
     * workspace (the version row has t3ver_oid > 0 and is excluded), and core's
     * overlay listener for FAL metadata is frontend-only. Reading the file
     * property directly in a backend module therefore reports live text as the
     * inherited alternative even when the draft cleared or deleted it.
     *
     * Answers for the metadata row the LISTING would have judged — same
     * language, same workspace restriction — so a reference can never be
     * reported as missing while the row beside it advertises inherited text.
     *
     * @param int $languageId The reference's language; metadata is matched on it.
     */
    public function findEffectiveMetaDataAlternative(int $fileUid, int $workspaceId, int $languageId = 0): ?string
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_metadata');
        // The same restriction set the listing's metadata join carries, so both
        // sides pick the same row: WorkspaceRestriction admits the live row AND
        // a workspace-NEW one (t3ver_oid = 0, t3ver_wsid = W), which a hand-rolled
        // "t3ver_wsid = 0" would have excluded — leaving a file whose only
        // metadata was created inside the workspace with no inherited text.
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, max($workspaceId, 0)));

        $joinedRow = $queryBuilder
            ->select('uid', 'alternative')
            ->from('sys_file_metadata')
            ->where(
                $queryBuilder->expr()->eq('file', $queryBuilder->createNamedParameter($fileUid, Connection::PARAM_INT)),
                // Matches the join's language predicate rather than FAL's
                // (0, -1): the listing decides "missing" from the metadata row
                // of the reference's OWN language, so the inherited text shown
                // beside that verdict has to come from the same row.
                $queryBuilder->expr()->eq(
                    TranslationFields::languageFieldName('sys_file_metadata'),
                    $queryBuilder->createNamedParameter($languageId, Connection::PARAM_INT)
                ),
            )
            ->orderBy('uid', 'ASC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if ($joinedRow === false) {
            return null;
        }

        $alternative = $joinedRow['alternative'] === null ? null : (string)$joinedRow['alternative'];

        if ($workspaceId <= 0) {
            return $alternative;
        }

        $joinedUid = (int)$joinedRow['uid'];

        return $this->applyMetaDataVersion(
            $alternative,
            $this->fetchMetaDataVersionRows([$joinedUid], $workspaceId)[$joinedUid] ?? null,
        );
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
     * effective rows in fetchFileRowsForReferenceUids(). The parent
     * authMode conditions do run here and thus judge the live parent row — a
     * parent whose restricting column changed only in the workspace keeps its
     * live visibility. That is deliberate: core itself never gates listing
     * visibility on authMode (the filter is this module's own hardening), and
     * the per-record edit controls still judge the workspace row through
     * checkRecordEditAccess().
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

        // No select() here: the chunk query sets its own column list.
        $queryBuilder
            ->from('sys_file_reference')
            ->innerJoin(
                'sys_file_reference',
                'sys_file',
                'mindfula11y_sys_file',
                $queryBuilder->expr()->eq('sys_file_reference.uid_local', $queryBuilder->quoteIdentifier('mindfula11y_sys_file.uid'))
            )->where(
                $queryBuilder->expr()->in('mindfula11y_sys_file.extension', $queryBuilder->createNamedParameter($this->getImageFileExtensions(), Connection::PARAM_STR_ARRAY)),
                $queryBuilder->expr()->eq(
                    'sys_file_reference.' . TranslationFields::languageFieldName('sys_file_reference'),
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
                $columnReference = $table->getTableName() . '.' . $columnName;
                $valueClause = $queryBuilder->expr()->in(
                    $columnReference,
                    $queryBuilder->createNamedParameter($allowedValues, Connection::PARAM_STR_ARRAY)
                );

                // checkAuthMode() casts the stored value to string before its
                // "blank is always allowed" short-circuit, so NULL is allowed
                // there — but SQL NULL matches no IN () list, not even IN ('').
                // Without this branch a nullable authMode column would hide rows
                // the user is in fact allowed to see. The parent-existence
                // conjunct below keeps this branch honest: it fires only for a
                // real parent row storing NULL, never for an absent join.
                if (in_array('', $allowedValues, true)) {
                    $valueClause = $queryBuilder->expr()->or(
                        $valueClause,
                        $queryBuilder->expr()->isNull($columnReference),
                    );
                }

                $authModeClauses[] = $valueClause;
            }

            $tableClauses[] = $queryBuilder->expr()->and(
                // A reference is authorized THROUGH its parent row, so that row
                // must actually be visible. leftJoin() materializes the deleted
                // and workspace restrictions into the ON clause, so a parent the
                // user may not see yields all-NULL parent columns rather than
                // dropping the reference — and every predicate below that reads
                // a parent column would then be evaluated against NULL. Without
                // this conjunct the authMode IS NULL branch accepts a reference
                // whose parent was deleted, regardless of the value that parent
                // used to store; a table declaring no authMode column at all
                // would carry no parent predicate whatsoever.
                $queryBuilder->expr()->isNotNull($table->getTableName() . '.uid'),
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
        } else {
            // Defense in depth: the table clauses ARE the authorization scope
            // (parent table, field, page ids, authMode). Without any, nothing
            // may match — never fall through to an unscoped query.
            $queryBuilder->andWhere('1 = 0');
        }

        return $queryBuilder;
    }

    /**
     * Join the file metadata row matching the reference's file and language.
     *
     * @param QueryBuilder $queryBuilder The query builder instance.
     */
    private function addFileMetaDataJoin(QueryBuilder $queryBuilder): void
    {
        $queryBuilder->leftJoin(
            'sys_file_reference',
            'sys_file_metadata',
            'mindfula11y_sys_file_metadata',
            $queryBuilder->expr()->and(
                $queryBuilder->expr()->eq('sys_file_reference.uid_local', $queryBuilder->quoteIdentifier('mindfula11y_sys_file_metadata.file')),
                $queryBuilder->expr()->eq('sys_file_reference.' . TranslationFields::languageFieldName('sys_file_reference'), $queryBuilder->quoteIdentifier('mindfula11y_sys_file_metadata.' . TranslationFields::languageFieldName('sys_file_metadata')))
            )
        );
    }

    /**
     * The "joined metadata row carries no alternative text" predicate.
     */
    private function createMetaDataAlternativeMissingClause(QueryBuilder $queryBuilder): string
    {
        return (string)$queryBuilder->expr()->or(
            $queryBuilder->expr()->isNull('mindfula11y_sys_file_metadata.alternative'),
            $queryBuilder->expr()->eq('mindfula11y_sys_file_metadata.alternative', $queryBuilder->createNamedParameter('', Connection::PARAM_STR)),
        );
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
}
