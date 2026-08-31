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
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 */

namespace MindfulMarkup\MindfulA11y\Tests\Functional\Service;

use MindfulMarkup\MindfulA11y\Enum\InteractiveLabelType;
use MindfulMarkup\MindfulA11y\Service\InteractiveLabelFinderService;
use MindfulMarkup\MindfulA11y\Tests\Functional\AbstractAuthorizationTestCase;

/**
 * Functional tests for InteractiveLabelFinderService::find(), focused on the
 * `editable` flag it annotates onto every finding.
 *
 * The flag was added to fix the Interactive Labels backend module: its Save
 * button used to render as always enabled, for every finding, regardless of
 * whether the current backend user could actually write the target field —
 * unlike every other editable-record feature in this extension
 * (AltlessFileReferenceViewHelper gates its `record-edit-link` attribute the
 * same way, via PermissionService::checkTableWriteAccess() +
 * checkNonExcludeFields() + checkRecordEditAccess()). `find()` mirrors that
 * exact three-check gate per label.
 *
 * Uses the shared fixture (Fixtures/AuthorizationScenario.csv, see
 * AbstractAuthorizationTestCase for the full map): tt_content 100/101 on page
 * 10 are ordinary editable content, 105 carries a record-level editlock.
 */
final class InteractiveLabelFinderServiceTest extends AbstractAuthorizationTestCase
{
    private function subject(): InteractiveLabelFinderService
    {
        return $this->get(InteractiveLabelFinderService::class);
    }

    /**
     * @param array<int, array<string, mixed>> $labels
     *
     * @return array<string, mixed>
     */
    private function labelByUid(array $labels, int $uid): array
    {
        foreach ($labels as $label) {
            if ((int)($label['uid'] ?? 0) === $uid) {
                return $label;
            }
        }

        self::fail(sprintf('No finding for tt_content:%d in the result.', $uid));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function findOnPage10(): array
    {
        return $this->subject()->find(10, 0, 'en', 'tt_content', ['header'], InteractiveLabelType::BUTTON);
    }

    public function testFullEditorMayEditOrdinaryContentButNotARecordEditLockedOne(): void
    {
        $this->logInBackendUser(2);

        $labels = $this->findOnPage10();

        self::assertTrue($this->labelByUid($labels, 100)['editable'], 'ordinary content must be editable for the full editor');
        self::assertTrue($this->labelByUid($labels, 101)['editable'], 'ordinary content must be editable for the full editor');
        self::assertFalse(
            $this->labelByUid($labels, 105)['editable'],
            'record editlock (uid 105) must deny editing even for the full editor',
        );
    }

    public function testUserWithoutTableWriteAccessGetsNoEditableFindings(): void
    {
        // Group 3 ("no_content_modify", be_users uid 4 "editor_no_content_modify"):
        // tt_content is readable but missing from tables_modify entirely.
        $this->logInBackendUser(4);

        $labels = $this->findOnPage10();

        self::assertNotEmpty($labels, 'the page still has interactive labels to report');
        foreach ($labels as $label) {
            self::assertFalse(
                $label['editable'],
                sprintf('uid %d must not be editable without tt_content write access', $label['uid']),
            );
        }
    }

    /**
     * Anti-vacuous counterpart to both cases above: a denied write must only
     * flip `editable` to false — it must never remove the finding from the
     * result. Hiding an accessibility issue from a user who cannot personally
     * fix it would make it invisible to everyone until an admin happens by;
     * the module's job is to report the issue, editing it is a separate
     * capability.
     */
    public function testPermissionGatingNeverHidesTheFindingItself(): void
    {
        $this->logInBackendUser(4);

        $label = $this->labelByUid($this->findOnPage10(), 100);

        self::assertFalse($label['editable']);
        self::assertSame('Editable content', $label['value']);
        self::assertSame('tt_content', $label['table']);
        self::assertSame('header', $label['field']);
    }

    public function testAdminMayEditEveryFindingIncludingRecordEditLocked(): void
    {
        // Anti-vacuous baseline for testFullEditorMayEditOrdinaryContentButNotARecordEditLockedOne:
        // pins that editlock really is what denies uid 105 above, not some
        // other property of the "full editor" user — an admin bypasses every
        // check inside checkRecordEditAccess() except the structural ones.
        $this->logInBackendUser(1);

        $labels = $this->findOnPage10();

        foreach ([100, 101, 105] as $uid) {
            self::assertTrue($this->labelByUid($labels, $uid)['editable'], sprintf('admin must be able to edit uid %d', $uid));
        }
    }
}
