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

namespace MindfulMarkup\MindfulA11y\Tests\Functional\Hooks;

use MindfulMarkup\MindfulA11y\Hooks\ScanStateDataHandlerGuard;
use MindfulMarkup\MindfulA11y\Service\ScanStateService;
use MindfulMarkup\MindfulA11y\Tests\Functional\AbstractAuthorizationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\DataHandling\DataHandler;

/**
 * Functional coverage of ScanStateDataHandlerGuard: the scan-state fields
 * (tx_mindfula11y_scanid / tx_mindfula11y_scanupdated) are internal scanner
 * state. Any external datamap write to them is silently stripped — the rest
 * of the record saves normally — while ScanCreationService's internal write
 * scope may persist them. The guard is an integrity gate, not a permission
 * gate: it applies to admins too, and the internal scope does NOT lift
 * core's own page permissions. Copies never inherit the state, and publishing
 * a workspace version never overwrites the live row's state.
 */
final class ScanStateDataHandlerGuardTest extends AbstractAuthorizationTestCase
{
    public function testExternalWriteToScanStateFieldsIsStrippedWhileTheRestSaves(): void
    {
        $this->seedScanState(10, 'legit-scan', 1000);
        $backendUser = $this->logInBackendUser(2);

        $dataHandler = $this->runDataHandler([
            'pages' => [
                10 => [
                    'title' => 'Renamed by editor',
                    ScanStateService::FIELD_SCAN_ID => 'forged-scan',
                    ScanStateService::FIELD_SCAN_UPDATED => 999999,
                ],
            ],
        ], $backendUser);

        $page = $this->fetchRow('pages', 10);
        self::assertSame('Renamed by editor', $page['title'], 'the legitimate field of the same save is stored');
        self::assertSame('legit-scan', $page[ScanStateService::FIELD_SCAN_ID], 'scan id survives the forgery attempt');
        self::assertSame(1000, (int)$page[ScanStateService::FIELD_SCAN_UPDATED], 'scan timestamp survives');
        self::assertSame([], $dataHandler->errorLog, 'stripping is silent — the save itself is not denied');
    }

    /**
     * No admin bypass: state integrity does not depend on who writes. Every
     * sanctioned write goes through the internal scope.
     */
    public function testAdminIsNotExemptFromStripping(): void
    {
        $this->seedScanState(10, 'legit-scan', 1000);
        $backendUser = $this->logInBackendUser(1);

        $this->runDataHandler([
            'pages' => [
                10 => [
                    ScanStateService::FIELD_SCAN_ID => 'admin-forged',
                ],
            ],
        ], $backendUser);

        self::assertSame('legit-scan', $this->fetchRow('pages', 10)[ScanStateService::FIELD_SCAN_ID]);
    }

    /**
     * TCAdefaults (page/user TSconfig) are applied by DataHandler AFTER the
     * pre-process hook, so stripping the submitted datamap alone would let a
     * TSconfig entry seed a chosen scan id onto every new page — and the scan
     * id is the authorization anchor for existing scans. The guard strips the
     * fields again after defaults were applied.
     *
     * Version note: on v14, newFieldArray() only iterates the record type's
     * sub-schema fields, and the scan-state columns are in no showitem — the
     * seed is already refused structurally (this test then passes without the
     * post-process hook and acts as a regression tripwire). On v13,
     * newFieldArray() iterates ALL schema columns and the hook is the only
     * protection; the subtitle control below proves TCAdefaults were actually
     * applied in this run, keeping the scan-id assertion non-vacuous.
     */
    public function testTcaDefaultsCannotSeedScanStateOntoANewPage(): void
    {
        $this->getConnectionPool()->getConnectionForTable('pages')->update(
            'pages',
            ['TSconfig' => "TCAdefaults.pages." . ScanStateService::FIELD_SCAN_ID . " = seeded-scan\nTCAdefaults.pages.subtitle = seeded-sub"],
            ['uid' => 10],
        );
        $backendUser = $this->logInBackendUser(1);

        $dataHandler = $this->runDataHandler([
            'pages' => [
                'NEW_SEEDED' => [
                    'pid' => 10,
                    'title' => 'Fresh page',
                    // New pages default to hidden=1; fetchPage() selects through
                    // the restriction-aware builder and would not see the row.
                    'hidden' => 0,
                ],
            ],
        ], $backendUser);

        $newUid = (int)($dataHandler->substNEWwithIDs['NEW_SEEDED'] ?? 0);
        self::assertGreaterThan(0, $newUid, 'the page itself is created');
        $page = $this->fetchRow('pages', $newUid);
        self::assertSame('seeded-sub', (string)$page['subtitle'], 'fixture guard: TCAdefaults were applied to this record at all');
        self::assertSame('', (string)$page[ScanStateService::FIELD_SCAN_ID], 'a TSconfig default must not seed a scan id');
    }

