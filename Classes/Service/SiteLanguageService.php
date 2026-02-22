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
        if (0 === $pageId) {
            return $this->getLanguageCodeFromAnySite($languageUid);
        }
        $siteLanguage = $this->getSiteLanguage($pageId, $languageUid);
        return $siteLanguage->getLocale()->getLanguageCode();
    }

    /**
     * Resolve a language code without a page context by searching all configured sites.
     * Used for root-level records (e.g. sys_file_metadata) that have no associated page.
     *
     * @param int $languageUid
     * @return string
     */
    protected function getLanguageCodeFromAnySite(int $languageUid): string
    {
        foreach ($this->siteFinder->getAllSites() as $site) {
            try {
                return $site->getLanguageById($languageUid)->getLocale()->getLanguageCode();
            } catch (\InvalidArgumentException) {
                // language not present in this site, try next
            }
        }

        // Fall back to the default language of the first available site
        foreach ($this->siteFinder->getAllSites() as $site) {
            return $site->getDefaultLanguage()->getLocale()->getLanguageCode();
        }

        return 'en';
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

    /**
     * Resolve the absolute base URL of a site language.
     *
     * Language bases in TYPO3 site configuration may be relative (e.g. /de/) or absolute
     * (e.g. https://de.example.com/). This method always returns an absolute URL without
     * a trailing slash, suitable for URL comparisons or crawler glob patterns.
     *
     * Returns null if the site or language cannot be resolved.
     *
     * @param int $pageId Page ID within the target site.
     * @param int $languageId Language ID.
     * @return string|null Absolute base URL without trailing slash, or null on failure.
     */
    public function getAbsoluteLanguageBase(int $pageId, int $languageId): ?string
    {
        try {
            $site = $this->siteFinder->getSiteByPageId($pageId);
            $langBase = $site->getLanguageById($languageId)->getBase();
            if (!empty($langBase->getScheme())) {
                // Absolute language base (e.g. https://de.example.com/ or https://example.com/de/)
                return rtrim((string)$langBase, '/');
            }
            // Relative language base (e.g. /de/) — resolve scheme+host from the site base
            $siteBase = $site->getBase();
            $origin = $siteBase->getScheme() . '://' . $siteBase->getHost();
            if ($siteBase->getPort()) {
                $origin .= ':' . $siteBase->getPort();
            }
            return $origin . rtrim($langBase->getPath(), '/');
        } catch (\Throwable) {
            return null;
        }
    }
}
