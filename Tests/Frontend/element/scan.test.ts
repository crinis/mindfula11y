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

const { createScanMock, loadScanMock, cancelScanMock } = vi.hoisted(() => ({
    createScanMock: vi.fn(),
    loadScanMock: vi.fn(),
    cancelScanMock: vi.fn(),
}));

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
            cancelScan = cancelScanMock;
        },
    );
    return module;
});

import type { Scan } from '../../../Resources/Private/Source/element/scan/scan.js';
import '../../../Resources/Private/Source/element/scan/scan.js';
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

const makeResult = (status: ScanStatus): ScanResult => ({
    status,
    violations: [],
    totalIssueCount: 0,
    mode: null,
    targets: [],
    progress: null,
    aiAudit: null,
    agentFindings: [],
    updatedAt: null,
});

/** Lets the controller's awaited service calls settle and Lit re-render. */
const settle = async (view: Scan): Promise<void> => {
    await new Promise((resolve) => setTimeout(resolve, 0));
    await view.updateComplete;
};

const mount = async (scanId: string): Promise<Scan> => {
    const view = document.createElement('mindfula11y-scan');
    view.scanId = scanId;
    view.createScanDemand = demand;
    document.body.append(view);
    await settle(view);
    return view;
};

const button = (view: Scan, action: 'trigger' | 'cancel'): HTMLButtonElement => {
    const element = view.renderRoot.querySelector<HTMLButtonElement>(`button[data-action="${action}"]`);
    if (element === null) {
        throw new Error(`No ${action} button rendered`);
    }
    return element;
};

