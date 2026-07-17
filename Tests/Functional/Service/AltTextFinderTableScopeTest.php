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
        self::assertSame([], $this->subject()->getAltlessFileReferences(10, 0, 0, []));
    }

    public function testQualifiedUserStaysScopedToTheRequestedPageTree(): void
    {
        $this->logInBackendUser(2);

        $foundUids = array_map(
            static fn(AltlessFileReference $reference): int => (int)$reference->getUid(),
            $this->subject()->getAltlessFileReferences(10, 0, 0, []),
        );

        self::assertSame([1], $foundUids, 'only page 10 references — the page-20 canary (801) stays out of scope');
    }
}
