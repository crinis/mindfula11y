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
    private function foundReferenceUids(): array
    {
        return array_map(
            static fn(AltlessFileReference $reference): int => (int)$reference->getUid(),
            $this->subject()->getAltlessFileReferences(10, 0, 0, [], tableName: 'tt_content'),
        );
    }

    public function testLiveWorkspaceListsLiveAltlessReferences(): void
    {
        $this->logInBackendUser(2);

        self::assertSame([1, 400, 404], $this->foundReferenceUids());
        self::assertSame(3, $this->subject()->countAltlessFileReferences(10, 0, 0, [], tableName: 'tt_content'));
    }

    public function testWorkspaceListsTheWorkspaceVersionsState(): void
    {
        $this->logInBackendUser(2, 1);

        self::assertSame([1, 401, 403], $this->foundReferenceUids());
        self::assertSame(3, $this->subject()->countAltlessFileReferences(10, 0, 0, [], tableName: 'tt_content'));
    }
}
