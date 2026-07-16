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
use TYPO3\CMS\Backend\Domain\Repository\Localization\LocalizationRepository;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Class SiteLanguageService.
 * 
 * This class provides methods to retrieve language codes based on language UIDs and page IDs.
 */
final readonly class SiteLanguageService
{
    /**
     * Constructor.
     *
     * @param SiteFinder $siteFinder
     */
    public function __construct(
        private SiteFinder $siteFinder,
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
    private function getLanguageCodeFromAnySite(int $languageUid): string
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
    private function getSiteLanguage(int $pageId, int $languageUid): SiteLanguage
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

    /**
     * Get the language ids a page is translated into, including the default
     * language, for the given workspace.
     *
     * $workspaceId must be the CURRENT backend user's workspace: the legacy
     * pre-14.2 branch delegates to BackendUtility::getExistingPageTranslations(),
     * which hard-reads $GLOBALS['BE_USER']->workspace and cannot honor any
     * other value — do not call this with a sessionless (ticket) workspace claim.
     *
     * @return array<int>
     */
    public function getTranslatedLanguageIds(int $pageId, int $workspaceId): array
    {
        $translatedLanguageIds = [0]; // Default language is always available

        // LocalizationRepository::getPageTranslations() and the deprecation of
        // the legacy BackendUtility::getExistingPageTranslations() both landed
        // in TYPO3 v14.2 (#108799 / #108810); on v13 and v14.0/v14.1 the legacy
        // method is the only one that exists.
        if (version_compare((new Typo3Version())->getVersion(), '14.2', '>=')) {
            // getPageTranslations() returns RawRecord[] keyed by language id,
            // so the ids are the array keys.
            $repository = GeneralUtility::makeInstance(LocalizationRepository::class);
            foreach (array_keys($repository->getPageTranslations($pageId, [], $workspaceId)) as $languageId) {
                $translatedLanguageIds[] = (int)$languageId;
            }
        } else {
            // TYPO3 v13 / v14.0 / v14.1: the legacy API returns page rows.
            $pageTranslations = BackendUtility::getExistingPageTranslations($pageId);
            foreach ($pageTranslations as $pageTranslation) {
                $languageId = $pageTranslation[$GLOBALS['TCA']['pages']['ctrl']['languageField']] ?? null;
                if (null !== $languageId) {
                    $translatedLanguageIds[] = (int)$languageId;
                }
            }
        }

        return array_unique($translatedLanguageIds);
    }

    /**
     * Keep only URLs living under one of the page's site (language) bases.
     *
     * Security allowlist for user-influenced URL filters that are forwarded to
     * the external scanner API: everything outside the site's own URL space is
     * dropped, and any resolution failure drops all URLs (fail closed).
     *
     * @param list<string> $urls
     * @return list<string>
     */
    public function filterUrlsToSiteBases(array $urls, int $pageId): array
    {
        if ($urls === []) {
            return [];
        }

        try {
            $site = $this->siteFinder->getSiteByPageId($pageId);
            $allowedBases = [];
            foreach ($site->getLanguages() as $language) {
                $base = rtrim((string)$language->getBase(), '/');
                if ($base !== '') {
                    $allowedBases[] = $base;
                }
            }
            $siteBase = rtrim((string)$site->getBase(), '/');
            if ($siteBase !== '' && !in_array($siteBase, $allowedBases, true)) {
                $allowedBases[] = $siteBase;
            }

            return array_values(array_filter($urls, static function (string $url) use ($allowedBases): bool {
                foreach ($allowedBases as $base) {
                    if (str_starts_with($url, $base . '/') || $url === $base) {
                        return true;
                    }
                }
                return false;
            }));
        } catch (\Exception) {
            return [];
        }
    }
}
