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

import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('@typo3/core/lit-helper.js', () => ({
    lll: (key: string, ...args: unknown[]): string => (args.length > 0 ? `${key}: ${args.join(', ')}` : key),
}));
vi.mock('@typo3/backend/element/icon-element.js', () => ({}));
vi.mock('@typo3/backend/element/spinner-element.js', () => ({}));
vi.mock('@typo3/backend/notification.js', () => ({
    default: { error: vi.fn(), success: vi.fn() },
}));
vi.mock('../../../Resources/Private/Source/service/structure/coordinator.js', () => {
    const module = {};
    Reflect.set(module, 'StructureAnalysisCoordinator', {
        createDefault: (): { analyze: ReturnType<typeof vi.fn> } => ({ analyze: vi.fn() }),
    });
    return module;
});

import '../../../Resources/Private/Source/element/structure/structure.js';
import type { StructureAnalysis, StructureError } from '../../../Resources/Private/Source/lib/structure/types.js';
import { StructureErrorSeverity } from '../../../Resources/Private/Source/lib/structure/types.js';

const makeError = (nodeId: string): StructureError => ({
    key: 'mindfula11y.structure.headings.error.emptyHeadings',
    severity: StructureErrorSeverity.Error,
    nodeId,
    viewports: ['desktop'],
});

describe('Structure', () => {
    afterEach(() => {
        document.body.replaceChildren();
    });

    it('labels and emphasizes the occurrence count in the findings overview', async () => {
        const analysis: StructureAnalysis = {
            headings: { nodes: [], errors: [makeError('heading-1'), makeError('heading-2')] },
            landmarks: null,
        };
        const view = document.createElement('mindfula11y-structure');
        view.hasHeadingStructureAccess = true;
        Reflect.set(view, 'analysis', analysis);
        document.body.append(view);
        await view.updateComplete;

        const count = view.renderRoot.querySelector('.finding-count');

        expect(count?.tagName).toBe('STRONG');
        expect(count?.textContent?.trim()).toBe('mindfula11y.structure.findingCount: 2');
        expect(count?.closest('ul.findings')).not.toBeNull();
    });
});
