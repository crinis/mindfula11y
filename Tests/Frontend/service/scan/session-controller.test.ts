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

import type { ReactiveControllerHost } from 'lit';
import { afterEach, describe, expect, it, type Mock, vi } from 'vitest';
import type { ScanApi } from '../../../../Resources/Private/Source/service/scan/api.js';
import {
    ScanSessionController,
    type ScanSessionOptions,
} from '../../../../Resources/Private/Source/service/scan/session-controller.js';
import type { CreateScanDemand, ScanResult } from '../../../../Resources/Private/Source/service/scan/types.js';
import { ScanStatus } from '../../../../Resources/Private/Source/service/scan/types.js';

const IN_PROGRESS = new Set<ScanStatus>([ScanStatus.Pending, ScanStatus.Running, ScanStatus.Analyzing]);

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

const makeResult = (status: ScanStatus, over: Partial<ScanResult> = {}): ScanResult => ({
    status,
    violations: [],
    totalIssueCount: 0,
    mode: null,
    targets: [],
    progress: null,
    aiAudit: null,
    agentFindings: [],
    updatedAt: null,
    ...over,
});

class FakeHost extends EventTarget implements ReactiveControllerHost {
    addController: Mock = vi.fn();
    removeController: Mock = vi.fn();
    requestUpdate: Mock = vi.fn();
    readonly updateComplete: Promise<boolean> = Promise.resolve(true);
}

interface FakeService {
    createScan: Mock;
    loadScan: Mock;
    cancelScan: Mock;
    isScanInProgress: (status: ScanStatus | '') => boolean;
}

const createFakeService = (): FakeService => ({
    createScan: vi.fn(),
    loadScan: vi.fn(),
    cancelScan: vi.fn(),
    isScanInProgress: (status: ScanStatus | ''): boolean => status !== '' && IN_PROGRESS.has(status),
});

const flush = async (): Promise<void> => {
    for (let index = 0; index < 12; index += 1) {
        await Promise.resolve();
    }
};

const build = (
    service: FakeService,
    options: Partial<Omit<ScanSessionOptions, 'service'>> = {},
): { host: FakeHost; controller: ScanSessionController; onTransition: Mock } => {
    const host = new FakeHost();
    const onTransition = vi.fn();
    const controller = new ScanSessionController(host, {
        service: service as unknown as ScanApi,
        scanId: options.scanId ?? ((): string => ''),
        demand: options.demand ?? ((): CreateScanDemand | null => null),
        pageUrlFilter: options.pageUrlFilter ?? ((): string[] => []),
        ...(options.withCrawlResult !== undefined ? { withCrawlResult: options.withCrawlResult } : {}),
        onTransition: options.onTransition ?? onTransition,
    });
    return { host, controller, onTransition };
};

