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

use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;

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
     * A page ID of 0 (root-level records such as sys_file_metadata) has no
     * site of its own; the code is then resolved from any site configuring
     * the language (see getLanguageCodeFromAnySite()).
     *
     * @param int $languageUid The UID of the language.
     * @param int $pageId The ID of the page, or 0 for root-level records.
     * 
     * @return string The language code.
     * 
     * @throws SiteNotFoundException If the page belongs to no site.
     * @throws InvalidArgumentException If the page's site does not configure the language UID.
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
        $sites = $this->siteFinder->getAllSites();
        $firstSite = reset($sites);

        return $firstSite !== false ? $firstSite->getDefaultLanguage()->getLocale()->getLanguageCode() : 'en';
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
     * @throws InvalidArgumentException If the language UID is invalid.
     */
    private function getSiteLanguage(int $pageId, int $languageUid): SiteLanguage
    {
        $site = $this->siteFinder->getSiteByPageId($pageId);
        return $site->getLanguageById($languageUid);
    }

    /**
     * Resolve the absolute base URL of a site language.
     *
     * Language bases in TYPO3 site configuration may be relative (e.g. /de/) or absolute
     * (e.g. https://de.example.com/); core already prefixes a relative language base with
     * an absolute site base. This method always returns an absolute URL without a
     * trailing slash, suitable for URL comparisons or crawler glob patterns.
     *
     * Returns null if the site or language cannot be resolved, or if the base is
     * relative (site `base: /`) and no current backend request supplies the origin.
     *
     * @param int $pageId Page ID within the target site.
     * @param int $languageId Language ID.
     * @return string|null Absolute base URL without trailing slash, or null on failure.
     */
    public function getAbsoluteLanguageBase(int $pageId, int $languageId): ?string
    {
        try {
            $site = $this->siteFinder->getSiteByPageId($pageId);
            return $this->toAbsoluteBase($site->getLanguageById($languageId)->getBase());
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Keep only URLs living under one of the page's site (language) bases.
     *
     * Security allowlist for user-influenced URL filters that are forwarded to
     * the external scanner API: everything outside the site's own URL space is
     * dropped, and any resolution failure drops all URLs (fail closed). A
     * relative site base admits the current backend request's origin only —
     * the host the preview URLs are built for.
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
            $bases = array_map(static fn(SiteLanguage $language): UriInterface => $language->getBase(), $site->getLanguages());
            $bases[] = $site->getBase();
            foreach ($bases as $base) {
                $absoluteBase = $this->toAbsoluteBase($base);
                if ($absoluteBase !== null && $absoluteBase !== '' && !in_array($absoluteBase, $allowedBases, true)) {
                    $allowedBases[] = $absoluteBase;
                }
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

    /**
     * A site or language base as an absolute URL without trailing slash.
     *
     * A base without host (site `base: /`, which core leaves relative for every
     * language) is resolved against the current backend request's origin — the
     * same host core's preview links resolve against. A base without scheme
     * (`//example.com/`) takes the request's scheme. Null when the base is not
     * absolute and no request is available to complete it (fail closed).
     */
    private function toAbsoluteBase(UriInterface $base): ?string
    {
        if ($base->getHost() !== '' && $base->getScheme() !== '') {
            return rtrim((string)$base, '/');
        }

        $normalizedParams = $this->getNormalizedParams();
        if ($normalizedParams === null || $normalizedParams->getRequestHost() === '') {
            return null;
        }

        if ($base->getHost() !== '') {
            return rtrim(($normalizedParams->isHttps() ? 'https:' : 'http:') . (string)$base, '/');
        }

        return rtrim($normalizedParams->getRequestHost() . '/' . ltrim($base->getPath(), '/'), '/');
    }

    private function getNormalizedParams(): ?NormalizedParams
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if (!$request instanceof ServerRequestInterface) {
            return null;
        }
        $normalizedParams = $request->getAttribute('normalizedParams');

        return $normalizedParams instanceof NormalizedParams ? $normalizedParams : null;
    }
}
