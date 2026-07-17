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
import { customElement, property, state } from 'lit/decorators.js';
import { LiveAnnouncer } from '../../lib/live-announcer.js';
import { IMPACT_ORDER, impactState, renderCountBadge } from '../../lib/status-render.js';
import { type TabDescriptor, TabsController } from '../../lib/tabs.js';
import { dispatch } from '../../lib/types.js';
import { type ErrorView, errorView, RequestError } from '../../service/request-error.js';
import { ScanApi } from '../../service/scan/api.js';
import { ScanSessionController } from '../../service/scan/session-controller.js';
import type { CreateScanDemand, ScanResult } from '../../service/scan/types.js';
import { isScanInProgress, ScanStatus } from '../../service/scan/types.js';
import { baseStyles } from '../../styles/base-styles.js';
import buttonStyles from '../../styles/button.css.js';
import noticeStyles from '../../styles/notice.css.js';
import placeholderStyles from '../../styles/placeholder.css.js';
import tabsStyles from '../../styles/tabs.css.js';
import componentStyles from './scan.css.js';
import { renderPanelContent, type ScanPanelCallbacks, type ScanPanelData, type ScanTab } from './scan-panel.js';

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

    @state() private actionBusy: boolean = false;
    @state() private actionError: ErrorView | null = null;
    /** Editor's toggle choice; null = follow the TSConfig-provided default. */
    @state() private aiAuditChecked: boolean | null = null;

    private readonly scanApi: ScanApi = new ScanApi();
    private readonly announcer: LiveAnnouncer = new LiveAnnouncer(this);
    private readonly tabs: TabsController<ScanTab> = new TabsController(this, () => this.enabledTabs(), 'scan');

    private readonly controller: ScanSessionController = new ScanSessionController(this, {
        service: this.scanApi,
        scanId: (): string => this.scanId,
        // A demand only auto-creates when the editor opted in; the manual
        // trigger buttons call controller.createScan directly with their demand.
        demand: (): CreateScanDemand | null => (this.autoCreateScan ? this.createScanDemand : null),
        pageUrlFilter: (): string[] => this.pageUrlFilter ?? [],
        // The crawl tab reads the same scan unfiltered — only when the stored
        // scan actually is a crawl.
        withCrawlResult: (): boolean => this.crawlScanDemand !== null,
        onTransition: (previous: ScanStatus | null, result: ScanResult): void => {
            void this.handleTransition(previous, result);
        },
    });

    override render(): TemplateResult {
        const tabs = this.enabledTabs();
        return html`<div class="scan">
            ${
                tabs.length > 1
                    ? this.tabs.renderTablist({
                          ariaLabel: lll('mindfula11y.scan'),
                          tabs: tabs.map((tab) => this.tabDescriptor(tab)),
                      })
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
        return this.controller.effectiveScanId();
    }

    private tabResult(tab: ScanTab): ScanResult | null {
        return tab === 'scan' ? this.controller.result : this.controller.crawlResult;
    }

    private tabDemand(tab: ScanTab): CreateScanDemand | null {
        return tab === 'scan' ? this.createScanDemand : this.crawlScanDemand;
    }

    private isScanRunning(): boolean {
        return this.controller.result !== null && isScanInProgress(this.controller.result.status);
    }

    private isAiAuditChecked(): boolean {
        return this.aiAuditChecked ?? this.aiAuditDefault;
    }

    private tabDescriptor(tab: ScanTab): TabDescriptor<ScanTab> {
        return {
            id: tab,
            label: lll(`mindfula11y.scan.tab.${tab}`),
            badge: this.renderTabBadge(tab),
        };
    }

    private renderTabBadge(tab: ScanTab): TemplateResult | typeof nothing {
        const result = this.tabResult(tab);
        if (result === null || result.status !== ScanStatus.Completed || result.totalIssueCount === 0) {
            return nothing;
        }
        const worst = IMPACT_ORDER.find((impact) => result.violations.some((violation) => violation.impact === impact));
        return renderCountBadge(
            impactState(worst ?? 'minor'),
            result.totalIssueCount,
            lll(
                result.totalIssueCount === 1 ? 'mindfula11y.scan.issueCount' : 'mindfula11y.scan.issuesCount',
                result.totalIssueCount,
            ),
        );
    }

    private renderPanel(tab: ScanTab, withTabs: boolean): TemplateResult {
        // Gate on an explicit action or the *first* load — a
        // background poll re-running with a result already in hand must not
        // flicker aria-busy on every tick.
        const busy = this.actionBusy || (this.controller.state === 'loading' && this.tabResult(tab) === null);
        const content = renderPanelContent(this.panelData(tab), this.panelCallbacks);
        return this.tabs.renderPanel({
            tab,
            withTablist: withTabs,
            busy,
            content,
        });
    }

    private panelData(tab: ScanTab): ScanPanelData {
        return {
            tab,
            result: this.tabResult(tab),
            demand: this.tabDemand(tab),
            running: this.isScanRunning(),
            scanId: this.effectiveScanId(),
            controllerState: this.controller.state,
            urlList: this.urlList ?? [],
            actionBusy: this.actionBusy,
            actionError: this.actionError,
            loadErrorDescription: this.loadErrorDescription(),
            aiAuditAvailable: this.aiAuditAvailable,
            aiAuditChecked: this.isAiAuditChecked(),
            reportBaseUrl: this.reportBaseUrl,
        };
    }

    private readonly panelCallbacks: ScanPanelCallbacks = {
        onTrigger: (tab: ScanTab): void => {
            void this.handleTrigger(tab);
        },
        onCancel: (): void => {
            void this.handleCancel();
        },
        onAiToggleChange: (checked: boolean): void => {
            this.aiAuditChecked = checked;
        },
        onReload: (): void => {
            void this.controller.reload();
        },
    };

    private loadErrorDescription(): string {
        return errorView(this.controller.error, 'mindfula11y.scan.error.getFailed').description;
    }

    private async handleTrigger(tab: ScanTab): Promise<void> {
        const demand = this.tabDemand(tab);
        if (demand === null || this.actionBusy) {
            return;
        }
        this.actionBusy = true;
        this.actionError = null;
        try {
            // The "started" announcement fires from onTransition once the new
            // scan first loads; the controller suppresses the attribute id.
            await this.controller.createScan(demand, this.aiAuditAvailable && this.isAiAuditChecked());
        } catch (error) {
            this.actionError = errorView(error, 'mindfula11y.scan.error.createFailed');
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
        // Clear any stale action error so a successful cancel does not leave a
        // previous failure pinned over the reloaded results.
        this.actionError = null;
        try {
            await this.controller.cancelScan();
        } catch (error) {
            // 409 = the scan already reached a terminal state; the controller's
            // reflecting reload shows the final result, so it is not an error to
            // surface (renderBody would pin an actionError over the results).
            if (!(error instanceof RequestError && error.status === 409)) {
                this.actionError = errorView(error, 'mindfula11y.scan.error.cancelFailed');
            }
        } finally {
            this.actionBusy = false;
        }
    }

    /**
     * Announces the session's transitions and dispatches the terminal events.
     * `previous === null` is a freshly created scan (the "started" transition);
     * otherwise a terminal status settled from an in-progress one.
     */
    private async handleTransition(previous: ScanStatus | null, result: ScanResult): Promise<void> {
        if (previous === null) {
            await this.announcer.announce(lll('mindfula11y.scan.announce.started'));
            return;
        }
        const scanId = this.effectiveScanId();
        if (result.status === ScanStatus.Completed) {
            dispatch(this, 'mindfula11y:scan:completed', { scanId, totalIssueCount: result.totalIssueCount });
            await this.announcer.announce(lll('mindfula11y.scan.announce.completed', result.totalIssueCount));
        } else if (result.status === ScanStatus.Canceled) {
            dispatch(this, 'mindfula11y:scan:canceled', { scanId });
            await this.announcer.announce(lll('mindfula11y.scan.announce.canceled'));
        } else if (result.status === ScanStatus.Failed) {
            await this.announcer.announce(lll('mindfula11y.scan.announce.failed'));
        }
    }
}

declare global {
    interface HTMLElementTagNameMap {
        'mindfula11y-scan': Scan;
    }
}