    /**
     * The sanctioned path (mirrors ScanCreationService::storeScanId): inside
     * withInternalWriteScope() the same datamap persists.
     */
    public function testInternalWriteScopePersistsScanState(): void
    {
        $backendUser = $this->logInBackendUser(2);

        $dataHandler = ScanStateDataHandlerGuard::withInternalWriteScope(
            fn(): DataHandler => $this->runDataHandler([
                'pages' => [
                    10 => [
                        ScanStateService::FIELD_SCAN_ID => 'service-issued',
                    ],
                ],
            ], $backendUser)
        );

        self::assertSame('service-issued', $this->fetchRow('pages', 10)[ScanStateService::FIELD_SCAN_ID]);
        self::assertSame([], $dataHandler->errorLog);
    }

    /**
     * The internal scope only disarms the stripping — DataHandler's own page
     * permission gate stays fully active. A scan-state write for a page the
     * user may not edit is denied even inside the scope (the createAction
     * authorization chain runs before ScanCreationService, and this pins
     * that the guard cannot be leveraged to widen it).
     */
    public function testInternalWriteScopeDoesNotBypassCorePagePermissions(): void
    {
        $this->seedScanState(14, 'protected-scan', 1000);
        $backendUser = $this->logInBackendUser(2);

        $dataHandler = ScanStateDataHandlerGuard::withInternalWriteScope(
            fn(): DataHandler => $this->runDataHandler([
                'pages' => [
                    14 => [
                        ScanStateService::FIELD_SCAN_ID => 'smuggled',
                    ],
                ],
            ], $backendUser)
        );

        self::assertSame('protected-scan', $this->fetchRow('pages', 14)[ScanStateService::FIELD_SCAN_ID], 'no-access page unchanged');
        self::assertNotSame([], $dataHandler->errorLog, 'DataHandler denied the write itself');
    }

    /**
     * Stripping an external write is recorded in sys_log — the note that
     * someone tried to forge scanner state stays for every such write.
     */
    public function testExternalWriteIsLogged(): void
    {
        $backendUser = $this->logInBackendUser(2);

        $this->runDataHandler([
            'pages' => [
                10 => [
                    ScanStateService::FIELD_SCAN_ID => 'forged-scan',
                ],
            ],
        ], $backendUser);

        self::assertSame(1, $this->countBlockedLogEntries());
    }

    /**
     * Copying a page duplicates its row — scan state included — through a
     * nested DataHandler. The copy must not inherit the scan id (it is the
     * authorization anchor of the source's scan), but nobody attempted to
     * write scanner state either: no "blocked" log entry per copied page.
     */
    public function testPageCopyStripsScanStateWithoutLogging(): void
    {
        $this->seedScanState(10, 'source-scan', 1000);
        $backendUser = $this->logInBackendUser(1);

        $dataHandler = $this->runCommandMap(['pages' => [10 => ['copy' => 1]]], $backendUser);

        self::assertSame([], $dataHandler->errorLog, 'the copy itself succeeded');
        $copyUid = (int)($dataHandler->copyMappingArray_merged['pages'][10] ?? 0);
        self::assertGreaterThan(0, $copyUid, 'the page was copied');
        $copy = $this->fetchPageRaw($copyUid);
        self::assertSame('', (string)$copy[ScanStateService::FIELD_SCAN_ID], 'the copy does not inherit the scan id');
        self::assertSame(0, (int)$copy[ScanStateService::FIELD_SCAN_UPDATED], 'nor the scan timestamp');
        self::assertSame(0, $this->countBlockedLogEntries(), 'a copy is not a forgery attempt');
    }

    /**
     * Localizing a page creates the translation from the language overlay
     * fields only — scan state is neither carried over nor reported.
     */
    public function testPageLocalizeCarriesNoScanStateAndLogsNothing(): void
    {
        $this->writeDefaultSiteConfiguration();
        $this->seedScanState(13, 'source-scan', 1000);
        $backendUser = $this->logInBackendUser(1);

        $dataHandler = $this->runCommandMap(['pages' => [13 => ['localize' => 1]]], $backendUser);

        self::assertSame([], $dataHandler->errorLog, 'the localization itself succeeded');
        $translationUid = (int)($dataHandler->copyMappingArray_merged['pages'][13] ?? 0);
        self::assertGreaterThan(0, $translationUid, 'the page was localized');
        self::assertSame('', (string)$this->fetchPageRaw($translationUid)[ScanStateService::FIELD_SCAN_ID]);
        self::assertSame(0, $this->countBlockedLogEntries());
    }

