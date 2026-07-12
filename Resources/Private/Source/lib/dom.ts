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

/**
 * Shared DOM helpers for reading the `data-mindfula11y-*` annotations the
 * structure ViewHelpers add, and for structure-view scrolling.
 */

import type { RecordReference } from './types.js';

/**
 * Reads the editable record an annotated element points at, or `null` when the
 * dataset is incomplete (a non-editable node). Consumed by both structure
 * analyzers, which share the same `data-mindfula11y-record-*` contract.
 */
export const extractRecord = (element: HTMLElement): RecordReference | null => {
    const tableName = element.dataset.mindfula11yRecordTableName ?? '';
    const columnName = element.dataset.mindfula11yRecordColumnName ?? '';
    const uid = Number.parseInt(element.dataset.mindfula11yRecordUid ?? '', 10);
    if (tableName === '' || columnName === '' || Number.isNaN(uid)) {
        return null;
    }
    return { tableName, columnName, uid, editLink: element.dataset.mindfula11yRecordEditLink ?? '' };
};

/**
 * Builds a repeat()-stable structure node id. Record-backed nodes share one
 * scheme across analyzers, while callers may provide an analyzer-specific
 * fallback base (for example a heading relation id).
 */
export const buildStructureNodeId = (
    record: RecordReference | null,
    index: number,
    seen: Map<string, number>,
    fallbackBase = '',
): string => {
    const base =
        record !== null ? `${record.tableName}:${record.uid}:${record.columnName}` : fallbackBase || `pos:${index}`;
    const occurrence = seen.get(base) ?? 0;
    seen.set(base, occurrence + 1);
    return occurrence === 0 ? base : `${base}#${occurrence}`;
};

/** Parses a JSON string→string map annotation; malformed data degrades to `{}` (read-only). */
export const parseJsonMap = (raw: string | undefined): Record<string, string> => {
    if (raw === undefined || raw === '') {
        return {};
    }
    try {
        return JSON.parse(raw) as Record<string, string>;
    } catch {
        return {};
    }
};

/** Scrolls an element to center, honoring `prefers-reduced-motion`. */
export const scrollIntoViewCentered = (element: HTMLElement): void => {
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    element.scrollIntoView({ block: 'center', behavior: reduceMotion ? 'auto' : 'smooth' });
};
