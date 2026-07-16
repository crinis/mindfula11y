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

use TYPO3\CMS\Backend\Tree\Repository\PageTreeRepository;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Resolves the page-tree ids the current backend user may see.
 *
 * Query-side companion to PermissionService: the permission checks live
 * there, while this service turns them into the id list the missing-alt-text
 * queries and page-URL generation are scoped to.
 */
final readonly class PageTreeIdResolver
{
    public function __construct(
        private ConnectionPool $connectionPool,
        private BackendUserProvider $backendUserProvider,
    ) {}

    /**
     * Get the uids of the page and its subpages, restricted to what the
     * current user may see.
     *
     * Honors the page-show permission clause, TSconfig hideRecords, and the
     * user's web mounts (an entry page outside every mount yields no ids).
     *
     * @param int $pageId The entry page (0 = the user's web mounts).
     * @param int $pageLevels Levels below the entry page to include.
     * @return array<int> Unique page uids.
     */
    public function getPageTreeIds(int $pageId, int $pageLevels): array
    {
        $backendUser = $this->backendUserProvider->get();

        $expressionBuilder = $this->connectionPool
            ->getQueryBuilderForTable('pages')
            ->expr();

        $permsClause = $expressionBuilder->and(
            $backendUser->getPagePermsClause(Permission::PAGE_SHOW),
        );

        // This will hide records from display - it has nothing to do with user rights!!
        $hiddenPidList = GeneralUtility::intExplode(',', (string)($backendUser->getTSConfig()['options.']['hideRecords.']['pages'] ?? ''), true);
        if (!empty($hiddenPidList)) {
            $permsClause = $permsClause->with($expressionBuilder->notIn('pages.uid', $hiddenPidList));
        }
        $permsClauseString = (string)$permsClause;

        if (!$backendUser->isAdmin() && $pageId === 0) {
            $mountPoints = $backendUser->getWebmounts();
        } else {
            // Self-contained mount containment: the perms clause below is pure
            // permission-bit arithmetic, so without this check the method's
            // safety would rest entirely on every caller pre-validating
            // $pageId (currently they do, via readPageAccess()).
            if (!$backendUser->isAdmin() && $backendUser->isInWebMount($pageId) === null) {
                return [];
            }
            $mountPoints = [$pageId];
        }

        // PageTreeRepository is prototype-scoped (constructor state), so
        // makeInstance is the idiomatic way to obtain it.
        $repository = GeneralUtility::makeInstance(PageTreeRepository::class);
        $repository->setAdditionalWhereClause($permsClauseString);
        $pages = $repository->getFlattenedPages($mountPoints, $pageLevels);
        $idList = [];
        foreach ($pages as $page) {
            $idList[] = (int)$page['uid'];
        }

        return array_unique($idList);
    }
}
