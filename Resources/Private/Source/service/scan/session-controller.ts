/*
 * Mindful A11y extension for TYPO3 integrating accessibility tools into the backend.
 * Copyright (C) 2026  Mindful Markup, Felix Spittel
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 */

import type { ReactiveController, ReactiveControllerHost } from 'lit';
import type { ScanApi } from './api.js';
import type { CreateScanDemand, ScanResult, ScanStatus } from './types.js';
import { isScanInProgress } from './types.js';

const POLL_DELAY_MS = 5000;

/** Coarse lifecycle of the scan session, mirrored into the host's render. */
export type ScanSessionState = 'initial' | 'loading' | 'ready' | 'error';

/** Wiring the host supplies so the controller can read attributes and react to transitions. */
export interface ScanSessionOptions {
    /** The async scan endpoints the controller drives (in production: a {@link ScanApi}). */
    service: Pick<ScanApi, 'createScan' | 'loadScan' | 'cancelScan'>;
    /** Attribute-provided scan id (`''` = none). */
    scanId: () => string;
    /** Auto-create demand; `null` disables auto-create (a manual trigger still calls `createScan`). */
    demand: () => CreateScanDemand | null;
    pageUrlFilter: () => string[];
    /** When it returns true AND the loaded scan's `mode === 'crawl'`, also load the unfiltered result. */
    withCrawlResult?: () => boolean;
    /**
     * Called after a status settles so the host can dispatch events/announce.
     * `previous` is `null` for a freshly created scan (the "started" transition)
     * and the prior in-progress status for a terminal transition.
     */
    onTransition?: (previous: ScanStatus | null, result: ScanResult) => void;
}

/**
 * Reactive controller owning one page's scan-session lifecycle: loading and
 * polling a scan, forgetting a 404'd id, auto-creating when demanded, and
 * signalling terminal transitions to the host. Presentation (tabs, panels,
 * announcements) stays in the host component — this controller only drives the
 * data.
 *
 * "Ensure a scan exists" (imperative, at most once per demand) is deliberately
 * separate from "load the current scan" (idempotent): {@link reload} never
 * creates. Every service call runs under an internal AbortController renewed
 * per operation, so {@link hostDisconnected} cancels in-flight work and no
 * disconnected request can mutate state.
 */
export class ScanSessionController implements ReactiveController {
    private _state: ScanSessionState = 'initial';
    private _error: unknown = null;
    private _result: ScanResult | null = null;
    private _crawlResult: ScanResult | null = null;

    /** The attribute scan id suppressed after a user-triggered create (old `invalidScanId`). */
    private dismissedAttributeScanId: string = '';
    /** The id of a scan this controller created; takes precedence over the attribute id. */
    private createdScanId: string = '';
    private lastStatus: ScanStatus | '' = '';
    private autoCreateAttempted: boolean = false;
    /** Marks the next settled load as the "started" transition of a fresh create. */
    private justCreated: boolean = false;

    private connected: boolean = false;
    private initialized: boolean = false;
    private pollTimer: number | undefined;
    private abortController: AbortController | null = null;

    constructor(
        private readonly host: ReactiveControllerHost & EventTarget,
        private readonly options: ScanSessionOptions,
    ) {
        host.addController(this);
    }

    get state(): ScanSessionState {
        return this._state;
    }

    get error(): unknown {
        return this._error;
    }

    /** Last successful load; stays mounted while a re-poll runs. */
    get result(): ScanResult | null {
        return this._result;
    }

    get crawlResult(): ScanResult | null {
        return this._crawlResult;
    }

    /** Attribute id unless it was created away from or dismissed by a user-triggered create. */
    effectiveScanId(): string {
        if (this.createdScanId !== '') {
            return this.createdScanId;
        }
        const scanId = this.options.scanId();
        return scanId !== this.dismissedAttributeScanId ? scanId : '';
    }

    hostConnected(): void {
        this.connected = true;
        if (!this.initialized) {
            this.initialized = true;
            this.start();
            return;
        }
        // Restart a load that disconnecting aborted mid-flight (the state can
        // only still be 'loading' then): without this the host stays on its
        // loading branch forever after being reinserted. start() falls back to
        // 'initial' when an aborted auto-create already consumed its single
        // attempt, so this cannot create a duplicate scan.
        if (this._state === 'loading') {
            this.start();
            return;
        }
        // Resume polling when reinserted mid-scan: disconnecting cleared the
        // only timer and nothing else re-triggers a load after reconnect, so
        // resume polling here.
        if (this.lastStatus !== '' && isScanInProgress(this.lastStatus)) {
            this.schedulePoll();
        }
    }

    hostDisconnected(): void {
        this.connected = false;
        this.clearPoll();
        this.abortController?.abort();
        this.abortController = null;
    }

