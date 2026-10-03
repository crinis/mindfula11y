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

const { createScanMock, loadScanMock } = vi.hoisted(() => ({ createScanMock: vi.fn(), loadScanMock: vi.fn() }));

vi.mock('@typo3/core/lit-helper.js', () => ({
    lll: (key: string, ...args: unknown[]): string => (args.length > 0 ? `${key}: ${args.join(', ')}` : key),
}));
vi.mock('@typo3/backend/element/icon-element.js', () => ({}));
vi.mock('@typo3/backend/element/spinner-element.js', () => ({}));
vi.mock('../../../Resources/Private/Source/service/scan/api.js', () => {
    const module = {};
    Reflect.set(
        module,
        'ScanApi',
        class {
            createScan = createScanMock;
            loadScan = loadScanMock;
        },
    );
    return module;
});

import type { ScanIssueCount } from '../../../Resources/Private/Source/element/scan-issue-count/scan-issue-count.js';
import '../../../Resources/Private/Source/element/scan-issue-count/scan-issue-count.js';

/**
 * happy-dom implements neither `attachInternals` nor `CustomStateSet`. The
 * component hides its empty host through a `--empty` custom state, so the
 * shim records each host's state set — the observable contract the custom
 * state tests assert against (`:state()` matching itself needs a real
 * browser and is covered by the backend verification pass).
 */
const statesByHost = new WeakMap<HTMLElement, Set<string>>();
HTMLElement.prototype.attachInternals = function (this: HTMLElement): ElementInternals {
    const states = new Set<string>();
    statesByHost.set(this, states);
    return { states } as unknown as ElementInternals;
};

import { LiveAnnouncer } from '../../../Resources/Private/Source/lib/live-announcer.js';
import type { CreateScanDemand, ScanResult } from '../../../Resources/Private/Source/lib/scan/types.js';
import { ScanStatus } from '../../../Resources/Private/Source/lib/scan/types.js';
import { RequestError } from '../../../Resources/Private/Source/service/request-error.js';

const demand: CreateScanDemand = {
    userId: 1,
    pageId: 5,
    previewUrl: 'https://example.test/',
    languageId: 0,
    workspaceId: 0,
    pageLevels: 0,
    crawl: false,
    expiresAt: 0,
    signature: 'sig',
};

const completedWith = (totalIssueCount: number): ScanResult => ({
    status: ScanStatus.Completed,
    violations: [],
    totalIssueCount,
    mode: null,
    targets: [],
    progress: null,
    aiAudit: null,
    agentFindings: [],
    updatedAt: null,
});

const runningScan = (): ScanResult => ({ ...completedWith(0), status: ScanStatus.Running });

/** Lets a pending load settle and the callout re-render (and announce). */
const settle = async (view: ScanIssueCount): Promise<void> => {
    await new Promise((resolve) => setTimeout(resolve, 0));
    await view.updateComplete;
    await view.updateComplete;
};

/** Mounts the callout for an already stored scan and settles its initial load. */
const mount = async (scanUri: string = ''): Promise<ScanIssueCount> => {
    const view = document.createElement('mindfula11y-scan-issue-count');
    view.scanId = 'scan-1';
    view.scanUri = scanUri;
    document.body.append(view);
    await view.updateComplete;
    // The load resolves in a microtask after the first update; yield a
    // macrotask so the settled re-render and its announcement have happened.
    await new Promise((resolve) => setTimeout(resolve, 0));
    await view.updateComplete;
    return view;
};

/** Mounts the callout on a failed load, then clicks Retry from the keyboard focus. */
const retryFromFailedLoad = async (scanUri: string = ''): Promise<ScanIssueCount> => {
    loadScanMock.mockRejectedValueOnce(new RequestError('Load failed', '', 500));
    const view = await mount(scanUri);
    const retry = view.renderRoot.querySelector<HTMLButtonElement>('button[data-action="retry"]');
    expect(retry).not.toBeNull();
    retry?.focus();
    retry?.click();
    return view;
};

