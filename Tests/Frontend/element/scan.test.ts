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

    it('creates a scan when triggered while idle', async () => {
        createScanMock.mockResolvedValue({ scanId: 'new-scan', status: ScanStatus.Pending });
        loadScanMock.mockResolvedValue(makeResult(ScanStatus.Completed));
        const view = await mount('');

        button(view, 'trigger').click();
        await settle(view);

        expect(createScanMock).toHaveBeenCalledTimes(1);
        expect(createScanMock).toHaveBeenCalledWith(demand, false, expect.anything());
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
