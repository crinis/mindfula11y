<?php

declare(strict_types=1);

namespace MindfulMarkup\MindfulA11y\RecordList;

use TYPO3\CMS\Backend\RecordList\DatabaseRecordList;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryHelper;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Backend\View\Event\ModifyDatabaseQueryForRecordListingEvent;

/**
 * Class AltLessRecordList.
 * 
 * This class is a custom implementation of the DatabaseRecordList class, which is used to
 * generate a list of records from the database. It is specifically designed to handle
 * records that do not have an alternative text for images. We try to avoid
 * any modifications to ease the upgrade process.
 * 
 * Class has to be created instead of using the event as it does not use aliases for the query.
 */
class AltLessRecordList extends DatabaseRecordList
{
    /**
     * Language UID.
     * 
     * @var int
     */
    protected int $languageId;

    /**
     * File reference fields.
     * 
     * @var array
     */
    protected array $fileReferenceFields = [];

    /**
     * Ignore file metadata.
     * 
     * @var bool
     */
    protected bool $ignoreFileMetadata = false;

    /**
     * Set to true to ignore alternative text in file metadata.
     * 
     * @param bool $ignoreFileMetadata Ignore file metadata
     * 
     * @return void
     */
    public function setIgnoreFileMetadata(bool $ignoreFileMetadata): void
    {
        $this->ignoreFileMetadata = $ignoreFileMetadata;
    }

    /**
     * Get if alternative text in file metadata should be ignored.
     * 
     * @return bool
     */
    public function getIgnoreFileMetadata(): bool
    {
        return $this->ignoreFileMetadata;
    }

    /**
     * Set the file reference fields.
     * 
     * @param array $fileReferenceFields File reference fields
     */
    public function setFileReferenceFields(array $fileReferenceFields): void
    {
        $this->fileReferenceFields = $fileReferenceFields;
    }

    /**
     * Get the file reference fields.
     * 
     * @return array
     */
    public function getFileReferenceFields(): array
    {
        return $this->fileReferenceFields;
    }

    /**
     * Set the language UID.
     * 
     * @param int $languageId Language UID
     */
    public function setLanguageId(int $languageId): void
    {
        $this->languageId = $languageId;
    }

    /**
     * Get the language UID.
     * 
     * @return int
     */
    public function getLanguageId(): int
    {
        return $this->languageId;
    }

