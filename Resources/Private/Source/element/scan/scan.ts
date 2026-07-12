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
import '@typo3/backend/element/icon-element.js';
import '@typo3/backend/element/spinner-element.js';
import '../notice/notice.js';
import '../scan-results/scan-results.js';
import { LiveAnnouncer } from '../../lib/live-announcer.js';
import { activateTabFromKeydown } from '../../lib/tablist.js';
import type { CreateScanDemand, ScanResult } from '../../lib/types.js';
import { AiAuditStatus, ScanStatus } from '../../lib/types.js';
import { RequestError } from '../../service/request-error.js';
import { ScanService } from '../../service/scan-service.js';
import { baseStyles } from '../../styles/base-styles.js';
import buttonStyles from '../../styles/button.css.js';
import noticeStyles from '../../styles/notice.css.js';
import placeholderStyles from '../../styles/placeholder.css.js';
import tabsStyles from '../../styles/tabs.css.js';
import { IMPACT_ORDER, impactState } from '../scan-results/scan-results.js';
import componentStyles from './scan.css.js';

type ScanTab = 'scan' | 'crawl';

const POLL_DELAY_MS = 5000;

/**
 * Accessibility scan module: creates and polls scans of the current page (and
 * its subpages or, on site roots, a full-site crawl as a second tab), offers
 * the optional AI review toggle, cancellation and report downloads, and
 * renders results through `<mindfula11y-scan-results>`.
 *
 * One scan id is stored per page; both tabs poll the same scan — the page
 * view filtered by the current page URLs, the crawl view unfiltered (shown
 * only when the stored scan actually is a crawl). Results render from state
 * so the DOM stays mounted across poll runs.
 */
@customElement('mindfula11y-scan')
export class Scan extends LitElement {
    static override styles: CSSResult[] = [
        ...baseStyles,
        noticeStyles,
        tabsStyles,
        buttonStyles,
        placeholderStyles,
        componentStyles,
    ];

    @property({ attribute: 'scan-id' }) scanId: string = '';
    @property({ type: Object, attribute: 'create-scan-demand' }) createScanDemand: CreateScanDemand | null = null;
    @property({ type: Object, attribute: 'crawl-scan-demand' }) crawlScanDemand: CreateScanDemand | null = null;
    @property({ type: Boolean, attribute: 'auto-create-scan' }) autoCreateScan: boolean = false;
    @property({ type: Boolean, attribute: 'ai-audit-available' }) aiAuditAvailable: boolean = false;
    @property({ type: Boolean, attribute: 'ai-audit-default' }) aiAuditDefault: boolean = false;
    @property({ type: Array, attribute: 'page-url-filter' }) pageUrlFilter: string[] = [];
    @property({ type: Array, attribute: 'url-list' }) urlList: string[] = [];
    @property({ attribute: 'report-base-url' }) reportBaseUrl: string = '';

    @state() private activeTab: ScanTab = 'scan';
    @state() private createdScanId: string = '';
    @state() private invalidScanId: string = '';
    @state() private scanResult: ScanResult | null = null;
    @state() private crawlResult: ScanResult | null = null;
    @state() private actionBusy: boolean = false;
    @state() private actionError: { title: string; description: string } | null = null;
    /** Editor's toggle choice; null = follow the TSConfig-provided default. */
    @state() private aiAuditChecked: boolean | null = null;

    private readonly scanService: ScanService = new ScanService();
    private readonly announcer: LiveAnnouncer = new LiveAnnouncer(this);
    private pollTimer: number | undefined;
    private lastStatus: ScanStatus | '' = '';
    private autoCreateAttempted: boolean = false;

