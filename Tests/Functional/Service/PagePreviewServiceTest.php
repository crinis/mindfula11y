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

use MindfulMarkup\MindfulA11y\Service\PagePreviewService;
use MindfulMarkup\MindfulA11y\Tests\Functional\AbstractAuthorizationTestCase;

/**
 * Frontend-visibility scoping for the URLs handed to the external scanner:
 * isPageFrontendAccessible() must exclude pages a public visitor cannot see —
 * hidden pages and fe_group-restricted pages, including restrictions
 * inherited from an ancestor via extendToSubpages. Without this, scan
 * demands would point the external scanner at URLs that resolve to access
 * errors — or worse, expect it to fetch member-only content.
 *
 * Supplement (PagePreviewSupplement.csv, uids 910+): page 910 "Members Area"
 * (fe_group=-2 show-at-any-login, extendToSubpages=1) with unrestricted
 * child 911.
 */
final class PagePreviewServiceTest extends AbstractAuthorizationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/PagePreviewSupplement.csv');
    }

    private function subject(): PagePreviewService
    {
        return $this->get(PagePreviewService::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchPage(int $uid): array
    {
        // NOT Connection::select(): that routes through TYPO3's QueryBuilder,
        // whose default restrictions silently filter the hidden fixture page.
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder
            ->select('*')
            ->from('pages')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid)))
            ->executeQuery()
            ->fetchAssociative();
        self::assertIsArray($row, 'page ' . $uid . ' exists');

        return $row;
    }

    public function testPublicVisiblePageIsFrontendAccessible(): void
    {
        $this->logInBackendUser(2);

        self::assertTrue($this->subject()->isPageFrontendAccessible($this->fetchPage(10)));
    }

    public function testHiddenPageIsNotFrontendAccessible(): void
    {
        $this->logInBackendUser(2);

        self::assertFalse($this->subject()->isPageFrontendAccessible($this->fetchPage(15)));
    }

    public function testFeGroupRestrictedPageIsNotFrontendAccessible(): void
    {
        $this->logInBackendUser(2);

        self::assertFalse($this->subject()->isPageFrontendAccessible($this->fetchPage(910)));
    }

    /**
     * The restriction cascades: page 911 carries no fe_group of its own, but
     * its parent 910 restricts with extendToSubpages — a public visitor never
     * reaches it, so neither may the scanner URL list.
     */
    public function testInheritedFeGroupRestrictionExcludesSubpage(): void
    {
        $this->logInBackendUser(2);

        self::assertFalse($this->subject()->isPageFrontendAccessible($this->fetchPage(911)));
    }
}
