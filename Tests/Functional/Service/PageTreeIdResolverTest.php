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

use MindfulMarkup\MindfulA11y\Service\PageTreeIdResolver;
use MindfulMarkup\MindfulA11y\Tests\Functional\AbstractAuthorizationTestCase;

/**
 * PageTreeIdResolver builds the page-id scope every missing-alt query and
 * scanner URL list is constrained to. Its authorization dimensions: the
 * PAGE_SHOW perms clause (including group-bitmask resolution via calcPerms'
 * SQL counterpart), self-contained webmount containment for an explicit page
 * id, and the user-TSconfig hideRecords display filter.
 */
final class PageTreeIdResolverTest extends AbstractAuthorizationTestCase
{
    private function subject(): PageTreeIdResolver
    {
        return $this->get(PageTreeIdResolver::class);
    }

    public function testZeroLevelsContainsOnlyTheSelectedPage(): void
    {
        $this->logInBackendUser(2);

        self::assertSame([10], $this->subject()->getPageTreeIds(10, 0));
    }

    public function testEditorTreeContainsGrantedPagesButNotDeniedOnes(): void
    {
        $this->logInBackendUser(2);

        $ids = $this->subject()->getPageTreeIds(1, 3);

        self::assertContains(10, $ids, 'editable page in scope');
        self::assertContains(11, $ids, 'show-only page still readable');
        self::assertContains(16, $ids, 'group-1-only page readable via owner-group bitmask');
        self::assertNotContains(14, $ids, 'no-PAGE_SHOW page excluded by the perms clause');
        self::assertNotContains(20, $ids, 'second site root is not part of the requested tree');
    }

    /**
     * Self-contained mount containment: an explicit page id outside every
     * db mount yields an empty scope regardless of the page's own perms
     * (perms_everybody 19 on page 20) — the resolver does not rely on its
     * callers' readPageAccess() pre-checks.
     */
    public function testExplicitPageIdOutsideWebmountYieldsEmptyScope(): void
    {
        $this->logInBackendUser(2);

        self::assertSame([], $this->subject()->getPageTreeIds(20, 3));
    }

    public function testAdminResolvesTreesOutsideAnyMount(): void
    {
        $this->logInBackendUser(1);

        self::assertContains(20, $this->subject()->getPageTreeIds(20, 3));
    }

    /**
     * options.hideRecords.pages is a display filter, not a permission — but
     * the module's queries must still honour it, or "hidden" pages would
     * resurface through accessibility listings.
     */
    public function testHideRecordsUserTsConfigFiltersPagesFromTheScope(): void
    {
        $this->getConnectionPool()->getConnectionForTable('be_users')->update(
            'be_users',
            ['TSconfig' => 'options.hideRecords.pages = 11'],
            ['uid' => 2],
        );
        $this->logInBackendUser(2);

        $ids = $this->subject()->getPageTreeIds(1, 3);

        self::assertContains(10, $ids);
        self::assertNotContains(11, $ids, 'hideRecords page filtered from the scope');
    }
}
