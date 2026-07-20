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
    isStructureAnalysisErrorMessage,
    isStructureAnalysisResultMessage,
    parsePortMessage,
    STRUCTURE_ANALYSIS_PROTOCOL,
} from '../../../../Resources/Private/Source/lib/structure/protocol.js';

const REQUEST_ID = '0123456789abcdef0123456789abcdef';
const FOREIGN_REQUEST_ID = 'fedcba9876543210fedcba9876543210';

describe('structure-analysis-protocol guards', () => {
    it('recognizes an HTTP error reported by the isolated runner', () => {
        expect(
            isStructureAnalysisErrorMessage(
                {
                    protocol: STRUCTURE_ANALYSIS_PROTOCOL,
                    type: 'error',
                    requestId: REQUEST_ID,
                    code: 'http',
                    status: 404,
                },
                REQUEST_ID,
            ),
        ).toBe(true);
    });

    it('rejects a result with the wrong request id', () => {
        expect(
            isStructureAnalysisResultMessage(
                {
                    protocol: STRUCTURE_ANALYSIS_PROTOCOL,
                    type: 'result',
                    requestId: FOREIGN_REQUEST_ID,
                    viewport: 'mobile',
                    headings: null,
                    landmarks: null,
                },
                REQUEST_ID,
                'mobile',
            ),
        ).toBe(false);
    });

    it('accepts findings with every axe impact severity', () => {
        const errors = ['critical', 'serious', 'moderate', 'minor'].map((severity) => ({
            key: 'mindfula11y.structure.headings.error.skippedLevel',
            severity,
            nodeId: null,
            viewports: ['mobile'],
        }));

        expect(
            isStructureAnalysisResultMessage(
                {
                    protocol: STRUCTURE_ANALYSIS_PROTOCOL,
                    type: 'result',
                    requestId: REQUEST_ID,
                    viewport: 'mobile',
                    headings: { nodes: [], errors },
                    landmarks: null,
                },
                REQUEST_ID,
                'mobile',
            ),
        ).toBe(true);
    });

    it('rejects a finding whose severity is not an axe impact', () => {
        // 'error'/'warning' were the pre-impact-scale severities; the wire
        // guard must accept exactly the current impact values and nothing else.
        expect(
            isStructureAnalysisResultMessage(
                {
                    protocol: STRUCTURE_ANALYSIS_PROTOCOL,
                    type: 'result',
                    requestId: REQUEST_ID,
                    viewport: 'mobile',
                    headings: {
                        nodes: [],
                        errors: [
                            {
                                key: 'mindfula11y.structure.headings.error.skippedLevel',
                                severity: 'error',
                                nodeId: null,
                                viewports: ['mobile'],
                            },
                        ],
                    },
                    landmarks: null,
                },
                REQUEST_ID,
                'mobile',
            ),
        ).toBe(false);
    });

    it('accepts a container node whose own type is not a heading (level 0)', () => {
        // Mirrors heading-analysis.ts: a data-mindfula11y-container="p|div"
        // element is reported as kind 'container' with level 0.
        const containerNode = {
            id: 'container',
            documentOrder: 0,
            kind: 'container',
            level: 0,
            label: '',
            availableTypes: {},
            availableChildTypes: {},
            record: null,
            childTypeRecord: null,
            relationId: 'rel-container',
            relation: null,
            skippedLevels: 0,
            viewports: ['mobile'],
            errors: [],
            children: [],
        };

        expect(
            isStructureAnalysisResultMessage(
                {
                    protocol: STRUCTURE_ANALYSIS_PROTOCOL,
                    type: 'result',
                    requestId: REQUEST_ID,
                    viewport: 'mobile',
                    headings: { nodes: [containerNode], errors: [] },
                    landmarks: null,
                },
                REQUEST_ID,
                'mobile',
            ),
        ).toBe(true);
    });

    it('accepts a demoted node at level 0 and rejects it at any heading level', () => {
        // Mirrors heading-analysis.ts: a rendered data-mindfula11y-demoted tag
        // (p/div) is reported as kind 'demoted' and always level 0.
        const demotedNode = {
            id: 'demoted',
            documentOrder: 0,
            kind: 'demoted',
            level: 0,
            label: 'Former heading',
            availableTypes: {},
            availableChildTypes: {},
            record: null,
            childTypeRecord: null,
            relationId: '',
            relation: null,
            skippedLevels: 0,
            viewports: ['mobile'],
            errors: [],
            children: [],
        };
        const message = (node: object): object => ({
            protocol: STRUCTURE_ANALYSIS_PROTOCOL,
            type: 'result',
            requestId: REQUEST_ID,
            viewport: 'mobile',
            headings: { nodes: [node], errors: [] },
            landmarks: null,
        });

        expect(isStructureAnalysisResultMessage(message(demotedNode), REQUEST_ID, 'mobile')).toBe(true);
        expect(isStructureAnalysisResultMessage(message({ ...demotedNode, level: 2 }), REQUEST_ID, 'mobile')).toBe(
            false,
        );
        expect(
            isStructureAnalysisResultMessage(message({ ...demotedNode, kind: 'paragraph' }), REQUEST_ID, 'mobile'),
        ).toBe(false);
        // The optional non-heading type is bounded to the two rendered tags.
        expect(
            isStructureAnalysisResultMessage(message({ ...demotedNode, nonHeadingType: 'p' }), REQUEST_ID, 'mobile'),
        ).toBe(true);
        expect(
            isStructureAnalysisResultMessage(message({ ...demotedNode, nonHeadingType: 'span' }), REQUEST_ID, 'mobile'),
        ).toBe(false);
    });

    it('rejects a level-0 node that claims to be a heading rather than a container', () => {
        const headingNode = {
            id: 'heading',
            documentOrder: 0,
            kind: 'heading',
            level: 0,
            label: 'Heading',
            availableTypes: {},
            availableChildTypes: {},
            record: null,
            childTypeRecord: null,
            relationId: '',
            relation: null,
            skippedLevels: 0,
            viewports: ['mobile'],
            errors: [],
            children: [],
        };

        expect(
            isStructureAnalysisResultMessage(
                {
                    protocol: STRUCTURE_ANALYSIS_PROTOCOL,
                    type: 'result',
                    requestId: REQUEST_ID,
                    viewport: 'mobile',
                    headings: { nodes: [headingNode], errors: [] },
                    landmarks: null,
                },
                REQUEST_ID,
                'mobile',
            ),
        ).toBe(false);
    });

    it('rejects a node smuggling a malformed childTypeRecord past the validator', () => {
        // childTypeRecord flows into the enrichment POST body; a hostile frame
        // must not get arbitrary objects past the record shape check.
        const node = {
            id: 'container',
            documentOrder: 0,
            kind: 'container',
            level: 0,
            label: '',
            availableTypes: {},
            availableChildTypes: {},
            record: null,
            childTypeRecord: {
                tableName: 'tt_content',
                columnName: 'header',
                uid: 1,
                editLink: 'https://evil.example/',
            },
            relationId: 'rel-container',
            relation: null,
            skippedLevels: 0,
            viewports: ['mobile'],
            errors: [],
            children: [],
        };

        expect(
            isStructureAnalysisResultMessage(
                {
                    protocol: STRUCTURE_ANALYSIS_PROTOCOL,
                    type: 'result',
                    requestId: REQUEST_ID,
                    viewport: 'mobile',
                    headings: { nodes: [node], errors: [] },
                    landmarks: null,
                },
                REQUEST_ID,
                'mobile',
            ),
        ).toBe(false);
    });

    it('rejects a record whose storedValue exceeds the wire bound', () => {
        const node = {
            id: 'container',
            documentOrder: 0,
            kind: 'container',
            level: 0,
            label: '',
            availableTypes: {},
            availableChildTypes: {},
            record: null,
            childTypeRecord: {
                tableName: 'tt_content',
                columnName: 'header',
                uid: 1,
                editLink: '',
                storedValue: 'x'.repeat(129),
            },
            relationId: 'rel-container',
            relation: null,
            skippedLevels: 0,
            viewports: ['mobile'],
            errors: [],
            children: [],
        };

        expect(
            isStructureAnalysisResultMessage(
                {
                    protocol: STRUCTURE_ANALYSIS_PROTOCOL,
                    type: 'result',
                    requestId: REQUEST_ID,
                    viewport: 'mobile',
                    headings: { nodes: [node], errors: [] },
                    landmarks: null,
                },
                REQUEST_ID,
                'mobile',
            ),
        ).toBe(false);

        // The same record with a bounded storedValue passes.
        node.childTypeRecord.storedValue = 'h2';
        expect(
            isStructureAnalysisResultMessage(
                {
                    protocol: STRUCTURE_ANALYSIS_PROTOCOL,
                    type: 'result',
                    requestId: REQUEST_ID,
                    viewport: 'mobile',
                    headings: { nodes: [node], errors: [] },
                    landmarks: null,
                },
                REQUEST_ID,
                'mobile',
            ),
        ).toBe(true);
    });

    it('rejects a node whose availableChildTypes is not a bounded string map', () => {
        const node = {
            id: 'heading',
            documentOrder: 0,
            kind: 'heading',
            level: 2,
            label: 'Heading',
            availableTypes: {},
            availableChildTypes: { h3: 42 },
            record: null,
            childTypeRecord: null,
            relationId: '',
            relation: null,
            skippedLevels: 0,
            viewports: ['mobile'],
            errors: [],
            children: [],
        };

        expect(
            isStructureAnalysisResultMessage(
                {
                    protocol: STRUCTURE_ANALYSIS_PROTOCOL,
                    type: 'result',
                    requestId: REQUEST_ID,
                    viewport: 'mobile',
                    headings: { nodes: [node], errors: [] },
                    landmarks: null,
                },
                REQUEST_ID,
                'mobile',
            ),
        ).toBe(false);
    });

    it('rejects analysis payloads that exceed the global node limit', () => {
        const node = {
            id: 'heading',
            documentOrder: 0,
            kind: 'heading',
            level: 1,
            label: 'Heading',
            availableTypes: {},
            record: null,
            relationId: '',
            relation: null,
            skippedLevels: 0,
            viewports: ['mobile'],
            errors: [],
            children: [],
        };

        expect(
            isStructureAnalysisResultMessage(
                {
                    protocol: STRUCTURE_ANALYSIS_PROTOCOL,
                    type: 'result',
                    requestId: REQUEST_ID,
                    viewport: 'mobile',
                    headings: { nodes: Array.from({ length: 2_001 }, () => node), errors: [] },
                    landmarks: null,
                },
                REQUEST_ID,
                'mobile',
            ),
        ).toBe(false);
    });
});