    /**
     * Returns a QueryBuilder configured to select $fields from $table where the pid is restricted
     * depending on the current searchlevel setting.
     *
     * @param string $table Table name
     * @param string[] $fields Field list to select, * for all
     */
    public function getQueryBuilder(
        string $table,
        array $fields = ['*'],
        bool $addSorting = true,
        int $firstResult = 0,
        int $maxResult = 0
    ): QueryBuilder {
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $queryBuilder = $connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $this->getBackendUserAuthentication()->workspace));
        $queryBuilder
            ->select(...$fields)
            ->from($table, $table);

        // Former prepareQueryBuilder
        if ($maxResult > 0) {
            $queryBuilder->setMaxResults($maxResult);
        }
        if ($firstResult > 0) {
            $queryBuilder->setFirstResult($firstResult);
        }
        if ($addSorting) {
            if ($this->sortField && in_array($this->sortField, BackendUtility::getAllowedFieldsForTable($table, false))) {
                $queryBuilder->orderBy($this->sortField, $this->sortRev ? 'DESC' : 'ASC');
            } else {
                $orderBy = ($GLOBALS['TCA'][$table]['ctrl']['sortby'] ?? '') ?: $GLOBALS['TCA'][$table]['ctrl']['default_sortby'] ?? '';
                $orderBys = QueryHelper::parseOrderBy($orderBy);
                foreach ($orderBys as $orderBy) {
                    $queryBuilder->addOrderBy($orderBy[0], $orderBy[1]);
                }
            }
        }

        // Build the query constraints
        $queryBuilder = $this->addPageIdConstraint($table, $queryBuilder, $this->searchLevels);

        // Filtering on displayable pages (permissions):
        if ($table === 'pages' && $this->perms_clause) {
            $queryBuilder->andWhere($this->perms_clause);
        }

        $queryBuilder->innerJoin(
            $table,
            'sys_file_reference',
            'sys_file_reference',
            $queryBuilder->expr()->eq(
                'sys_file_reference.uid_foreign',
                $queryBuilder->quoteIdentifier($table . '.uid')
            )
        );

        // $allowedFileReferenceFields = array_intersect($this->getFileReferenceFields(), BackendUtility::getAllowedFieldsForTable($table));
        // if (
        //     $this->getBackendUserAuthentication()->check('tables_select', 'sys_file_reference')
        //     && in_array('alternative', BackendUtility::getAllowedFieldsForTable('sys_file_reference'))
        //     && !empty($allowedFileReferenceFields)
        // ) {
        //     /**
        //      * We have to use a subquery as the QueryBuilder created in class DatabaseRecordList does not specify an alias and
        //      * we do not want to override so many methods.
        //      */
        //     $sysFileReferenceQueryBuilder = $connectionPool->getQueryBuilderForTable('sys_file_reference')
        //         ->select('sys_file_reference.uid_foreign')
        //         ->from('sys_file_reference');

        //     $sysFileReferenceQueryBuilder->getRestrictions()
        //         ->removeAll()
        //         ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
        //         ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $this->getBackendUserAuthentication()->workspace));

        //     $sysFileReferenceQueryBuilder->innerJoin(
        //         'sys_file_reference',
        //         'sys_file',
        //         'sys_file',
        //         (string)$sysFileReferenceQueryBuilder->expr()->and(
        //             $sysFileReferenceQueryBuilder->expr()->eq(
        //                 'sys_file_reference.uid_local',
        //                 $queryBuilder->quoteIdentifier('sys_file.uid')
        //             ),
        //         )
        //     );

        //     /**
        //      * Only show if associated sys_file_metadata has no alternative text too by default.
        //      */
        //     if (!$this->getIgnoreFileMetadata()) {
        //         $sysFileReferenceQueryBuilder->innerJoin(
        //             'sys_file_reference',
        //             'sys_file_metadata',
        //             'sys_file_metadata',
        //             (string)$sysFileReferenceQueryBuilder->expr()->and(
        //                 $sysFileReferenceQueryBuilder->expr()->eq(
        //                     'sys_file_reference.uid_local',
        //                     $queryBuilder->quoteIdentifier('sys_file_metadata.file')
        //                 ),
        //             )
        //         );

        //         $sysFileReferenceQueryBuilder->andWhere(
        //             $sysFileReferenceQueryBuilder->expr()->eq(
        //                 'sys_file_metadata.alternative',
        //                 $queryBuilder->createNamedParameter('', Connection::PARAM_STR)
        //             )
        //         );
        //     }

        //     $sysFileReferenceQueryBuilder->andWhere(
        //         $sysFileReferenceQueryBuilder->expr()->eq(
        //             'sys_file_reference.alternative',
        //             $queryBuilder->createNamedParameter('', Connection::PARAM_STR)
        //         ),
        //         $sysFileReferenceQueryBuilder->expr()->in(
        //             'sys_file.extension',
        //             $queryBuilder->createNamedParameter(
        //                 $this->getImageFileExtensions(),
        //                 Connection::PARAM_STR_ARRAY
        //             )
        //         ),
        //         $sysFileReferenceQueryBuilder->expr()->eq(
        //             'sys_file_reference.tablenames',
        //             $queryBuilder->createNamedParameter($table, Connection::PARAM_STR)
        //         ),
        //         $sysFileReferenceQueryBuilder->expr()->in(
        //             'sys_file_reference.fieldname',
        //             $queryBuilder->createNamedParameter($allowedFileReferenceFields, Connection::PARAM_STR_ARRAY)
        //         )
        //     );

        //     $queryBuilder->andWhere(
        //         $queryBuilder->expr()->in(
        //             'uid',
        //             '(' . $sysFileReferenceQueryBuilder->getSQL() . ')'
        //         ),
        //     );
        // } else {
        //     /**
        //      * If the user does not have access to sys_file_reference, there is no point in
        //      * showing records that have no alternative text.
        //      */
        //     $queryBuilder->andWhere('1=0');
        // }

        if (isset($GLOBALS['TCA'][$table]['ctrl']['languageField'])) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq(
                    $table . '.' . $GLOBALS['TCA'][$table]['ctrl']['languageField'],
                    $queryBuilder->createNamedParameter($this->getLanguageId(), Connection::PARAM_INT)
                ),
                $queryBuilder->expr()->in(
                    $table . '.' . $GLOBALS['TCA'][$table]['ctrl']['languageField'],
                    $queryBuilder->quoteArrayBasedValueListToIntegerList(
                        array_keys($this->languagesAllowedForUser)
                    )
                )
            );
        }

        // @todo This event should contain the $addSorting value, so listener knows when to add ORDER-BY stuff.
        //       Additionally, having QueryBuilder order-by with `addSorting: false` should be deprecated along
        //       with the additional event flag.
        $event = new ModifyDatabaseQueryForRecordListingEvent(
            $queryBuilder,
            $table,
            $this->id,
            $fields,
            $firstResult,
            $maxResult,
            $this
        );
        $this->eventDispatcher->dispatch($event);
        return $event->getQueryBuilder();
    }

    /**
     * Get image file extensions.
     * 
     * @return array
     */
    protected function getImageFileExtensions(): array
    {
        return explode(',', $GLOBALS['TYPO3_CONF_VARS']['GFX']['imagefile_ext'] ?? []);
    }
}
