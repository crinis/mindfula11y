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

use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;

/**
 * Resolves a single record's columns for the heading ViewHelpers, honouring the
 * workspace of the current frontend Context.
 *
 * The primary consumer is a structure-analysis request: the auth middleware
 * (AuthenticateStructureAnalysisRequestMiddleware) sets a WorkspaceAspect(N) on the
 * shared Context before page rendering, so a backend editor previewing workspace N
 * must see their workspace heading types, not the live ones. The previous plain
 * QueryBuilder fetch in AbstractHeadingViewHelper::getCachedRecord() read the LIVE
 * row and therefore leaked live heading types into a workspace preview.
 *
 * Workspace overlay
 * -----------------
 * Fetching is delegated to PageRepository::getRawRecord(), the canonical frontend
 * API for "the record by uid, no matter what, except deleted" (identical signature
 * on TYPO3 13.4 and 14.3). It selects with enable-field restrictions removed (only
 * DeletedRestriction kept), so a record hidden live but visible in the workspace is
 * still found, and then applies PageRepository::versionOL($table, $row) — the
 * context-aspect-aware workspace overlay. versionOL reads the workspace id from the
 * shared Context on every call and is a no-op for the live workspace (id 0), so a
 * live render simply returns the live row; for a delete placeholder getRawRecord
 * returns null, which is the "no record" case of the `?array` contract here.
 * versionOL only overlays when it can see the versioning bookkeeping fields, so
 * `uid`, `t3ver_oid` and `t3ver_state` are added to the select for workspace-capable
 * tables (`ctrl.versioningWS`); non-versioned custom tables skip those fields, and
 * versionOL then self-guards into a no-op. getRawRecord() additionally resolves
 * nothing for a table without TCA — always present for the annotated tables, whose
 * column must be TCA-configured to be editable in the first place.
 *
 * Language overlay — intentionally NOT applied
 * --------------------------------------------
 * Resolution is by an explicit record uid emitted by the ViewHelpers themselves
 * (`data-mindfula11y-record-uid`). The templates pass the LOCALISED record uid for
 * translated content (`data._LOCALIZED_UID` / `record.computedProperties.localizedUid`),
 * so the row fetched by that uid is already the language-specific row and its
 * heading/landmark column already holds the localised value. Re-running a language
 * overlay (PageRepository::getLanguageOverlay()) would attempt to overlay an
 * already-localised row and could resolve the wrong row, so it is deliberately
 * omitted — this matches the enrichment controller, which likewise treats the
 * emitted uid as an editable record uid, and the old code, which fetched by uid
 * directly. The workspace-overlaid uid stays the live/online uid (versionOL
 * restores `uid` to the online value), keeping it consistent with that emitted uid.
 *
 * Caching
 * -------
 * Results are cached for the request lifetime in the injected runtime cache. The
 * cache key includes the table, uid, requested column, workspace id and language
 * id so a workspace preview and a live render (or two language subrequests) in the
 * same process cannot cross-contaminate. Negative results are cached too (finding
 * (c)): a stored `false` sentinel is used instead of `null` because the runtime
 * cache's TransientMemoryBackend answers `has()` via `isset()`, which reports a
 * stored `null` as absent and would re-query on every ViewHelper instance.
 */
final class HeadingRecordResolver
{
    public function __construct(
        private readonly PageRepository $pageRepository,
        private readonly Context $context,
        private readonly FrontendInterface $runtimeCache,
    ) {}

    /**
     * Resolves a record's column, overlaid with the current workspace version.
     *
     * A row whose $column is empty is reported as a negative result (null),
     * matching the heading ViewHelpers, which only render a stored type when that
     * column is set.
     *
     * @param string $table The database table name.
     * @param int $uid The uid of the record to resolve.
     * @param string $column The column to select.
     *
     * @return array<string, mixed>|null The workspace-overlaid record row, or null
     *                                    if no (significant) record exists.
     */
    public function resolve(string $table, int $uid, string $column): ?array
    {
        if ($uid <= 0 || $column === '') {
            return null;
        }

        $workspaceId = (int)$this->context->getPropertyFromAspect('workspace', 'id', 0);
        $languageId = (int)$this->context->getPropertyFromAspect('language', 'id', 0);
        $cacheIdentifier = 'mindfula11y_record_' . $table . '_' . $uid
            . '_' . $column
            . '_ws' . $workspaceId
            . '_lang' . $languageId;

        if ($this->runtimeCache->has($cacheIdentifier)) {
            $cached = $this->runtimeCache->get($cacheIdentifier);
            return is_array($cached) ? $cached : null;
        }

        $record = $this->fetchOverlaidRecord($table, $uid, $column);

        // Cache the negative result as a `false` sentinel (not null) so has()/isset()
        // in the runtime cache reports it as present and no re-query happens.
        $this->runtimeCache->set($cacheIdentifier, $record ?? false);

        return $record;
    }

    /**
     * Fetches the record by uid, overlaid with the current workspace version.
     * Returns null when the record is absent, a workspace delete placeholder, or
     * has an empty significant column.
     *
     * @return array<string, mixed>|null
     */
    private function fetchOverlaidRecord(string $table, int $uid, string $column): ?array
    {
        // versionOL() (applied inside getRawRecord()) only overlays when it can read
        // the versioning bookkeeping fields off the live row, so select them for
        // workspace-capable tables. `pid` is included too: for a workspace move
        // pointer versionOL() reads the live `pid` to store `_ORIG_pid`, and
        // omitting it would raise a notice.
        $selectFields = [$column, 'uid'];
        if (!empty($GLOBALS['TCA'][$table]['ctrl']['versioningWS'])) {
            $selectFields[] = 'pid';
            $selectFields[] = 't3ver_oid';
            $selectFields[] = 't3ver_state';
        }

        $row = $this->pageRepository->getRawRecord($table, $uid, array_values(array_unique($selectFields)));

        if (null === $row || empty($row[$column])) {
            return null;
        }

        return $row;
    }
}