describe('parsePortMessage', () => {
    it('parses a valid result envelope with the expected viewport', () => {
        const headings = { nodes: [], errors: [] };
        const landmarks = { nodes: [], errors: [] };

        expect(
            parsePortMessage(
                {
                    protocol: STRUCTURE_ANALYSIS_PROTOCOL,
                    type: 'result',
                    requestId: REQUEST_ID,
                    viewport: 'mobile',
                    headings,
                    landmarks,
                },
                REQUEST_ID,
                'mobile',
            ),
        ).toEqual({ kind: 'result', headings, landmarks });
    });

    it('parses a valid error envelope into a human-readable message', () => {
        expect(
            parsePortMessage(
                {
                    protocol: STRUCTURE_ANALYSIS_PROTOCOL,
                    type: 'error',
                    requestId: REQUEST_ID,
                    code: 'http',
                    status: 404,
                },
                REQUEST_ID,
                'mobile',
            ),
        ).toEqual({
            kind: 'error',
            code: 'http',
            status: 404,
            message: 'The frontend preview returned HTTP status 404.',
        });
    });

    it('reports an addressed but invalid result envelope as invalid-result rather than ignoring it', () => {
        expect(
            parsePortMessage(
                {
                    protocol: STRUCTURE_ANALYSIS_PROTOCOL,
                    type: 'result',
                    requestId: REQUEST_ID,
                    viewport: 'mobile',
                    headings: 'not-an-analysis',
                    landmarks: null,
                },
                REQUEST_ID,
                'mobile',
            ),
        ).toEqual({ kind: 'invalid-result' });
    });

    it('reports an addressed result envelope with the wrong viewport as invalid-result', () => {
        expect(
            parsePortMessage(
                {
                    protocol: STRUCTURE_ANALYSIS_PROTOCOL,
                    type: 'result',
                    requestId: REQUEST_ID,
                    viewport: 'desktop',
                    headings: { nodes: [], errors: [] },
                    landmarks: null,
                },
                REQUEST_ID,
                'mobile',
            ),
        ).toEqual({ kind: 'invalid-result' });
    });

    it('ignores a message addressed to a different request id', () => {
        expect(
            parsePortMessage(
                {
                    protocol: STRUCTURE_ANALYSIS_PROTOCOL,
                    type: 'result',
                    requestId: FOREIGN_REQUEST_ID,
                    viewport: 'mobile',
                    headings: null,
                    landmarks: null,
                },
                REQUEST_ID,
                'mobile',
            ),
        ).toBeNull();
    });

    it('ignores data that is not part of this protocol', () => {
        expect(parsePortMessage({ foo: 'bar' }, REQUEST_ID, 'mobile')).toBeNull();
        expect(parsePortMessage(null, REQUEST_ID, 'mobile')).toBeNull();
    });
});
