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
 * Helpers for reading the `data-mindfula11y-*` annotations the structure
 * ViewHelpers add. Part of the DOM-pure structure core: the sandboxed iframe
 * runner bundles this module, so it must never reference `window`, `lit`, or
 * any bare-specifier import (backend-window helpers live in `lib/dom.ts`).
 */

import type { RecordReference } from '../types.js';

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
    // Present (possibly empty = "automatic") only when the stored value can differ
    // from the rendered state; absence keeps the rendered-state semantics.
    const storedValue = element.dataset.mindfula11yRecordValue;
    return { tableName, columnName, uid, editLink: '', ...(storedValue !== undefined ? { storedValue } : {}) };
};

/**
 * Child-type column coordinates of a container element (or its hidden marker).
 * Unlike `extractRecord`, the stored value is always present — '' means
 * "automatic" and is a real, selectable state of the column.
 */
export const extractChildTypeRecord = (element: HTMLElement): RecordReference | null => {
    const tableName = element.dataset.mindfula11yChildtypeTableName ?? '';
    const columnName = element.dataset.mindfula11yChildtypeColumnName ?? '';
    const uid = Number.parseInt(element.dataset.mindfula11yChildtypeUid ?? '', 10);
    if (tableName === '' || columnName === '' || Number.isNaN(uid)) {
        return null;
    }
    return { tableName, columnName, uid, editLink: '', storedValue: element.dataset.mindfula11yChildtypeValue ?? '' };
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