    private readonly loadTask = new Task(this, {
        args: (): readonly [string, string[]] => [this.effectiveScanId(), this.pageUrlFilter ?? []],
        task: async ([scanId, pageUrlFilter]: readonly [string, string[]]): Promise<void> => {
            if (scanId === '') {
                this.scanResult = null;
                this.crawlResult = null;
                await this.maybeAutoCreate();
                return;
            }
            const [filtered, unfiltered] = await Promise.all([
                this.scanService.loadScan(scanId, pageUrlFilter),
                this.crawlScanDemand !== null ? this.scanService.loadScan(scanId, []) : Promise.resolve(null),
            ]);
            if (filtered === null) {
                // Scan gone on the API side — forget the id; auto-create (when
                // enabled) recreates it on the follow-up run.
                if (this.createdScanId === scanId) {
                    this.createdScanId = '';
                } else {
                    this.invalidScanId = scanId;
                }
                this.scanResult = null;
                this.crawlResult = null;
                this.lastStatus = '';
                return;
            }
            this.scanResult = filtered;
            this.crawlResult = unfiltered !== null && unfiltered.mode === 'crawl' ? unfiltered : null;
            await this.handleStatusChange(filtered);
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

    override render(): TemplateResult {
        const tabs = this.enabledTabs();
        return html`<div class="scan">
            ${
                tabs.length > 1
                    ? html`<div class="tabs" role="tablist" aria-label=${lll('mindfula11y.scan')}>
                          ${tabs.map((tab) => this.renderTab(tab))}
                      </div>`
                    : nothing
            }
            ${this.announcer.render()}
            ${tabs.map((tab) => this.renderPanel(tab, tabs.length > 1))}
        </div>`;
    }

    private enabledTabs(): ScanTab[] {
        return this.crawlScanDemand !== null ? ['scan', 'crawl'] : ['scan'];
    }

    private effectiveScanId(): string {
        if (this.createdScanId !== '') {
            return this.createdScanId;
        }
        return this.scanId !== this.invalidScanId ? this.scanId : '';
    }

    private tabResult(tab: ScanTab): ScanResult | null {
        return tab === 'scan' ? this.scanResult : this.crawlResult;
    }

    private tabDemand(tab: ScanTab): CreateScanDemand | null {
        return tab === 'scan' ? this.createScanDemand : this.crawlScanDemand;
    }

    private isScanRunning(): boolean {
        return this.scanResult !== null && this.scanService.isScanInProgress(this.scanResult.status);
    }

    private isAiAuditChecked(): boolean {
        return this.aiAuditChecked ?? this.aiAuditDefault;
    }

    private renderTab(tab: ScanTab): TemplateResult {
        const selected = this.activeTab === tab;
        return html`<button
            type="button"
            role="tab"
            id="tab-${tab}"
            data-tab=${tab}
            aria-selected=${selected ? 'true' : 'false'}
            aria-controls="panel-${tab}"
            tabindex=${selected ? '0' : '-1'}
            @click=${(): void => {
                this.activeTab = tab;
            }}
            @keydown=${this.handleTabKeydown}
        >
            ${lll(`mindfula11y.scan.tab.${tab}`)} ${this.renderTabBadge(tab)}
        </button>`;
    }

    private renderTabBadge(tab: ScanTab): TemplateResult | typeof nothing {
        const result = this.tabResult(tab);
        if (result === null || result.status !== ScanStatus.Completed || result.totalIssueCount === 0) {
            return nothing;
        }
        const worst = IMPACT_ORDER.find((impact) => result.violations.some((violation) => violation.impact === impact));
        return html`<span class="notice count" data-state=${impactState(worst ?? 'minor')} data-variant="pill"
            ><span aria-hidden="true">${result.totalIssueCount}</span
            ><span class="sr-only"
                >${lll(
                    result.totalIssueCount === 1 ? 'mindfula11y.scan.issueCount' : 'mindfula11y.scan.issuesCount',
                    result.totalIssueCount,
                )}</span
            ></span
        >`;
    }

    private renderPanel(tab: ScanTab, withTabs: boolean): TemplateResult {
        const busy = this.loadTask.status === TaskStatus.PENDING || this.actionBusy;
        const content = html`<p class="description">${lll(`mindfula11y.scan.tab.${tab}.description`)}</p>
            ${this.renderHints(tab)} ${this.renderAiToggle(tab)} ${this.renderActions(tab)} ${this.renderBody(tab)}`;

        if (!withTabs) {
            return html`<div class="panel" aria-busy=${busy ? 'true' : 'false'}>${content}</div>`;
        }
        return html`<div
            class="panel"
            role="tabpanel"
            id="panel-${tab}"
            aria-labelledby="tab-${tab}"
            tabindex="0"
            aria-busy=${busy ? 'true' : 'false'}
            ?hidden=${this.activeTab !== tab}
        >
            ${content}
        </div>`;
    }

    private renderHints(tab: ScanTab): TemplateResult | typeof nothing {
        if (tab === 'crawl') {
            if (this.crawlResult === null && !this.isScanRunning() && !this.actionBusy) {
                return html`<mindfula11y-notice state="info">
                    <span>
                        <span class="notice-title">${lll('mindfula11y.scan.crawl.idle.title')}</span>
                        ${lll('mindfula11y.scan.crawl.idle.description')}
                    </span>
                </mindfula11y-notice>`;
            }
            return nothing;
        }

        const urlList = this.urlList ?? [];
        // The stored scan no longer covers the selected page scope.
        if (
            this.scanResult !== null &&
            this.scanResult.mode !== 'crawl' &&
            urlList.length > 0 &&
            !this.urlListCovered(urlList, this.scanResult.targets)
        ) {
            return html`<mindfula11y-notice state="info">
                <span>
                    <span class="notice-title">${lll('mindfula11y.scan.scopeExpanded')}</span>
                    ${lll('mindfula11y.scan.scopeExpanded.description')}
                </span>
            </mindfula11y-notice>`;
        }
        if (
            this.scanResult === null &&
            !this.actionBusy &&
            this.loadTask.status !== TaskStatus.PENDING &&
            this.createScanDemand !== null &&
            urlList.length > 1
        ) {
            return html`<mindfula11y-notice state="info">
                <span>
                    <span class="notice-title">${lll('mindfula11y.scan.multiPage.manualScan')}</span>
                    ${lll('mindfula11y.scan.multiPage.manualScan.description')}
                </span>
            </mindfula11y-notice>`;
        }
        return nothing;
    }

    private renderActions(tab: ScanTab): TemplateResult | typeof nothing {
        const demand = this.tabDemand(tab);
        const result = this.tabResult(tab);
        const running = this.isScanRunning();
        const scanId = this.effectiveScanId();

        if (demand === null && !running) {
            return nothing;
        }

        const triggerKey =
            tab === 'crawl'
                ? result !== null
                    ? 'mindfula11y.scan.crawl.refresh'
                    : 'mindfula11y.scan.crawl.start'
                : result !== null
                  ? 'mindfula11y.scan.refresh'
                  : 'mindfula11y.scan.start';

        return html`<div class="actions">
            ${
                demand !== null
                    ? html`<button
                          type="button"
                          class="button"
                          data-action="trigger"
                          ?disabled=${this.actionBusy || running}
                          @click=${(): void => {
                              void this.handleTrigger(tab);
                          }}
                      >
                          ${
                              this.actionBusy
                                  ? html`<typo3-backend-spinner size="small"></typo3-backend-spinner>`
                                  : html`<typo3-backend-icon
                                        identifier=${result !== null ? 'actions-refresh' : 'actions-search'}
                                        size="small"
                                    ></typo3-backend-icon>`
}
                          ${lll(this.actionBusy ? 'mindfula11y.scan.processing' : triggerKey)}
                      </button>`
                    : nothing
            }
            ${
                running && scanId !== ''
                    ? html`<button
                          type="button"
                          class="button"
                          data-action="cancel"
                          ?disabled=${this.actionBusy}
                          @click=${(): void => {
                              void this.handleCancel();
                          }}
                      >
                          <typo3-backend-icon identifier="actions-close" size="small"></typo3-backend-icon>
                          ${lll('mindfula11y.scan.cancel')}
                      </button>`
                    : nothing
            }
        </div>`;
    }

    private renderAiToggle(tab: ScanTab): TemplateResult | typeof nothing {
        if (!this.aiAuditAvailable || this.tabDemand(tab) === null) {
            return nothing;
        }
        return html`<span class="toggle">
            <input
                type="checkbox"
                id="ai-toggle-${tab}"
                class="checkbox"
                .checked=${this.isAiAuditChecked()}
                ?disabled=${this.actionBusy || this.isScanRunning()}
                aria-describedby="ai-toggle-description-${tab}"
                @change=${(event: Event): void => {
                    this.aiAuditChecked = (event.currentTarget as HTMLInputElement).checked;
                }}
            />
            <label class="toggle-label" for="ai-toggle-${tab}">${lll('mindfula11y.scan.aiAudit.toggle')}</label>
            <span class="toggle-description" id="ai-toggle-description-${tab}"
                >${lll('mindfula11y.scan.aiAudit.toggle.description')}</span
            >
        </span>`;
    }

    private renderBody(tab: ScanTab): TemplateResult | typeof nothing {
        if (this.actionError !== null) {
            return html`<mindfula11y-notice state="danger">
                <span>
                    <span class="notice-title">${this.actionError.title}</span>
                    ${this.actionError.description}
                </span>
            </mindfula11y-notice>`;
        }

        if (this.loadTask.status === TaskStatus.ERROR) {
            return html`<mindfula11y-notice state="danger">
                <span>
                    <span class="notice-title">${lll('mindfula11y.scan.error.loading')}</span>
                    ${this.loadErrorDescription()}
                </span>
                <button
                    type="button"
                    class="button"
                    @click=${(): void => {
                        void this.loadTask.run();
                    }}
                >
                    ${lll('mindfula11y.scan.refresh')}
                </button>
            </mindfula11y-notice>`;
        }

        const result = this.tabResult(tab);
        if (result === null) {
            if (this.loadTask.status === TaskStatus.PENDING && this.effectiveScanId() !== '') {
                return html`<div class="placeholder">
                    <typo3-backend-spinner size="default"></typo3-backend-spinner>
                    <span>${lll('mindfula11y.scan.loading')}</span>
                </div>`;
            }
            return nothing;
        }

        // The AI review can carry findings even when axe found nothing, so the
        // results element renders whenever either source has content.
        const hasAiReview = result.aiAudit !== null && result.aiAudit.status !== AiAuditStatus.Skipped;
        return html`${this.renderStatus(result, tab === 'crawl')} ${this.renderUpdatedAt(result)}
        ${
            result.status === ScanStatus.Completed && (result.totalIssueCount > 0 || hasAiReview)
                ? html`<mindfula11y-scan-results .result=${result}></mindfula11y-scan-results>`
                : nothing
        }
        ${this.renderReportLinks(result)}`;
    }

    /** Download/view links for the stored report, closing the results. */
    private renderReportLinks(result: ScanResult): TemplateResult | typeof nothing {
        const scanId = this.effectiveScanId();
        if (result.status !== ScanStatus.Completed || scanId === '' || this.reportBaseUrl === '') {
            return nothing;
        }
        return html`<div class="actions">
            <a class="button" href=${this.buildReportUrl(scanId, 'html')} target="_blank" rel="noreferrer">
                <typo3-backend-icon identifier="actions-document" size="small"></typo3-backend-icon>
                ${lll('mindfula11y.scan.report.html')}
                <span class="sr-only">${lll('mindfula11y.scan.opensNewTab')}</span>
            </a>
            <a class="button" href=${this.buildReportUrl(scanId, 'pdf')} download="accessibility-report.pdf">
                <typo3-backend-icon identifier="actions-download" size="small"></typo3-backend-icon>
                ${lll('mindfula11y.scan.report.pdf')}
            </a>
        </div>`;
    }

    private renderStatus(result: ScanResult, isCrawl: boolean): TemplateResult {
        switch (result.status) {
            case ScanStatus.Pending:
                return this.renderProgressNotice(lll('mindfula11y.scan.status.pending'), null);
            case ScanStatus.Running: {
                let progressText: string | null = null;
                if (isCrawl && result.progress !== null) {
                    progressText =
                        result.progress.pagesDiscovered === 0
                            ? lll('mindfula11y.scan.progress.discovering')
                            : lll(
                                  'mindfula11y.scan.progress.pages',
                                  result.progress.pagesScanned,
                                  result.progress.pagesDiscovered,
                              );
                    if (result.progress.pagesFailed > 0) {
                        progressText += ` — ${lll('mindfula11y.scan.progress.pagesFailed', result.progress.pagesFailed)}`;
                    }
                }
                return this.renderProgressNotice(lll('mindfula11y.scan.status.running'), progressText);
            }
            case ScanStatus.Analyzing: {
                const audit = result.aiAudit;
                const progressText =
                    audit !== null && audit.tasksTotal > 0
                        ? lll('mindfula11y.scan.aiAudit.status.running', audit.tasksCompleted, audit.tasksTotal)
                        : null;
                return this.renderProgressNotice(lll('mindfula11y.scan.status.analyzing'), progressText);
            }
            case ScanStatus.Failed:
                return html`<mindfula11y-notice state="danger">
                    <span>
                        <span class="notice-title">${lll('mindfula11y.scan.status.failed')}</span>
                        ${lll('mindfula11y.scan.status.failed.description')}
                    </span>
                </mindfula11y-notice>`;
            case ScanStatus.Canceled:
                return html`<mindfula11y-notice state="info">
                    <span>
                        <span class="notice-title">${lll('mindfula11y.scan.status.canceled')}</span>
                        ${lll('mindfula11y.scan.status.canceled.description')}
                    </span>
                </mindfula11y-notice>`;
            default:
                return result.totalIssueCount > 0
                    ? html`<mindfula11y-notice state="warning">
                          <span>${lll('mindfula11y.scan.issuesFound', result.totalIssueCount)}</span>
                      </mindfula11y-notice>`
                    : html`<mindfula11y-notice state="success">
                          <span>${lll('mindfula11y.scan.noIssues')}</span>
                      </mindfula11y-notice>`;
        }
    }

    private renderProgressNotice(title: string, progressText: string | null): TemplateResult {
        return html`<mindfula11y-notice state="info">
            <typo3-backend-spinner slot="icon" size="small"></typo3-backend-spinner>
            <span>${title}${progressText !== null ? html` — ${progressText}` : nothing}</span>
        </mindfula11y-notice>`;
    }

    private renderUpdatedAt(result: ScanResult): TemplateResult | typeof nothing {
        if (result.updatedAt === null) {
            return nothing;
        }
        const date = new Date(result.updatedAt);
        if (Number.isNaN(date.getTime())) {
            return nothing;
        }
        const formatted = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(date);
        return html`<p class="meta">
            ${lll('mindfula11y.scan.updatedAt')}
            <time datetime=${result.updatedAt}>${formatted}</time>
        </p>`;
    }

    private loadErrorDescription(): string {
        const error = this.loadTask.error;
        if (error instanceof RequestError) {
            return error.description !== '' ? error.description : error.message;
        }
        return lll('mindfula11y.scan.error.getFailed.description');
    }

    private buildReportUrl(scanId: string, format: 'html' | 'pdf'): string {
        return `${this.reportBaseUrl}&scanId=${encodeURIComponent(scanId)}&format=${format}`;
    }

    private urlListCovered(urlList: string[], targets: string[]): boolean {
        const targetSet = new Set(targets);
        return urlList.every((url) => targetSet.has(url));
    }

    /** Auto-creates the initial scan once — never with the AI audit (cost). */
    private async maybeAutoCreate(): Promise<void> {
        if (!this.autoCreateScan || this.createScanDemand === null || this.autoCreateAttempted) {
            return;
        }
        this.autoCreateAttempted = true;
        try {
            const created = await this.scanService.createScan(this.createScanDemand);
            this.lastStatus = created.status;
            this.createdScanId = created.scanId; // args change → task reloads
            await this.announcer.announce(lll('mindfula11y.scan.announce.started'));
        } catch (error) {
            this.actionError = this.toActionError(error, 'mindfula11y.scan.error.createFailed');
            await this.announcer.announce(this.actionError.title);
        }
    }

    private async handleTrigger(tab: ScanTab): Promise<void> {
        const demand = this.tabDemand(tab);
        if (demand === null || this.actionBusy) {
            return;
        }
        this.actionBusy = true;
        this.actionError = null;
        try {
            const created = await this.scanService.createScan(demand, this.aiAuditAvailable && this.isAiAuditChecked());
            this.lastStatus = created.status;
            this.scanResult = null;
            this.crawlResult = null;
            this.invalidScanId = this.scanId;
            this.createdScanId = created.scanId;
            await this.announcer.announce(lll('mindfula11y.scan.announce.started'));
        } catch (error) {
            this.actionError = this.toActionError(error, 'mindfula11y.scan.error.createFailed');
            await this.announcer.announce(this.actionError.title);
        } finally {
            this.actionBusy = false;
        }
    }

    private async handleCancel(): Promise<void> {
        const scanId = this.effectiveScanId();
        if (scanId === '' || this.actionBusy) {
            return;
        }
        this.actionBusy = true;
        try {
            await this.scanService.cancelScan(scanId);
        } catch (error) {
            // 409 = the scan already reached a terminal state; the reload
            // below shows the final result, so it is not an error to surface
            // (renderBody would pin an actionError over the loaded results).
            if (!(error instanceof RequestError && error.status === 409)) {
                this.actionError = this.toActionError(error, 'mindfula11y.scan.error.cancelFailed');
                await this.announcer.announce(this.actionError.title);
            }
        } finally {
            this.actionBusy = false;
            void this.loadTask.run();
        }
    }

    private toActionError(error: unknown, fallbackKey: string): { title: string; description: string } {
        if (error instanceof RequestError) {
            return { title: error.message, description: error.description };
        }
        return { title: lll(fallbackKey), description: lll(`${fallbackKey}.description`) };
    }

    /** Announces terminal transitions and keeps polling while the scan runs. */
    private async handleStatusChange(result: ScanResult): Promise<void> {
        const wasInProgress = this.lastStatus !== '' && this.scanService.isScanInProgress(this.lastStatus);
        if (this.scanService.isScanInProgress(result.status)) {
            this.schedulePoll();
        } else if (wasInProgress) {
            const scanId = this.effectiveScanId();
            if (result.status === ScanStatus.Completed) {
                this.dispatchEvent(
                    new CustomEvent('mindfula11y:scan:completed', {
                        bubbles: true,
                        composed: true,
                        detail: { scanId, totalIssueCount: result.totalIssueCount },
                    }),
                );
                await this.announcer.announce(lll('mindfula11y.scan.announce.completed', result.totalIssueCount));
            } else if (result.status === ScanStatus.Canceled) {
                this.dispatchEvent(
                    new CustomEvent('mindfula11y:scan:canceled', {
                        bubbles: true,
                        composed: true,
                        detail: { scanId },
                    }),
                );
                await this.announcer.announce(lll('mindfula11y.scan.announce.canceled'));
            } else if (result.status === ScanStatus.Failed) {
                await this.announcer.announce(lll('mindfula11y.scan.announce.failed'));
            }
        }
        this.lastStatus = result.status;
    }

    private schedulePoll(): void {
        window.clearTimeout(this.pollTimer);
        this.pollTimer = window.setTimeout(() => {
            this.loadTask.run().catch(() => {
                // The error surfaces through the render error branch, but a
                // transient poll failure must not stop polling permanently:
                // handleStatusChange (the only other place that re-arms the
                // timer) runs on the task's success path only. lastStatus is
                // left untouched on failure, so re-arm here while the scan is
                // still believed to be in progress — the next poll recovers.
                if (this.lastStatus !== '' && this.scanService.isScanInProgress(this.lastStatus)) {
                    this.schedulePoll();
                }
            });
        }, POLL_DELAY_MS);
    }

    private handleTabKeydown = (event: KeyboardEvent): void => {
        void activateTabFromKeydown(this, event, this.enabledTabs(), this.activeTab, (tab) => {
            this.activeTab = tab;
        });
    };
}

declare global {
    interface HTMLElementTagNameMap {
        'mindfula11y-scan': Scan;
    }
}
