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

/** Creates stable fingerprints of complete persisted database records. */
final readonly class RecordSnapshotService
{
    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /** @param array<string, mixed> $record */
    public function fingerprint(string $table, array $record): string
    {
        $columns = $this->connectionPool
            ->getConnectionForTable($table)
            ->createSchemaManager()
            ->listTableColumns($table);
        ksort($columns);

        $persistedRecord = [];
        foreach (array_keys($columns) as $column) {
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
     */
    public function matches(string $snapshot, string $table, array $record): bool
    {
        return hash_equals($snapshot, $this->fingerprint($table, $record));
    }
}
