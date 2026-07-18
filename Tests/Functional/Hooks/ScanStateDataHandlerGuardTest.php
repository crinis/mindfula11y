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
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Functional coverage of ScanStateDataHandlerGuard: the scan-state fields
 * (tx_mindfula11y_scanid / tx_mindfula11y_scanupdated) are internal scanner
 * state. Any external datamap write to them is silently stripped — the rest
 * of the record saves normally — while ScanCreationService's internal write
 * scope may persist them. The guard is an integrity gate, not a permission
 * gate: it applies to admins too, and the internal scope does NOT lift
 * core's own page permissions.
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

        $page = $this->fetchPage(10);
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

        self::assertSame('legit-scan', $this->fetchPage(10)[ScanStateService::FIELD_SCAN_ID]);
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

        self::assertSame('service-issued', $this->fetchPage(10)[ScanStateService::FIELD_SCAN_ID]);
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

        self::assertSame('protected-scan', $this->fetchPage(14)[ScanStateService::FIELD_SCAN_ID], 'no-access page unchanged');
        self::assertNotSame([], $dataHandler->errorLog, 'DataHandler denied the write itself');
    }

    /**
     * @param array<string, array<int|string, array<string, mixed>>> $datamap
     */
    private function runDataHandler(array $datamap, BackendUserAuthentication $backendUser): DataHandler
    {
        GeneralUtility::makeInstance(CacheManager::class)->getCache('runtime')->flush();

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($datamap, [], $backendUser);
        $dataHandler->process_datamap();

        return $dataHandler;
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

    /**
     * @return array<string, mixed>
     */
    private function fetchPage(int $uid): array
    {
        $row = $this->getConnectionPool()
            ->getConnectionForTable('pages')
            ->select(['*'], 'pages', ['uid' => $uid])
            ->fetchAssociative();
        self::assertIsArray($row, 'page ' . $uid . ' exists');

        return $row;
    }
}
