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

use MindfulMarkup\MindfulA11y\Domain\Model\AltlessFileReference;
use MindfulMarkup\MindfulA11y\Service\AltTextFinderService;
use MindfulMarkup\MindfulA11y\Tests\Functional\AbstractAuthorizationTestCase;

/**
 * The parent-table scope of the missing-alt query must fail CLOSED.
 *
 * The table clauses carry every parent-table, field, page-id, and authMode
 * predicate. A user who passes the module gate (sys_file_reference read +
 * the alternative grant) but has NO readable table with file columns must
 * get an empty result — not a query whose scope predicates all vanished,
 * which would enumerate image references from every table and page tree the
 * installation has (limited only by FAL file mounts).
 *
 * Supplementary fixture (EmptyTableScopeSupplement.csv, uids >= 800):
 *  - be_groups/be_users 800 "editor_file_tables_only": module access and
 *    sys_file* tables only — no pages/tt_content/tx_a11ytest_content in
 *    tables_select, so getTablesWithFiles() yields no table.
 *  - sys_file_reference 801 on page 20 (outside the fixture's page-10 tree,
 *    parent tt_content 106, mount-accessible file 1): the enumeration canary.
 */
final class AltTextFinderTableScopeTest extends AbstractAuthorizationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/EmptyTableScopeSupplement.csv');
    }

    private function subject(): AltTextFinderService
    {
        return $this->get(AltTextFinderService::class);
    }

    public function testUserWithoutQualifyingTablesGetsNothing(): void
    {
        $this->logInBackendUser(800);

        self::assertSame(0, $this->subject()->countAltlessFileReferences(10, 0, 0, []));
        self::assertSame([], $this->subject()->findAltlessFileReferencePage(10, 0, 0, [], 1, 100)['items']);
    }

    public function testQualifiedUserStaysScopedToTheRequestedPageTree(): void
    {
        $this->logInBackendUser(2);

        $foundUids = array_map(
            static fn(AltlessFileReference $reference): int => (int)$reference->getUid(),
            $this->subject()->findAltlessFileReferencePage(10, 0, 0, [], 1, 100)['items'],
        );

        self::assertSame([1], $foundUids, 'only page 10 references — the page-20 canary (801) stays out of scope');
    }

    /**
     * CType/authMode dimension of the table scope: the tt_content clause
     * restricts parent rows to the CType values the user's explicit_allowdeny
     * grants. Reference 1's parent (tt_content 100) is "textmedia"; user 7 may
     * only edit "text", so the reference must vanish from their listing and
     * count. Anti-vacuous anchor: testQualifiedUserStaysScopedToTheRequestedPageTree
     * pins [1] for the full editor on the identical query.
     */
    public function testCtypeRestrictedUserDoesNotSeeReferencesOnDisallowedContentTypes(): void
    {
        $this->logInBackendUser(7);

        self::assertSame(0, $this->subject()->countAltlessFileReferences(10, 0, 0, []));
        self::assertSame([], $this->subject()->findAltlessFileReferencePage(10, 0, 0, [], 1, 100)['items']);
    }

    /**
     * @return list<int>
     */
    private function visibleReferenceUids(int $languageId = 0): array
    {
        return array_map(
            static fn(AltlessFileReference $reference): int => (int)$reference->getUid(),
            $this->subject()->findAltlessFileReferencePage(10, 0, $languageId, [], 1, 100)['items'],
        );
    }

    /**
     * A reference stored for "All languages" (sys_language_uid -1) — what
     * FormEngine creates inside "All languages" content — renders in every
     * language, so every language's listing and count must include it.
     * Fixture: AllLanguagesReferenceSupplement.csv (tt_content 410 and its
     * reference 410 on page 10, both -1, file 1 without metadata text).
     */
    public function testAllLanguagesReferenceIsListedAndCountedForEveryLanguage(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/AllLanguagesReferenceSupplement.csv');
        $this->logInBackendUser(2);

        self::assertSame([1, 410], $this->visibleReferenceUids(0), 'default language: its own reference plus the all-languages one');
        self::assertSame(2, $this->subject()->countAltlessFileReferences(10, 0, 0, []));
        self::assertSame([410], $this->visibleReferenceUids(1), 'translation: the all-languages reference renders there too');
        self::assertSame(1, $this->subject()->countAltlessFileReferences(10, 0, 1, []));
    }

    /**
     * The frontend reaches an inline reference through its parent record: an
     * "All languages" reference attached to a default-language parent (411 on
     * tt_content 100) renders where that parent does — in the default
     * language — and not in a translation, which renders the translated
     * parent's own references. Only an all-languages parent (410) carries it
     * into every language.
     */
    public function testAllLanguagesReferenceFollowsItsParentsLanguage(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/AllLanguagesReferenceSupplement.csv');
        $this->getConnectionPool()->getConnectionForTable('sys_file_reference')->insert('sys_file_reference', [
            'uid' => 411,
            'pid' => 10,
            'uid_local' => 1,
            'uid_foreign' => 100,
            'tablenames' => 'tt_content',
            'fieldname' => 'assets',
            'sys_language_uid' => -1,
            'alternative' => '',
        ]);
        $this->logInBackendUser(2);

        self::assertSame([1, 410, 411], $this->visibleReferenceUids(0), 'all of them render in the default language');
        self::assertSame(3, $this->subject()->countAltlessFileReferences(10, 0, 0, []));
        self::assertSame([410], $this->visibleReferenceUids(1), 'the default-language parent keeps 411 out of the translation');
        self::assertSame(1, $this->subject()->countAltlessFileReferences(10, 0, 1, []));
    }

    /**
     * File metadata never has an "All languages" row, so an all-languages
     * reference falls back to the metadata of the language it renders in —
     * the language being listed, judged like that language's own references.
     */
    public function testAllLanguagesReferenceIsJudgedByTheListedLanguagesMetadata(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/AllLanguagesReferenceSupplement.csv');
        $this->getConnectionPool()->getConnectionForTable('sys_file_metadata')
            ->update('sys_file_metadata', ['alternative' => 'Metadata text'], ['uid' => 1]);
        $this->logInBackendUser(2);

        self::assertSame([], $this->visibleReferenceUids(0), 'the default-language metadata text covers both references');
        self::assertSame([410], $this->visibleReferenceUids(1), 'no French metadata text: still missing in French');
    }

    private function setNullableType(?string $value): void
    {
        $this->getConnectionPool()
            ->getConnectionForTable('tt_content')
            ->update('tt_content', ['tx_a11ytest_nullabletype' => $value], ['uid' => 100]);
    }

    /**
     * NULL dimension of the same authMode clause: core's checkAuthMode() casts
     * to string before its "blank is always allowed" short-circuit, so a NULL
     * value is allowed — but SQL NULL matches no IN () list, not even IN ('').
     * Without an explicit IS NULL branch the reference would vanish for the
     * non-admin editor although they are allowed to see it. Anti-vacuous
     * anchor: testQualifiedUserStaysScopedToTheRequestedPageTree pins the same
     * [1] for this user on the identical query.
     */
    public function testNullableAuthModeColumnStoringNullStaysVisible(): void
    {
        $this->setNullableType(null);
        $this->logInBackendUser(2);

        self::assertSame([1], $this->visibleReferenceUids(), 'NULL is a blank authMode value and must not filter the row out');
        self::assertSame(1, $this->subject()->countAltlessFileReferences(10, 0, 0, []));
    }

    /**
     * The IN () half of the same predicate, which the NULL case alone cannot
     * pin: a granted non-blank value must stay visible. Without this, replacing
     * the `IN () OR IS NULL` composite with a bare IS NULL would still pass.
     */
    public function testNullableAuthModeColumnStoringGrantedValueStaysVisible(): void
    {
        $this->getConnectionPool()->getConnectionForTable('be_groups')->update(
            'be_groups',
            ['explicit_allowdeny' => 'tt_content:CType:text,tt_content:CType:textmedia,tt_content:tx_a11ytest_nullabletype:granted'],
            ['uid' => 1],
        );
        $this->setNullableType('granted');
        $this->logInBackendUser(2);

        self::assertSame([1], $this->visibleReferenceUids(), 'a granted authMode value must not filter the row out');
    }

    /**
     * Anti-vacuous counterpart to both cases above: the column really does
     * filter. Without this, a predicate degenerating to "always true" would
     * satisfy every other authMode assertion in this suite.
     */
    public function testNullableAuthModeColumnStoringUngrantedValueIsFilteredOut(): void
    {
        $this->setNullableType('ungranted');
        $this->logInBackendUser(2);

        self::assertSame([], $this->visibleReferenceUids(), 'an ungranted authMode value must hide the row');
        self::assertSame(0, $this->subject()->countAltlessFileReferences(10, 0, 0, []));
    }

    /**
     * The parent row is what carries the authMode value, so it must exist for
     * the reference to be authorized at all. leftJoin() materializes the
     * deleted/workspace restrictions into the ON clause, so a soft-deleted
     * parent supplies NULL for every parent column — which the IS NULL branch
     * would otherwise read as "blank value, therefore allowed", handing the
     * reference to a user with no grant for what the parent actually stored.
     */
    public function testReferenceWithDeletedParentIsNotUnlockedByTheNullBranch(): void
    {
        $this->getConnectionPool()
            ->getConnectionForTable('tt_content')
            ->update('tt_content', ['deleted' => 1], ['uid' => 100]);

        // User 7 may only edit CType "text"; tt_content 100 is "textmedia".
        $this->logInBackendUser(7);

        self::assertSame([], $this->visibleReferenceUids(), 'a deleted parent must not unlock its references');
        self::assertSame(0, $this->subject()->countAltlessFileReferences(10, 0, 0, []));
    }
}
