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

import { Task, TaskStatus } from '@lit/task';
import { lll } from '@typo3/core/lit-helper.js';
import type { CSSResult, TemplateResult } from 'lit';
import { html, LitElement, nothing } from 'lit';
import { customElement, property, state } from 'lit/decorators.js';
import '@typo3/backend/element/spinner-element.js';
import { LiveAnnouncer } from '../../lib/live-announcer.js';
import type { CreateScanDemand, NoticeState } from '../../lib/types.js';
import { ScanStatus } from '../../lib/types.js';
import { ScanService } from '../../service/scan-service.js';
import { baseStyles } from '../../styles/base-styles.js';
import '../notice/notice.js';

/** How the current scan state is presented. */
interface StatusView {
    state: NoticeState;
    text: string;
    showSpinner?: boolean;
}

const POLL_DELAY_MS = 5000;

/**
 * Compact accessibility-scan status callout: creates or loads a scan, polls
 * while it runs and announces the resulting issue count.
 *
 * Reference component for the frontend conventions in AGENTS.md — shadow DOM,
 * layered CSS via baseStyles, token aliases, @lit/task, lll() labels, typed
 * colon-namespaced events.
 */
@customElement('mindfula11y-scan-issue-count')
export class ScanIssueCount extends LitElement {
    static override styles: CSSResult[] = [...baseStyles];

    @property({ attribute: 'scan-id' }) scanId: string = '';
    @property({ attribute: 'scan-uri' }) scanUri: string = '';
    @property({ type: Object, attribute: 'create-scan-demand' }) createScanDemand: CreateScanDemand | null = null;
    @property({ type: Boolean, attribute: 'auto-create-scan' }) autoCreateScan: boolean = false;
    @property({ type: Array, attribute: 'page-url-filter' }) pageUrlFilter: string[] = [];

    @state() private createdScanId: string = '';

    private readonly scanService: ScanService = new ScanService();
    private readonly announcer: LiveAnnouncer = new LiveAnnouncer(this);
    private pollTimer: number | undefined;
    private lastStatus: ScanStatus | '' = '';
    private lastAnnounced: string = '';

    private readonly scanTask = new Task(this, {
        args: (): readonly [string, CreateScanDemand | null, string[]] => [
            this.scanId || this.createdScanId,
            this.createScanDemand,
            // Lit's JSON attribute converter yields null (not the default) for
            // a missing/malformed attribute value.
            this.pageUrlFilter ?? [],
        ],
        task: async ([scanId, demand, pageUrlFilter]: readonly [
            string,
            CreateScanDemand | null,
            string[],
        ]): Promise<StatusView | null> => {
            if (scanId === '') {
                if (demand === null || !this.autoCreateScan) {
                    return null;
                }
                const created = await this.scanService.createScan(demand);
                this.createdScanId = created.scanId; // args change → task re-runs and loads
                return { state: 'info', text: lll('mindfula11y.scan.status.pending'), showSpinner: true };
            }

            const result = await this.scanService.loadScan(scanId, pageUrlFilter);
            if (result === null) {
                this.createdScanId = '';
                return null;
            }

            if (this.scanService.isScanInProgress(result.status)) {
                this.schedulePoll();
                let label = lll('mindfula11y.scan.status.pending');
                if (result.status === ScanStatus.Running) {
                    label = lll('mindfula11y.scan.status.running');
                } else if (result.status === ScanStatus.Analyzing) {
                    label = lll('mindfula11y.scan.status.analyzing');
                }
                this.lastStatus = result.status;
                return { state: 'info', text: label, showSpinner: true };
            }

            if (result.status === ScanStatus.Failed) {
                this.lastStatus = result.status;
                return { state: 'danger', text: lll('mindfula11y.scan.error.loading') };
            }

            if (result.status === ScanStatus.Canceled) {
                this.lastStatus = result.status;
                return { state: 'info', text: lll('mindfula11y.scan.status.canceled') };
            }

            if (this.lastStatus !== ScanStatus.Completed && this.lastStatus !== '') {
                this.dispatchEvent(
                    new CustomEvent('mindfula11y:scan:completed', {
                        bubbles: true,
                        composed: true,
                        detail: { scanId, totalIssueCount: result.totalIssueCount },
                    }),
                );
            }
            this.lastStatus = result.status;

            if (result.totalIssueCount > 0) {
                return { state: 'warning', text: lll('mindfula11y.scan.issuesFound', result.totalIssueCount) };
            }
            return { state: 'success', text: lll('mindfula11y.scan.noIssues') };
        },
    });