describe('ScanSessionController', () => {
    afterEach(() => {
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    it('registers itself as a reactive controller on construction', () => {
        const service = createFakeService();
        const { host, controller } = build(service);
        expect(host.addController).toHaveBeenCalledWith(controller);
    });

    it('loads the attribute scan on connect without ever creating', async () => {
        const service = createFakeService();
        service.loadScan.mockResolvedValue(makeResult(ScanStatus.Completed, { totalIssueCount: 3 }));
        const { host, controller } = build(service, { scanId: () => 'attr', demand: () => demand });

        controller.hostConnected();
        await flush();

        expect(service.createScan).not.toHaveBeenCalled();
        expect(service.loadScan).toHaveBeenCalledTimes(1);
        expect(service.loadScan.mock.calls[0]?.[0]).toBe('attr');
        expect(controller.result?.totalIssueCount).toBe(3);
        expect(controller.state).toBe('ready');
        expect(host.requestUpdate).toHaveBeenCalled();
    });

    it('auto-creates exactly once when no id exists and a demand is present', async () => {
        const service = createFakeService();
        service.createScan.mockResolvedValue({ scanId: 'created-1', status: ScanStatus.Pending });
        service.loadScan.mockResolvedValue(makeResult(ScanStatus.Pending));
        const { controller } = build(service, { scanId: () => '', demand: () => demand });

        controller.hostConnected();
        await flush();

        expect(service.createScan).toHaveBeenCalledTimes(1);
        expect(service.createScan.mock.calls[0]?.[1]).toBe(false); // auto-create never enables the AI audit
        expect(service.loadScan).toHaveBeenCalledWith('created-1', [], expect.anything());
        expect(controller.effectiveScanId()).toBe('created-1');
        controller.hostDisconnected(); // clear the pending-status poll timer
    });

    it('does not auto-create when the demand is null', async () => {
        const service = createFakeService();
        const { controller } = build(service, { scanId: () => '', demand: () => null });

        controller.hostConnected();
        await flush();

        expect(service.createScan).not.toHaveBeenCalled();
        expect(service.loadScan).not.toHaveBeenCalled();
        expect(controller.state).toBe('initial');
    });

    it('announces a fresh create through onTransition with a null previous status', async () => {
        const service = createFakeService();
        service.createScan.mockResolvedValue({ scanId: 'created-1', status: ScanStatus.Pending });
        service.loadScan.mockResolvedValue(makeResult(ScanStatus.Pending));
        const onTransition = vi.fn();
        const { controller } = build(service, { scanId: () => '', demand: () => demand, onTransition });

        controller.hostConnected();
        await flush();

        expect(onTransition).toHaveBeenCalledTimes(1);
        expect(onTransition.mock.calls[0]?.[0]).toBeNull();
        controller.hostDisconnected(); // clear the pending-status poll timer
    });

    it('forgets a 404 id and recreates at most once', async () => {
        const service = createFakeService();
        service.loadScan.mockResolvedValue(null); // every scan is gone on the API side
        service.createScan.mockResolvedValue({ scanId: 'created-1', status: ScanStatus.Pending });
        const { controller } = build(service, { scanId: () => 'attr', demand: () => demand });

        controller.hostConnected();
        await flush();

        expect(service.createScan).toHaveBeenCalledTimes(1);
        expect(controller.effectiveScanId()).toBe('');
    });

    it('re-arms the poll after a transient load failure', async () => {
        vi.useFakeTimers();
        const service = createFakeService();
        service.loadScan
            .mockResolvedValueOnce(makeResult(ScanStatus.Running))
            .mockRejectedValueOnce(new Error('network blip'))
            .mockResolvedValueOnce(makeResult(ScanStatus.Completed));
        const { controller } = build(service, { scanId: () => 'attr' });

        controller.hostConnected();
        await flush();
        expect(service.loadScan).toHaveBeenCalledTimes(1);

        await vi.advanceTimersByTimeAsync(5000);
        expect(service.loadScan).toHaveBeenCalledTimes(2);
        expect(controller.state).toBe('error');

        await vi.advanceTimersByTimeAsync(5000);
        expect(service.loadScan).toHaveBeenCalledTimes(3);
        expect(controller.state).toBe('ready');
    });

    it('stops polling and fires onTransition once on a terminal status', async () => {
        vi.useFakeTimers();
        const service = createFakeService();
        service.loadScan
            .mockResolvedValueOnce(makeResult(ScanStatus.Running))
            .mockResolvedValueOnce(makeResult(ScanStatus.Completed, { totalIssueCount: 2 }));
        const onTransition = vi.fn();
        const { controller } = build(service, { scanId: () => 'attr', onTransition });

        controller.hostConnected();
        await flush();
        expect(onTransition).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(5000);
        expect(onTransition).toHaveBeenCalledTimes(1);
        expect(onTransition.mock.calls[0]?.[0]).toBe(ScanStatus.Running);
        expect(onTransition.mock.calls[0]?.[1]?.status).toBe(ScanStatus.Completed);

        await vi.advanceTimersByTimeAsync(20000);
        expect(service.loadScan).toHaveBeenCalledTimes(2); // no further polls after terminal
    });

    it('aborts in-flight requests and stops polling on disconnect', async () => {
        vi.useFakeTimers();
        const service = createFakeService();
        service.loadScan.mockResolvedValue(makeResult(ScanStatus.Running));
        const { controller } = build(service, { scanId: () => 'attr' });

        controller.hostConnected();
        await flush();
        const signal = service.loadScan.mock.calls[0]?.[2] as AbortSignal;
        expect(signal.aborted).toBe(false);

        controller.hostDisconnected();
        expect(signal.aborted).toBe(true);

        await vi.advanceTimersByTimeAsync(20000);
        expect(service.loadScan).toHaveBeenCalledTimes(1); // poll timer was cleared
    });

    it('does not mutate state from a request that resolves after disconnect', async () => {
        const service = createFakeService();
        let resolveLoad: (value: ScanResult | null) => void = () => {};
        service.loadScan.mockReturnValue(
            new Promise<ScanResult | null>((resolve) => {
                resolveLoad = resolve;
            }),
        );
        const { host, controller } = build(service, { scanId: () => 'attr' });

        controller.hostConnected();
        await flush();
        controller.hostDisconnected();
        const updatesBefore = host.requestUpdate.mock.calls.length;

        resolveLoad(makeResult(ScanStatus.Completed));
        await flush();

        expect(controller.result).toBeNull();
        expect(controller.state).not.toBe('ready');
        expect(host.requestUpdate.mock.calls.length).toBe(updatesBefore);
    });

    it('resumes polling on reconnect while a scan is in progress', async () => {
        vi.useFakeTimers();
        const service = createFakeService();
        service.loadScan.mockResolvedValue(makeResult(ScanStatus.Running));
        const { controller } = build(service, { scanId: () => 'attr' });

        controller.hostConnected();
        await flush();
        expect(service.loadScan).toHaveBeenCalledTimes(1);

        controller.hostDisconnected();
        controller.hostConnected(); // reconnect mid-scan

        await vi.advanceTimersByTimeAsync(5000);
        expect(service.loadScan).toHaveBeenCalledTimes(2); // resumed by the poll, not an immediate reload
    });

    it('does not resume polling on reconnect once the scan is terminal', async () => {
        vi.useFakeTimers();
        const service = createFakeService();
        service.loadScan.mockResolvedValue(makeResult(ScanStatus.Completed));
        const { controller } = build(service, { scanId: () => 'attr' });

        controller.hostConnected();
        await flush();
        controller.hostDisconnected();
        controller.hostConnected();

        await vi.advanceTimersByTimeAsync(20000);
        expect(service.loadScan).toHaveBeenCalledTimes(1);
    });

    it('restarts an initial load that a disconnect aborted mid-flight', async () => {
        const service = createFakeService();
        service.loadScan
            .mockReturnValueOnce(new Promise<ScanResult | null>(() => {})) // aborted by the disconnect
            .mockResolvedValueOnce(makeResult(ScanStatus.Completed, { totalIssueCount: 4 }));
        const { controller } = build(service, { scanId: () => 'attr' });

        controller.hostConnected();
        await flush();
        controller.hostDisconnected(); // TYPO3 removes and reinserts module content, e.g. on tab switches
        expect(controller.state).toBe('loading');

        controller.hostConnected();
        await flush();

        expect(service.loadScan).toHaveBeenCalledTimes(2);
        expect(controller.state).toBe('ready');
        expect(controller.result?.totalIssueCount).toBe(4);
    });

    it('falls back to the initial state on reconnect after an aborted auto-create, without creating twice', async () => {
        const service = createFakeService();
        service.createScan.mockReturnValue(new Promise<never>(() => {})); // aborted by the disconnect
        const { controller } = build(service, { scanId: () => '', demand: () => demand });

        controller.hostConnected();
        await flush();
        controller.hostDisconnected();
        expect(controller.state).toBe('loading');

        controller.hostConnected();
        await flush();

        expect(service.createScan).toHaveBeenCalledTimes(1); // the single auto-create attempt is spent
        expect(controller.state).toBe('initial');
    });

    it('createScan suppresses the attribute id so a later 404 does not fall back to it', async () => {
        const service = createFakeService();
        service.createScan.mockResolvedValue({ scanId: 'created-1', status: ScanStatus.Pending });
        service.loadScan.mockResolvedValue(null); // the created scan immediately 404s
        const { controller } = build(service, { scanId: () => 'attr' });

        await controller.createScan(demand);
        await flush();

        expect(service.createScan).toHaveBeenCalledTimes(1);
        expect(service.loadScan.mock.calls[0]?.[0]).toBe('created-1');
        expect(controller.effectiveScanId()).toBe('');
    });

    it('passes the AI-audit flag through an explicit createScan', async () => {
        const service = createFakeService();
        service.createScan.mockResolvedValue({ scanId: 'created-1', status: ScanStatus.Pending });
        service.loadScan.mockResolvedValue(makeResult(ScanStatus.Pending));
        const { controller } = build(service, { scanId: () => 'attr' });

        await controller.createScan(demand, true);
        await flush();

        expect(service.createScan.mock.calls[0]?.[1]).toBe(true);
        controller.hostDisconnected(); // clear the pending-status poll timer
    });

    it('rethrows an explicit createScan failure for the host to surface', async () => {
        const service = createFakeService();
        service.createScan.mockRejectedValue(new Error('create failed'));
        const { controller } = build(service, { scanId: () => 'attr' });

        await expect(controller.createScan(demand)).rejects.toThrow('create failed');
        expect(service.loadScan).not.toHaveBeenCalled();
    });

    it('fetches the unfiltered crawl result only when opted in and the scan is a crawl', async () => {
        const service = createFakeService();
        service.loadScan.mockImplementation(async (_id: string, pageUrls: string[]) =>
            pageUrls.length > 0
                ? makeResult(ScanStatus.Completed, { mode: 'crawl' })
                : makeResult(ScanStatus.Completed, { mode: 'crawl', totalIssueCount: 9 }),
        );
        const { controller } = build(service, {
            scanId: () => 'attr',
            pageUrlFilter: () => ['https://example.test/page'],
            withCrawlResult: () => true,
        });

        controller.hostConnected();
        await flush();

        expect(service.loadScan).toHaveBeenCalledTimes(2);
        expect(service.loadScan.mock.calls[1]?.[1]).toEqual([]);
        expect(controller.crawlResult?.totalIssueCount).toBe(9);
    });

    it('does not fetch the unfiltered crawl result for an ordinary page scan', async () => {
        const service = createFakeService();
        service.loadScan.mockResolvedValue(makeResult(ScanStatus.Completed, { mode: 'default' }));
        const { controller } = build(service, {
            scanId: () => 'attr',
            pageUrlFilter: () => ['https://example.test/page'],
            withCrawlResult: () => true,
        });

        controller.hostConnected();
        await flush();

        expect(service.loadScan).toHaveBeenCalledTimes(1);
        expect(controller.crawlResult).toBeNull();
    });

    it('never fetches the unfiltered crawl result when the option is off', async () => {
        const service = createFakeService();
        service.loadScan.mockResolvedValue(makeResult(ScanStatus.Completed, { mode: 'crawl' }));
        const { controller } = build(service, {
            scanId: () => 'attr',
            withCrawlResult: () => false,
        });

        controller.hostConnected();
        await flush();

        expect(service.loadScan).toHaveBeenCalledTimes(1);
        expect(controller.crawlResult).toBeNull();
    });

    it('reloads to reflect the final state after a cancel', async () => {
        const service = createFakeService();
        service.loadScan.mockResolvedValue(makeResult(ScanStatus.Canceled));
        service.cancelScan.mockResolvedValue(ScanStatus.Canceled);
        const { controller } = build(service, { scanId: () => 'attr' });

        controller.hostConnected();
        await flush();
        await controller.cancelScan();
        await flush();

        expect(service.cancelScan).toHaveBeenCalledWith('attr', expect.anything());
        expect(service.loadScan).toHaveBeenCalled();
    });

    it('rethrows a cancel failure after triggering the reflecting reload', async () => {
        const service = createFakeService();
        service.loadScan.mockResolvedValue(makeResult(ScanStatus.Canceled));
        service.cancelScan.mockRejectedValue(new Error('cancel failed'));
        const { controller } = build(service, { scanId: () => 'attr' });

        controller.hostConnected();
        await flush();
        await expect(controller.cancelScan()).rejects.toThrow('cancel failed');
        await flush();

        expect(service.loadScan).toHaveBeenCalled();
    });
});
