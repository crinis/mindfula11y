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

use MindfulMarkup\MindfulA11y\Service\BackendPageLanguageService;
use MindfulMarkup\MindfulA11y\Tests\Functional\AbstractAuthorizationTestCase;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;
use TYPO3\CMS\Core\Site\SiteFinder;

final class BackendPageLanguageServiceTest extends AbstractAuthorizationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->writeDefaultSiteConfiguration();
    }

    private function subject(): BackendPageLanguageService
    {
        return $this->get(BackendPageLanguageService::class);
    }

    public function testReturnsConfiguredPermittedPageTranslationsInSiteOrder(): void
    {
        $this->logInBackendUser(2);

        self::assertSame([0, 1], $this->languageIdsForPage(10));
        self::assertSame(0, $this->subject()->getFirstSelectableLanguageId($this->siteForPage(10), 10));
    }

    public function testFiltersTranslationsByBackendUserLanguageAccess(): void
    {
        $this->logInBackendUser(6);

        self::assertSame([0], $this->languageIdsForPage(10));
    }

    public function testReturnsNoLanguageWhenUsersOnlyLanguageHasNoPageTranslation(): void
    {
        $this->logInBackendUser(11);

        self::assertSame([], $this->languageIdsForPage(11));
        self::assertNull($this->subject()->getFirstSelectableLanguageId($this->siteForPage(11), 11));
    }

    public function testReturnsNoLanguageWithoutResolvedSite(): void
    {
        $this->logInBackendUser(2);

        self::assertSame([], $this->subject()->getSelectableLanguages(null, 10));
        self::assertNull($this->subject()->getFirstSelectableLanguageId(null, 10));
    }

    /** @return list<int> */
    private function languageIdsForPage(int $pageId): array
    {
        return array_map(
            static fn(SiteLanguage $language): int => $language->getLanguageId(),
            $this->subject()->getSelectableLanguages($this->siteForPage($pageId), $pageId),
        );
    }

    private function siteForPage(int $pageId): SiteInterface
    {
        return $this->get(SiteFinder::class)->getSiteByPageId($pageId);
    }
}