describe('Scan', () => {
    beforeEach(() => {
        createScanMock.mockReset();
        loadScanMock.mockReset();
        cancelScanMock.mockReset();
    });

    afterEach(() => {
        document.body.replaceChildren();
    });

    it('offers the AI review toggle only for the scan modes TSconfig lists', async () => {
        loadScanMock.mockResolvedValue(makeResult(ScanStatus.Completed));
        const view = await mount('scan');
        view.aiAuditAvailable = true;
        view.aiAuditScanModes = ['single_url'];
        await settle(view);
        expect(view.renderRoot.querySelector('#ai-toggle-scan')).not.toBeNull();

        // A page tree asks for a url_list scan, which is not listed.
        view.createScanDemand = { ...demand, pageLevels: 1 };
        await settle(view);
        expect(view.renderRoot.querySelector('#ai-toggle-scan')).toBeNull();

        view.aiAuditScanModes = ['single_url', 'url_list'];
        await settle(view);
        expect(view.renderRoot.querySelector('#ai-toggle-scan')).not.toBeNull();
    });

    it('never requests an AI review for a scan mode that is not listed, even when the review is the default', async () => {
        loadScanMock.mockResolvedValue(makeResult(ScanStatus.Completed));
        createScanMock.mockResolvedValue({ scanId: 'new-scan', status: ScanStatus.Pending });
        const view = await mount('scan');
        view.aiAuditAvailable = true;
        view.aiAuditDefault = true;
        view.aiAuditScanModes = ['single_url'];
        view.createScanDemand = { ...demand, pageLevels: 5 };
        await settle(view);

        button(view, 'trigger').click();
        await settle(view);

        expect(createScanMock).toHaveBeenCalledWith(
            expect.objectContaining({ pageLevels: 5 }),
            false,
            expect.anything(),
        );
    });

    it('offers the AI review toggle on the crawl tab only when crawls are listed', async () => {
        loadScanMock.mockResolvedValue(makeResult(ScanStatus.Completed));
        const view = await mount('scan');
        view.aiAuditAvailable = true;
        view.aiAuditScanModes = ['single_url', 'url_list'];
        view.crawlScanDemand = { ...demand, crawl: true };
        await settle(view);
        view.renderRoot.querySelector<HTMLElement>('[role="tab"]:nth-of-type(2)')?.click();
        await settle(view);
        expect(view.renderRoot.querySelector('#ai-toggle-crawl')).toBeNull();

        view.aiAuditScanModes = ['crawl'];
        await settle(view);
        expect(view.renderRoot.querySelector('#ai-toggle-crawl')).not.toBeNull();
    });

    it('ignores the trigger while a scan is running', async () => {
        loadScanMock.mockResolvedValue(makeResult(ScanStatus.Running));
        const view = await mount('running-scan');
        const trigger = button(view, 'trigger');
        expect(trigger.getAttribute('aria-disabled')).toBe('true');

        trigger.click();
        await settle(view);

        expect(createScanMock).not.toHaveBeenCalled();
    });

    it('creates only one scan when the trigger is clicked again before the new scan first loads', async () => {
        createScanMock.mockResolvedValue({ scanId: 'new-scan', status: ScanStatus.Pending });
        loadScanMock.mockReturnValue(new Promise<never>(() => {})); // the first getScan never answers
        const view = await mount('');

        button(view, 'trigger').click();
        await settle(view);
        const trigger = button(view, 'trigger');
        expect(trigger.getAttribute('aria-disabled')).toBe('true');
        trigger.click();
        await settle(view);

        expect(createScanMock).toHaveBeenCalledTimes(1);
    });

    it('ignores the trigger during the initial load of an existing scan', async () => {
        loadScanMock.mockReturnValue(new Promise<never>(() => {}));
        const view = await mount('stored-scan');

        const trigger = button(view, 'trigger');
        expect(trigger.getAttribute('aria-disabled')).toBe('true');
        trigger.click();
        await settle(view);

        expect(createScanMock).not.toHaveBeenCalled();
    });

    it('ignores the trigger while the automatic scan is being created', async () => {
        createScanMock.mockReturnValue(new Promise<never>(() => {})); // auto-create in flight
        const view = document.createElement('mindfula11y-scan');
        view.createScanDemand = demand;
        view.autoCreateScan = true;
        document.body.append(view);
        await settle(view);

        const trigger = button(view, 'trigger');
        expect(trigger.getAttribute('aria-disabled')).toBe('true');
        trigger.click();
        await settle(view);

        expect(createScanMock).toHaveBeenCalledTimes(1); // the auto-create alone
    });

    it('creates a scan when triggered while idle', async () => {
        createScanMock.mockResolvedValue({ scanId: 'new-scan', status: ScanStatus.Pending });
        loadScanMock.mockResolvedValue(makeResult(ScanStatus.Completed));
        const view = await mount('');

        button(view, 'trigger').click();
        await settle(view);

        expect(createScanMock).toHaveBeenCalledTimes(1);
        expect(createScanMock).toHaveBeenCalledWith(demand, false, expect.anything());
    });

    it('reports a completed scan whose every page failed as "no page could be scanned"', async () => {
        loadScanMock.mockResolvedValue({
            ...makeResult(ScanStatus.Completed),
            progress: { pagesDiscovered: 1, pagesScanned: 0, pagesFailed: 1 },
        });
        const view = await mount('scan');

        const notice = view.renderRoot.querySelector('mindfula11y-notice[state="danger"]');
        expect(notice?.textContent).toContain('mindfula11y.scan.noPagesScanned');
        expect(notice?.textContent).toContain('mindfula11y.scan.noPagesScanned.description');
        expect(view.renderRoot.textContent).not.toContain('mindfula11y.scan.noIssues');
    });

    it('announces a completed scan without any loaded page as such, not as "0 issues found"', async () => {
        createScanMock.mockResolvedValue({ scanId: 'new-scan', status: ScanStatus.Pending });
        loadScanMock.mockResolvedValue({
            ...makeResult(ScanStatus.Completed),
            progress: { pagesDiscovered: 1, pagesScanned: 0, pagesFailed: 1 },
        });
        const view = await mount('');

        button(view, 'trigger').click();
        for (let round = 0; round < 4; round += 1) {
            await settle(view);
        }

        // The announcer's region is the first status region, ahead of the panels'.
        expect(view.renderRoot.querySelector('[role="status"]')?.textContent?.trim()).toBe(
            'mindfula11y.scan.noPagesScanned',
        );
    });

    it('does not claim a changed scope when the scanner stored the selected URLs normalized', async () => {
        // A fresh scan of a language root: TYPO3's preview URLs end in a
        // slash, MindfulAPI stores the targets without it.
        loadScanMock.mockResolvedValue({
            ...makeResult(ScanStatus.Completed),
            mode: 'url_list',
            targets: ['https://example.test/de', 'https://example.test/de/about'],
        });
        const view = document.createElement('mindfula11y-scan');
        view.scanId = 'scan';
        view.createScanDemand = { ...demand, pageLevels: 1 };
        view.urlList = ['https://example.test/de/', 'https://example.test/de/about/'];
        document.body.append(view);
        await settle(view);

        expect(view.renderRoot.textContent).not.toContain('mindfula11y.scan.scopeExpanded');

        // A page added since the scan still counts as a changed scope.
        view.urlList = [...view.urlList, 'https://example.test/de/new/'];
        await settle(view);
        expect(view.renderRoot.textContent).toContain('mindfula11y.scan.scopeExpanded');
    });

    it('warns about failed pages of a completed scan in both tabs', async () => {
        loadScanMock.mockResolvedValue({
            ...makeResult(ScanStatus.Completed),
            mode: 'crawl',
            progress: { pagesDiscovered: 3, pagesScanned: 2, pagesFailed: 1 },
        });
        const view = document.createElement('mindfula11y-scan');
        view.scanId = 'scan';
        view.createScanDemand = demand;
        view.crawlScanDemand = { ...demand, crawl: true };
        document.body.append(view);
        await settle(view);

        const panels = [...view.renderRoot.querySelectorAll('[role="tabpanel"]')];
        expect(panels).toHaveLength(2);
        for (const panel of panels) {
            const notice = panel.querySelector('mindfula11y-notice[state="warning"]');
            expect(notice?.textContent).toContain('mindfula11y.scan.noIssuesOnScannedPages');
            expect(notice?.textContent).toContain('mindfula11y.scan.pagesFailed: 1, 3');
            expect(panel.querySelector('mindfula11y-notice[state="success"]')).toBeNull();
        }
    });

    it('swallows a 409 on cancel — the scan already reached a terminal state', async () => {
        loadScanMock.mockResolvedValueOnce(makeResult(ScanStatus.Running));
        loadScanMock.mockResolvedValue(makeResult(ScanStatus.Completed));
        cancelScanMock.mockRejectedValue(new RequestError('Conflict', 'Already finished', 409));
        const view = await mount('running-scan');

        button(view, 'cancel').click();
        await settle(view);

        expect(cancelScanMock).toHaveBeenCalledTimes(1);
        expect(view.renderRoot.querySelector('mindfula11y-notice[state="danger"]')).toBeNull();
        expect(view.renderRoot.textContent).not.toContain('Conflict');
    });

    it('surfaces a non-409 cancel failure', async () => {
        loadScanMock.mockResolvedValue(makeResult(ScanStatus.Running));
        cancelScanMock.mockRejectedValue(new RequestError('Cancel failed', 'Server said no', 500));
        const view = await mount('running-scan');

        button(view, 'cancel').click();
        await settle(view);

        expect(view.renderRoot.querySelector('mindfula11y-notice[state="danger"]')?.textContent).toContain(
            'Cancel failed',
        );
    });
});
