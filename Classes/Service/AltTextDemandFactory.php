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

use MindfulMarkup\MindfulA11y\Domain\Model\GenerateAltTextDemand;
use TYPO3\CMS\Backend\Utility\BackendUtility;

/**
 * The single assembly point for signed AI alt-text generation demands.
 *
 * Owns the demand's revision-pinning invariant: every demand fingerprints the
 * exact persisted file, parent-record, and file-reference revisions it was
 * issued for, and fails closed (null) when any of those rows cannot be
 * resolved. It also owns the supported-file-type precondition: demands are
 * only issued for raster formats the OpenAI vision input accepts, so every
 * surface (FormEngine field control, module list) offers or withholds the
 * generate action consistently — redemption needs no extra gate because any
 * later file mutation breaks the pinned file fingerprint.
 * Unlike ScanDemandFactory it does NOT own the authorization
 * decision — its two surfaces render in different authorization contexts
 * (the module ViewHelper proves record edit access itself; the FormEngine
 * field control renders inside an already-authorized edit form), so each
 * caller keeps its own gate and this factory only guarantees that what gets
 * signed pins the current state.
 */
final readonly class AltTextDemandFactory
{
    public function __construct(
        private BackendUserProvider $backendUserProvider,
        private OpenAIService $openAIService,
        private RecordSnapshotService $recordSnapshotService,
    ) {}

    /**
     * @param array<string, mixed> $record Workspace-overlaid row of the record carrying the file.
     * @param int $fileReferenceUid The sys_file_reference uid, or 0 when the demand targets file metadata only.
     * @param list<string> $columns
     */
    public function create(
        int $pageId,
        int $languageUid,
        string $recordTableName,
        int $recordUid,
        array $record,
        int $fileUid,
        int $fileReferenceUid,
        array $columns,
    ): ?GenerateAltTextDemand {
        $fileRecord = BackendUtility::getRecordWSOL('sys_file', $fileUid);
        if (!is_array($fileRecord)
            || !$this->openAIService->isFileExtSupported((string)($fileRecord['extension'] ?? ''))
        ) {
            return null;
        }

        $fileReferenceSnapshot = '';
        if ($fileReferenceUid > 0) {
            $reference = $recordTableName === 'sys_file_reference' && $recordUid === $fileReferenceUid
                ? $record
                : BackendUtility::getRecordWSOL('sys_file_reference', $fileReferenceUid);
            if (!is_array($reference)) {
                return null;
            }
            $fileReferenceSnapshot = $this->recordSnapshotService->fingerprint('sys_file_reference', $reference);
        }

        $backendUser = $this->backendUserProvider->get();

        // Full-row fingerprints on purpose (unlike the pages-scoped structure
        // ticket / scan demand): this demand writes alt text back into the
        // exact revision it was issued for, so any concurrent change — content
        // included — must force a re-issue rather than a stale write.
        return new GenerateAltTextDemand(
            (int)$backendUser->user['uid'],
            $pageId,
            $languageUid,
            $backendUser->workspace,
            $recordTableName,
            $recordUid,
            $fileUid,
            $fileReferenceUid,
            $this->recordSnapshotService->fingerprint('sys_file', $fileRecord),
            $this->recordSnapshotService->fingerprint($recordTableName, $record),
            $fileReferenceSnapshot,
            $columns,
            expiresAt: time() + GenerateAltTextDemand::LIFETIME,
        );
    }
}
