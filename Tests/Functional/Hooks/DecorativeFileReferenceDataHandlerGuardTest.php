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
use PHPUnit\Framework\Attributes\DataProvider;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;

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
 *  - copy/localize cases only (DecorativeCopySupplement.csv): reference 702,
 *    decorative with empty alternative/title, on tt_content 702 (page 10)
 *  - user 2 full editor (workspace 1 member), user 4 no tt_content modify,
 *    user 5 no exclude fields, user 20 only the decorative grant.
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

        $reference = $this->fetchRow('sys_file_reference', 1);
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

        $reference = $this->fetchRow('sys_file_reference', 1);
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

        $version = $this->fetchRow('sys_file_reference', 701);
        self::assertSame(1, (int)$version['tx_mindfula11y_decorative'], 'workspace version stays decorative');
        self::assertSame('', (string)$version['alternative'], 'alternative forced empty on the decorative workspace version');
        self::assertSame('', (string)$this->fetchRow('sys_file_reference', 1)['alternative'], 'live row untouched by the workspace save');
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

        $reference = $this->fetchRow('sys_file_reference', 700);
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

        $reference = $this->fetchRow('sys_file_reference', 1);
        self::assertSame(1, (int)$reference['tx_mindfula11y_decorative'], 'decorative stored under core reference rules');
        self::assertSame('', (string)$reference['alternative'], 'alternative blanked');
        self::assertSame([], $dataHandler->errorLog, 'no denial without parent-table access');
    }

    /**
     * User 20 holds ONLY the decorative exclude-field grant. DataHandler would
     * store the toggle but silently drop the guard's injected blanks for the
     * ungranted alternative/title fields, leaving a decorative reference that
     * still renders its stored alternative text. The guard must reject the
     * toggle as one atomic change and surface an error.
     */
    public function testTogglePartialGrantsIsRejectedInsteadOfHalfApplied(): void
    {
        $this->getConnectionPool()
            ->getConnectionForTable('sys_file_reference')
            ->update('sys_file_reference', ['alternative' => 'Existing alt text'], ['uid' => 1]);
        $backendUser = $this->logInBackendUser(20);

        $dataHandler = $this->runDataHandler([
            'sys_file_reference' => [
                1 => [
                    'tx_mindfula11y_decorative' => 1,
                ],
            ],
        ], $backendUser);

        $reference = $this->fetchRow('sys_file_reference', 1);
        self::assertSame(0, (int)$reference['tx_mindfula11y_decorative'], 'toggle rejected without alternative/title grants');
        self::assertSame('Existing alt text', (string)$reference['alternative'], 'stored alternative untouched');
        self::assertNotSame([], $dataHandler->errorLog, 'rejection is surfaced, not silent');
    }

    /**
     * The mirror image of the case above: the adjacent-field grants are held but
     * the toggle's own is NOT. The guard's atomicity check passes, yet
     * DataHandler drops the toggle for the missing grant — so deriving the
     * blanking from the incoming value would wipe a stored alternative and title
     * while the flag stays off. Destructive precisely because it is silent: the
     * editor sees an unchanged toggle and no error.
     */
    public function testMissingToggleGrantDoesNotWipeAdjacentFields(): void
    {
        // Group 20 keeps its adjacent-field access but loses the toggle grant.
        $this->getConnectionPool()->getConnectionForTable('be_groups')->update(
            'be_groups',
            ['non_exclude_fields' => 'sys_file_reference:alternative,sys_file_reference:title'],
            ['uid' => 20],
        );
        $this->getConnectionPool()->getConnectionForTable('sys_file_reference')->update(
            'sys_file_reference',
            ['alternative' => 'Existing alt text', 'title' => 'Existing title'],
            ['uid' => 1],
        );
        $backendUser = $this->logInBackendUser(20);

        $this->runDataHandler([
            'sys_file_reference' => [
                1 => [
                    'tx_mindfula11y_decorative' => 1,
                ],
            ],
        ], $backendUser);

        $reference = $this->fetchRow('sys_file_reference', 1);
        self::assertSame(0, (int)$reference['tx_mindfula11y_decorative'], 'toggle dropped by DataHandler for the missing grant');
        self::assertSame('Existing alt text', (string)$reference['alternative'], 'alternative survives the dropped toggle');
        self::assertSame('Existing title', (string)$reference['title'], 'title survives the dropped toggle');
    }

    /**
     * FormEngine resubmits every rendered field, so an unchanged resave of an
     * already-decorative reference carries the flag too. That is not an enable
     * and must not be rejected — otherwise the user gets a red flash message on
     * every save of that record while the stored value never changed.
     */
    public function testUnchangedResaveOfDecorativeReferenceIsNotRejected(): void
    {
        $this->seedStoredDecorative(1);
        $backendUser = $this->logInBackendUser(20);

        $dataHandler = $this->runDataHandler([
            'sys_file_reference' => [
                1 => [
                    'tx_mindfula11y_decorative' => 1,
                ],
            ],
        ], $backendUser);

        $reference = $this->fetchRow('sys_file_reference', 1);
        self::assertSame(1, (int)$reference['tx_mindfula11y_decorative'], 'decorative stays on');
        self::assertSame([], $dataHandler->errorLog, 'an unchanged resave raises no error');
    }

    /**
     * An installation that removes `exclude` from the core alternative/title
     * columns needs no non_exclude_fields grant for them: DataHandler's filter
     * only drops fields TCA marks as exclude, so the injected blanks are
     * written and the toggle must be accepted. The module offers it in exactly
     * this case, because PermissionService::checkNonExcludeFields() consults
     * TCA the same way — the guard must not disagree with core and the UI.
     */
    public function testToggleAcceptedWhenAdjacentFieldsAreNotExcludeFields(): void
    {
        $originalTca = $GLOBALS['TCA'];
        unset(
            $GLOBALS['TCA']['sys_file_reference']['columns']['alternative']['exclude'],
            $GLOBALS['TCA']['sys_file_reference']['columns']['title']['exclude'],
        );
        // TYPO3 v14's DataHandler reads the exclude flag through the Schema API
        // (TcaSchema field ->supportsAccessControl()), which is built once at
        // bootstrap — mutating $GLOBALS['TCA'] alone would leave it stale and
        // the scenario unexercised.
        $schemaFactory = $this->get(TcaSchemaFactory::class);
        $schemaFactory->rebuild($GLOBALS['TCA']);

        try {
            $this->getConnectionPool()
                ->getConnectionForTable('sys_file_reference')
                ->update('sys_file_reference', ['alternative' => 'Existing alt text'], ['uid' => 1]);
            // User 20 holds the decorative grant but neither adjacent grant —
            // the same user the partial-grant rejection above uses.
            $backendUser = $this->logInBackendUser(20);

            $dataHandler = $this->runDataHandler([
                'sys_file_reference' => [
                    1 => [
                        'tx_mindfula11y_decorative' => 1,
                    ],
                ],
            ], $backendUser);

            $reference = $this->fetchRow('sys_file_reference', 1);
            self::assertSame(1, (int)$reference['tx_mindfula11y_decorative'], 'toggle stored without exclude flags');
            self::assertSame('', (string)$reference['alternative'], 'blanking applied');
            self::assertSame([], $dataHandler->errorLog, 'no spurious denial');
        } finally {
            $GLOBALS['TCA'] = $originalTca;
            $schemaFactory->rebuild($GLOBALS['TCA']);
        }
    }

    /**
     * Disabling decorative blanks nothing, so the partial-grant user may
     * still turn the flag OFF.
     */
    public function testToggleOffNeedsNoAdjacentFieldGrants(): void
    {
        $this->seedStoredDecorative(1);
        $backendUser = $this->logInBackendUser(20);

        $dataHandler = $this->runDataHandler([
            'sys_file_reference' => [
                1 => [
                    'tx_mindfula11y_decorative' => 0,
                ],
            ],
        ], $backendUser);

        self::assertSame(0, (int)$this->fetchRow('sys_file_reference', 1)['tx_mindfula11y_decorative'], 'toggle off stored');
        self::assertSame([], $dataHandler->errorLog, 'no denial for disabling');
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

        $reference = $this->fetchRow('sys_file_reference', 1);
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
        $reference = $this->fetchRow('sys_file_reference', $referenceUid);
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

        $reference = $this->fetchRow('sys_file_reference', 1);
        self::assertSame(0, (int)$reference['tx_mindfula11y_decorative'], 'reference stays non-decorative');
        self::assertSame('hello', (string)$reference['alternative'], 'core alternative stored, guard does not interfere');
    }

    /**
     * A decorative reference that is not decorative in the database yet still
     * gets its submitted text blanked must say so: the save is valid, but the
     * editor's alternative/title input is discarded. The note is a MESSAGE
     * (sys_log only), never a USER_ERROR flash.
     */
    public function testBlankingSubmittedTextOnDecorativeReferenceIsLoggedAsMessage(): void
    {
        $this->seedStoredDecorative(1);
        $backendUser = $this->logInBackendUser(2);

        $dataHandler = $this->runDataHandler([
            'sys_file_reference' => [
                1 => [
                    'alternative' => 'sneaky',
                ],
            ],
        ], $backendUser);

        self::assertSame('', (string)$this->fetchRow('sys_file_reference', 1)['alternative'], 'alternative still forced empty');
        self::assertSame([], $dataHandler->errorLog, 'no error: the save is valid');
        self::assertSame([0], $this->decorativeLogLevels(), 'exactly one MESSAGE-level note about the emptied fields');
    }

    /**
     * An already-consistent decorative reference resaved with empty
     * alternative/title needs no note — nothing the editor typed was dropped.
     */
    public function testBlankingAlreadyEmptyFieldsLogsNothing(): void
    {
        $this->seedStoredDecorative(1);
        $backendUser = $this->logInBackendUser(2);

        $this->runDataHandler([
            'sys_file_reference' => [
                1 => [
                    'tx_mindfula11y_decorative' => 1,
                    'alternative' => '',
                ],
            ],
        ], $backendUser);

        self::assertSame([], $this->decorativeLogLevels());
    }

    /**
     * Copying a reference and localizing its parent run a nested DataHandler
     * over a NEW record carrying the source's decorative flag. An editor
     * holding every grant keeps the flag on the duplicate, with no error.
     */
    #[DataProvider('duplicatingCommandProvider')]
    public function testDuplicateKeepsDecorativeFlagForEditorWithAllGrants(string $table, string $command, int $target): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/DecorativeCopySupplement.csv');
        $this->writeDefaultSiteConfiguration();
        $backendUser = $this->logInBackendUser(2);

        $dataHandler = $this->runCommandMap([$table => [702 => [$command => $target]]], $backendUser);

        $duplicate = $this->fetchDuplicateReference($command, $dataHandler);
        self::assertSame(1, (int)$duplicate['tx_mindfula11y_decorative'], 'decorative flag carried over');
        self::assertSame('', (string)$duplicate['alternative'], 'alternative stays empty on the duplicate');
        self::assertSame([], $dataHandler->errorLog, 'no error for a plain ' . $command);
        self::assertSame([], $this->decorativeLogLevels(), 'nothing to report');
    }

    /**
     * The duplicate of a decorative reference is a NEW record: without the
     * alternative/title grants DataHandler drops the (empty) values and the
     * nullable columns fall back to NULL — FAL would then inherit the file
     * metadata's alternative text on a reference flagged decorative. The flag
     * is therefore dropped, but the editor did not ask for anything: a
     * MESSAGE-level note, no USER_ERROR flash for a toggle they never touched.
     */
    #[DataProvider('duplicatingCommandProvider')]
    public function testDuplicateDropsDecorativeFlagQuietlyWithoutAdjacentGrants(string $table, string $command, int $target): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/DecorativeCopySupplement.csv');
        $this->writeDefaultSiteConfiguration();
        $backendUser = $this->logInBackendUser(20);

        $dataHandler = $this->runCommandMap([$table => [702 => [$command => $target]]], $backendUser);

        $duplicate = $this->fetchDuplicateReference($command, $dataHandler);
        self::assertSame(0, (int)$duplicate['tx_mindfula11y_decorative'], 'flag dropped: its blanks cannot be written');
        self::assertSame([], $dataHandler->errorLog, 'no USER_ERROR for a ' . $command);
        self::assertSame([0], $this->decorativeLogLevels(), 'one MESSAGE-level note about the dropped flag');
    }

    /**
     * Copying the PARENT in live duplicates its references with
     * copyRecord_raw(), which runs no datamap hooks and copies the row
     * verbatim — flag and empty strings alike. The result is consistent for
     * every editor, so the guard has nothing to decide (pinned so a core
     * change to that path surfaces here).
     */
    public function testLiveParentCopyDuplicatesDecorativeReferenceVerbatim(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/DecorativeCopySupplement.csv');
        $backendUser = $this->logInBackendUser(20);

        $dataHandler = $this->runCommandMap(['tt_content' => [702 => ['copy' => 10]]], $backendUser);

        $copiedParent = (int)($dataHandler->copyMappingArray_merged['tt_content'][702] ?? 0);
        self::assertGreaterThan(0, $copiedParent, 'the parent was copied');
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('sys_file_reference');
        $queryBuilder->getRestrictions()->removeAll();
        $duplicate = $queryBuilder
            ->select('*')
            ->from('sys_file_reference')
            ->where($queryBuilder->expr()->eq('uid_foreign', $queryBuilder->createNamedParameter($copiedParent, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();
        self::assertIsArray($duplicate, 'the reference was duplicated with its parent');
        self::assertSame(1, (int)$duplicate['tx_mindfula11y_decorative']);
        self::assertSame('', (string)$duplicate['alternative']);
        self::assertSame([], $dataHandler->errorLog);
        self::assertSame([], $this->decorativeLogLevels());
    }

    /**
     * @return array<string, array{string, string, int}>
     */
    public static function duplicatingCommandProvider(): array
    {
        return [
            'copy the reference' => ['sys_file_reference', 'copy', 10],
            'localize the parent' => ['tt_content', 'localize', 1],
        ];
    }

    /**
     * The reference created from fixture reference 702 by the command.
     *
     * @return array<string, mixed>
     */
    private function fetchDuplicateReference(string $command, DataHandler $dataHandler): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('sys_file_reference');
        $queryBuilder->getRestrictions()->removeAll();
        $constraint = $command === 'copy'
            ? $queryBuilder->expr()->eq(
                'uid',
                $queryBuilder->createNamedParameter((int)($dataHandler->copyMappingArray_merged['sys_file_reference'][702] ?? 0), Connection::PARAM_INT)
            )
            : $queryBuilder->expr()->eq('l10n_parent', $queryBuilder->createNamedParameter(702, Connection::PARAM_INT));
        $row = $queryBuilder
            ->select('*')
            ->from('sys_file_reference')
            ->where($constraint, $queryBuilder->expr()->neq('uid', $queryBuilder->createNamedParameter(702, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();
        self::assertIsArray($row, 'the ' . $command . ' created a duplicate of reference 702');

        return $row;
    }

    /**
     * Error levels of the guard's own sys_log entries (0 = MESSAGE).
     *
     * @return list<int>
     */
    private function decorativeLogLevels(): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('sys_log');
        $queryBuilder->getRestrictions()->removeAll();

        return array_map('intval', $queryBuilder
            ->select('error')
            ->from('sys_log')
            ->where(
                $queryBuilder->expr()->eq('tablename', $queryBuilder->createNamedParameter('sys_file_reference')),
                $queryBuilder->expr()->like('details', $queryBuilder->createNamedParameter('%ecorative%'))
            )
            ->executeQuery()
            ->fetchFirstColumn());
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
