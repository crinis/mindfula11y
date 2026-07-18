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
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Workspace awareness of the missing-alt list and count.
 *
 * The list must show the state an editor sees in their workspace: the
 * workspace version's alternative text decides, not the live row's.
 *
 * Supplementary fixture (WorkspaceAltTextSupplement.csv, uids >= 400), all on
 * page 10 / tt_content 100 / the mount-accessible file 1:
 *  - 400 live altless + 401 its workspace-1 version, still altless
 *    (a reference versioned by an unrelated draft edit must not vanish),
 *  - 402 live WITH alt + 403 its workspace-1 version, alt cleared
 *    (missing only in the draft — must appear in the workspace),
 *  - 404 live altless + 405 its workspace-1 version, alt filled
 *    (fixed in the draft — must disappear in the workspace).
 *  - 406 live decorative + 407 its workspace-1 version, non-decorative
 *    (missing only in the draft unless decorative references are included),
 *  - 408 live non-decorative + 409 its workspace-1 version, decorative
 *    (missing only live unless decorative references are included).
 * The shared scenario adds reference 1 (live altless, unversioned) and
 * reference 2 (file outside the user's file mount, always filtered).
 */
final class AltTextFinderWorkspaceTest extends AbstractAuthorizationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/WorkspaceAltTextSupplement.csv');
    }

    private function subject(): AltTextFinderService
    {
        return $this->get(AltTextFinderService::class);
    }

    /** @return list<int> */
    private function foundReferenceUids(
        bool $includeDecorative = false,
        bool $filterFileMetaData = true,
        bool $includeAllReferences = false,
    ): array
    {
        return array_map(
            static fn(AltlessFileReference $reference): int => (int)$reference->getUid(),
            $this->subject()->getAltlessFileReferences(
                10,
                0,
                0,
                [],
                filterFileMetaData: $filterFileMetaData,
                tableName: 'tt_content',
                includeDecorative: $includeDecorative,
                includeAllReferences: $includeAllReferences,
            ),
        );
    }

    public function testLiveWorkspaceListsLiveAltlessReferences(): void
    {
        $this->logInBackendUser(2);

        self::assertSame([1, 400, 404, 408], $this->foundReferenceUids());
        self::assertSame(4, $this->subject()->countAltlessFileReferences(10, 0, 0, [], tableName: 'tt_content'));
    }

    public function testWorkspaceListsTheWorkspaceVersionsState(): void
    {
        $this->logInBackendUser(2, 1);

        self::assertSame([1, 401, 403, 407], $this->foundReferenceUids());
        self::assertSame(4, $this->subject()->countAltlessFileReferences(10, 0, 0, [], tableName: 'tt_content'));
    }

    public function testLiveWorkspaceCanIncludeDecorativeReferences(): void
    {
        $this->logInBackendUser(2);

        self::assertSame([1, 400, 404, 406, 408], $this->foundReferenceUids(includeDecorative: true));
        self::assertSame(5, $this->subject()->countAltlessFileReferences(
            10,
            0,
            0,
            [],
            tableName: 'tt_content',
            includeDecorative: true,
        ));
    }

    public function testWorkspaceCanIncludeItsDecorativeReferences(): void
    {
        $this->logInBackendUser(2, 1);

        self::assertSame([1, 401, 403, 407, 409], $this->foundReferenceUids(true));
        self::assertSame(5, $this->subject()->countAltlessFileReferences(
            10,
            0,
            0,
            [],
            tableName: 'tt_content',
            includeDecorative: true,
        ));
    }

    public function testLiveWorkspaceCanIncludeReferencesThatAlreadyHaveAlternativeText(): void
    {
        $this->logInBackendUser(2);

        self::assertSame([1, 400, 402, 404, 408], $this->foundReferenceUids(includeAllReferences: true));
        self::assertSame(5, $this->subject()->countAltlessFileReferences(
            10,
            0,
            0,
            [],
            tableName: 'tt_content',
            includeAllReferences: true,
        ));
    }

    public function testWorkspaceCanIncludeReferencesThatAlreadyHaveAlternativeText(): void
    {
        $this->logInBackendUser(2, 1);

        self::assertSame([1, 401, 403, 405, 407], $this->foundReferenceUids(includeAllReferences: true));
        self::assertSame(5, $this->subject()->countAltlessFileReferences(
            10,
            0,
            0,
            [],
            tableName: 'tt_content',
            includeAllReferences: true,
        ));
    }

    public function testAllReferencesStillRespectsDecorativeFilter(): void
    {
        $this->logInBackendUser(2);

        self::assertSame([1, 400, 402, 404, 406, 408], $this->foundReferenceUids(
            includeDecorative: true,
            includeAllReferences: true,
        ));
    }

    public function testMetadataFilterStillAppliesWhenDecorativeReferencesAreIncluded(): void
    {
        $this->logInBackendUser(2);
        $this->get(ConnectionPool::class)
            ->getConnectionForTable('sys_file_metadata')
            ->update('sys_file_metadata', ['alternative' => 'Inherited alternative'], ['file' => 1]);

        self::assertSame([], $this->foundReferenceUids(true, true));
        self::assertSame(0, $this->subject()->countAltlessFileReferences(
            10,
            0,
            0,
            [],
            filterFileMetaData: true,
            tableName: 'tt_content',
            includeDecorative: true,
        ));
        self::assertSame([1, 400, 404, 406, 408], $this->foundReferenceUids(
            includeDecorative: true,
            filterFileMetaData: false,
        ));
        self::assertSame(5, $this->subject()->countAltlessFileReferences(
            10,
            0,
            0,
            [],
            filterFileMetaData: false,
            tableName: 'tt_content',
            includeDecorative: true,
        ));
        self::assertSame([], $this->foundReferenceUids(
            includeDecorative: true,
            filterFileMetaData: true,
            includeAllReferences: true,
        ));
        self::assertSame(0, $this->subject()->countAltlessFileReferences(
            10,
            0,
            0,
            [],
            filterFileMetaData: true,
            tableName: 'tt_content',
            includeDecorative: true,
            includeAllReferences: true,
        ));
    }
}
