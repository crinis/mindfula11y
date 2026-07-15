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

    it('rejects analysis payloads that exceed the global node limit', () => {
        const node = {
            id: 'heading',
            documentOrder: 0,
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
