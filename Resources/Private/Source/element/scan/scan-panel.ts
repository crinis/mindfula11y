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
import type { TemplateResult } from 'lit';
import { html, nothing } from 'lit';
import '@typo3/backend/element/icon-element.js';
import '@typo3/backend/element/spinner-element.js';
import { scanStatusView } from '../../lib/scan/status-view.js';
import type { CreateScanDemand, ScanResult } from '../../lib/scan/types.js';
import { ScanStatus } from '../../lib/scan/types.js';
import { urlListCoveredByTargets } from '../../lib/scan/url.js';
import {
    renderExternalLink,
    renderLoadingPlaceholder,
    renderNoticeBody,
    renderProgressNotice,
    renderStatusRegion,
} from '../../lib/status-render.js';
import { withQueryParams } from '../../lib/url.js';
import type { ErrorView } from '../../service/request-error.js';
import type { ScanSessionState } from '../../service/scan/session-controller.js';
import '../notice/notice.js';
import '../scan-results/scan-results.js';

export type ScanTab = 'scan' | 'crawl';

/**
 * Presentational input for one tab's panel body — every field the render
 * functions below need, gathered by the host (`<mindfula11y-scan>`) from its
 * properties/`ScanSessionController` state. Purely input-driven, following
 * `scan-results.ts`'s philosophy: this module owns no state and triggers no
 * side effects, it only maps data + callbacks to markup.
 */
export interface ScanPanelData {
    tab: ScanTab;
    /** This tab's stored result (scan or crawl view of the same session), or null. */
    result: ScanResult | null;
    /** This tab's create/refresh demand, or null when the tab has no manual trigger. */
    demand: CreateScanDemand | null;
    /** Whether the primary scan is in progress — gates both tabs' actions/hints, matching prior behavior. */
    running: boolean;
    /** The effective scan id (`ScanSessionController.effectiveScanId()`). */
    scanId: string;
    controllerState: ScanSessionState;
    urlList: string[];
    actionBusy: boolean;
    actionError: ErrorView | null;
    /** Description shown for a controller `'error'` state (fallback errorView already resolved by the host). */
    loadErrorDescription: string;
    aiAuditAvailable: boolean;
    aiAuditChecked: boolean;
    reportBaseUrl: string;
}

/** Callbacks the host wires up; every handler already knows which tab it belongs to via `ScanPanelData.tab`. */
export interface ScanPanelCallbacks {
    onTrigger: (tab: ScanTab) => void;
    onCancel: () => void;
    onAiToggleChange: (checked: boolean) => void;
    onReload: () => void;
}

/** Builds a report download/view URL, preserving any query params `reportBaseUrl` already carries (e.g. the route's CSRF token). */
export function buildReportUrl(reportBaseUrl: string, scanId: string, format: 'html' | 'pdf'): string {
    return withQueryParams(reportBaseUrl, { scanId, format });
}

/** Progress detail layered onto the in-progress statuses (crawl page counts, AI-audit tasks). */
function progressDetail(result: ScanResult, isCrawl: boolean): string | null {
    if (result.status === ScanStatus.Running) {
        if (!isCrawl || result.progress === null) {
            return null;
        }
        let progressText =
            result.progress.pagesDiscovered === 0
                ? lll('mindfula11y.scan.progress.discovering')
                : lll('mindfula11y.scan.progress.pages', result.progress.pagesScanned, result.progress.pagesDiscovered);
        if (result.progress.pagesFailed > 0) {
            progressText += ` — ${lll('mindfula11y.scan.progress.pagesFailed', result.progress.pagesFailed)}`;
        }
        return progressText;
    }
    if (result.status === ScanStatus.Analyzing) {
        const audit = result.aiAudit;
        return audit !== null && audit.tasksTotal > 0
            ? lll('mindfula11y.scan.aiAudit.status.running', audit.tasksCompleted, audit.tasksTotal)
            : null;
    }
    return null;
}

function renderStatus(result: ScanResult, isCrawl: boolean): TemplateResult {
    const view = scanStatusView(result);
    if (view.spinner === true) {
        return renderProgressNotice(lll(view.labelKey), progressDetail(result, isCrawl));
    }
    const description =
        view.descriptionKey !== undefined
            ? lll(view.descriptionKey)
            : view.pagesFailed !== undefined
              ? lll('mindfula11y.scan.pagesFailed', view.pagesFailed.failed, view.pagesFailed.total)
              : null;
    return html`<mindfula11y-notice state=${view.state} count=${view.count ?? nothing}>
        ${
            description === null
                ? html`<span>${lll(view.labelKey)}</span>`
                : renderNoticeBody({ title: lll(view.labelKey), description })
        }
    </mindfula11y-notice>`;
}