const announcement = (view: ScanIssueCount): string =>
    view.renderRoot.querySelector('[role="status"]')?.textContent?.trim() ?? '';

describe('ScanIssueCount', () => {
    beforeEach(() => {
        document.body.replaceChildren();
        createScanMock.mockReset();
        loadScanMock.mockReset();
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    it('surfaces a running scan whose polling gave up, announced once, and recovers through Retry', async () => {
        // Polling stops after repeated failures (or at once on a 401/403):
        // the row must not keep claiming "Scan running" with a spinner, and
        // the editor needs a way back once the cause is gone (re-login).
        vi.useFakeTimers();
        const announce = vi.spyOn(LiveAnnouncer.prototype, 'announce');
        loadScanMock
            .mockResolvedValueOnce({ ...completedWith(0), status: ScanStatus.Running })
            .mockRejectedValue(new RequestError('Scanner busy', '', 429));
        const view = document.createElement('mindfula11y-scan-issue-count');
        view.scanId = 'scan-1';
        document.body.append(view);
        await vi.advanceTimersByTimeAsync(0);
        await view.updateComplete;
        expect(view.renderRoot.textContent).toContain('mindfula11y.scan.status.running');

        await vi.advanceTimersByTimeAsync(600_000);
        await view.updateComplete;

        expect(loadScanMock).toHaveBeenCalledTimes(6); // the first load, then five failed polls
        const row = view.renderRoot.querySelector('mindfula11y-notice');
        expect(row?.getAttribute('state')).toBe('danger');
        expect(row?.textContent).toContain('Scanner busy');
        expect(view.renderRoot.querySelector('typo3-backend-spinner')).toBeNull();
        expect(announce.mock.calls.filter(([text]) => text === 'Scanner busy')).toHaveLength(1);

        loadScanMock.mockReset();
        loadScanMock.mockResolvedValue(completedWith(3));
        const retry = view.renderRoot.querySelector<HTMLButtonElement>('button[data-action="retry"]');
        expect(retry?.textContent).toContain('mindfula11y.scan.retry');
        retry?.click();
        await vi.advanceTimersByTimeAsync(0);
        await view.updateComplete;

        expect(loadScanMock).toHaveBeenCalledTimes(1);
        expect(view.renderRoot.querySelector('mindfula11y-notice')?.getAttribute('count')).toBe('3');
        expect(view.renderRoot.querySelector('button[data-action="retry"]')).toBeNull();
    });

    it('returns focus to Retry when the retried load fails again', async () => {
        loadScanMock.mockRejectedValue(new RequestError('Load failed', '', 500));
        const view = await mount();
        const retry = view.renderRoot.querySelector<HTMLButtonElement>('button[data-action="retry"]');
        expect(retry).not.toBeNull();

        retry?.focus();
        retry?.click();
        await new Promise((resolve) => setTimeout(resolve, 0));
        await view.updateComplete;
        await view.updateComplete;

        expect(loadScanMock).toHaveBeenCalledTimes(2);
        const again = view.renderRoot.querySelector('button[data-action="retry"]');
        expect(again).not.toBeNull();
        expect((view.renderRoot as ShadowRoot).activeElement).toBe(again);
    });

    it('keeps a running scan as its last known status, not an error, while a failed poll is still retried', async () => {
        // The controller retries a transient failure on its own: no reason to
        // flash the error and its Retry, nor to announce it. The status stays,
        // without the spinner, which would claim live progress.
        vi.useFakeTimers();
        const announce = vi.spyOn(LiveAnnouncer.prototype, 'announce');
        loadScanMock
            .mockResolvedValueOnce(runningScan())
            .mockRejectedValueOnce(new TypeError('Failed to fetch'))
            .mockResolvedValue(runningScan());
        const view = document.createElement('mindfula11y-scan-issue-count');
        view.scanId = 'scan-1';
        document.body.append(view);
        await vi.advanceTimersByTimeAsync(0);
        await view.updateComplete;

        await vi.advanceTimersByTimeAsync(5000); // the poll fails; a retry follows after 10 s
        await view.updateComplete;

        const row = view.renderRoot.querySelector('mindfula11y-notice');
        expect(row?.getAttribute('state')).toBe('info');
        expect(row?.textContent).toContain('mindfula11y.scan.status.running');
        expect(row?.textContent).toContain('mindfula11y.scan.status.notRefreshed');
        expect(view.renderRoot.querySelector('typo3-backend-spinner')).toBeNull();
        expect(view.renderRoot.querySelector('button[data-action="retry"]')).toBeNull();

        await vi.advanceTimersByTimeAsync(10_000); // the retry succeeds
        await view.updateComplete;

        expect(loadScanMock).toHaveBeenCalledTimes(3);
        expect(view.renderRoot.querySelector('typo3-backend-spinner')).not.toBeNull();
        expect(view.renderRoot.textContent).not.toContain('mindfula11y.scan.status.notRefreshed');
        expect(announce.mock.calls.some(([text]) => text.includes('mindfula11y.scan.error.loading'))).toBe(false);
    });

    it('shows a failed first load at once, even while the controller retries it', async () => {
        // A freshly created scan is known to be pending, so its failed first
        // load is retried — but there is no status to keep showing meanwhile.
        createScanMock.mockResolvedValue({ scanId: 'created-1', status: ScanStatus.Pending });
        loadScanMock.mockRejectedValue(new TypeError('Failed to fetch'));
        const view = document.createElement('mindfula11y-scan-issue-count');
        view.createScanDemand = demand;
        view.autoCreateScan = true;
        document.body.append(view);
        await settle(view);

        expect(loadScanMock).toHaveBeenCalledTimes(1);
        const row = view.renderRoot.querySelector('mindfula11y-notice');
        expect(row?.getAttribute('state')).toBe('danger');
        expect(row?.textContent).toContain('mindfula11y.scan.error.loading');
        expect(view.renderRoot.querySelector('button[data-action="retry"]')).not.toBeNull();
    });

    it('moves focus to the details link once a Retry succeeded', async () => {
        // The Retry button leaves with the error: focus must not stay on <body>.
        loadScanMock.mockResolvedValue(completedWith(3));
        const view = await retryFromFailedLoad('/typo3/module/mindfula11y');
        await settle(view);

        const link = view.renderRoot.querySelector('a[href]');
        expect(link).not.toBeNull();
        expect((view.renderRoot as ShadowRoot).activeElement).toBe(link);
    });

    it('focuses the callout itself once a Retry succeeded and there is no link to move to', async () => {
        loadScanMock.mockResolvedValue(completedWith(3));
        const view = await retryFromFailedLoad();
        await settle(view);

        const row = view.renderRoot.querySelector<HTMLElement>('mindfula11y-notice');
        expect(row?.getAttribute('count')).toBe('3');
        expect((view.renderRoot as ShadowRoot).activeElement).toBe(row);
        expect(row?.getAttribute('tabindex')).toBe('-1');

        // Focusable for this moment only — once left, it is no Tab stop.
        const elsewhere = document.createElement('button');
        document.body.append(elsewhere);
        elsewhere.focus();
        expect(row?.hasAttribute('tabindex')).toBe(false);
    });

    it('leaves focus where the editor moved it while a Retry was loading', async () => {
        let finishLoad: (result: ScanResult) => void = () => {};
        loadScanMock.mockReturnValue(
            new Promise<ScanResult>((resolve) => {
                finishLoad = resolve;
            }),
        );
        const view = await retryFromFailedLoad('/typo3/module/mindfula11y');
        const elsewhere = document.createElement('button');
        document.body.append(elsewhere);
        elsewhere.focus();

        finishLoad(completedWith(3));
        await settle(view);

        expect(view.renderRoot.querySelector('mindfula11y-notice')?.getAttribute('count')).toBe('3');
        expect(document.activeElement).toBe(elsewhere);
    });

    it('announces the issue total, which the visible row shows only as a badge', async () => {
        loadScanMock.mockResolvedValue(completedWith(12));

        const view = await mount();

        // The row splits label and count — the label alone says nothing about
        // how many issues there are.
        const row = view.renderRoot.querySelector('mindfula11y-notice');
        expect(row?.getAttribute('count')).toBe('12');
        expect(row?.textContent).toContain('mindfula11y.scan.issuesFound');
        // ...so the status message has to carry the number itself.
        expect(announcement(view)).toBe('mindfula11y.scan.announce.issuesFound: 12');
    });

    it('distinguishes two scans that differ only in issue count', async () => {
        // `announceIfChanged` suppresses a repeat of the previous announcement,
        // so two totals must not produce the same string — otherwise a later
        // scan of the same page is silently swallowed. Asserted on the strings
        // themselves rather than by driving the five-second poll.
        loadScanMock.mockResolvedValue(completedWith(12));
        const first = announcement(await mount());
        document.body.replaceChildren();

        loadScanMock.mockResolvedValue(completedWith(30));
        const second = announcement(await mount());

        expect(first).not.toBe(second);
        expect(second).toBe('mindfula11y.scan.announce.issuesFound: 30');
    });

    it('announces a clean scan without a count', async () => {
        loadScanMock.mockResolvedValue(completedWith(0));

        const view = await mount();

        expect(view.renderRoot.querySelector('mindfula11y-notice')?.hasAttribute('count')).toBe(false);
        expect(announcement(view)).toBe('mindfula11y.scan.noIssues');
    });

    it('reports an auto-created scan whose page failed to load as danger, never as "no issues"', async () => {
        loadScanMock.mockResolvedValue({
            ...completedWith(0),
            progress: { pagesDiscovered: 1, pagesScanned: 0, pagesFailed: 1 },
        });

        const view = await mount();

        const row = view.renderRoot.querySelector('mindfula11y-notice');
        expect(row?.getAttribute('state')).toBe('danger');
        expect(row?.textContent).toContain('mindfula11y.scan.noPagesScanned');
        expect(announcement(view)).toBe('mindfula11y.scan.noPagesScanned');
    });

    it('warns about the pages a completed scan could not load, visibly and in the announcement', async () => {
        loadScanMock.mockResolvedValue({
            ...completedWith(5),
            progress: { pagesDiscovered: 4, pagesScanned: 3, pagesFailed: 1 },
        });

        const view = await mount();

        const row = view.renderRoot.querySelector('mindfula11y-notice');
        expect(row?.getAttribute('state')).toBe('warning');
        expect(row?.getAttribute('count')).toBe('5');
        expect(row?.textContent).toContain('mindfula11y.scan.pagesFailed: 1, 4');
        expect(announcement(view)).toBe('mindfula11y.scan.announce.issuesFound: 5 mindfula11y.scan.pagesFailed: 1, 4');
    });

    it('hides an empty host through the --empty custom state, not the hidden attribute', async () => {
        // No scan id and no demand: there is nothing to show.
        const view = document.createElement('mindfula11y-scan-issue-count');
        document.body.append(view);
        await view.updateComplete;

        expect(statesByHost.get(view)?.has('--empty')).toBe(true);
        expect(view.hasAttribute('hidden')).toBe(false);
        expect(loadScanMock).not.toHaveBeenCalled();
    });

    it('never touches an integrator-set hidden attribute', async () => {
        loadScanMock.mockResolvedValue(completedWith(3));
        const view = document.createElement('mindfula11y-scan-issue-count');
        // The embedding markup owns `hidden`; the component previously
        // clobbered it on the first update with a result.
        view.setAttribute('hidden', '');
        view.scanId = 'scan-1';
        document.body.append(view);
        await view.updateComplete;
        await new Promise((resolve) => setTimeout(resolve, 0));
        await view.updateComplete;

        expect(view.hasAttribute('hidden')).toBe(true);
        expect(statesByHost.get(view)?.has('--empty')).toBe(false);
    });
});
