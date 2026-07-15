/*
 * Mindful A11y extension for TYPO3 integrating accessibility tools into the backend.
 * Copyright (C) 2026  Mindful Markup, Felix Spittel
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 */

// @vitest-environment happy-dom

import { describe, expect, it, vi } from 'vitest';
import type {
    HeadingAnalysis,
    HeadingNode,
    StructureViewport,
} from '../../../../Resources/Private/Source/lib/structure/types.js';
import { StructureAnalysisCoordinator } from '../../../../Resources/Private/Source/service/structure/coordinator.js';

vi.mock('../../../../Resources/Private/Source/service/structure/api.js', () => {
    const module = {};
    Reflect.set(module, 'StructureAnalysisApi', class {});
    return module;
});

const heading = (viewport: StructureViewport): HeadingNode => ({
    id: 'heading:1',
    documentOrder: 0,
    level: 1,
    label: 'Title',
    availableTypes: {},
    record: null,
    relationId: '',
    relation: null,
    skippedLevels: 0,
    viewports: [viewport],
    errors: [],
    children: [],
});

describe('StructureAnalysisCoordinator', () => {
    it('loads both viewports concurrently, merges enabled domains, and enriches the result', async () => {
        const pending = new Map<StructureViewport, (analysis: HeadingAnalysis) => void>();
        const loader = {
            load: vi.fn(
                (viewport: StructureViewport) =>
                    new Promise<{ headings: HeadingAnalysis; landmarks: null }>((resolve) => {
                        pending.set(viewport, (headings) => resolve({ headings, landmarks: null }));
                    }),
            ),
        };
        const backend = {
            fetchRecordMetadata: vi.fn(async () => new Map()),
        };
        const coordinator = new StructureAnalysisCoordinator(backend, loader);
        const signal = new AbortController().signal;
        const analyzing = coordinator.analyze(
            { pageId: 42, languageId: 3, headings: true, landmarks: false },
            document,
            signal,
        );

        expect(loader.load.mock.calls.map(([viewport]) => viewport)).toEqual(['mobile', 'desktop']);
        pending.get('mobile')?.({ nodes: [heading('mobile')], errors: [] });
        pending.get('desktop')?.({ nodes: [heading('desktop')], errors: [] });

        const analysis = await analyzing;
        expect(analysis.headings?.nodes[0]?.viewports).toEqual(['mobile', 'desktop']);
        expect(analysis.landmarks).toBeNull();
        // The merged heading node has no backing record, so nothing to fetch metadata for.
        expect(backend.fetchRecordMetadata).toHaveBeenCalledWith([], signal);
    });

    it('rejects an enabled domain when either viewport omits its analysis', async () => {
        const loader = {
            load: vi.fn(async (viewport: StructureViewport) => ({
                headings: viewport === 'mobile' ? { nodes: [heading(viewport)], errors: [] } : null,
                landmarks: null,
            })),
        };
        const backend = {
            fetchRecordMetadata: vi.fn(async () => new Map()),
        };
        const coordinator = new StructureAnalysisCoordinator(backend, loader);

        await expect(
            coordinator.analyze(
                { pageId: 42, languageId: 0, headings: true, landmarks: false },
                document,
                new AbortController().signal,
            ),
        ).rejects.toMatchObject({ code: 'payload' });
        expect(backend.fetchRecordMetadata).not.toHaveBeenCalled();
    });
});
