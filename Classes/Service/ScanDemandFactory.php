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

use MindfulMarkup\MindfulA11y\Domain\Model\CreateScanDemand;

/**
 * The single issuance point for signed scan-creation demands.
 *
 * Owns the "signed => authorized at issuance" invariant: a demand is only
 * signed when redeeming it could succeed, so the authorization policy cannot
 * drift between the surfaces that render scan controls.
 */
final readonly class ScanDemandFactory
{
    public function __construct(
        private PermissionService $permissionService,
        private BackendUserProvider $backendUserProvider,
    ) {}

    /**
     * Whether the current user may trigger scans for the given page record.
     *
     * Requires the live workspace (the external scanner cannot fetch
     * workspace previews, and storing the scan id must not create a workspace
     * version of the page) and edit access to the page record the scan id is
     * stored on.
     *
     * @param array<string, mixed> $pageRecord Workspace-overlaid (and, for translations, localized) page record.
     */
    public function canTriggerScan(array $pageRecord): bool
    {
        return $this->backendUserProvider->get()->workspace === 0
            && $this->permissionService->checkRecordEditAccess('pages', $pageRecord);
    }

    /**
     * Issue a signed scan-creation demand, or null when the current user may
     * not trigger scans for the page.
     *
     * @param array<string, mixed> $pageRecord Workspace-overlaid (and, for translations, localized) page record.
     * @param int $pageId Default-language page uid — redemption resolves the
     *   localized record from this id and the demand's language, so localized
     *   record uids must never be signed here.
     */
    public function create(
        array $pageRecord,
        int $pageId,
        string $previewUrl,
        int $languageId,
        int $pageLevels = 0,
        bool $crawl = false,
    ): ?CreateScanDemand {
        if (!$this->canTriggerScan($pageRecord)) {
            return null;
        }

        $backendUser = $this->backendUserProvider->get();

        return new CreateScanDemand(
            userId: (int)$backendUser->user['uid'],
            pageId: $pageId,
            previewUrl: $previewUrl,
            languageId: $languageId,
            workspaceId: $backendUser->workspace,
            pageLevels: $pageLevels,
            crawl: $crawl,
        );
    }
}
