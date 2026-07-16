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

use TYPO3\CMS\Backend\Routing\PreviewUriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
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
     * Check if a page record is visible (not hidden and within start/end time).
     * Uses TCA configuration to determine the correct fields.
     *
     * @param array<string, mixed> $pageRecord The page record to check.
     */
    public function isPageVisible(array $pageRecord): bool
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
     * Check if a page record is accessible on the frontend (visible and not restricted by fe_group).
     *
     * Also checks ancestor pages for inherited restrictions via extendToSubpages: when a parent page
     * has extendToSubpages=1, its hidden, starttime, endtime, and fe_group restrictions cascade to
     * all descendant pages.
     *
     * @param array<string, mixed> $pageRecord The page record to check.
     */
    public function isPageFrontendAccessible(array $pageRecord): bool
    {
        if (!$this->isPageVisible($pageRecord)) {
            return false;
        }

        $feGroup = (string)($pageRecord['fe_group'] ?? '');
        if ($feGroup !== '' && $feGroup !== '0') {
            return false;
        }

        // Check ancestor pages for inherited restrictions via extendToSubpages.
        // Use the original-language uid (l10n_parent) when the record is a translation overlay,
        // since RootlineUtility is designed for default-language page uids.
        $pageId = (int)(($pageRecord['l10n_parent'] ?? 0) ?: ($pageRecord['uid'] ?? 0));
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
                $ancestorFeGroup = (string)($ancestor['fe_group'] ?? '');
                if ($ancestorFeGroup !== '' && $ancestorFeGroup !== '0') {
                    return false;
                }
                if (!$this->isPageVisible($ancestor)) {
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
     * Get the workspace-overlaid localized page record, or null when the page
     * is not translated into the given language.
     *
     * @return array<string, mixed>|null
     */
    public function getLocalizedPageRecord(int $pageId, int $languageId): ?array
    {
        if ($languageId === 0) {
            return null;
        }
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $this->backendUserProvider->get()->workspace));
        $overlayRecord = $queryBuilder
            ->select('*')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq(
                    $GLOBALS['TCA']['pages']['ctrl']['transOrigPointerField'],
                    $queryBuilder->createNamedParameter($pageId, Connection::PARAM_INT)
                ),
                $queryBuilder->expr()->eq(
                    $GLOBALS['TCA']['pages']['ctrl']['languageField'],
                    $queryBuilder->createNamedParameter($languageId, Connection::PARAM_INT)
                )
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();
        if ($overlayRecord) {
            BackendUtility::workspaceOL('pages', $overlayRecord, $this->backendUserProvider->get()->workspace);
        }
        return is_array($overlayRecord) ? $overlayRecord : null;
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
