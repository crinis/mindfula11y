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
    aggregateFindings,
    domainErrors,
    enabledDomains,
    pageErrors,
    severityCounts,
} from '../../../../Resources/Private/Source/lib/structure/findings.js';
import type {
    StructureAnalysis,
    StructureError,
    StructureViewport,
} from '../../../../Resources/Private/Source/lib/structure/types.js';
import { StructureErrorSeverity } from '../../../../Resources/Private/Source/lib/structure/types.js';

const makeError = (
    key: string,
    // Destructuring defaults apply on undefined only, so an explicit
    // page-level `nodeId: null` passes through.
    {
        severity = StructureErrorSeverity.Error,
        nodeId = 'node-1',
        viewports = ['mobile', 'desktop'],
    }: { severity?: StructureErrorSeverity; nodeId?: string | null; viewports?: StructureViewport[] } = {},
): StructureError => ({ key, severity, nodeId, viewports });

const makeAnalysis = (headingErrors: StructureError[], landmarkErrors: StructureError[] | null): StructureAnalysis => ({
    headings: { nodes: [], errors: headingErrors },
    landmarks: landmarkErrors === null ? null : { nodes: [], errors: landmarkErrors },
});

const bothEnabled = { headings: true, landmarks: true };

describe('enabledDomains', () => {
    it('lists the enabled domains in canonical order, headings first', () => {
        expect(enabledDomains(bothEnabled)).toEqual(['headings', 'landmarks']);
        expect(enabledDomains({ headings: false, landmarks: true })).toEqual(['landmarks']);
        expect(enabledDomains({ headings: true, landmarks: false })).toEqual(['headings']);
        expect(enabledDomains({ headings: false, landmarks: false })).toEqual([]);
    });
});

describe('domainErrors', () => {
    it('returns the empty list without an analysis', () => {
        expect(domainErrors(null, 'headings')).toEqual([]);
    });

    it('returns the empty list for a disabled (null) domain slice', () => {
        expect(domainErrors(makeAnalysis([], null), 'landmarks')).toEqual([]);
    });

    it('returns exactly the requested domain slice', () => {
        const headingError = makeError('mindfula11y.structure.headings.error.skippedLevel');
        const landmarkError = makeError('mindfula11y.structure.landmarks.error.duplicateMain');
        const analysis = makeAnalysis([headingError], [landmarkError]);

        expect(domainErrors(analysis, 'headings')).toEqual([headingError]);
        expect(domainErrors(analysis, 'landmarks')).toEqual([landmarkError]);
    });
});

describe('pageErrors', () => {
    it('keeps only errors without a node (page-level findings)', () => {
        const pageLevel = makeError('mindfula11y.structure.headings.error.missingH1', { nodeId: null });
        const nodeLevel = makeError('mindfula11y.structure.headings.error.skippedLevel');
        const analysis = makeAnalysis([pageLevel, nodeLevel], null);

        expect(pageErrors(analysis, 'headings')).toEqual([pageLevel]);
    });
});

describe('severityCounts', () => {
    it('counts zero for a missing analysis', () => {
        expect(severityCounts(null, 'headings')).toEqual({ errors: 0, warnings: 0 });
    });

    it('splits a domain into error and warning totals', () => {
        const analysis = makeAnalysis(
            [
                makeError('a', { severity: StructureErrorSeverity.Error }),
                makeError('b', { severity: StructureErrorSeverity.Warning }),
                makeError('c', { severity: StructureErrorSeverity.Warning }),
            ],
            null,
        );

        expect(severityCounts(analysis, 'headings')).toEqual({ errors: 1, warnings: 2 });
    });
});

describe('aggregateFindings', () => {
    it('returns no findings without an analysis', () => {
        expect(aggregateFindings(null, bothEnabled)).toEqual([]);
    });

    it('counts occurrences of the same error key into one finding', () => {
        const analysis = makeAnalysis([makeError('dup', { nodeId: 'a' }), makeError('dup', { nodeId: 'b' })], null);

        const findings = aggregateFindings(analysis, bothEnabled);

        expect(findings).toHaveLength(1);
        expect(findings[0]).toMatchObject({ key: 'dup', domain: 'headings', count: 2 });
    });

    it('merges the viewports of aggregated occurrences in canonical order', () => {
        const analysis = makeAnalysis(
            [
                makeError('dup', { nodeId: 'a', viewports: ['desktop'] }),
                makeError('dup', { nodeId: 'b', viewports: ['mobile'] }),
            ],
            null,
        );

        const findings = aggregateFindings(analysis, bothEnabled);

        expect(findings[0]?.viewports).toEqual(['mobile', 'desktop']);
    });

    it('never merges the same key across domains into one chip', () => {
        const analysis = makeAnalysis([makeError('shared.key')], [makeError('shared.key')]);

        const findings = aggregateFindings(analysis, bothEnabled);

        expect(findings).toHaveLength(2);
        expect(findings.map((finding) => finding.domain)).toEqual(['headings', 'landmarks']);
    });

    it('sorts errors before warnings and keeps insertion order within a severity', () => {
        const analysis = makeAnalysis(
            [
                makeError('warning-1', { severity: StructureErrorSeverity.Warning }),
                makeError('error-1', { severity: StructureErrorSeverity.Error }),
                makeError('warning-2', { severity: StructureErrorSeverity.Warning }),
            ],
            null,
        );

        const findings = aggregateFindings(analysis, bothEnabled);

        expect(findings.map((finding) => finding.key)).toEqual(['error-1', 'warning-1', 'warning-2']);
    });

    it('ignores errors of a disabled domain', () => {
        const analysis = makeAnalysis([makeError('heading-error')], [makeError('landmark-error')]);

        const findings = aggregateFindings(analysis, { headings: true, landmarks: false });

        expect(findings).toHaveLength(1);
        expect(findings[0]?.key).toBe('heading-error');
    });

    it('does not mutate the source errors when merging viewports', () => {
        const first = makeError('dup', { nodeId: 'a', viewports: ['mobile'] });
        const second = makeError('dup', { nodeId: 'b', viewports: ['desktop'] });
        const analysis = makeAnalysis([first, second], null);

        aggregateFindings(analysis, bothEnabled);

        expect(first.viewports).toEqual(['mobile']);
        expect(second.viewports).toEqual(['desktop']);
    });
});
