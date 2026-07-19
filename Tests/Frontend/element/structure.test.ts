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

const { analyzeMock } = vi.hoisted(() => ({ analyzeMock: vi.fn() }));

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
        createDefault: (): { analyze: typeof analyzeMock } => ({ analyze: analyzeMock }),
    });
    return module;
});

import type { Structure } from '../../../Resources/Private/Source/element/structure/structure.js';
import '../../../Resources/Private/Source/element/structure/structure.js';
import { StructureAnalysisError } from '../../../Resources/Private/Source/lib/structure/error.js';
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
        analyzeMock.mockReset();
    });

    // Typed `Promise<Structure>`, not `HTMLElement`: callers need `.renderRoot`,
    // which only exists on the `Structure` custom-element type.
    const renderFailed = async (error: StructureAnalysisError): Promise<Structure> => {
        analyzeMock.mockRejectedValueOnce(error);
        const view = document.createElement('mindfula11y-structure');
        view.pageId = 1;
        view.hasHeadingStructureAccess = true;
        document.body.append(view);
        await view.updateComplete;
        // The analyze task rejects in a microtask after the first update;
        // yield a macrotask so the ERROR state re-render has happened.
        await new Promise((resolve) => setTimeout(resolve, 0));
        await view.updateComplete;
        return view;
    };

    it('describes an auth failure and links the page for signing in', async () => {
        const view = await renderFailed(
            new StructureAnalysisError('auth', 'requires sign-in', 401, 'https://staging.example/protected'),
        );

        expect(view.renderRoot.textContent).toContain('mindfula11y.structure.error.rendering.auth');
        const link = view.renderRoot.querySelector('a.open-page') as HTMLAnchorElement | null;
        expect(link?.getAttribute('href')).toBe('https://staging.example/protected');
        expect(link?.target).toBe('_blank');
        expect(link?.rel).toBe('noopener');
        expect(link?.textContent).toContain('mindfula11y.structure.error.rendering.openPage');
        expect(view.renderRoot.querySelector('button.retry')).not.toBeNull();
    });

    it('offers the open-page link for framing failures too, but not without a page URL', async () => {
        const withUrl = await renderFailed(
            new StructureAnalysisError('framing', 'refused', undefined, 'https://staging.example/page'),
        );
        expect(withUrl.renderRoot.querySelector('a.open-page')).not.toBeNull();
        document.body.replaceChildren();
        analyzeMock.mockReset();

        const withoutUrl = await renderFailed(new StructureAnalysisError('timeout', 'slow'));
        expect(withoutUrl.renderRoot.querySelector('a.open-page')).toBeNull();
        expect(withoutUrl.renderRoot.querySelector('button.retry')).not.toBeNull();
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
