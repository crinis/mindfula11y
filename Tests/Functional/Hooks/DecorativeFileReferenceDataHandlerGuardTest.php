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
 * processDatamap_preProcessFieldArray hook that guards writes to the
 * extension's own tx_mindfula11y_decorative toggle on sys_file_reference.
 *
 * Every blocked-write case is paired with an allowed baseline so nothing can
 * pass vacuously. All writes are driven through a real DataHandler run and
 * asserted against the persisted database state (plus the guard's error-log
 * entry on the blocked toggles).
 *
 * Fixture context (see AuthorizationScenario.csv + DecorativeGuardSupplement.csv):
 *  - sys_file_reference 1 -> tt_content 100 (assets, page 10 editable), decorative 0
 *  - sys_file_reference 700 -> tt_content 700 (assets, page 14 no-access), decorative 0
 *  - user 2 full editor, user 4 no tt_content modify, user 5 no exclude fields.
 */
final class DecorativeFileReferenceDataHandlerGuardTest extends AbstractAuthorizationTestCase
{
    private const GUARD_LOG_FRAGMENT = 'without access to its parent record and relation field';

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/DecorativeGuardSupplement.csv');
    }

    /**
     * A: full editor sets decorative=1 on an accessible reference. The toggle is
     * stored, and the guard blanks alternative + title in the SAME write even
     * though a non-empty alternative was submitted alongside it.
     */
    public function testAllowedToggleStoresDecorativeAndBlanksAlternativeAndTitle(): void
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
        self::assertSame([], $dataHandler->errorLog, 'no guard denial for an accessible reference');
    }

    /**
     * B: an already-decorative reference receives an UNRELATED save that submits
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
     * C: full editor flips decorative on a reference whose parent tt_content
     * sits on a no-access page (14). The guard denies via the stored parent
     * relation; the toggle stays 0 and the guard log entry is present.
     * DataHandler may additionally refuse the record for lack of page access —
     * the assertion is specifically about the toggle value remaining 0.
     */
    public function testBlockedWhenParentPageNotAccessible(): void
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
        self::assertSame(0, (int)$reference['tx_mindfula11y_decorative'], 'decorative unchanged: no parent page access');
        self::assertGuardDenialLogged($dataHandler);
    }

    /**
     * D: user 4 lacks tt_content in tables_modify but CAN modify
     * sys_file_reference itself. Flipping decorative on reference 1 must be
     * blocked by the guard's parent-relation check (checkRecordEditAccess on the
     * parent tt_content fails at table write access), not by DataHandler's own
     * reference-table rights.
     */
    public function testBlockedWhenNoParentTableModifyAccess(): void
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
        self::assertSame(0, (int)$reference['tx_mindfula11y_decorative'], 'decorative unchanged: no tt_content modify access');
        self::assertGuardDenialLogged($dataHandler);
    }

    /**
     * E: user 5 has no non_exclude_fields grant at all. tx_mindfula11y_decorative
     * is an exclude field, so it never reaches the stored record.
     *
     * OBSERVED MECHANISM: the guard does NOT fire here — user 5 CAN edit the
     * parent tt_content 100 (tt_content is in tables_modify, page 10 is content-
     * editable, and 'assets' is not an exclude field), so mayEditParentRelations
     * returns true and no guard log entry is written. DataHandler's own
     * exclude-field filtering in fillInFieldArray (DataHandler.php ~line 1112,
     * which runs AFTER the guard's preProcessFieldArray hook at ~line 708) is
     * what silently drops the toggle. The observable DB outcome is unchanged.
     */
    public function testBlockedByExcludeFieldFilteringForUserWithoutNonExcludeGrant(): void
    {
        $backendUser = $this->logInBackendUser(5);

        $dataHandler = $this->runDataHandler([
            'sys_file_reference' => [
                1 => [
                    'tx_mindfula11y_decorative' => 1,
                ],
            ],
        ], $backendUser);

        $reference = $this->fetchReference(1);
        self::assertSame(0, (int)$reference['tx_mindfula11y_decorative'], 'decorative dropped for exclude-field-less user');
        // The guard's parent check passes for user 5, so the drop is DataHandler's,
        // not the guard's: no guard denial should be logged.
        self::assertGuardDenialNotLogged($dataHandler);
    }

    /**
     * F (forward): move attack. One datamap flips decorative on reference 1 AND
     * repoints uid_foreign to the no-access record 700. The submitted (target)
     * relation is not editable, so the toggle change is rejected even though the
     * stored relation is editable.
     */
    public function testBlockedWhenSubmittedRelationMovesToInaccessibleParent(): void
    {
        $backendUser = $this->logInBackendUser(2);

        $dataHandler = $this->runDataHandler([
            'sys_file_reference' => [
                1 => [
                    'tx_mindfula11y_decorative' => 1,
                    'uid_foreign' => 700,
                ],
            ],
        ], $backendUser);

        $reference = $this->fetchReference(1);
        self::assertSame(0, (int)$reference['tx_mindfula11y_decorative'], 'decorative rejected: submitted relation not editable');
        self::assertGuardDenialLogged($dataHandler);
    }

    /**
     * F (reverse): on the no-access reference 700, submit decorative plus a
     * uid_foreign pointing at the editable record 100. The stored relation
     * (parent 700, page 14) is not editable, so the guard rejects regardless of
     * the editable submitted target.
     */
    public function testBlockedWhenStoredRelationNotEditableEvenIfSubmittedTargetIs(): void
    {
        $backendUser = $this->logInBackendUser(2);

        $dataHandler = $this->runDataHandler([
            'sys_file_reference' => [
                700 => [
                    'tx_mindfula11y_decorative' => 1,
                    'uid_foreign' => 100,
                ],
            ],
        ], $backendUser);

        $reference = $this->fetchReference(700);
        self::assertSame(0, (int)$reference['tx_mindfula11y_decorative'], 'decorative rejected: stored relation not editable');
        self::assertGuardDenialLogged($dataHandler);
    }

    /**
     * G (positive): create a new tt_content on the editable page 10 with a new
     * IRRE sys_file_reference child carrying decorative=1. The guard resolves the
     * submitted parent relation from the datamap and permits the toggle.
     */
    public function testNewIrreChildStoresDecorativeWhenParentAccessible(): void
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
                ],
            ],
        ], $backendUser);

        $referenceUid = (int)($dataHandler->substNEWwithIDs['NEW2'] ?? 0);
        self::assertGreaterThan(0, $referenceUid, 'IRRE child reference was created');
        $reference = $this->fetchReference($referenceUid);
        self::assertSame(1, (int)$reference['tx_mindfula11y_decorative'], 'decorative stored on new IRRE child');
        self::assertGuardDenialNotLogged($dataHandler);
    }

    /**
     * G (negative): same IRRE shape but the new parent sits on the no-access
     * page 14. The guard resolves the submitted parent and denies the toggle; if
     * a child reference is created at all it must not carry decorative=1.
     */
    public function testNewIrreChildDeniesDecorativeWhenParentNotAccessible(): void
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

        $referenceUid = (int)($dataHandler->substNEWwithIDs['NEW2'] ?? 0);
        if ($referenceUid > 0) {
            $reference = $this->fetchReference($referenceUid);
            self::assertSame(0, (int)$reference['tx_mindfula11y_decorative'], 'decorative not stored on no-access IRRE child');
        } else {
            self::assertSame(
                0,
                $this->countDecorativeReferences(),
                'no decorative reference persisted when parent page is inaccessible'
            );
        }
    }

    /**
     * H: non-goal / deliberate scope. Writing a core field (alternative) on a
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

    private static function assertGuardDenialLogged(DataHandler $dataHandler): void
    {
        foreach ($dataHandler->errorLog as $entry) {
            if (str_contains($entry, self::GUARD_LOG_FRAGMENT)) {
                return;
            }
        }
        self::fail('Expected the guard denial log entry, got: ' . var_export($dataHandler->errorLog, true));
    }

    private static function assertGuardDenialNotLogged(DataHandler $dataHandler): void
    {
        foreach ($dataHandler->errorLog as $entry) {
            self::assertStringNotContainsString(self::GUARD_LOG_FRAGMENT, $entry, 'no guard denial expected');
        }
    }
}
