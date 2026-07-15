/*
 * Mindful A11y extension for TYPO3 integrating accessibility tools into the backend.
 * Copyright (C) 2026  Mindful Markup, Felix Spittel
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 */

import { describe, expect, it } from 'vitest';
import {
    applyRecordMetadata,
    collectRecordRequests,
    type StructureRecordMetadata,
} from '../../../../Resources/Private/Source/lib/structure/enrichment.js';
import type { HeadingNode, LandmarkNode } from '../../../../Resources/Private/Source/lib/structure/types.js';
import type { RecordReference } from '../../../../Resources/Private/Source/lib/types.js';
import type { StructureAnalysis } from '../../../../Resources/Private/Source/service/structure/coordinator.js';

const record = (uid: number, columnName = 'header_layout'): RecordReference => ({
    tableName: 'tt_content',
    columnName,
    uid,
    editLink: '',
});

const heading = (overrides: Partial<HeadingNode> = {}): HeadingNode => ({
    id: `heading:${overrides.documentOrder ?? 0}`,
    documentOrder: 0,
    level: 2,
    label: 'Heading',
    availableTypes: {},
    record: null,
    relationId: '',
    relation: null,
    skippedLevels: 0,
    viewports: ['desktop'],
    errors: [],
    children: [],
    ...overrides,
});

const landmark = (overrides: Partial<LandmarkNode> = {}): LandmarkNode => ({
    id: `landmark:${overrides.documentOrder ?? 0}`,
    documentOrder: 0,
    role: 'navigation',
    label: 'Landmark',
    availableRoles: {},
    record: null,
    viewports: ['desktop'],
    errors: [],
    children: [],
    ...overrides,
});

describe('collectRecordRequests', () => {
    it('gathers deduplicated requests from a small tree, across both domains', () => {
        const analysis: StructureAnalysis = {
            headings: {
                nodes: [
                    heading({
                        record: record(1),
                        children: [heading({ record: record(2) })],
                    }),
                    // Same table/column/uid as above: must collapse to one request.
                    heading({ record: record(1) }),
                ],
                errors: [],
            },
            landmarks: {
                nodes: [landmark({ record: record(3, 'menu_layout') })],
                errors: [],
            },
        };

        const requests = collectRecordRequests(analysis);

        expect(requests).toHaveLength(3);
        expect(requests).toEqual(
            expect.arrayContaining([
                { tableName: 'tt_content', columnName: 'header_layout', uid: 1 },
                { tableName: 'tt_content', columnName: 'header_layout', uid: 2 },
                { tableName: 'tt_content', columnName: 'menu_layout', uid: 3 },
            ]),
        );
    });

    it('skips nodes without a backing record', () => {
        const analysis: StructureAnalysis = {
            headings: { nodes: [heading({ record: null })], errors: [] },
            landmarks: null,
        };

        expect(collectRecordRequests(analysis)).toEqual([]);
    });

    it('returns an empty array when both domains are disabled', () => {
        expect(collectRecordRequests({ headings: null, landmarks: null })).toEqual([]);
    });
});

describe('applyRecordMetadata', () => {
    it('sets availableTypes and record.editLink on matching heading nodes', () => {
        const matched = heading({ record: record(1) });
        const unmatched = heading({ record: record(99), documentOrder: 1 });
        const analysis: StructureAnalysis = { headings: { nodes: [matched, unmatched], errors: [] }, landmarks: null };
        const metadata = new Map<string, StructureRecordMetadata>([
            [
                'tt_content\u00001\u0000header_layout',
                {
                    tableName: 'tt_content',
                    columnName: 'header_layout',
                    uid: 1,
                    editLink: '/edit/1',
                    availableValues: { h2: 'H2' },
                },
            ],
        ]);

        applyRecordMetadata(analysis, metadata);

        expect(matched.availableTypes).toEqual({ h2: 'H2' });
        expect(matched.record).toEqual({
            tableName: 'tt_content',
            columnName: 'header_layout',
            uid: 1,
            editLink: '/edit/1',
        });
        // Non-matching node is left untouched.
        expect(unmatched.availableTypes).toEqual({});
        expect(unmatched.record).toEqual(record(99));
    });

    it('sets availableRoles on matching landmark nodes, recursing into children', () => {
        const child = landmark({ record: record(5, 'menu_layout'), documentOrder: 1 });
        const parent = landmark({ record: null, children: [child] });
        const analysis: StructureAnalysis = { headings: null, landmarks: { nodes: [parent], errors: [] } };
        const metadata = new Map<string, StructureRecordMetadata>([
            [
                'tt_content\u00005\u0000menu_layout',
                {
                    tableName: 'tt_content',
                    columnName: 'menu_layout',
                    uid: 5,
                    editLink: '/edit/5',
                    availableValues: { nav: 'Navigation' },
                },
            ],
        ]);

        applyRecordMetadata(analysis, metadata);

        expect(child.availableRoles).toEqual({ nav: 'Navigation' });
        expect(child.record?.editLink).toBe('/edit/5');
        // Record-less parent is left untouched.
        expect(parent.availableRoles).toEqual({});
        expect(parent.record).toBeNull();
    });

    it('is a no-op for a disabled domain', () => {
        const analysis: StructureAnalysis = { headings: null, landmarks: null };

        expect(() => applyRecordMetadata(analysis, new Map())).not.toThrow();
    });
});