    /**
     * Versioning a page copies its scan state into the workspace version, and
     * publishing swaps the version's columns over live. Scan state is owned by
     * live (scans only run there), so a scan taken after the draft was created
     * must survive the publish instead of being replaced by the stale copy.
     */
    #[DataProvider('publishActionProvider')]
    public function testPublishingAWorkspaceVersionKeepsTheLiveScanState(string $action): void
    {
        $this->seedScanState(10, 'scan-before-draft', 1000);
        $versionUid = $this->createWorkspaceVersionOfPage10();

        // A newer live scan lands while the draft waits for publishing.
        $this->seedScanState(10, 'scan-after-draft', 2000);

        $dataHandler = $this->runCommandMap(
            ['pages' => [10 => ['version' => ['action' => $action, 'swapWith' => $versionUid]]]],
            $this->logInBackendUser(1, 1)
        );

        $live = $this->fetchPageRaw(10);
        self::assertSame([], $dataHandler->errorLog, 'the publish itself succeeded');
        self::assertSame('Draft title', $live['title'], 'fixture guard: the draft was published');
        self::assertSame('scan-after-draft', $live[ScanStateService::FIELD_SCAN_ID], 'the newer live scan id survives');
        self::assertSame(2000, (int)$live[ScanStateService::FIELD_SCAN_UPDATED], 'the newer live scan timestamp survives');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function publishActionProvider(): array
    {
        return [
            'publish' => ['publish'],
            'swap' => ['swap'],
        ];
    }

    /**
     * The pre-publish alignment only ever touches a genuine version of the
     * record being published. A crafted command pointing swapWith at an
     * unrelated live page must not copy scan state onto it — workspaces then
     * rejects the swap on its own.
     */
    public function testPublishAlignmentIgnoresASwapTargetThatIsNotAVersionOfTheRecord(): void
    {
        $this->seedScanState(10, 'scan-of-10', 2000);
        $this->seedScanState(13, 'scan-of-13', 1000);

        $this->runCommandMap(
            ['pages' => [10 => ['version' => ['action' => 'publish', 'swapWith' => 13]]]],
            $this->logInBackendUser(1, 1)
        );

        $unrelated = $this->fetchPageRaw(13);
        self::assertSame('scan-of-13', $unrelated[ScanStateService::FIELD_SCAN_ID]);
        self::assertSame(1000, (int)$unrelated[ScanStateService::FIELD_SCAN_UPDATED]);
    }

    /**
     * Edit page 10 in workspace 1 and return the created version's uid.
     */
    private function createWorkspaceVersionOfPage10(): int
    {
        $this->runDataHandler(['pages' => [10 => ['title' => 'Draft title']]], $this->logInBackendUser(1, 1));

        $versionUid = (int)$this->getConnectionPool()
            ->getConnectionForTable('pages')
            ->select(['uid'], 'pages', ['t3ver_oid' => 10, 't3ver_wsid' => 1])
            ->fetchOne();
        self::assertGreaterThan(0, $versionUid, 'fixture guard: the edit created a workspace version');
        self::assertSame(
            'scan-before-draft',
            $this->fetchPageRaw($versionUid)[ScanStateService::FIELD_SCAN_ID],
            'fixture guard: versioning copied the scan state into the version row'
        );

        return $versionUid;
    }

    private function countBlockedLogEntries(): int
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('sys_log');
        $queryBuilder->getRestrictions()->removeAll();

        return (int)$queryBuilder
            ->count('uid')
            ->from('sys_log')
            ->where($queryBuilder->expr()->like(
                'details',
                $queryBuilder->createNamedParameter('%internal Mindful A11y scanner state fields was blocked%')
            ))
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * Restriction-free read: copied pages are hidden, versions are filtered by
     * the default workspace restriction.
     *
     * @return array<string, mixed>
     */
    private function fetchPageRaw(int $uid): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder
            ->select('*')
            ->from('pages')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();
        self::assertIsArray($row, 'page ' . $uid . ' exists');

        return $row;
    }

    private function seedScanState(int $pageUid, string $scanId, int $updated): void
    {
        $this->getConnectionPool()->getConnectionForTable('pages')->update(
            'pages',
            [
                ScanStateService::FIELD_SCAN_ID => $scanId,
                ScanStateService::FIELD_SCAN_UPDATED => $updated,
            ],
            ['uid' => $pageUid],
        );
    }
}
