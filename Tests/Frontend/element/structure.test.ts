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

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const { analyzeMock, clientGetMock, clientSetMock } = vi.hoisted(() => ({
    analyzeMock: vi.fn(),
    clientGetMock: vi.fn(),
    clientSetMock: vi.fn(),
}));

vi.mock('@typo3/core/lit-helper.js', () => ({
    lll: (key: string, ...args: unknown[]): string => (args.length > 0 ? `${key}: ${args.join(', ')}` : key),
}));
vi.mock('@typo3/backend/element/icon-element.js', () => ({}));
vi.mock('@typo3/backend/element/spinner-element.js', () => ({}));
vi.mock('@typo3/backend/notification.js', () => ({
    default: { error: vi.fn(), success: vi.fn() },
}));
vi.mock('@typo3/backend/storage/client.js', () => ({
    default: { get: clientGetMock, set: clientSetMock },
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

const makeError = (nodeId: string): StructureError => ({
    key: 'mindfula11y.structure.headings.error.emptyHeadings',
    severity: 'moderate',
    nodeId,
    viewports: ['desktop'],
});

/** Two moderate heading findings — the fixture the status-row assertions read. */
const twoHeadingErrors: StructureAnalysis = {
    headings: { nodes: [], errors: [makeError('heading-1'), makeError('heading-2')] },
    landmarks: null,
};

describe('Structure', () => {
    beforeEach(() => {
        analyzeMock.mockReset();
        clientGetMock.mockReset();
        clientGetMock.mockReturnValue(null);
        clientSetMock.mockReset();
    });

    afterEach(() => {
        document.body.replaceChildren();
    });

    /**
     * Mounts the widget with an analysis already in place, skipping the
     * coordinator. Typed `Promise<Structure>`, not `HTMLElement`: callers need
     * `.renderRoot`, which only exists on the custom-element type.
     */
    const render = async (opts: {
        analysis: StructureAnalysis;
        collapsible?: boolean;
        landmarks?: boolean;
    }): Promise<Structure> => {
        const view = document.createElement('mindfula11y-structure');
        view.hasHeadingStructureAccess = true;
        view.hasLandmarkStructureAccess = opts.landmarks ?? false;
        view.collapsible = opts.collapsible ?? false;
        Reflect.set(view, 'analysis', opts.analysis);
        document.body.append(view);
        await view.updateComplete;
        return view;
    };

    /**
     * The widget's status row: the first `<mindfula11y-notice>`, selected
     * structurally rather than by a styling class. It leads the widget on both
     * surfaces — as the disclosure's summary in the page module — and the
     * findings pills below are plain buttons, not notice elements.
     */
    const statusRow = (view: Structure): Element | null => view.renderRoot.querySelector('mindfula11y-notice');

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
        expect(link?.rel).toBe('noreferrer');
        expect(link?.querySelector('.sr-only')?.textContent).toContain('mindfula11y.general.opensNewTab');
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
        const view = await render({ analysis: twoHeadingErrors });

        const count = view.renderRoot.querySelector('.finding-count');

        expect(count?.tagName).toBe('STRONG');
        expect(count?.textContent?.trim()).toBe('mindfula11y.structure.findingCount: 2');
        expect(count?.closest('ul.findings')).not.toBeNull();
    });

    it('summarizes the analysis as a standardized status row', async () => {
        const view = await render({ analysis: twoHeadingErrors });

        const row = statusRow(view);

        // The state icon is aria-hidden and two impacts share one icon, so the
        // worst severity must reach screen readers as text rather than as tint
        // alone.
        expect(row?.querySelector('.sr-only')?.textContent).toContain('mindfula11y.severity.moderate');
        // Two moderate findings: the row is tinted by the worst present impact,
        // not by a flat warning state, and hands the total to the notice's
        // shared count badge instead of spelling it into the label.
        expect(row?.getAttribute('state')).toBe('warning');
        expect(row?.textContent).toContain('mindfula11y.structure.issuesFound');
        expect(row?.getAttribute('count')).toBe('2');
        // The disclosure marker belongs to the collapsible layout only.
        expect(row?.querySelector('.marker')).toBeNull();
    });

    it('reports a clean page as a success row', async () => {
        const view = await render({ analysis: { headings: { nodes: [], errors: [] }, landmarks: null } });

        const row = statusRow(view);

        expect(row?.getAttribute('state')).toBe('success');
        expect(row?.textContent).toContain('mindfula11y.structure.noIssues');
        expect(row?.hasAttribute('count')).toBe(false);
    });

    // A two-domain analysis, so both the tablist and the findings pills are
    // present and their placement can be asserted.
    const renderAnalyzed = (collapsible: boolean): Promise<Structure> =>
        render({
            analysis: {
                headings: { nodes: [], errors: [makeError('heading-1')] },
                landmarks: { nodes: [], errors: [] },
            },
            landmarks: true,
            collapsible,
        });

    it('renders the module layout uncollapsed in the same order as the page module', async () => {
        const view = await renderAnalyzed(false);

        // Same order on both surfaces — status row, tablist, panels — with the
        // disclosure as the only difference.
        expect(view.renderRoot.querySelector('details')).toBeNull();
        const row = statusRow(view) as Element;
        const tablist = view.renderRoot.querySelector('[role="tablist"]') as Element;
        expect(row.compareDocumentPosition(tablist) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
        expect(view.renderRoot.querySelectorAll('[role="tabpanel"]').length).toBe(2);
    });

    it('collapses the trees behind the status row in the page module', async () => {
        const view = await renderAnalyzed(true);

        const details = view.renderRoot.querySelector('details');
        expect(details?.open).toBe(false);
        // The status row is the disclosure's summary and carries the marker.
        const row = details?.querySelector('summary > mindfula11y-notice');
        expect(row).not.toBeNull();
        expect(row?.querySelector('.marker')).not.toBeNull();
        // Tablist, findings pills and panels all live inside the disclosure.
        expect(details?.querySelector('[role="tablist"]')).not.toBeNull();
        expect(details?.querySelector('ul.findings')).not.toBeNull();
        expect(details?.querySelectorAll('[role="tabpanel"]').length).toBe(2);
    });

    it('names the single structure view when access leaves only one domain', async () => {
        // Only heading access: no tablist is rendered, so nothing else would
        // identify the tree — the status row above speaks for the widget, not
        // for the domain.
        const view = await render({ analysis: twoHeadingErrors });

        expect(view.renderRoot.querySelector('[role="tablist"]')).toBeNull();
        const panel = view.renderRoot.querySelector('.panel');
        expect(panel?.getAttribute('role')).toBe('region');
        expect(panel?.getAttribute('aria-label')).toBe('mindfula11y.structure.headings');
    });

    it("lists only the active tab's findings, inside that tab's panel", async () => {
        const analysis: StructureAnalysis = {
            headings: { nodes: [], errors: [makeError('heading-1')] },
            landmarks: {
                nodes: [],
                errors: [
                    {
                        key: 'mindfula11y.structure.landmarks.error.missingMain',
                        severity: 'moderate',
                        nodeId: null,
                        viewports: ['desktop'],
                    },
                ],
            },
        };
        const view = await render({ analysis, landmarks: true });

        const findingsOf = (tab: string): string[] =>
            Array.from(view.renderRoot.querySelectorAll(`#panel-${tab} ul.findings button.finding`)).map(
                (button) => button.textContent?.replace(/\s+/g, ' ').trim() ?? '',
            );

        // Each panel lists its own domain's findings only — a pill is a jump
        // target into the view below it, so the headings panel must not offer
        // jumps into the hidden landmarks view.
        expect(findingsOf('headings')).toHaveLength(1);
        expect(findingsOf('headings')[0]).toContain('mindfula11y.structure.headings.error.emptyHeadings');
        expect(findingsOf('landmarks')).toHaveLength(1);
        expect(findingsOf('landmarks')[0]).toContain('mindfula11y.structure.landmarks.error.missingMain');
    });

    it('renders an identical status row in both surfaces for the same analysis', async () => {
        const moduleView = await render({ analysis: twoHeadingErrors });
        const moduleRow = statusRow(moduleView);
        // One domain, so neither surface renders a tablist and only the page
        // module wraps the row in a disclosure.
        expect(moduleView.renderRoot.querySelector('[role="tablist"]')).toBeNull();
        expect(moduleView.renderRoot.querySelector('details')).toBeNull();
        document.body.replaceChildren();

        const pageView = await render({ analysis: twoHeadingErrors, collapsible: true });
        const pageRow = statusRow(pageView);
        expect(pageView.renderRoot.querySelector('[role="tablist"]')).toBeNull();
        expect(pageView.renderRoot.querySelector('summary > mindfula11y-notice')).not.toBeNull();

        expect(pageRow?.getAttribute('state')).toBe(moduleRow?.getAttribute('state'));
        // The row's leading <span> carries the label and the count reaches the
        // notice's shared badge — compare both independently of the trailing
        // marker, which is collapsible-only and not part of this parity claim.
        expect(pageRow?.querySelector('span')?.textContent?.trim()).toBe(
            moduleRow?.querySelector('span')?.textContent?.trim(),
        );
        expect(pageRow?.getAttribute('count')).toBe('2');
        expect(pageRow?.getAttribute('count')).toBe(moduleRow?.getAttribute('count'));
    });

    it('shows a compact spinner row while the first analysis runs', async () => {
        analyzeMock.mockReturnValueOnce(new Promise(() => {}));
        const view = document.createElement('mindfula11y-structure');
        view.pageId = 1;
        view.hasHeadingStructureAccess = true;
        view.collapsible = true;
        document.body.append(view);
        await view.updateComplete;

        expect(view.renderRoot.querySelector('details')).toBeNull();
        const row = view.renderRoot.querySelector('mindfula11y-notice[state="info"]');
        expect(row?.textContent).toContain('mindfula11y.structure.analyzing');
        expect(row?.querySelector('typo3-backend-spinner')).not.toBeNull();
    });

    it('restores a remembered expansion and persists a toggle', async () => {
        clientGetMock.mockReturnValue('1');
        const view = await renderAnalyzed(true);

        const details = view.renderRoot.querySelector('details');
        expect(clientGetMock).toHaveBeenCalledWith('mindfula11y-structure-expanded');
        expect(details?.open).toBe(true);

        // happy-dom does not implement summary activation; drive the native
        // state change the way the browser would report it.
        (details as HTMLDetailsElement).open = false;
        details?.dispatchEvent(new Event('toggle'));
        await view.updateComplete;

        expect(clientSetMock).toHaveBeenCalledWith('mindfula11y-structure-expanded', '0');
    });

    it('defaults to collapsed when nothing is stored', async () => {
        const view = await renderAnalyzed(true);

        expect(view.renderRoot.querySelector('details')?.open).toBe(false);
        expect(clientSetMock).not.toHaveBeenCalled();
    });

    it('keeps an open disclosure open and mounted across a re-analysis', async () => {
        clientGetMock.mockReturnValue('1');
        const view = await renderAnalyzed(true);

        const details = view.renderRoot.querySelector('details');
        expect(details?.open).toBe(true);

        // A save-triggered re-analysis swaps `analysis` in place; the
        // disclosure must neither slam shut nor be torn down and recreated.
        const nextAnalysis: StructureAnalysis = {
            headings: { nodes: [], errors: [] },
            landmarks: { nodes: [], errors: [] },
        };
        Reflect.set(view, 'analysis', nextAnalysis);
        await view.updateComplete;

        const detailsAfter = view.renderRoot.querySelector('details');
        expect(detailsAfter).toBe(details);
        expect(detailsAfter?.open).toBe(true);
    });

    describe('when moved while an analysis runs', () => {
        /** An analysis that only settles when its signal aborts — the pending run a move interrupts. */
        const abortableAnalysis = (_demand: unknown, _root: unknown, signal: AbortSignal): Promise<never> =>
            new Promise((_resolve, reject) => {
                signal.addEventListener('abort', () => reject(signal.reason), { once: true });
            });

        const mountPending = async (): Promise<Structure> => {
            analyzeMock.mockImplementationOnce(abortableAnalysis);
            analyzeMock.mockResolvedValueOnce(twoHeadingErrors);
            const view = document.createElement('mindfula11y-structure');
            view.pageId = 1;
            view.hasHeadingStructureAccess = true;
            document.body.append(view);
            await view.updateComplete;
            return view;
        };

        const settle = async (view: Structure): Promise<void> => {
            await new Promise((resolve) => setTimeout(resolve, 0));
            await view.updateComplete;
        };

        it('re-runs the analysis when reinserted after the aborted run settled', async () => {
            const view = await mountPending();
            view.remove();
            await settle(view);
            document.body.append(view);
            await settle(view);

            expect(analyzeMock).toHaveBeenCalledTimes(2);
            expect(view.renderRoot.textContent).not.toContain('mindfula11y.structure.error.rendering');
            expect(statusRow(view)).not.toBeNull();
        });

        it('re-runs the analysis when reinserted in the same task', async () => {
            const view = await mountPending();
            const target = document.createElement('div');
            document.body.append(target);
            target.append(view);
            await settle(view);

            expect(analyzeMock).toHaveBeenCalledTimes(2);
            expect(view.renderRoot.textContent).not.toContain('mindfula11y.structure.error.rendering');
        });
    });
});
