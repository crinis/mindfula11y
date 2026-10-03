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
 * child 911; page 912 "Guests Only" (fe_group=-1 hide-at-login,
 * extendToSubpages=1) with unrestricted child 913; page 914 (fe_group=-1,3:
 * guests plus one frontend group).
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

    /**
     * "Hide at login" (-1) hides a page from logged-in visitors only; the
     * scanner visits anonymously and sees it, on the page itself and when
     * inherited through extendToSubpages.
     */
    public function testHideAtLoginPageIsFrontendAccessible(): void
    {
        $this->logInBackendUser(2);

        self::assertTrue($this->subject()->isPageFrontendAccessible($this->fetchPage(912)));
        self::assertTrue($this->subject()->isPageFrontendAccessible($this->fetchPage(913)));
    }

    /**
     * fe_group grants visibility to ANY listed group; an anonymous visitor
     * matches -1, so a list that also names a frontend group stays public.
     */
    public function testHideAtLoginCombinedWithAGroupIsFrontendAccessible(): void
    {
        $this->logInBackendUser(2);

        self::assertTrue($this->subject()->isPageFrontendAccessible($this->fetchPage(914)));
    }

    /**
     * The frontend resolves a translation through its default-language page:
     * PageRepository::getPage() applies hidden, start/end time and fe_group
     * to the default-language row before overlaying the translation. Page 30
     * (French translation of page 10) carries none of these itself, so only
     * the default-language row can tell the scanner would get a 404.
     */
    public function testTranslationOfAHiddenDefaultLanguagePageIsNotVisible(): void
    {
        $this->updatePage(10, ['hidden' => 1]);
        $this->logInBackendUser(2);

        self::assertFalse($this->subject()->isPageVisible($this->fetchPage(30)));
        self::assertFalse($this->subject()->isPageFrontendAccessible($this->fetchPage(30)));
    }

    public function testTranslationOfADefaultLanguagePageOutsideItsPublicationPeriodIsNotVisible(): void
    {
        $this->updatePage(10, ['endtime' => time() - 3600]);
        $this->logInBackendUser(2);

        self::assertFalse($this->subject()->isPageVisible($this->fetchPage(30)));
    }

    public function testTranslationOfAGroupRestrictedDefaultLanguagePageIsNotFrontendAccessible(): void
    {
        $this->updatePage(10, ['fe_group' => '-2']);
        $this->logInBackendUser(2);

        self::assertTrue($this->subject()->isPageVisible($this->fetchPage(30)), 'fe_group is no visibility setting');
        self::assertFalse($this->subject()->isPageFrontendAccessible($this->fetchPage(30)));
    }

    public function testTranslationOfAPublicDefaultLanguagePageIsFrontendAccessible(): void
    {
        $this->logInBackendUser(2);

        self::assertTrue($this->subject()->isPageVisible($this->fetchPage(30)));
        self::assertTrue($this->subject()->isPageFrontendAccessible($this->fetchPage(30)));
    }

    /**
     * "Hide default language of page" (l18n_cfg bit 1) makes the frontend
     * answer the default language with a 404 while its translations stay
     * reachable.
     */
    public function testDefaultLanguageHiddenByTheTranslationSettingIsNotVisibleButItsTranslationIs(): void
    {
        $this->updatePage(10, ['l18n_cfg' => 1]);
        $this->logInBackendUser(2);

        self::assertFalse($this->subject()->isPageVisible($this->fetchPage(10)));
        self::assertFalse($this->subject()->isPageFrontendAccessible($this->fetchPage(10)));
        self::assertTrue($this->subject()->isPageFrontendAccessible($this->fetchPage(30)));
    }

    /**
     * Page TSconfig switches the scanner off per page tree ("scan.enable = 0"
     * on page 17). A page-tree scan started further up must respect that
     * instead of scanning — and AI-reviewing — the opted-out pages.
     */
    public function testPageTreeUrlsSkipPagesThatDisableScanning(): void
    {
        $this->writeDefaultSiteConfiguration();
        $this->logInBackendUser(2);

        $urls = $this->subject()->generatePageUrls(1, 0, 1);

        self::assertContains('https://example.com/editable', $urls, 'fixture guard: scanned siblings are listed');
        self::assertNotContains('https://example.com/scan-disabled', $urls);
    }

    /**
     * @param array<string, int|string> $fields
     */
    private function updatePage(int $uid, array $fields): void
    {
        $this->getConnectionPool()->getConnectionForTable('pages')->update('pages', $fields, ['uid' => $uid]);
    }
}
