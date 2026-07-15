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
 * Reads the record coordinates an annotated element points at, or `null` when
 * the dataset is incomplete. Editing metadata is deliberately resolved later
 * by the authenticated backend endpoint.
 */
export const extractRecord = (element: HTMLElement): RecordReference | null => {
    const tableName = element.dataset.mindfula11yRecordTableName ?? '';
    const columnName = element.dataset.mindfula11yRecordColumnName ?? '';
    const uid = Number.parseInt(element.dataset.mindfula11yRecordUid ?? '', 10);
    if (tableName === '' || columnName === '' || Number.isNaN(uid)) {
        return null;
    }
    return { tableName, columnName, uid, editLink: '' };
};

/**
 * Builds a repeat()-stable structure node id. Record-backed nodes share one
 * scheme across analyzers, while callers may provide an analyzer-specific
 * fallback base (for example a heading relation id).
 */
const buildStructureNodeId = (
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

export interface StructureNodeIndexEntry {
    id: string;
    documentOrder: number;
}

/**
 * Assigns a stable node ID and a document-order position to each candidate.
 *
 * Candidate order is the document order and is identical across viewports (same
 * URL, same markup), so `documentOrder` can drive the cross-viewport merge.
 */
export const indexStructureNodes = (
    elements: readonly HTMLElement[],
    fallbackBase: (element: HTMLElement) => string = () => '',
): Map<HTMLElement, StructureNodeIndexEntry> => {
    const index = new Map<HTMLElement, StructureNodeIndexEntry>();
    const seen = new Map<string, number>();
    elements.forEach((element, documentOrder) => {
        index.set(element, {
            id: buildStructureNodeId(extractRecord(element), documentOrder, seen, fallbackBase(element)),
            documentOrder,
        });
    });
    return index;
};

/** Scrolls an element to center, honoring `prefers-reduced-motion`. */
export const scrollIntoViewCentered = (element: HTMLElement): void => {
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    element.scrollIntoView({ block: 'center', behavior: reduceMotion ? 'auto' : 'smooth' });
};
