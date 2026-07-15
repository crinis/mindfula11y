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

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Reads the scan state stored on page records
 * (tx_mindfula11y_scanid / tx_mindfula11y_scanupdated).
 */
final readonly class ScanStateService
{
    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * Determine if a scan should be invalidated based on page info.
     *
     * @param array<string, mixed> $pageInfo The page info array containing SYS_LASTCHANGED and tx_mindfula11y_scanupdated.
     * @param int $fallbackSysLastChanged Fallback SYS_LASTCHANGED from the default-language page record.
     *   Pass this when $pageInfo is a translation overlay, as overlays may not have SYS_LASTCHANGED updated.
     * @return bool True if the scan should be invalidated (new scan needed), false otherwise.
     */
    public function shouldInvalidateScan(array $pageInfo, int $fallbackSysLastChanged = 0): bool
    {
        $sysLastChanged = max((int)($pageInfo['SYS_LASTCHANGED'] ?? 0), $fallbackSysLastChanged);
        $scanUpdated = $pageInfo['tx_mindfula11y_scanupdated'] ?? null;

        // If no scan has been done yet, scan should be invalidated (new scan needed)
        if (!$scanUpdated) {
            return true;
        }

        // If scan exists, check if content has been modified since last scan
        return $sysLastChanged > 0 && $sysLastChanged > $scanUpdated;
    }

    /**
     * Get the workspace-overlaid page record carrying the given scan ID.
     *
     * @return array<string, mixed>|null The page record or null if not found.
     */
    public function getPageRecordByScanId(string $scanId): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, (int)$this->getBackendUserAuthentication()->workspace));

        $result = $queryBuilder
            ->select('uid', 'pid', 't3ver_oid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('tx_mindfula11y_scanid', $queryBuilder->createNamedParameter($scanId))
            )
            ->executeQuery()
            ->fetchAssociative();

        if (!$result) {
            return null;
        }

        // Resolve the Live UID: since TYPO3 v11 workspace version rows keep the
        // page's pid and reference their live counterpart in t3ver_oid (the old
        // pid = -1 convention is gone), so t3ver_oid > 0 identifies a version.
        $liveUid = (int)($result['t3ver_oid'] ?? 0) > 0
            ? (int)$result['t3ver_oid']
            : (int)($result['uid'] ?? 0);

        if ($liveUid <= 0) {
            return null;
        }

        // Use standard TYPO3 utility to fetch and overlay the record correctly.
        // This handles workspace logic ("move placeholders", permissions, etc.) automatically.
        $pageRecord = BackendUtility::getRecordWSOL('pages', $liveUid);

        return is_array($pageRecord) ? $pageRecord : null;
    }

    private function getBackendUserAuthentication(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }
}
