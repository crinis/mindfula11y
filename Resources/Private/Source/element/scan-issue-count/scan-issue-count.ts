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

import { lll } from '@typo3/core/lit-helper.js';
import type { CSSResult, TemplateResult } from 'lit';
import { html, LitElement, nothing } from 'lit';
import { customElement, property } from 'lit/decorators.js';
import '@typo3/backend/element/spinner-element.js';
import { LiveAnnouncer } from '../../lib/live-announcer.js';
import type { NoticeState } from '../../lib/status-render.js';
import type { CreateScanDemand, ScanResult } from '../../lib/types.js';
import { ScanStatus } from '../../lib/types.js';
import { errorView } from '../../service/request-error.js';
import { ScanService } from '../../service/scan-service.js';
import { ScanSessionController } from '../../service/scan-session-controller.js';
import { baseStyles } from '../../styles/base-styles.js';
import '../notice/notice.js';

/** How the current scan state is presented. */
interface StatusView {
    state: NoticeState;
    text: string;
    showSpinner?: boolean;
}

/**
 * Compact accessibility-scan status callout: creates or loads a scan, polls
 * while it runs and announces the resulting issue count.
 *
 * Reference component for the frontend conventions in AGENTS.md — shadow DOM,
 * layered CSS via baseStyles, token aliases, lll() labels, typed
 * colon-namespaced events. The scan-session lifecycle (loading, polling,
 * auto-create, 404-forget, terminal-transition detection) lives in the shared
 * `ScanSessionController`; this component only maps its state to a callout and
 * announces the settled status.
 */
@customElement('mindfula11y-scan-issue-count')
export class ScanIssueCount extends LitElement {
    static override styles: CSSResult[] = [...baseStyles];

    @property({ attribute: 'scan-id' }) scanId: string = '';
    @property({ attribute: 'scan-uri' }) scanUri: string = '';
    @property({ type: Object, attribute: 'create-scan-demand' }) createScanDemand: CreateScanDemand | null = null;
    @property({ type: Boolean, attribute: 'auto-create-scan' }) autoCreateScan: boolean = false;
    @property({ type: Array, attribute: 'page-url-filter' }) pageUrlFilter: string[] = [];

    private readonly scanService: ScanService = new ScanService();
    private readonly announcer: LiveAnnouncer = new LiveAnnouncer(this);
    private lastAnnounced: string = '';

    private readonly controller: ScanSessionController = new ScanSessionController(this, {
        service: this.scanService,
        scanId: (): string => this.scanId,
        // A demand only auto-creates when the editor opted in; there is no
        // manual trigger in this compact callout.
        demand: (): CreateScanDemand | null => (this.autoCreateScan ? this.createScanDemand : null),
        // Lit's JSON attribute converter yields null (not the default) for a
        // missing/malformed attribute value.
        pageUrlFilter: (): string[] => this.pageUrlFilter ?? [],
        onTransition: (previous: ScanStatus | null, result: ScanResult): void =>
            this.handleTransition(previous, result),
    });

    override updated(): void {
        const view = this.statusView();
        // Without a scan to show, the host must not occupy layout — siblings
        // are spaced with flex gap, and an empty block would leave a hole.
        this.toggleAttribute('hidden', view === null);
        if (view === null) {
            return;
        }
        // Announce settled statuses only when their text actually changed:
        // polling re-runs the load every five seconds, and the interim generic
        // loading placeholder or an unchanged status must not reach the live
        // region.
        if (!(this.controller.result === null && this.controller.state === 'loading')) {
            this.announceIfChanged(view.text);
        }
    }

    override render(): TemplateResult {
        // The status callout stays out of the live region: the announcer's
        // stable, initially empty region receives status texts from updated().
        const view = this.statusView();
        return html`${view === null ? nothing : this.renderView(view)}${this.announcer.render()}`;
    }

    /** Maps the controller's state to the callout, or null when there is nothing to show. */
    private statusView(): StatusView | null {
        const result = this.controller.result;
        if (result !== null) {
            return this.viewFromResult(result);
        }
        if (this.controller.state === 'error') {
            return { state: 'danger', text: errorView(this.controller.error, 'mindfula11y.scan.error.loading').title };
        }
        if (this.controller.state === 'loading') {
            return { state: 'info', text: lll('mindfula11y.scan.loading'), showSpinner: true };
        }
        return null;
    }

    private viewFromResult(result: ScanResult): StatusView {
        if (this.scanService.isScanInProgress(result.status)) {
            let label = lll('mindfula11y.scan.status.pending');
            if (result.status === ScanStatus.Running) {
                label = lll('mindfula11y.scan.status.running');
            } else if (result.status === ScanStatus.Analyzing) {
                label = lll('mindfula11y.scan.status.analyzing');
            }
            return { state: 'info', text: label, showSpinner: true };
        }
        if (result.status === ScanStatus.Failed) {
            return { state: 'danger', text: lll('mindfula11y.scan.error.loading') };
        }
        if (result.status === ScanStatus.Canceled) {
            return { state: 'info', text: lll('mindfula11y.scan.status.canceled') };
        }
        if (result.totalIssueCount > 0) {
            return { state: 'warning', text: lll('mindfula11y.scan.issuesFound', result.totalIssueCount) };
        }
        return { state: 'success', text: lll('mindfula11y.scan.noIssues') };
    }

    private handleTransition(previous: ScanStatus | null, result: ScanResult): void {
        if (previous !== null && previous !== ScanStatus.Completed && result.status === ScanStatus.Completed) {
            this.dispatchEvent(
                new CustomEvent('mindfula11y:scan:completed', {
                    bubbles: true,
                    composed: true,
                    detail: { scanId: this.controller.effectiveScanId(), totalIssueCount: result.totalIssueCount },
                }),
            );
        }
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
}

declare global {
    interface HTMLElementTagNameMap {
        'mindfula11y-scan-issue-count': ScanIssueCount;
    }
}