    override connectedCallback(): void {
        super.connectedCallback();
        // Resume polling when reinserted mid-scan: disconnecting cleared the
        // only timer and the task args are unchanged, so nothing else reruns it.
        if (this.lastStatus !== '' && this.scanService.isScanInProgress(this.lastStatus)) {
            this.schedulePoll();
        }
    }

    override disconnectedCallback(): void {
        super.disconnectedCallback();
        window.clearTimeout(this.pollTimer);
    }

    override updated(): void {
        // Without a scan to show, the host must not occupy layout — siblings
        // are spaced with flex gap, and an empty block would leave a hole.
        this.toggleAttribute('hidden', this.scanTask.status === TaskStatus.COMPLETE && this.scanTask.value === null);

        // Announce settled statuses only when their text actually changed:
        // polling re-runs the task every five seconds, and the interim PENDING
        // state or an unchanged status must not reach the live region.
        const view = this.scanTask.value;
        if (this.scanTask.status === TaskStatus.COMPLETE && view !== null && view !== undefined) {
            this.announceIfChanged(view.text);
        } else if (this.scanTask.status === TaskStatus.ERROR) {
            this.announceIfChanged(this.errorText(this.scanTask.error));
        }
    }

    override render(): TemplateResult {
        // The task UI stays out of the live region: the announcer's stable,
        // initially empty region receives status texts from updated() instead.
        return html`${this.scanTask.render({
            pending: (): TemplateResult =>
                this.renderView({ state: 'info', text: lll('mindfula11y.scan.loading'), showSpinner: true }),
            complete: (view: StatusView | null): TemplateResult | typeof nothing =>
                view === null ? nothing : this.renderView(view),
            error: (error: unknown): TemplateResult =>
                this.renderView({ state: 'danger', text: this.errorText(error) }),
        })}${this.announcer.render()}`;
    }

    private announceIfChanged(text: string): void {
        if (text === this.lastAnnounced) {
            return;
        }
        this.lastAnnounced = text;
        void this.announcer.announce(text);
    }

    private renderView(view: StatusView): TemplateResult {
        return html`<mindfula11y-notice state=${view.state}>
            ${
                view.showSpinner === true
                    ? html`<typo3-backend-spinner slot="icon" size="small"></typo3-backend-spinner>`
                    : nothing
            }
            <span>${view.text}</span>
            ${
                this.scanUri !== '' && view.showSpinner !== true
                    ? html`<a href=${this.scanUri}>${lll('mindfula11y.general.viewDetails')}</a>`
                    : nothing
            }
        </mindfula11y-notice>`;
    }

    private errorText(error: unknown): string {
        if (error instanceof Error && error.message !== '') {
            return error.message;
        }
        return lll('mindfula11y.scan.error.loading');
    }

    private schedulePoll(): void {
        window.clearTimeout(this.pollTimer);
        this.pollTimer = window.setTimeout(() => {
            this.scanTask.run().catch(() => {
                // Task errors surface through scanTask.render()'s error branch.
            });
        }, POLL_DELAY_MS);
    }
}

declare global {
    interface HTMLElementTagNameMap {
        'mindfula11y-scan-issue-count': ScanIssueCount;
    }
}