/**
 * The completion announcement: the issue total, or — when no page could be
 * loaded — that, since "0 issues found" would claim a clean result. Pages
 * that failed in a partially loaded scan are announced along with the total.
 */
export function completedAnnouncement(result: ScanResult): string {
    const view = scanStatusView(result);
    if (view.state === 'danger') {
        return lll(view.labelKey);
    }
    const announcement = lll('mindfula11y.scan.announce.completed', result.totalIssueCount);
    return view.pagesFailed === undefined
        ? announcement
        : `${announcement} ${lll('mindfula11y.scan.pagesFailed', view.pagesFailed.failed, view.pagesFailed.total)}`;
}

function renderUpdatedAt(result: ScanResult): TemplateResult | typeof nothing {
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

/** Download/view links for the stored report, closing the results. */
function renderReportLinks(result: ScanResult, scanId: string, reportBaseUrl: string): TemplateResult | typeof nothing {
    if (result.status !== ScanStatus.Completed || scanId === '' || reportBaseUrl === '') {
        return nothing;
    }
    return html`<div class="actions">
        ${renderExternalLink({
            className: 'button',
            href: buildReportUrl(reportBaseUrl, scanId, 'html'),
            content: html`<typo3-backend-icon identifier="actions-document" size="small"></typo3-backend-icon>
                ${lll('mindfula11y.scan.report.html')}`,
        })}
        <a class="button" href=${buildReportUrl(reportBaseUrl, scanId, 'pdf')} download="accessibility-report.pdf">
            <typo3-backend-icon identifier="actions-download" size="small"></typo3-backend-icon>
            ${lll('mindfula11y.scan.report.pdf')}
        </a>
    </div>`;
}

function renderHints(data: ScanPanelData): TemplateResult | typeof nothing {
    if (data.tab === 'crawl') {
        if (data.result === null && !data.running && !data.actionBusy) {
            return html`<mindfula11y-notice state="info">
                ${renderNoticeBody({
                    title: lll('mindfula11y.scan.crawl.idle.title'),
                    description: lll('mindfula11y.scan.crawl.idle.description'),
                })}
            </mindfula11y-notice>`;
        }
        return nothing;
    }

    const urlList = data.urlList;
    const result = data.result;
    // The stored scan no longer covers the selected page scope.
    if (
        result !== null &&
        result.mode !== 'crawl' &&
        urlList.length > 0 &&
        !urlListCoveredByTargets(urlList, result.targets)
    ) {
        return html`<mindfula11y-notice state="info">
            ${renderNoticeBody({
                title: lll('mindfula11y.scan.scopeExpanded'),
                description: lll('mindfula11y.scan.scopeExpanded.description'),
            })}
        </mindfula11y-notice>`;
    }
    if (
        result === null &&
        !data.actionBusy &&
        data.controllerState !== 'loading' &&
        data.demand !== null &&
        urlList.length > 1
    ) {
        return html`<mindfula11y-notice state="info">
            ${renderNoticeBody({
                title: lll('mindfula11y.scan.multiPage.manualScan'),
                description: lll('mindfula11y.scan.multiPage.manualScan.description'),
            })}
        </mindfula11y-notice>`;
    }
    return nothing;
}

function renderActions(
    data: ScanPanelData,
    onTrigger: () => void,
    onCancel: () => void,
): TemplateResult | typeof nothing {
    const { demand, result, running, scanId, actionBusy, tab } = data;

    if (demand === null && !running) {
        return nothing;
    }
    // Mirrors the host's trigger guard (Scan.isTriggerBlocked()): while the
    // session still loads — the first getScan after a create, the initial
    // load of a stored scan, an auto-create — `running` cannot know yet that
    // a scan is under way, and a second create would orphan it.
    const triggerBlocked = actionBusy || running || data.controllerState === 'loading';

    // A flat template string keeps the key legible; all
    // four resulting keys (scan.start/refresh, scan.crawl.start/refresh) exist in the XLF.
    const triggerKey = `mindfula11y.scan.${tab === 'crawl' ? 'crawl.' : ''}${result !== null ? 'refresh' : 'start'}`;

    return html`<div class="actions">
        ${
            demand !== null
                ? html`<button type="button" class="button" data-action="trigger" aria-disabled=${triggerBlocked ? 'true' : nothing} @click=${onTrigger}>
                      ${
                          actionBusy
                              ? html`<typo3-backend-spinner size="small"></typo3-backend-spinner>`
                              : html`<typo3-backend-icon
                                    identifier=${result !== null ? 'actions-refresh' : 'actions-search'}
                                    size="small"
                                ></typo3-backend-icon>`
}
                      ${lll(actionBusy ? 'mindfula11y.scan.processing' : triggerKey)}
                  </button>`
                : nothing
        }
        ${
            running && scanId !== ''
                ? html`<button type="button" class="button" data-action="cancel" aria-disabled=${actionBusy ? 'true' : nothing} @click=${onCancel}>
                      <typo3-backend-icon identifier="actions-close" size="small"></typo3-backend-icon>
                      ${lll('mindfula11y.scan.cancel')}
                  </button>`
                : nothing
        }
    </div>`;
}

function renderAiToggle(data: ScanPanelData, onChange: (checked: boolean) => void): TemplateResult | typeof nothing {
    if (!data.aiAuditAvailable || data.demand === null) {
        return nothing;
    }
    return html`<span class="toggle">
        <input
            type="checkbox"
            id="ai-toggle-${data.tab}"
            class="checkbox"
            .checked=${data.aiAuditChecked}
            ?disabled=${data.actionBusy || data.running}
            aria-describedby="ai-toggle-description-${data.tab}"
            @change=${(event: Event): void => onChange((event.currentTarget as HTMLInputElement).checked)}
        />
        <label class="toggle-label" for="ai-toggle-${data.tab}">${lll('mindfula11y.scan.aiAudit.toggle')}</label>
        <span class="toggle-description" id="ai-toggle-description-${data.tab}"
            >${lll('mindfula11y.scan.aiAudit.toggle.description')}</span
        >
    </span>`;
}

function renderRequestError(data: ScanPanelData): TemplateResult | typeof nothing {
    if (data.actionError !== null) {
        return html`<mindfula11y-notice state="danger">
            ${renderNoticeBody(data.actionError)}
        </mindfula11y-notice>`;
    }
    if (data.controllerState === 'error') {
        return html`<mindfula11y-notice state="danger">
            ${renderNoticeBody({ title: lll('mindfula11y.scan.error.loading'), description: data.loadErrorDescription })}
        </mindfula11y-notice>`;
    }
    return nothing;
}

/** Rendered outside the status region — see `renderStatusRegion`. */
function renderErrorActions(data: ScanPanelData, onReload: () => void): TemplateResult | typeof nothing {
    if (data.controllerState !== 'error') {
        return nothing;
    }
    return html`<button type="button" class="button" @click=${onReload}>${lll('mindfula11y.scan.refresh')}</button>`;
}

function renderBody(data: ScanPanelData): TemplateResult | typeof nothing {
    // A failed load keeps the last result in view below its error notice: a
    // failing poll of a running scan must not take the results away.
    if (data.actionError !== null) {
        return nothing;
    }
    const result = data.result;
    if (result === null) {
        if (data.controllerState === 'loading' && data.scanId !== '') {
            return renderLoadingPlaceholder(lll('mindfula11y.scan.loading'));
        }
        return nothing;
    }

    // The AI review can carry findings even when axe found nothing — or
    // explain why a requested review did not run — so the results element
    // renders whenever a review was requested (MindfulAPI reports `aiAudit`
    // for exactly those scans) or axe found issues.
    const aiReviewRequested = result.aiAudit !== null;
    return html`${renderStatus(result, data.tab === 'crawl')} ${renderUpdatedAt(result)}
    ${
        result.status === ScanStatus.Completed && (result.totalIssueCount > 0 || aiReviewRequested)
            ? html`<mindfula11y-scan-results .result=${result}></mindfula11y-scan-results>`
            : nothing
    }
    ${renderReportLinks(result, data.scanId, data.reportBaseUrl)}`;
}

/** Renders one tab's full panel body — the content `lib/tabs.ts`'s `renderTabPanel` wraps. */
export function renderPanelContent(data: ScanPanelData, callbacks: ScanPanelCallbacks): TemplateResult {
    return html`<p class="description">${lll(`mindfula11y.scan.tab.${data.tab}.description`)}</p>
        ${renderHints(data)}
        ${renderAiToggle(data, callbacks.onAiToggleChange)}
        ${renderActions(data, () => callbacks.onTrigger(data.tab), callbacks.onCancel)}
        ${renderStatusRegion(renderRequestError(data))}
        ${renderErrorActions(data, callbacks.onReload)}
        ${renderBody(data)}`;
}
