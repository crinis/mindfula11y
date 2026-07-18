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
        $record = $this->pageRecord();
        $before = $this->subject()->fingerprint('pages', $record);

        $record['tstamp'] = (int)$record['tstamp'] + 100;

        self::assertNotSame($before, $this->subject()->fingerprint('pages', $record));
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
