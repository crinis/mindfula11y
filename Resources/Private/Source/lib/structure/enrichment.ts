/*
 * Mindful A11y extension for TYPO3 integrating accessibility tools into the backend.
 * Copyright (C) 2026  Mindful Markup, Felix Spittel
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 */

import type { RecordReference } from '../types.js';
import type { StructureAnalysis } from './types.js';

/** A record's annotated column; `columnName` alone distinguishes the domains. */
export interface StructureRecordRequest {
    tableName: string;
    columnName: string;
    uid: number;
}

export interface StructureRecordMetadata extends StructureRecordRequest {
    editLink: string;
    availableValues: Record<string, string>;
}

/**
 * Identifies a record's annotated column across both the request and response
 * wire shapes. `\u0000` cannot appear in a table/column name, so this is
 * injective; `columnName` is included because one record can annotate more
 * than one column (e.g. a heading and a landmark on the same row).
 */
export const recordKey = (record: StructureRecordRequest): string =>
    `${record.tableName}\u0000${record.uid}\u0000${record.columnName}`;

const walk = <T extends { children: T[] }>(nodes: T[], visit: (node: T) => void): void => {
    for (const node of nodes) {
        visit(node);
        walk(node.children, visit);
    }
};

const addRecord = (records: Map<string, StructureRecordRequest>, record: RecordReference | null): void => {
    if (record === null) {
        return;
    }
    const request = { tableName: record.tableName, columnName: record.columnName, uid: record.uid };
    records.set(recordKey(request), request);
};

/** Walks both domains and collects every distinct record/column pair that needs backend metadata. */
export const collectRecordRequests = (analysis: StructureAnalysis): StructureRecordRequest[] => {
    const records = new Map<string, StructureRecordRequest>();
    if (analysis.headings !== null) {
        walk(analysis.headings.nodes, (node) => addRecord(records, node.record));
    }
    if (analysis.landmarks !== null) {
        walk(analysis.landmarks.nodes, (node) => addRecord(records, node.record));
    }
    return Array.from(records.values());
};

/** Attaches the backend-resolved edit link to the node and hands its select items to the domain setter. */
const enrichNode = (
    node: { record: RecordReference | null },
    metadata: ReadonlyMap<string, StructureRecordMetadata>,
    applyValues: (values: Record<string, string>) => void,
): void => {
    const record = node.record;
    if (record === null) {
        return;
    }
    const value = metadata.get(recordKey(record));
    if (value === undefined) {
        return;
    }
    node.record = { ...record, editLink: value.editLink };
    applyValues(value.availableValues);
};

/**
 * Mutates `analysis` in place: sets `availableTypes`/`availableRoles` and
 * `record.editLink` on every node whose record matches an entry in
 * `metadata`; nodes without a match (or without a record) are left as-is.
 */
export const applyRecordMetadata = (
    analysis: StructureAnalysis,
    metadata: ReadonlyMap<string, StructureRecordMetadata>,
): void => {
    if (analysis.headings !== null) {
        walk(analysis.headings.nodes, (node) =>
            enrichNode(node, metadata, (values) => {
                node.availableTypes = values;
            }),
        );
    }
    if (analysis.landmarks !== null) {
        walk(analysis.landmarks.nodes, (node) =>
            enrichNode(node, metadata, (values) => {
                node.availableRoles = values;
            }),
        );
    }
};
