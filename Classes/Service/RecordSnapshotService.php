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

use TYPO3\CMS\Core\Database\ConnectionPool;

/** Creates stable fingerprints of persisted database records. */
final readonly class RecordSnapshotService
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

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * Without $columns the fingerprint covers every schema column — the strict
     * any-change-invalidates revision pin the alt-text demands rely on. With
     * $columns it covers exactly the given authorization scope.
     *
     * @param array<string, mixed> $record
     * @param list<string>|null $columns
     */
    public function fingerprint(string $table, array $record, ?array $columns = null): string
    {
        $columnNames = $columns ?? array_keys(
            $this->connectionPool
                ->getConnectionForTable($table)
                ->createSchemaManager()
                ->listTableColumns($table)
        );
        sort($columnNames);

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
