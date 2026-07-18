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

namespace MindfulMarkup\MindfulA11y\Service;

use MindfulMarkup\MindfulA11y\Tca\TranslationFields;
use TYPO3\CMS\Backend\Domain\Repository\Localization\LocalizationRepository;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/** Resolves the page languages selectable by the current backend user. */
final readonly class BackendPageLanguageService
{
    public function __construct(
        private BackendUserProvider $backendUserProvider,
    ) {}

    /**
     * Return configured site languages that the current user may access and
     * for which the page has a translation in the current workspace.
     *
     * @return list<SiteLanguage>
     */
    public function getSelectableLanguages(?SiteInterface $site, int $pageId): array
    {
        $backendUser = $this->backendUserProvider->getAuthenticated();
        if ($backendUser === null || $site === null || $pageId <= 0) {
            return [];
        }

        $translatedLanguageIds = $this->getTranslatedLanguageIds($pageId, $backendUser->workspace);

        return array_values(array_filter(
            $site->getAvailableLanguages($backendUser, false, $pageId),
            static fn(SiteLanguage $language): bool => in_array(
                $language->getLanguageId(),
                $translatedLanguageIds,
                true,
            ),
        ));
    }

    /** Return the first selectable language ID, preserving site configuration order. */
    public function getFirstSelectableLanguageId(?SiteInterface $site, int $pageId): ?int
    {
        foreach ($this->getSelectableLanguages($site, $pageId) as $language) {
            return $language->getLanguageId();
        }

        return null;
    }

    /**
     * Get the language IDs a page is translated into, including the default
     * language, for the current backend user's validated workspace.
     *
     * The legacy pre-14.2 API hard-reads $GLOBALS['BE_USER']->workspace, so
     * accepting an arbitrary workspace ID outside this service would be unsafe.
     *
     * @return list<int>
     */
    private function getTranslatedLanguageIds(int $pageId, int $workspaceId): array
    {
        $translatedLanguageIds = [0];

        // LocalizationRepository::getPageTranslations() and the deprecation of
        // the legacy BackendUtility::getExistingPageTranslations() both landed
        // in TYPO3 v14.2 (#108799 / #108810).
        if (version_compare((new Typo3Version())->getVersion(), '14.2', '>=')) {
            $repository = GeneralUtility::makeInstance(LocalizationRepository::class);
            foreach (array_keys($repository->getPageTranslations($pageId, [], $workspaceId)) as $languageId) {
                $translatedLanguageIds[] = (int)$languageId;
            }
        } else {
            $pageTranslations = BackendUtility::getExistingPageTranslations($pageId);
            foreach ($pageTranslations as $pageTranslation) {
                // A row missing the language column contributes 0, which the
                // default-language entry already covers.
                $translatedLanguageIds[] = TranslationFields::languageId('pages', $pageTranslation);
            }
        }

        return array_values(array_unique($translatedLanguageIds));
    }
}
