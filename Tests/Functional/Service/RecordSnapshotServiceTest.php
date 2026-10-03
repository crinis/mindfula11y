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

namespace MindfulMarkup\MindfulA11y\Tests\Functional\Service;

use MindfulMarkup\MindfulA11y\Service\RecordSnapshotService;
use MindfulMarkup\MindfulA11y\Tests\Functional\AbstractAuthorizationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use TYPO3\CMS\Backend\Utility\BackendUtility;

/**
 * Scoped fingerprints bind a capability to exactly the columns its
 * authorization path consumes; the full-row mode stays the strict
 * any-change-invalidates revision pin used by the alt-text demands.
 */
final class RecordSnapshotServiceTest extends AbstractAuthorizationTestCase
{
    private function subject(): RecordSnapshotService
    {
        return $this->get(RecordSnapshotService::class);
    }

    /** @return array<string, mixed> */
    private function pageRecord(): array
    {
        $record = BackendUtility::getRecord('pages', 10);
        self::assertIsArray($record);

        return $record;
    }

    public function testScopedFingerprintIgnoresColumnsOutsideTheScope(): void
    {
        $record = $this->pageRecord();
        $before = $this->subject()->fingerprint('pages', $record, RecordSnapshotService::PAGES_SCOPE_COLUMNS);

        $record['tstamp'] = (int)$record['tstamp'] + 100;
        $record['SYS_LASTCHANGED'] = time();
        $record['tx_mindfula11y_scanid'] = '186';
        $record['title'] = 'Changed';

        self::assertSame(
            $before,
            $this->subject()->fingerprint('pages', $record, RecordSnapshotService::PAGES_SCOPE_COLUMNS),
        );
    }

    public function testScopedFingerprintTracksScopedColumns(): void
    {
        $record = $this->pageRecord();
        $before = $this->subject()->fingerprint('pages', $record, RecordSnapshotService::PAGES_SCOPE_COLUMNS);

        $record['hidden'] = 1;

        self::assertNotSame(
            $before,
            $this->subject()->fingerprint('pages', $record, RecordSnapshotService::PAGES_SCOPE_COLUMNS),
        );
    }

    public function testScopedFingerprintFailsClosedOnMissingScopedColumn(): void
    {
        $record = $this->pageRecord();
        $record['editlock'] = null;
        $nullPresent = $this->subject()->fingerprint('pages', $record, RecordSnapshotService::PAGES_SCOPE_COLUMNS);

        unset($record['editlock']);

        self::assertNotSame(
            $nullPresent,
            $this->subject()->fingerprint('pages', $record, RecordSnapshotService::PAGES_SCOPE_COLUMNS),
        );
    }

    public function testFullRowFingerprintTracksEveryColumn(): void
    {
        $record = BackendUtility::getRecord('tt_content', 100);
        self::assertIsArray($record);
        $before = $this->subject()->fingerprint('tt_content', $record);

        $record['tstamp'] = (int)$record['tstamp'] + 100;

        self::assertNotSame($before, $this->subject()->fingerprint('tt_content', $record));
    }

    /**
     * The exception: page columns written without anyone editing the page —
     * core's frontend render (SYS_LASTCHANGED) and the extension's own scan
     * bookkeeping, which DataHandler stores along with tstamp and
     * l10n_diffsource.
     *
     * @return array<string, array{string, int|string}>
     */
    public static function pageBookkeepingColumnProvider(): array
    {
        return [
            'SYS_LASTCHANGED' => ['SYS_LASTCHANGED', time() + 100],
            'tstamp' => ['tstamp', time() + 100],
            'l10n_diffsource' => ['l10n_diffsource', '{"tx_mindfula11y_scanid":""}'],
            'tx_mindfula11y_scanid' => ['tx_mindfula11y_scanid', 'scan-1'],
            'tx_mindfula11y_scanupdated' => ['tx_mindfula11y_scanupdated', time()],
        ];
    }

    #[DataProvider('pageBookkeepingColumnProvider')]
    public function testFullRowFingerprintIgnoresPageBookkeepingColumns(string $column, int|string $changedValue): void
    {
        $record = $this->pageRecord();
        self::assertArrayHasKey($column, $record, 'fixture guard: the row carries the column');
        $before = $this->subject()->fingerprint('pages', $record);

        $record[$column] = $changedValue;

        self::assertSame($before, $this->subject()->fingerprint('pages', $record));
    }

    /**
     * Every column an editor changes stays pinned on pages, too.
     *
     * @return array<string, array{string, int|string}>
     */
    public static function pageContentColumnProvider(): array
    {
        return [
            'title' => ['title', 'Changed'],
            'media' => ['media', 3],
            'hidden' => ['hidden', 1],
        ];
    }

    #[DataProvider('pageContentColumnProvider')]
    public function testFullRowFingerprintTracksEditedPageColumns(string $column, int|string $changedValue): void
    {
        $record = $this->pageRecord();
        $before = $this->subject()->fingerprint('pages', $record);

        $record[$column] = $changedValue;

        self::assertNotSame($before, $this->subject()->fingerprint('pages', $record));
    }

    /**
     * The full-row pin covers mixed-case columns too: DBAL lowercases the
     * keys of its column listing, so `CType` and `colPos` never matched the
     * row's keys and fingerprinted as the constant missing-column sentinel.
     *
     * @return array<string, array{string, int|string}>
     */
    public static function mixedCaseColumnProvider(): array
    {
        return [
            'CType' => ['CType', 'header'],
            'colPos' => ['colPos', 3],
        ];
    }

    #[DataProvider('mixedCaseColumnProvider')]
    public function testFullRowFingerprintTracksMixedCaseColumns(string $column, int|string $changedValue): void
    {
        $record = BackendUtility::getRecord('tt_content', 100);
        self::assertIsArray($record);
        self::assertArrayHasKey($column, $record, 'fixture guard: the row carries the column in its schema case');
        $before = $this->subject()->fingerprint('tt_content', $record);

        $record[$column] = $changedValue;

        self::assertNotSame($before, $this->subject()->fingerprint('tt_content', $record));
    }

    public function testScopedMatchesAcceptsOutOfScopeDrift(): void
    {
        $record = $this->pageRecord();
        $snapshot = $this->subject()->fingerprint('pages', $record, RecordSnapshotService::PAGES_SCOPE_COLUMNS);

        $record['tx_mindfula11y_scanupdated'] = time();

        self::assertTrue(
            $this->subject()->matches($snapshot, 'pages', $record, RecordSnapshotService::PAGES_SCOPE_COLUMNS),
        );
    }
}