    /** Idempotent load of the current scan — never creates. */
    async reload(): Promise<void> {
        const scanId = this.effectiveScanId();
        if (scanId === '') {
            this._result = null;
            this._crawlResult = null;
            this.lastStatus = '';
            this.setState('initial');
            return;
        }

        const signal = this.beginOperation();
        this.setState('loading');
        try {
            const filtered = await this.options.service.loadScan(scanId, this.options.pageUrlFilter(), signal);
            if (signal.aborted) {
                return;
            }
            if (filtered === null) {
                this.forgetScan(scanId);
                return;
            }
            let crawl: ScanResult | null = null;
            // Sequential and keyed off the first response: the unfiltered fetch
            // only makes sense once we know the stored scan actually is a crawl,
            // so an ordinary page scan never issues the second request.
            if (this.options.withCrawlResult?.() === true && filtered.mode === 'crawl') {
                const unfiltered = await this.options.service.loadScan(scanId, [], signal);
                if (signal.aborted) {
                    return;
                }
                crawl = unfiltered;
            }
            this._result = filtered;
            this._crawlResult = crawl !== null && crawl.mode === 'crawl' ? crawl : null;
            this.setState('ready');
            this.commitStatus(filtered);
        } catch (error) {
            if (signal.aborted) {
                return;
            }
            this._error = error;
            this.setState('error');
            // The error surfaces through the host's error branch, but a
            // transient poll failure must not stop polling permanently:
            // commitStatus (the only other place that re-arms the timer) runs
            // on the load's success path only. lastStatus is left untouched on
            // failure, so re-arm here while the scan is still believed to be in
            // progress — the next poll recovers.
            if (this.lastStatus !== '' && isScanInProgress(this.lastStatus)) {
                this.schedulePoll();
            }
        }
    }

    /**
     * Explicit create (manual trigger). Suppresses the attribute id, then loads
     * the new scan. Rethrows a create failure so the host can surface it; the
     * AI audit rides alongside as an editor choice (never for auto-create).
     */
    async createScan(demand: CreateScanDemand, aiAudit: boolean = false): Promise<void> {
        const signal = this.beginOperation();
        let created: { scanId: string; status: ScanStatus };
        try {
            created = await this.options.service.createScan(demand, aiAudit, signal);
        } catch (error) {
            if (signal.aborted) {
                return;
            }
            throw error;
        }
        if (signal.aborted) {
            return;
        }
        this.dismissedAttributeScanId = this.options.scanId();
        this.adoptCreatedScan(created);
        void this.reload();
    }

    /** Cancels the running scan, then reloads to reflect the final state. Rethrows a real failure. */
    async cancelScan(): Promise<void> {
        const scanId = this.effectiveScanId();
        if (scanId === '') {
            return;
        }
        const signal = this.beginOperation();
        let failure: unknown = null;
        try {
            await this.options.service.cancelScan(scanId, signal);
        } catch (error) {
            if (signal.aborted) {
                return;
            }
            failure = error;
        }
        // Reflect the final state regardless of a 409/terminal race — but only
        // if the host is still around to receive the update.
        if (this.connected) {
            void this.reload();
        }
        if (failure !== null) {
            throw failure;
        }
    }

    /** Initial entry: load an existing scan, otherwise attempt the auto-create. */
    private start(): void {
        if (!this.connected) {
            return;
        }
        if (this.effectiveScanId() !== '') {
            void this.reload();
        } else {
            void this.ensureScan();
        }
    }

    /** Auto-creates the initial scan at most once — never with the AI audit (cost). */
    private async ensureScan(): Promise<void> {
        const demand = this.options.demand();
        if (demand === null || this.autoCreateAttempted) {
            this._result = null;
            this._crawlResult = null;
            this.setState('initial');
            return;
        }
        this.autoCreateAttempted = true;
        const signal = this.beginOperation();
        this.setState('loading');
        let created: { scanId: string; status: ScanStatus };
        try {
            created = await this.options.service.createScan(demand, false, signal);
        } catch (error) {
            if (signal.aborted) {
                return;
            }
            this._error = error;
            this.setState('error');
            return;
        }
        if (signal.aborted) {
            return;
        }
        this.adoptCreatedScan(created);
        void this.reload();
    }

    /** Shared state update after any successful create (auto or explicit). */
    private adoptCreatedScan(created: { scanId: string; status: ScanStatus }): void {
        this.createdScanId = created.scanId;
        this.lastStatus = created.status;
        this.justCreated = true;
        this._result = null;
        this._crawlResult = null;
        this.host.requestUpdate();
    }

    /**
     * Scan gone on the API side — forget the id (auto-create, when enabled,
     * recreates it) and re-run so the now-effective id (or the auto-create) is
     * picked up. The id changes toward '' and auto-create is guarded to once,
     * so this cannot loop.
     */
    private forgetScan(scanId: string): void {
        if (this.createdScanId === scanId) {
            this.createdScanId = '';
        } else {
            this.dismissedAttributeScanId = scanId;
        }
        this._result = null;
        this._crawlResult = null;
        this.lastStatus = '';
        this.setState('initial');
        this.start();
    }

    /** Detects the transition, re-arms the poll while in progress, and notifies the host. */
    private commitStatus(result: ScanResult): void {
        const previous = this.lastStatus;
        if (isScanInProgress(result.status)) {
            this.schedulePoll();
        }
        if (this.justCreated) {
            this.justCreated = false;
            this.lastStatus = result.status;
            this.options.onTransition?.(null, result);
            return;
        }
        const wasInProgress = previous !== '' && isScanInProgress(previous);
        this.lastStatus = result.status;
        if (wasInProgress && !isScanInProgress(result.status)) {
            this.options.onTransition?.(previous, result);
        }
    }

    private schedulePoll(): void {
        this.clearPoll();
        this.pollTimer = window.setTimeout(() => {
            this.pollTimer = undefined;
            void this.reload();
        }, POLL_DELAY_MS);
    }

    private clearPoll(): void {
        if (this.pollTimer !== undefined) {
            window.clearTimeout(this.pollTimer);
            this.pollTimer = undefined;
        }
    }

    private beginOperation(): AbortSignal {
        this.abortController?.abort();
        this.abortController = new AbortController();
        return this.abortController.signal;
    }

    private setState(state: ScanSessionState): void {
        this._state = state;
        if (state !== 'error') {
            this._error = null;
        }
        this.host.requestUpdate();
    }
}
