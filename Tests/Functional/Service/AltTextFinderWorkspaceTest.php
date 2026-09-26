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
use MindfulMarkup\MindfulA11y\Domain\Repository\AltlessFileReferenceRepository;
use MindfulMarkup\MindfulA11y\Service\AltTextFinderService;
use MindfulMarkup\MindfulA11y\Tests\Functional\AbstractAuthorizationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
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
            $this->subject()->findAltlessFileReferencePage(
                10,
                0,
                0,
                [],
                1,
                100,
                filterFileMetaData: $filterFileMetaData,
                tableName: 'tt_content',
                includeDecorative: $includeDecorative,
                includeAllReferences: $includeAllReferences,
            )['items'],
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

    /**
     * The metadata fallback is workspace-mutable state like the reference's
     * own alternative: a draft that ADDS metadata alternative text must hide
     * the file's references in that workspace while the live list, judging
     * the live metadata row, keeps showing them.
     */
    public function testWorkspaceMetadataDraftWithAlternativeHidesReferences(): void
    {
        $this->insertMetadataVersion(500, 'Alt added in metadata draft');

        $this->logInBackendUser(2, 1);
        self::assertSame([], $this->foundReferenceUids());
        self::assertSame(0, $this->subject()->countAltlessFileReferences(10, 0, 0, [], tableName: 'tt_content'));
    }

    public function testLiveListIgnoresWorkspaceMetadataDraft(): void
    {
        $this->insertMetadataVersion(500, 'Alt added in metadata draft');

        $this->logInBackendUser(2);
        self::assertSame([1, 400, 404, 408], $this->foundReferenceUids());
    }

    /**
     * The reverse direction: live metadata has alternative text (references
     * hidden live), a draft clears it — the workspace list must surface the
     * references the draft actually renders without a fallback.
     */
    public function testWorkspaceMetadataDraftClearingAlternativeSurfacesReferences(): void
    {
        $this->setLiveMetadataAlternative('Inherited alternative');
        $this->insertMetadataVersion(500, '');

        $this->logInBackendUser(2, 1);
        self::assertSame([1, 401, 403, 407], $this->foundReferenceUids());
        self::assertSame(4, $this->subject()->countAltlessFileReferences(10, 0, 0, [], tableName: 'tt_content'));
    }

    /**
     * A delete placeholder version means the draft has no metadata at all —
     * no fallback text exists there, so the references must surface.
     */
    public function testWorkspaceMetadataDeletePlaceholderSurfacesReferences(): void
    {
        $this->setLiveMetadataAlternative('Inherited alternative');
        $this->insertMetadataVersion(500, 'Inherited alternative', 2);

        $this->logInBackendUser(2, 1);
        self::assertSame([1, 401, 403, 407], $this->foundReferenceUids());
    }

    /**
     * The live list must judge the live metadata row even when some
     * workspace's draft cleared the alternative: before the metadata join was
     * actually workspace-restricted, the draft row joined alongside the live
     * one and surfaced (and double-counted) references in every workspace.
     */
    public function testLiveListIgnoresWorkspaceMetadataDraftClearingAlternative(): void
    {
        $this->setLiveMetadataAlternative('Inherited alternative');
        $this->insertMetadataVersion(500, '');

        $this->logInBackendUser(2);
        self::assertSame([], $this->foundReferenceUids());
        self::assertSame(0, $this->subject()->countAltlessFileReferences(10, 0, 0, [], tableName: 'tt_content'));
    }

    /**
     * The rendering path must reach the SAME verdict as the listing filter.
     * FAL resolves metadata through WorkspaceRestriction, which returns the
     * live row for a file whose metadata was edited in a workspace, and core's
     * FAL metadata overlay listener is frontend-only — so reading the file
     * property in a backend module reports live text the draft no longer has.
     * A reference the list counts as missing would then still advertise an
     * inherited alternative, telling the editor an image is covered when in
     * their workspace it is not.
     */
    #[DataProvider('emptyingMetadataDraftProvider')]
    public function testEffectiveMetadataAlternativeFollowsTheWorkspaceDraft(string $alternative, int $versionState): void
    {
        $this->setLiveMetadataAlternative('Inherited alternative');
        $this->insertMetadataVersion(500, $alternative, $versionState);
        $this->logInBackendUser(2, 1);

        $repository = $this->get(AltlessFileReferenceRepository::class);

        // Cleared and deleted differ ('' vs null) but mean the same to the
        // caller: there is no inherited alternative to advertise.
        self::assertSame(
            '',
            (string)$repository->findEffectiveMetaDataAlternative(1, 1),
            'the draft carries no inherited alternative',
        );
        self::assertSame(
            'Inherited alternative',
            $repository->findEffectiveMetaDataAlternative(1, 0),
            'the live workspace is unaffected by the draft',
        );
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function emptyingMetadataDraftProvider(): array
    {
        return [
            'draft clears the alternative' => ['', 0],
            'draft deletes the metadata row' => ['Inherited alternative', 2],
        ];
    }

    /**
     * A draft that still carries text must keep advertising it — the
     * anti-vacuous counterpart to the two cases above.
     */
    public function testEffectiveMetadataAlternativeUsesTheDraftsOwnText(): void
    {
        $this->setLiveMetadataAlternative('Live alternative');
        $this->insertMetadataVersion(500, 'Draft alternative');
        $this->logInBackendUser(2, 1);

        self::assertSame(
            'Draft alternative',
            $this->get(AltlessFileReferenceRepository::class)->findEffectiveMetaDataAlternative(1, 1),
        );
    }

    /**
     * BackendUserAuthentication::$workspace is -99 for a user who may neither
     * work live nor reach any workspace, and that value reaches the repository
     * verbatim. Branching the metadata filter on "=== 0 / > 0" left such a user
     * with NO metadata filter at all, so every reference covered by file
     * metadata was listed and counted as missing alternative text.
     */
    public function testNoWorkspaceAvailableStillAppliesTheMetadataFilter(): void
    {
        $this->setLiveMetadataAlternative('Inherited alternative');

        $backendUser = $this->logInBackendUser(2);
        $backendUser->workspace = -99;

        self::assertSame([], $this->foundReferenceUids(), 'metadata alternative still filters the listing');
        self::assertSame(0, $this->subject()->countAltlessFileReferences(
            10,
            0,
            0,
            [],
            tableName: 'tt_content',
        ));
    }

    private function insertMetadataVersion(int $uid, string $alternative, int $versionState = 0): void
    {
        $this->get(ConnectionPool::class)
            ->getConnectionForTable('sys_file_metadata')
            ->insert('sys_file_metadata', [
                'uid' => $uid,
                'pid' => 0,
                'file' => 1,
                'alternative' => $alternative,
                't3ver_oid' => 1,
                't3ver_wsid' => 1,
                't3ver_state' => $versionState,
            ]);
    }

    private function setLiveMetadataAlternative(string $alternative): void
    {
        $this->get(ConnectionPool::class)
            ->getConnectionForTable('sys_file_metadata')
            ->update('sys_file_metadata', ['alternative' => $alternative], ['uid' => 1]);
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
