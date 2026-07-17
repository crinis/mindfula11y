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

use MindfulMarkup\MindfulA11y\Tests\Functional\AbstractAuthorizationTestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Functional coverage of DecorativeFileReferenceDataHandlerGuard, the
 * processDatamap_preProcessFieldArray hook that keeps alternative and title
 * empty on decorative sys_file_reference rows.
 *
 * Who may toggle tx_mindfula11y_decorative is deliberately core's business:
 * reference-table rights, the reference's page permissions, and the field's
 * exclude grant — the same rules that govern the adjacent alternative/title
 * columns. The tests pin that model (core denials still deny; reference
 * access suffices) alongside the hook's own blanking invariant, including
 * its workspace-overlaid read of the stored decorative state.
 *
 * All writes are driven through a real DataHandler run and asserted against
 * the persisted database state.
 *
 * Fixture context (see AuthorizationScenario.csv + DecorativeGuardSupplement.csv):
 *  - sys_file_reference 1 -> tt_content 100 (assets, page 10 editable), decorative 0
 *  - sys_file_reference 700 -> tt_content 700 (assets, page 14 no-access), decorative 0
 *  - sys_file_reference 701 = workspace-1 version of reference 1, decorative 1
 *  - user 2 full editor (workspace 1 member), user 4 no tt_content modify,
 *    user 5 no exclude fields.
 */
