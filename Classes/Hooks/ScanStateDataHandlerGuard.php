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

namespace MindfulMarkup\MindfulA11y\Hooks;

use MindfulMarkup\MindfulA11y\Service\ScanStateService;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\SysLog\Action\Database as SystemLogDatabaseAction;
use TYPO3\CMS\Core\SysLog\Error as SystemLogErrorClassification;
use TYPO3\CMS\Core\Utility\MathUtility;

/**
 * Keeps the page scan state (tx_mindfula11y_scanid / _scanupdated) owned by
 * ScanCreationService and by the live page row.
 *
 * Scans only run in the live workspace and are stored on the live row, and
 * the scan id is the authorization anchor for the stored scan. So: external
 * datamap writes are stripped, copies do not inherit the state, and
 * publishing a workspace version does not overwrite it with the copy the
 * version was created with.
 */
#[Autoconfigure(public: true)]
final class ScanStateDataHandlerGuard
{
    // A depth left raised by a throwing command only leaves a later strip
    // unlogged — it still strips.
    use DuplicatingCommandScopeTrait;

    /**
     * @var array<string>
     */
    private const SCAN_STATE_FIELDS = [
        ScanStateService::FIELD_SCAN_ID,
        ScanStateService::FIELD_SCAN_UPDATED,
    ];

    /**
     * EXT:workspaces' publish actions of the "version" command (both majors
     * accept either; the workspace module sends "publish", older API
     * consumers "swap").
     *
     * @var list<string>
     */
    private const PUBLISH_ACTIONS = ['publish', 'swap'];

    private static int $internalWriteDepth = 0;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    public static function withInternalWriteScope(callable $callback): mixed
    {
        self::$internalWriteDepth++;
        try {
            return $callback();
        } finally {
            self::$internalWriteDepth--;
        }
    }

    /**
     * @param mixed $incomingFieldArray
     */
    public function processDatamap_preProcessFieldArray(&$incomingFieldArray, string $table, int|string $id, DataHandler $dataHandler): void
    {
        if (!is_array($incomingFieldArray)) {
            return;
        }
        $this->stripScanStateFields($incomingFieldArray, $table, $id, $dataHandler);
    }

    /**
     * Second pass after DataHandler applied field defaults: TCAdefaults from
     * page/user TSconfig are merged in AFTER the pre-process hook, so a
     * TSconfig entry could otherwise seed a chosen scan id onto every new
     * page — and the scan id is the authorization anchor for existing scans.
     *
     * @param array<string, mixed> $fieldArray
     */
    public function processDatamap_postProcessFieldArray(string $status, string $table, int|string $id, array &$fieldArray, DataHandler $dataHandler): void
    {
        $this->stripScanStateFields($fieldArray, $table, $id, $dataHandler);
    }

    /**
     * Runs for every command before any processCmdmap() hook executes it —
     * in particular before EXT:workspaces' processCmdmap() performs the
     * publish swap (its processCmdmap_beforeStart() only expands the command
     * map; it touches no rows). Same order on TYPO3 13 and 14.
     */
    public function processCmdmap_preProcess(string $command, string $table, mixed $id, mixed $value, DataHandler $dataHandler): void
    {
        self::enterDuplicatingCommand($command);

        if ($table === 'pages'
            && $command === 'version'
            && is_array($value)
            && in_array($value['action'] ?? null, self::PUBLISH_ACTIONS, true)
        ) {
            $this->alignVersionScanStateWithLive((int)$id, (int)($value['swapWith'] ?? 0));
        }
    }

    public function processCmdmap_postProcess(string $command, string $table, mixed $id, mixed $value, DataHandler $dataHandler): void
    {
        self::leaveDuplicatingCommand($command);
    }

    /**
     * Publishing swaps every column of the version over the live row, except
     * the few EXT:workspaces keeps (unique/uuid fields). The version still
     * holds the scan state it was copied with at versioning time, so a scan
     * taken since would be replaced by a stale one. Writing the live values
     * onto the version first makes the swap a no-op for these columns.
     *
     * Only a genuine version of $liveUid is touched: the command is user
     * input, and the publish permission checks run later, inside the swap.
     */
    private function alignVersionScanStateWithLive(int $liveUid, int $versionUid): void
    {
        if ($liveUid <= 0 || $versionUid <= 0) {
            return;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();
        $liveRow = $queryBuilder
            ->select(...self::SCAN_STATE_FIELDS)
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($liveUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('t3ver_oid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->executeQuery()
            ->fetchAssociative();
        if (!is_array($liveRow)) {
            return;
        }

        $this->connectionPool->getConnectionForTable('pages')->update(
            'pages',
            $liveRow,
            ['uid' => $versionUid, 't3ver_oid' => $liveUid],
            [
                ScanStateService::FIELD_SCAN_ID => Connection::PARAM_STR,
                ScanStateService::FIELD_SCAN_UPDATED => Connection::PARAM_INT,
            ]
        );
    }

    /**
     * @param array<string, mixed> $fieldArray
     */
    private function stripScanStateFields(array &$fieldArray, string $table, int|string $id, DataHandler $dataHandler): void
    {
        if ($table !== 'pages' || self::$internalWriteDepth > 0) {
            return;
        }

        $submittedScanFields = array_intersect(self::SCAN_STATE_FIELDS, array_keys($fieldArray));
        if ($submittedScanFields === []) {
            return;
        }

        foreach ($submittedScanFields as $fieldName) {
            unset($fieldArray[$fieldName]);
        }

        // A copied page carries its source's scan state in the duplicated row:
        // stripped all the same, but nobody attempted to write it.
        if (self::isDuplicating() && !MathUtility::canBeInterpretedAsInteger($id)) {
            return;
        }

        $dataHandler->log(
            $table,
            (int)$id,
            SystemLogDatabaseAction::UPDATE,
            null,
            SystemLogErrorClassification::MESSAGE,
            'Attempt to modify internal Mindful A11y scanner state fields was blocked',
            null,
            ['fields' => implode(', ', $submittedScanFields)]
        );
    }
}
