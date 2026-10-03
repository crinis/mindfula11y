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
import type { ScanStatusView } from '../../lib/scan/status-view.js';
import { scanStatusView } from '../../lib/scan/status-view.js';
import type { CreateScanDemand, ScanResult } from '../../lib/scan/types.js';
import { isScanInProgress, ScanStatus } from '../../lib/scan/types.js';
import { renderProgressNotice } from '../../lib/status-render.js';
import { dispatch } from '../../lib/types.js';
import { errorView } from '../../service/request-error.js';
import { ScanApi } from '../../service/scan/api.js';
import { ScanSessionController } from '../../service/scan/session-controller.js';
import { baseStyles } from '../../styles/base-styles.js';
import buttonStyles from '../../styles/button.css.js';
import '../notice/notice.js';
import componentStyles from './scan-issue-count.css.js';

/**
 * The shared scan-status view with its label already localized — the compact
 * callout adds two states the mapping does not model (loading, load error),
 * so it carries `text` where `ScanStatusView` carries `labelKey`, and the
 * failed-pages note as localized `detail`. There is no room for the
 * mapping's longer description.
 */
interface StatusView extends Omit<ScanStatusView, 'labelKey' | 'descriptionKey' | 'pagesFailed'> {
    text: string;
    detail?: string;
    /** A failed load the editor can repeat: offers the Retry action. */
    retry?: boolean;
}

/**
 * What the live region says for a status view: the spoken variant the status
 * mapping provides where the visible label omits the count, otherwise the
 * visible text — followed by the failed-pages note, which qualifies either.
 * Which statuses need a spoken variant is the mapping's business — see
 * `ScanStatusView.announceLabelKey`.
 */
