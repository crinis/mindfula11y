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

use MindfulMarkup\MindfulA11y\Service\ScanStateService;
use MindfulMarkup\MindfulA11y\Tests\Functional\AbstractAuthorizationTestCase;
use TYPO3\CMS\Backend\Utility\BackendUtility;

/**
 * Scan state is live-owned: scans only run in the live workspace and are
 * stored on the live page row. A workspace version of the page carries the
 * scan state it was copied with at versioning time — withLiveScanState()
 * swaps that stale copy for the live row's values.
 *
 * Fixture: page 10 (live) plus a workspace-1 version inserted per test.
 */
final class ScanStateServiceTest extends AbstractAuthorizationTestCase
{
    private const VERSION_UID = 700;

    protected function setUp(): void
    {
        parent::setUp();

        $connection = $this->getConnectionPool()->getConnectionForTable('pages');
        $connection->update('pages', [
            ScanStateService::FIELD_SCAN_ID => 'live-scan',
            ScanStateService::FIELD_SCAN_UPDATED => 2000,
        ], ['uid' => 10]);
        $connection->insert('pages', [
            'uid' => self::VERSION_UID,
            'pid' => 1,
            'title' => 'Editable (draft)',
            'doktype' => 1,
            'slug' => '/editable',
            'perms_everybody' => 19,
            't3ver_oid' => 10,
            't3ver_wsid' => 1,
            't3ver_state' => 0,
            ScanStateService::FIELD_SCAN_ID => 'stale-scan',
            ScanStateService::FIELD_SCAN_UPDATED => 1000,
        ]);
    }

    private function subject(): ScanStateService
    {
        return $this->get(ScanStateService::class);
    }

    public function testWorkspaceOverlayReadsTheLiveScanState(): void
    {
        $this->logInBackendUser(2, 1);
        $overlay = BackendUtility::getRecordWSOL('pages', 10);
        self::assertIsArray($overlay);
        self::assertSame(self::VERSION_UID, (int)($overlay['_ORIG_uid'] ?? 0), 'fixture guard: the row is the workspace overlay');
        self::assertSame('stale-scan', $overlay[ScanStateService::FIELD_SCAN_ID], 'fixture guard: the overlay carries the stale copy');

        $result = $this->subject()->withLiveScanState($overlay);

        self::assertSame('live-scan', $result[ScanStateService::FIELD_SCAN_ID]);
        self::assertSame(2000, (int)$result[ScanStateService::FIELD_SCAN_UPDATED]);
        self::assertSame('Editable (draft)', $result['title'], 'every other field keeps the workspace state');
    }

    public function testRawVersionRowReadsTheLiveScanState(): void
    {
        $this->logInBackendUser(2, 1);
        $version = BackendUtility::getRecord('pages', self::VERSION_UID);
        self::assertIsArray($version);

        $result = $this->subject()->withLiveScanState($version);

        self::assertSame('live-scan', $result[ScanStateService::FIELD_SCAN_ID]);
        self::assertSame(2000, (int)$result[ScanStateService::FIELD_SCAN_UPDATED]);
    }

    public function testLiveRowIsReturnedUnchanged(): void
    {
        $this->logInBackendUser(2);
        $live = BackendUtility::getRecord('pages', 10);
        self::assertIsArray($live);

        self::assertSame($live, $this->subject()->withLiveScanState($live));
    }
}
