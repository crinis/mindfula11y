<?php
declare(strict_types=1);

/*
 * Mindful A11y extension for TYPO3 integrating accessibility tools into the backend.
 * Copyright (C) 2026  Mindful Markup, Felix Spittel
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 */

namespace MindfulMarkup\MindfulA11y\Service;

use Doctrine\DBAL\Schema\Column;
use TYPO3\CMS\Core\Database\ConnectionPool;

/** Creates stable fingerprints of persisted database records. */
final class RecordSnapshotService
{
    /**
     * Columns of the `pages` row that the structure-analysis ticket and
     * scan-demand paths actually consume when authorizing and redeeming the
     * capability. Scoped fingerprints over this list bind a capability to the
     * row state those checks ran against — a consistency guarantee, not an
     * access control: durable denial always comes from the live checks
     * re-evaluated at redemption.
     *
     * Deliberately absent, with the covering mechanism:
     *  - `slug`: never read from the row — the preview URL is rebuilt fresh
     *    at redemption and compared against the signed target.
     *  - `fe_group`, `extendToSubpages`: consumed only via fresh
     *    BackendUtility::readPageAccess() lookups inside PreviewUriBuilder's
     *    access simulation, which feeds the same signed-target comparison.
     *  - `deleted`: every load path filters deleted rows; authorization fails
     *    on the missing record before any snapshot comparison runs.
     *  - bookkeeping (`tstamp`, `SYS_LASTCHANGED`, `l10n_diffsource`,
     *    `tx_mindfula11y_scanid`, `tx_mindfula11y_scanupdated`, unknown
     *    third-party columns): no check reads them, and pinning them lets the
     *    scan auto-create and core's SYS_LASTCHANGED render write kill
     *    in-flight capabilities.
     */
    public const PAGES_SCOPE_COLUMNS = [
        // Identity and location: isInWebMount(), perms resolution, PreviewUriBuilder.
        'uid',
        'pid',
        // Translation identity: localized lookup, PreviewUriBuilder, checkRecordEditAccess().
        'sys_language_uid',
        'l10n_parent',
        // Workspace version identity: workspaceOL(), WorkspaceRestriction, PreviewUriBuilder.
        't3ver_oid',
        't3ver_wsid',
        't3ver_state',
        // doesUserHaveAccess(PAGE_SHOW) / checkRecordEditAccess().
        'perms_userid',
        'perms_groupid',
        'perms_user',
        'perms_group',
        'perms_everybody',
        // PreviewUriBuilder::isPreviewable().
        'doktype',
        // PermissionService::checkRecordEditAccess() (scan flow).
        'editlock',
        // PagePreviewService::isPageVisible() (scan flow).
        'hidden',
        'starttime',
        'endtime',
    ];

    /**
     * Columns the full-row fingerprint leaves out, per table: values core
     * rewrites without anyone editing the record, which would otherwise kill
     * every outstanding demand for it.
     *
     *  - `pages.SYS_LASTCHANGED`: the first uncached frontend render after a
     *    content change writes it with a plain connection update (v13
     *    TypoScriptFrontendController::setSysLastChanged(), v14
     *    RequestHandler::updateSysLastChangedInPageRecord()) — including the
     *    extension's own uncached structure-analysis render, so a Generate
     *    button for a `pages.media` image would fail right after the editor
     *    looked at the page's structure. No check reads it (see
     *    PAGES_SCOPE_COLUMNS for the same reasoning), and every real edit
     *    still changes `tstamp` alongside the edited columns.
     */
    private const FULL_ROW_EXCLUDED_COLUMNS = [
        'pages' => ['SYS_LASTCHANGED'],
    ];

    /**
     * Wire format of a fingerprint produced by fingerprint(): lowercase hex
     * SHA-256.
     *
     * Every trust boundary validates the shape of an incoming fingerprint
     * before trusting it, and those checks stay separate on purpose (each
     * boundary fails closed on its own). What they must not do is disagree
     * about the format: changing the hash here would otherwise leave six
     * validators rejecting every legitimate demand.
     */
    public const FINGERPRINT_PATTERN = '/^[a-f0-9]{64}$/';

    /**
     * Sorted schema column names per table. Doctrine does not cache
     * listTableColumns(), and one alt-text listing fingerprints several
     * times per row for up to a page of rows — the schema does not change
     * within a request, so it is read once per table.
     *
     * @var array<string, list<string>>
     */
    private array $schemaColumnNames = [];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * Without $columns the fingerprint covers every schema column but the
     * FULL_ROW_EXCLUDED_COLUMNS — the strict any-change-invalidates revision
     * pin the alt-text demands rely on. With $columns it covers exactly the
     * given authorization scope.
     *
     * @param array<string, mixed> $record
     * @param list<string>|null $columns
     */
    public function fingerprint(string $table, array $record, ?array $columns = null): string
    {
        if ($columns !== null) {
            $columnNames = $columns;
            sort($columnNames);
        } else {
            $columnNames = $this->getSchemaColumnNames($table);
        }

        $persistedRecord = [];
        foreach ($columnNames as $column) {
            // Fail closed when callers supplied a partial row: missing columns
            // remain distinguishable from persisted NULL values.
            $persistedRecord[$column] = array_key_exists($column, $record)
                ? $record[$column]
                : ['__mindfula11y_missing_column__'];
        }

        return hash('sha256', json_encode($persistedRecord, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * The table's column names in their schema case — the keys rows carry —
     * without the FULL_ROW_EXCLUDED_COLUMNS.
     *
     * Not the keys of listTableColumns(): DBAL lowercases those (from the
     * quoted name), so mixed-case columns such as `CType` or `colPos` would
     * never match the row and always hash as the missing-column sentinel,
     * leaving changes to them unpinned. Column::getName() is what core's own
     * schema information uses for the same reason (TYPO3 13 and 14).
     *
     * @return list<string>
     */
    private function getSchemaColumnNames(string $table): array
    {
        if (!isset($this->schemaColumnNames[$table])) {
            $excludedColumns = array_map(strtolower(...), self::FULL_ROW_EXCLUDED_COLUMNS[$table] ?? []);
            $columnNames = array_values(array_filter(
                array_map(
                    // getName() is deprecated since DBAL 4.4, but its successor getObjectName() only exists
                    // from DBAL 4.3, while TYPO3 13.4.18 ships 4.2 (core's own ColumnInfo uses getName() too).
                    static fn(Column $column): string => $column->getName(),
                    $this->connectionPool
                        ->getConnectionForTable($table)
                        ->createSchemaManager()
                        ->listTableColumns($table)
                ),
                // Case-insensitive like SQL identifiers, whatever case the platform reports.
                static fn(string $column): bool => !in_array(strtolower($column), $excludedColumns, true),
            ));
            sort($columnNames);
            $this->schemaColumnNames[$table] = $columnNames;
        }

        return $this->schemaColumnNames[$table];
    }

    /**
     * Whether a previously issued snapshot still matches the record's current
     * persisted state. Timing-safe: snapshots authorize signed demands, so the
     * comparison must not leak how much of the fingerprint matched.
     *
     * @param array<string, mixed> $record
     * @param list<string>|null $columns Must be the same scope the snapshot was issued with.
     */
    public function matches(string $snapshot, string $table, array $record, ?array $columns = null): bool
    {
        return hash_equals($snapshot, $this->fingerprint($table, $record, $columns));
    }
}