const announcementFor = (view: StatusView): string => {
    const text = view.announceLabelKey === undefined ? view.text : lll(view.announceLabelKey, view.count ?? 0);
    return view.detail === undefined ? text : `${text} ${view.detail}`;
};

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
    static override styles: CSSResult[] = [...baseStyles, buttonStyles, componentStyles];

    @property({ attribute: 'scan-id' }) scanId: string = '';
    @property({ attribute: 'scan-uri' }) scanUri: string = '';
    @property({ type: Object, attribute: 'create-scan-demand' }) createScanDemand: CreateScanDemand | null = null;
    @property({ type: Boolean, attribute: 'auto-create-scan' }) autoCreateScan: boolean = false;
    @property({ type: Array, attribute: 'page-url-filter' }) pageUrlFilter: string[] = [];

    private readonly scanApi: ScanApi = new ScanApi();
    private readonly announcer: LiveAnnouncer = new LiveAnnouncer(this);
    /**
     * Custom-state set for the `--empty` host state (styled in the component
     * stylesheet). Guarded: below Baseline 2024 (`attachInternals` Safari
     * < 16.4, `CustomStateSet` Safari < 17.4 / Firefox < 126) the host keeps
     * an empty flex slot — a cosmetic regression, never a crash.
     */
    private readonly states: CustomStateSet | null =
        typeof this.attachInternals === 'function'
            ? ((this.attachInternals() as Partial<ElementInternals>).states ?? null)
            : null;

    private lastAnnounced: string = '';
    /** Set while a Retry is loading: its button leaves the DOM meanwhile. */
    private refocusRetry: boolean = false;

    private readonly controller: ScanSessionController = new ScanSessionController(this, {
        service: this.scanApi,
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
        // Without a scan to show, the host must not occupy layout. The custom
        // state (styled in the component stylesheet) hides the host without
        // touching the `hidden` attribute, which belongs to the embedding
        // markup — self-toggling it clobbered integrator-set values.
        if (view === null) {
            this.states?.add('--empty');
            return;
        }
        this.states?.delete('--empty');
        // Announce settled statuses only when their announcement actually
        // changed: polling re-runs the load every five seconds, and the interim
        // generic loading placeholder or an unchanged status must not reach the
        // live region.
        if (!(this.controller.result === null && this.controller.state === 'loading')) {
            this.announceIfChanged(announcementFor(view));
        }
        // The Retry button leaves the DOM while its load runs, dropping focus
        // to <body>. When the load fails again the button returns — give focus
        // back to it, unless the editor has moved on meanwhile.
        if (this.refocusRetry && this.controller.state !== 'loading') {
            this.refocusRetry = false;
            const active = this.ownerDocument.activeElement;
            if (active === null || active === this.ownerDocument.body) {
                this.renderRoot.querySelector<HTMLButtonElement>('button[data-action="retry"]')?.focus();
            }
        }
    }

    override render(): TemplateResult {
        // The status callout stays out of the live region: the announcer's
        // stable, initially empty region receives status texts from updated().
        const view = this.statusView();
        return html`${view === null ? nothing : this.renderView(view)}${this.announcer.render()}`;
    }

    /**
     * Maps the controller's state to the callout, or null when there is nothing to show.
     *
     * A failed load wins over a result that is still in progress: polling
     * backs off and stops after repeated failures (at once on a 401/403), so
     * that result's "Scan running" would otherwise stay up with its spinner
     * for good. A settled result stays — no later load can change it. With a
     * scan to load, the error offers Retry, which also restarts polling; this
     * compact callout has no other way back after, say, a re-login.
     */
    private statusView(): StatusView | null {
        const result = this.controller.result;
        if (this.controller.state === 'error' && (result === null || isScanInProgress(result.status))) {
            return {
                state: 'danger',
                text: errorView(this.controller.error, 'mindfula11y.scan.error.loading').title,
                retry: this.controller.effectiveScanId() !== '',
            };
        }
        if (result !== null) {
            return this.viewFromResult(result);
        }
        if (this.controller.state === 'loading') {
            return { state: 'info', text: lll('mindfula11y.scan.loading'), spinner: true };
        }
        return null;
    }

    private viewFromResult(result: ScanResult): StatusView {
        // The compact callout reuses the loading-error label for a failed scan
        // — there is no room for the scan module's full failure description.
        if (result.status === ScanStatus.Failed) {
            return { state: 'danger', text: lll('mindfula11y.scan.error.loading') };
        }
        const { labelKey, descriptionKey: _description, pagesFailed, ...view } = scanStatusView(result);
        return {
            ...view,
            text: lll(labelKey),
            ...(pagesFailed === undefined
                ? {}
                : { detail: lll('mindfula11y.scan.pagesFailed', pagesFailed.failed, pagesFailed.total) }),
        };
    }

    private handleTransition(previous: ScanStatus | null, result: ScanResult): void {
        if (previous !== null && previous !== ScanStatus.Completed && result.status === ScanStatus.Completed) {
            dispatch(this, 'mindfula11y:scan:completed', {
                scanId: this.controller.effectiveScanId(),
                totalIssueCount: result.totalIssueCount,
            });
        }
    }

    private readonly handleRetry = (): void => {
        this.refocusRetry = true;
        void this.controller.reload();
    };

    private announceIfChanged(text: string): void {
        if (text === this.lastAnnounced) {
            return;
        }
        this.lastAnnounced = text;
        void this.announcer.announce(text);
    }

    private renderView(view: StatusView): TemplateResult {
        // An in-progress scan has nothing to link to yet, so it renders as the
        // shared progress row instead of the settled status + details link.
        if (view.spinner === true) {
            return renderProgressNotice(view.text);
        }
        return html`<mindfula11y-notice state=${view.state} count=${view.count ?? nothing}>
            <span>${view.text}${view.detail === undefined ? nothing : html` — ${view.detail}`}</span>
            ${
                view.retry === true
                    ? html`<button type="button" slot="trailing" class="button" data-action="retry" @click=${this.handleRetry}>
                          ${lll('mindfula11y.scan.retry')}<span class="sr-only"> ${lll('mindfula11y.scan')}</span>
                      </button>`
                    : nothing
            }
            ${
                this.scanUri === ''
                    ? nothing
                    : html`<a slot="trailing" href=${this.scanUri}
                          >${lll('mindfula11y.general.viewDetails')}<span class="sr-only">
                              ${lll('mindfula11y.scan')}</span
                          ></a
                      >`
            }
        </mindfula11y-notice>`;
    }
}

declare global {
    interface HTMLElementTagNameMap {
        'mindfula11y-scan-issue-count': ScanIssueCount;
    }
}
