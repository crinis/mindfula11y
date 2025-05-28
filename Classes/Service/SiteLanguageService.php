<?php
declare(strict_types=1);

/*
 * Mindful A11y extension for TYPO3 integrating accessibility tools into the backend.
 * Copyright (C) 2025  Mindful Markup, Felix Spittel
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

namespace MindfulMarkup\MindfulA11y\Service;

use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use \InvalidArgumentException;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Class SiteLanguageService.
 * 
 * This class provides methods to retrieve language codes based on language UIDs and page IDs.
 */
class SiteLanguageService
{
    /**
     * Constructor.
     * 
     * @param SiteFinder $siteFinder
     */
    public function __construct(
        protected readonly SiteFinder $siteFinder,
    ) {}

    /**
     * Get the language code by language UID and page ID.
     *
     * @param int $languageUid The UID of the language.
     * @param int $pageId The ID of the page.
     * 
     * @return string The language code.
     * 
     * @throws SiteNotFoundException If the site is not found.
     * @throws InvalidArgumentException If the language UID is invalid or page ID is 0.
     */
    public function getLanguageCode(int $languageUid, int $pageId): string
    {
        $siteLanguage = $this->getSiteLanguage($pageId, $languageUid);
        return $siteLanguage->getLocale()->getLanguageCode();
    }

    /**
     * Get language fallbacks by language UID and page ID.
     * 
     * @param int $languageUid The UID of the language.
     * @param int $pageId The ID of the page.
     * 
     * @return array<int> The language fallbacks.
     * 
     * @throws SiteNotFoundException If the site is not found.
     * @throws InvalidArgumentException If the language UID is invalid or page ID is 0.
     */
    public function getFallbackLanguageIds(int $languageUid, int $pageId): array
    {
        $siteLanguage = $this->getSiteLanguage($pageId, $languageUid);
        return $siteLanguage->getFallbackLanguageIds();
    }

    /**
     * Get site language by page ID and language UID.
     * 
     * @param int $pageId The ID of the page.
     * @param int $languageUid The UID of the language.
     * 
     * @return SiteLanguage
     * 
     * @throws SiteNotFoundException If the site is not found.
     * @throws InvalidArgumentException If the language UID is invalid or page ID is 0.
     */
    protected function getSiteLanguage(int $pageId, int $languageUid): SiteLanguage
    {
        if (0 === $pageId) {
            throw new InvalidArgumentException('Page ID cannot be 0.', 1634567890);
        }

        $site = $this->siteFinder->getSiteByPageId($pageId);
        return $site->getLanguageById($languageUid);
    }
}
