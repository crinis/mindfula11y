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
use TYPO3\CMS\Backend\Routing\PreviewUriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\Bitmask\PageTranslationVisibility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\RootlineUtility;

/**
 * Answers whether and how a page can be previewed on the frontend.
 *
 * Covers page visibility (hidden/start/end time, inherited fe_group
 * restrictions), workspace-aware localized page records, doktype preview
 * gates, and frontend preview URL generation for page-tree scopes.
 */
final readonly class PagePreviewService
{
    public function __construct(
        private PermissionService $permissionService,
        private ModuleSettingsService $moduleSettingsService,
        private ConnectionPool $connectionPool,
        private BackendUserProvider $backendUserProvider,
        private PageTreeIdResolver $pageTreeIdResolver,
        private Context $context,
        private SiteFinder $siteFinder,
    ) {}

    /**
     * Check if preview is enabled for a given doktype.
     *
     * @param array<string, mixed> $pageTsConfig
     */
    public function isPreviewEnabledForDoktype(int $doktype, array $pageTsConfig): bool
    {
        if (isset($pageTsConfig['TCEMAIN']['preview']['disableButtonForDokType'])) {
            return !in_array($doktype, GeneralUtility::intExplode(',', (string)$pageTsConfig['TCEMAIN']['preview']['disableButtonForDokType'], true));
        }
        return !in_array($doktype, [PageRepository::DOKTYPE_SYSFOLDER, PageRepository::DOKTYPE_SPACER, PageRepository::DOKTYPE_LINK]);
    }

    /**
     * Check if a page record is visible: not hidden and within its start/end
     * time — judged the way the frontend resolves the page.
     *
     * A translation is resolved through its default-language page: core's
     * PageRepository::getPage() applies hidden, start/end time and fe_group
     * to the default-language row before overlaying the translation (TYPO3 13
     * and 14 alike), and none of these fields is excluded from translation,
     * so both rows must pass. A default-language page whose "Hide default
     * language of page" setting (l18n_cfg bit 1) is on answers that language
     * with a 404, while its translations stay reachable.
     *
     * @param array<string, mixed> $pageRecord The page record to check.
     */
    public function isPageVisible(array $pageRecord): bool
    {
        if (!$this->isWithinVisibility($pageRecord)) {
            return false;
        }

        $translationParentUid = TranslationFields::translationParentUid('pages', $pageRecord);
        if ($translationParentUid === 0) {
            return !(new PageTranslationVisibility((int)($pageRecord['l18n_cfg'] ?? 0)))->shouldBeHiddenInDefaultLanguage();
        }

        $defaultLanguagePage = $this->getDefaultLanguagePage($translationParentUid);

        return $defaultLanguagePage !== null && $this->isWithinVisibility($defaultLanguagePage);
    }

    /**
     * The record's own hidden flag and start/end time, read from the TCA
     * enable columns.
     *
     * @param array<string, mixed> $pageRecord
     */
    private function isWithinVisibility(array $pageRecord): bool
    {
        $ctrl = $GLOBALS['TCA']['pages']['ctrl'];
        $enableColumns = $ctrl['enablecolumns'] ?? [];

        // Check disabled/hidden
        $disabledField = $enableColumns['disabled'] ?? 'hidden';
        if (isset($pageRecord[$disabledField]) && (int)$pageRecord[$disabledField] === 1) {
            return false;
        }

        $now = $this->context->getPropertyFromAspect('date', 'timestamp') ?? time();

        // Check starttime
        $starttimeField = $enableColumns['starttime'] ?? 'starttime';
        if (isset($pageRecord[$starttimeField]) && (int)$pageRecord[$starttimeField] > $now) {
            return false;
        }

        // Check endtime
        $endtimeField = $enableColumns['endtime'] ?? 'endtime';
        if (isset($pageRecord[$endtimeField]) && (int)$pageRecord[$endtimeField] !== 0 && (int)$pageRecord[$endtimeField] <= $now) {
            return false;
        }

        return true;
    }

    /**
     * The workspace-overlaid default-language page of a translation, or null
     * when it does not exist (a translation without one is unreachable).
     *
     * @return array<string, mixed>|null
     */
    private function getDefaultLanguagePage(int $translationParentUid): ?array
    {
        $page = BackendUtility::getRecordWSOL('pages', $translationParentUid);

        return is_array($page) ? $page : null;
    }

    /**
     * Check if a page record is accessible on the frontend (visible and not restricted by fe_group).
     *
     * Also checks ancestor pages for inherited restrictions via extendToSubpages: when a parent page
     * has extendToSubpages=1, its hidden, starttime, endtime, and fe_group restrictions cascade to
     * all descendant pages. A translation must pass on its default-language page as well
     * (see isPageVisible()).
     *
     * @param array<string, mixed> $pageRecord The page record to check.
     */
    public function isPageFrontendAccessible(array $pageRecord): bool
    {
        if (!$this->isPageVisible($pageRecord)) {
            return false;
        }

        if (!$this->isPublicFrontendGroupList((string)($pageRecord['fe_group'] ?? ''))) {
            return false;
        }

        $translationParentUid = TranslationFields::translationParentUid('pages', $pageRecord);
        if ($translationParentUid > 0
            && !$this->isPublicFrontendGroupList((string)($this->getDefaultLanguagePage($translationParentUid)['fe_group'] ?? ''))
        ) {
            return false;
        }

        // Check ancestor pages for inherited restrictions via extendToSubpages.
        // Use the original-language uid when the record is a translation overlay,
        // since RootlineUtility is designed for default-language page uids.
        $pageId = $translationParentUid ?: (int)($pageRecord['uid'] ?? 0);
        if ($pageId <= 0) {
            return true;
        }

        try {
            $rootline = GeneralUtility::makeInstance(RootlineUtility::class, $pageId)->get();
            foreach ($rootline as $ancestor) {
                if ((int)$ancestor['uid'] === $pageId) {
                    continue; // Skip current page, already checked above
                }
                // Only evaluate ancestors that extend their restrictions to subpages
                if (!($ancestor['extendToSubpages'] ?? false)) {
                    continue;
                }
                if (!$this->isPublicFrontendGroupList((string)($ancestor['fe_group'] ?? ''))) {
                    return false;
                }
                if (!$this->isWithinVisibility($ancestor)) {
                    return false;
                }
            }
        } catch (\Exception) {
            // If the rootline cannot be resolved, treat as inaccessible to be safe
            return false;
        }

        return true;
    }

    /**
     * Whether an anonymous visitor — the scanner — passes a record's fe_group
     * access list.
     *
     * Mirrors core's FrontendGroupRestriction: an empty list or "0" means no
     * restriction, and otherwise ANY listed group grants access. An anonymous
     * visitor carries the pseudo groups 0 and -1 ("hide at login"), so a list
     * naming -1 is public, while -2 ("show at any login") and real frontend
     * groups alone require a login.
     */
    private function isPublicFrontendGroupList(string $feGroup): bool
    {
        $groupIds = GeneralUtility::intExplode(',', $feGroup, true);

        return $groupIds === [] || array_intersect($groupIds, [0, -1]) !== [];
    }

    /**
     * Get the workspace-overlaid localized page record, or null when the page
     * is not translated into the given language.
     *
     * @param int|null $workspaceId The workspace to overlay for, or null for
     *                              the session user's workspace. Session-less
     *                              flows (structure-ticket redemption) pass
     *                              their authenticated workspace claim
     *                              explicitly instead of relying on a global.
     * @return array<string, mixed>|null
     */
    public function getLocalizedPageRecord(int $pageId, int $languageId, ?int $workspaceId = null): ?array
    {
        if ($languageId === 0) {
            return null;
        }
        $workspaceId ??= $this->backendUserProvider->get()->workspace;
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $workspaceId));
        $overlayRecord = $queryBuilder
            ->select('*')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq(
                    TranslationFields::translationParentFieldName('pages'),
                    $queryBuilder->createNamedParameter($pageId, Connection::PARAM_INT)
                ),
                $queryBuilder->expr()->eq(
                    TranslationFields::languageFieldName('pages'),
                    $queryBuilder->createNamedParameter($languageId, Connection::PARAM_INT)
                )
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();
        if ($overlayRecord) {
            BackendUtility::workspaceOL('pages', $overlayRecord, $workspaceId);
        }
        return is_array($overlayRecord) ? $overlayRecord : null;
    }

    /**
     * Build the current preview URL for one authorized page/language scope.
     *
     * @param array<string, mixed> $page Workspace-overlaid default-language page.
     */
    public function buildPreviewUrl(array $page, int $pageId, int $languageId): ?string
    {
        try {
            $site = $this->siteFinder->getSiteByPageId($pageId);
            // Throws when the site does not define this language; the catch
            // below turns that into "no preview". Return value unused by design.
            $site->getLanguageById($languageId);
            $previewPage = $this->getPreviewPageRecord($page, $pageId, $languageId);
            if (!is_array($previewPage)) {
                return null;
            }
            $previewUri = PreviewUriBuilder::create($previewPage)->buildUri();
        } catch (\Throwable) {
            return null;
        }

        return $previewUri === null ? null : (string)$previewUri;
    }

    /**
     * Resolve the complete record from which a page/language preview is built.
     *
     * @param array<string, mixed> $page Workspace-overlaid default-language page.
     * @return array<string, mixed>|null
     */
    public function getPreviewPageRecord(array $page, int $pageId, int $languageId): ?array
    {
        return $languageId > 0
            ? $this->getLocalizedPageRecord($pageId, $languageId)
            : $page;
    }

    /**
     * Generate frontend URLs for pages in the page tree.
     *
     * Resolves the page tree from the given root page, filters to only pages that are
     * visible and publicly accessible (no fe_group restrictions), and generates
     * frontend preview URLs for each.
     *
     * @param int $pageId The root page ID.
     * @param int $languageId The language ID.
     * @param int $pageLevels The number of page tree levels to include.
     * @param string $fallbackUrl URL to return when no pages are found (typically the current page preview URL).
     *
     * @return string[] Array of frontend URLs.
     */
    public function generatePageUrls(int $pageId, int $languageId, int $pageLevels, string $fallbackUrl = ''): array
    {
        $pageTreeIds = $this->pageTreeIdResolver->getPageTreeIds($pageId, $pageLevels);
        $urls = [];

        foreach ($pageTreeIds as $treePageId) {
            $pageRecord = BackendUtility::getRecordWSOL('pages', $treePageId);
            if (!is_array($pageRecord)) {
                continue;
            }

            if ($languageId > 0) {
                $localizedPage = $this->getLocalizedPageRecord($treePageId, $languageId);
                if (null === $localizedPage) {
                    continue;
                }
                $pageRecord = $localizedPage;
            }

            if (!$this->isPageFrontendAccessible($pageRecord)) {
                continue;
            }

            $pageTsConfig = $this->moduleSettingsService->getConvertedPageTsConfig($treePageId);
            if (!$this->isPreviewEnabledForDoktype((int)($pageRecord['doktype'] ?? 0), $pageTsConfig)) {
                continue;
            }

            $previewUri = PreviewUriBuilder::create($pageRecord)->buildUri();
            if (null !== $previewUri) {
                $urls[] = (string)$previewUri;
            }
        }

        $urls = array_unique($urls);

        if (empty($urls) && $fallbackUrl !== '') {
            return [$fallbackUrl];
        }

        return $urls;
    }
}
