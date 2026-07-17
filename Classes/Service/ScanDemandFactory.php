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
use TYPO3\CMS\Backend\Routing\PreviewUriBuilder;

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
        private RecordSnapshotService $recordSnapshotService,
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
        int $pageLevels = 0,
        bool $crawl = false,
    ): ?CreateScanDemand {
        if (!$this->canTriggerScan($pageRecord)) {
            return null;
        }

        $backendUser = $this->backendUserProvider->get();
        $languageField = (string)($GLOBALS['TCA']['pages']['ctrl']['languageField'] ?? 'sys_language_uid');

        return new CreateScanDemand(
            userId: (int)$backendUser->user['uid'],
            pageId: $pageId,
            previewUrl: $previewUrl,
            // Sign the language of the record the preview is actually built
            // from — not the module's selected language. When the selection has
            // no page translation the caller falls back to the default-language
            // record, and a demand signed with the untranslated selection could
            // never be redeemed (no localized record, snapshot mismatch).
            languageId: (int)($pageRecord[$languageField] ?? 0),
            workspaceId: $backendUser->workspace,
            pageRecordSnapshot: $this->recordSnapshotService->fingerprint('pages', $pageRecord),
            pageLevels: $pageLevels,
            crawl: $crawl,
        );
    }

    /**
     * Whether the mutable page state still produces the exact signed scan scope.
     *
     * @param array<string, mixed> $pageRecord Current default-language or localized page record.
     */
    public function matchesCurrentSnapshot(CreateScanDemand $demand, array $pageRecord): bool
    {
        $languageField = (string)($GLOBALS['TCA']['pages']['ctrl']['languageField'] ?? 'sys_language_uid');
        $translationParentField = (string)($GLOBALS['TCA']['pages']['ctrl']['transOrigPointerField'] ?? 'l10n_parent');
        $currentPageId = (int)($pageRecord[$translationParentField] ?? 0) ?: (int)($pageRecord['uid'] ?? 0);
        if ($currentPageId !== $demand->getPageId()
            || (int)($pageRecord[$languageField] ?? 0) !== $demand->getLanguageId()
            || !hash_equals(
                $demand->getPageRecordSnapshot(),
                $this->recordSnapshotService->fingerprint('pages', $pageRecord),
            )
        ) {
            return false;
        }

        $previewUri = PreviewUriBuilder::create($pageRecord)->buildUri();

        return $previewUri !== null && hash_equals($demand->getPreviewUrl(), (string)$previewUri);
    }
}
