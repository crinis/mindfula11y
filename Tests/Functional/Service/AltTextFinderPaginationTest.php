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
 * The Missing Alternative Text list counts and pages in ONE pass over the
 * permission-filtered stream: the total covers every accessible match, the
 * items are exactly the requested page, and an out-of-range page clamps to
 * the last one.
 *
 * Beyond the fixture's reference 1 (tt_content 100 on page 10, the only one
 * the full editor sees there), 600 more identical references (uids
 * 1001-1600) are generated so the stream spans more than one repository
 * chunk (500 rows).
 */
final class AltTextFinderPaginationTest extends AbstractAuthorizationTestCase
{
    private const GENERATED_FIRST_UID = 1001;
    private const GENERATED_COUNT = 600;

    protected function setUp(): void
    {
        parent::setUp();

        $rows = [];
        for ($uid = self::GENERATED_FIRST_UID; $uid < self::GENERATED_FIRST_UID + self::GENERATED_COUNT; $uid++) {
            $rows[] = [$uid, 10, 1, 100, 'tt_content', 'assets', 0, 0, ''];
        }
        $this->getConnectionPool()->getConnectionForTable('sys_file_reference')->bulkInsert(
            'sys_file_reference',
            $rows,
            ['uid', 'pid', 'uid_local', 'uid_foreign', 'tablenames', 'fieldname', 'sys_language_uid', 'tx_mindfula11y_decorative', 'alternative'],
        );
    }

    /**
     * @param array{items: list<AltlessFileReference>, total: int} $page
     * @return list<int>
     */
    private function uids(array $page): array
    {
        return array_map(static fn(AltlessFileReference $reference): int => (int)$reference->getUid(), $page['items']);
    }

    public function testPageSpanningChunksCarriesTheTotalAndExactlyItsSlice(): void
    {
        $this->logInBackendUser(2);

        $page = $this->get(AltTextFinderService::class)->findAltlessFileReferencePage(10, 0, 0, [], 6, 100);

        self::assertSame(601, $page['total']);
        self::assertSame(6, $page['page']);
        // Stream order: 1, 1001, 1002, … — page 6 starts at position 500,
        // the first row of the second chunk.
        self::assertSame(range(1500, 1599), $this->uids($page));
    }

    public function testOutOfRangePageClampsToTheLastPage(): void
    {
        $this->logInBackendUser(2);

        $page = $this->get(AltTextFinderService::class)->findAltlessFileReferencePage(10, 0, 0, [], PHP_INT_MAX, 100);

        self::assertSame(601, $page['total']);
        self::assertSame(7, $page['page'], 'the result names the clamped page it returns');
        self::assertSame([1600], $this->uids($page));
    }

    public function testCountAgreesWithThePagedTotal(): void
    {
        $this->logInBackendUser(2);

        self::assertSame(601, $this->get(AltTextFinderService::class)->countAltlessFileReferences(10, 0, 0, []));
    }
}