final class DecorativeFileReferenceDataHandlerGuardTest extends AbstractAuthorizationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/DecorativeGuardSupplement.csv');
    }

    /**
     * Setting decorative=1 stores the toggle and blanks alternative + title in
     * the SAME write even though non-empty values were submitted alongside it.
     */
    public function testToggleStoresDecorativeAndBlanksAlternativeAndTitle(): void
    {
        $backendUser = $this->logInBackendUser(2);

        $dataHandler = $this->runDataHandler([
            'sys_file_reference' => [
                1 => [
                    'tx_mindfula11y_decorative' => 1,
                    'alternative' => 'should be discarded',
                    'title' => 'should be discarded too',
                ],
            ],
        ], $backendUser);

        $reference = $this->fetchReference(1);
        self::assertSame(1, (int)$reference['tx_mindfula11y_decorative'], 'decorative toggle stored');
        self::assertSame('', (string)$reference['alternative'], 'alternative blanked by guard');
        self::assertSame('', (string)$reference['title'], 'title blanked by guard');
        self::assertSame([], $dataHandler->errorLog, 'no denial for an accessible reference');
    }

    /**
     * An already-decorative reference receives an UNRELATED save that submits
     * only a non-empty alternative. The guard re-derives the stored decorative
     * state and forces alternative back to empty.
     */
    public function testBlankingIsEnforcedOnUnrelatedSaveOfDecorativeReference(): void
    {
        $this->seedStoredDecorative(1);
        $backendUser = $this->logInBackendUser(2);

        $this->runDataHandler([
            'sys_file_reference' => [
                1 => [
                    'alternative' => 'sneaky',
                ],
            ],
        ], $backendUser);

        $reference = $this->fetchReference(1);
        self::assertSame(1, (int)$reference['tx_mindfula11y_decorative'], 'decorative stays on');
        self::assertSame('', (string)$reference['alternative'], 'alternative forced empty on decorative reference');
    }

    /**
     * The stored decorative state must be the WORKSPACE-overlaid one: reference
     * 1 is non-decorative live but its workspace-1 version (701) is decorative.
     * A workspace editor saving an alternative on the live uid writes to the
     * version — so the blanking must follow the version's decorative flag, not
     * the live row's.
     */
    public function testBlankingFollowsTheWorkspaceVersionsDecorativeState(): void
    {
        $backendUser = $this->logInBackendUser(2, 1);

        $this->runDataHandler([
            'sys_file_reference' => [
                1 => [
                    'alternative' => 'sneaky in draft',
                ],
            ],
        ], $backendUser);

        $version = $this->fetchReference(701);
        self::assertSame(1, (int)$version['tx_mindfula11y_decorative'], 'workspace version stays decorative');
        self::assertSame('', (string)$version['alternative'], 'alternative forced empty on the decorative workspace version');
        self::assertSame('', (string)$this->fetchReference(1)['alternative'], 'live row untouched by the workspace save');
    }

    /**
     * Core's own authorization still applies: reference 700 lives on the
     * no-access page 14, so DataHandler itself refuses the write. The
     * extension adds no parent-relation check on top.
     */
    public function testToggleFollowsCorePagePermissions(): void
    {
        $backendUser = $this->logInBackendUser(2);

        $dataHandler = $this->runDataHandler([
            'sys_file_reference' => [
                700 => [
                    'tx_mindfula11y_decorative' => 1,
                ],
            ],
        ], $backendUser);

        $reference = $this->fetchReference(700);
        self::assertSame(0, (int)$reference['tx_mindfula11y_decorative'], 'decorative unchanged: no page access');
        self::assertNotSame([], $dataHandler->errorLog, 'DataHandler denied the write itself');
    }

    /**
     * The toggle is governed by core's sys_file_reference rules alone — like
     * the adjacent alternative/title columns. User 4 lacks tt_content in
     * tables_modify but may modify sys_file_reference on the editable page 10,
     * so the toggle succeeds; no parent-table access is required.
     */
    public function testToggleRequiresOnlyReferenceAccessNotParentTableAccess(): void
    {
        $backendUser = $this->logInBackendUser(4);

        $dataHandler = $this->runDataHandler([
            'sys_file_reference' => [
                1 => [
                    'tx_mindfula11y_decorative' => 1,
                ],
            ],
        ], $backendUser);

        $reference = $this->fetchReference(1);
        self::assertSame(1, (int)$reference['tx_mindfula11y_decorative'], 'decorative stored under core reference rules');
        self::assertSame('', (string)$reference['alternative'], 'alternative blanked');
        self::assertSame([], $dataHandler->errorLog, 'no denial without parent-table access');
    }

    /**
     * User 5 has no non_exclude_fields grant at all. tx_mindfula11y_decorative
     * is an exclude field, so DataHandler's own exclude-field filtering drops
     * the toggle before it reaches the stored record.
     */
    public function testExcludeFieldFilteringDropsToggleForUserWithoutGrant(): void
    {
        $backendUser = $this->logInBackendUser(5);

        $this->runDataHandler([
            'sys_file_reference' => [
                1 => [
                    'tx_mindfula11y_decorative' => 1,
                ],
            ],
        ], $backendUser);

        $reference = $this->fetchReference(1);
        self::assertSame(0, (int)$reference['tx_mindfula11y_decorative'], 'decorative dropped for exclude-field-less user');
    }

    /**
     * A new tt_content on the editable page 10 with a new IRRE
     * sys_file_reference child carrying decorative=1: the toggle is stored and
     * blanking applies to the new child.
     */
    public function testNewIrreChildStoresDecorative(): void
    {
        $backendUser = $this->logInBackendUser(2);

        $dataHandler = $this->runDataHandler([
            'tt_content' => [
                'NEW1' => [
                    'pid' => 10,
                    'sys_language_uid' => 0,
                    'CType' => 'text',
                    'header' => 'IRRE parent (accessible)',
                    'assets' => 'NEW2',
                ],
            ],
            'sys_file_reference' => [
                'NEW2' => [
                    'pid' => 10,
                    'sys_language_uid' => 0,
                    'uid_local' => 1,
                    'tx_mindfula11y_decorative' => 1,
                    'alternative' => 'should be discarded',
                ],
            ],
        ], $backendUser);

        $referenceUid = (int)($dataHandler->substNEWwithIDs['NEW2'] ?? 0);
        self::assertGreaterThan(0, $referenceUid, 'IRRE child reference was created');
        $reference = $this->fetchReference($referenceUid);
        self::assertSame(1, (int)$reference['tx_mindfula11y_decorative'], 'decorative stored on new IRRE child');
        self::assertSame('', (string)$reference['alternative'], 'alternative blanked on new IRRE child');
    }

    /**
     * Core denies creating records on the no-access page 14 — nothing is
     * persisted, decorative or otherwise. The extension adds no check of its
     * own here.
     */
    public function testNewIrreChildOnInaccessiblePageIsDeniedByCore(): void
    {
        $backendUser = $this->logInBackendUser(2);

        $dataHandler = $this->runDataHandler([
            'tt_content' => [
                'NEW1' => [
                    'pid' => 14,
                    'sys_language_uid' => 0,
                    'CType' => 'text',
                    'header' => 'IRRE parent (no access)',
                    'assets' => 'NEW2',
                ],
            ],
            'sys_file_reference' => [
                'NEW2' => [
                    'pid' => 14,
                    'sys_language_uid' => 0,
                    'uid_local' => 1,
                    'tx_mindfula11y_decorative' => 1,
                ],
            ],
        ], $backendUser);

        self::assertArrayNotHasKey('NEW2', $dataHandler->substNEWwithIDs, 'no reference created on no-access page');
        self::assertSame(
            1,
            $this->countDecorativeReferences(),
            'only the fixture workspace version is decorative — nothing new persisted'
        );
    }

    /**
     * Non-goal / deliberate scope: writing a core field (alternative) on a
     * NON-decorative reference is untouched by the guard and stored verbatim.
     */
    public function testCoreFieldWriteOnNonDecorativeReferenceIsUntouched(): void
    {
        $backendUser = $this->logInBackendUser(2);

        $this->runDataHandler([
            'sys_file_reference' => [
                1 => [
                    'alternative' => 'hello',
                ],
            ],
        ], $backendUser);

        $reference = $this->fetchReference(1);
        self::assertSame(0, (int)$reference['tx_mindfula11y_decorative'], 'reference stays non-decorative');
        self::assertSame('hello', (string)$reference['alternative'], 'core alternative stored, guard does not interfere');
    }

    /**
     * @param array<string, array<int|string, array<string, mixed>>> $datamap
     */
    private function runDataHandler(array $datamap, BackendUserAuthentication $backendUser): DataHandler
    {
        // BackendUtility caches record lookups in the runtime cache; flush it so
        // the guard reads the current database state (relevant after direct
        // seeding writes and across cases within one test process).
        GeneralUtility::makeInstance(\TYPO3\CMS\Core\Cache\CacheManager::class)
            ->getCache('runtime')
            ->flush();

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($datamap, [], $backendUser);
        $dataHandler->process_datamap();

        return $dataHandler;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchReference(int $uid): array
    {
        $row = $this->getConnectionPool()
            ->getConnectionForTable('sys_file_reference')
            ->select(['*'], 'sys_file_reference', ['uid' => $uid])
            ->fetchAssociative();
        self::assertIsArray($row, 'sys_file_reference ' . $uid . ' exists');

        return $row;
    }

    private function countDecorativeReferences(): int
    {
        return (int)$this->getConnectionPool()
            ->getConnectionForTable('sys_file_reference')
            ->count('*', 'sys_file_reference', ['tx_mindfula11y_decorative' => 1]);
    }

    private function seedStoredDecorative(int $uid): void
    {
        $this->getConnectionPool()
            ->getConnectionForTable('sys_file_reference')
            ->update('sys_file_reference', ['tx_mindfula11y_decorative' => 1], ['uid' => $uid]);
    }
}
