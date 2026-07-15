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
import type { CreateScanDemand, ScanResult } from '../../lib/types.js';
import { AiAuditStatus, ScanStatus } from '../../lib/types.js';
import { withQueryParams } from '../../lib/url.js';
import type { ScanSessionState } from '../../service/scan-session-controller.js';
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
    actionError: { title: string; description: string } | null;
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

/** `true` when every entry of `urlList` is covered by the result's stored targets. */
function urlListCovered(urlList: string[], targets: string[]): boolean {
    const targetSet = new Set(targets);
    return urlList.every((url) => targetSet.has(url));
}

/** Builds a report download/view URL, preserving any query params `reportBaseUrl` already carries (e.g. the route's CSRF token). */
export function buildReportUrl(reportBaseUrl: string, scanId: string, format: 'html' | 'pdf'): string {
    return withQueryParams(reportBaseUrl, { scanId, format });
}

function renderProgressNotice(title: string, progressText: string | null): TemplateResult {
    return html`<mindfula11y-notice state="info">
        <typo3-backend-spinner slot="icon" size="small"></typo3-backend-spinner>
        <span>${title}${progressText !== null ? html` — ${progressText}` : nothing}</span>
    </mindfula11y-notice>`;
}

function renderStatus(result: ScanResult, isCrawl: boolean): TemplateResult {
    switch (result.status) {
        case ScanStatus.Pending:
            return renderProgressNotice(lll('mindfula11y.scan.status.pending'), null);
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
            return renderProgressNotice(lll('mindfula11y.scan.status.running'), progressText);
        }
        case ScanStatus.Analyzing: {
            const audit = result.aiAudit;
            const progressText =
                audit !== null && audit.tasksTotal > 0
                    ? lll('mindfula11y.scan.aiAudit.status.running', audit.tasksCompleted, audit.tasksTotal)
                    : null;
            return renderProgressNotice(lll('mindfula11y.scan.status.analyzing'), progressText);
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
        <a class="button" href=${buildReportUrl(reportBaseUrl, scanId, 'html')} target="_blank" rel="noreferrer">
            <typo3-backend-icon identifier="actions-document" size="small"></typo3-backend-icon>
            ${lll('mindfula11y.scan.report.html')}
            <span class="sr-only">${lll('mindfula11y.scan.opensNewTab')}</span>
        </a>
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
                <span>
                    <span class="notice-title">${lll('mindfula11y.scan.crawl.idle.title')}</span>
                    ${lll('mindfula11y.scan.crawl.idle.description')}
                </span>
            </mindfula11y-notice>`;
        }
        return nothing;
    }

    const urlList = data.urlList;
    const result = data.result;
    // The stored scan no longer covers the selected page scope.
    if (result !== null && result.mode !== 'crawl' && urlList.length > 0 && !urlListCovered(urlList, result.targets)) {
        return html`<mindfula11y-notice state="info">
            <span>
                <span class="notice-title">${lll('mindfula11y.scan.scopeExpanded')}</span>
                ${lll('mindfula11y.scan.scopeExpanded.description')}
            </span>
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
            <span>
                <span class="notice-title">${lll('mindfula11y.scan.multiPage.manualScan')}</span>
                ${lll('mindfula11y.scan.multiPage.manualScan.description')}
            </span>
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

    // Fix (a): a flat template string replaces the former nested ternary; all
    // four resulting keys (scan.start/refresh, scan.crawl.start/refresh) exist in the XLF.
    const triggerKey = `mindfula11y.scan.${tab === 'crawl' ? 'crawl.' : ''}${result !== null ? 'refresh' : 'start'}`;

    return html`<div class="actions">
        ${
            demand !== null
                ? html`<button type="button" class="button" data-action="trigger" ?disabled=${actionBusy || running} @click=${onTrigger}>
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
                ? html`<button type="button" class="button" data-action="cancel" ?disabled=${actionBusy} @click=${onCancel}>
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

function renderRequestError(data: ScanPanelData, onReload: () => void): TemplateResult | typeof nothing {
    if (data.actionError !== null) {
        return html`<mindfula11y-notice state="danger">
            <span>
                <span class="notice-title">${data.actionError.title}</span>
                ${data.actionError.description}
            </span>
        </mindfula11y-notice>`;
    }
    if (data.controllerState === 'error') {
        return html`<mindfula11y-notice state="danger">
            <span>
                <span class="notice-title">${lll('mindfula11y.scan.error.loading')}</span>
                ${data.loadErrorDescription}
            </span>
            <button type="button" class="button" @click=${onReload}>${lll('mindfula11y.scan.refresh')}</button>
        </mindfula11y-notice>`;
    }
    return nothing;
}

function renderBody(data: ScanPanelData): TemplateResult | typeof nothing {
    if (data.actionError !== null || data.controllerState === 'error') {
        return nothing;
    }
    const result = data.result;
    if (result === null) {
        if (data.controllerState === 'loading' && data.scanId !== '') {
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
    return html`${renderStatus(result, data.tab === 'crawl')} ${renderUpdatedAt(result)}
    ${
        result.status === ScanStatus.Completed && (result.totalIssueCount > 0 || hasAiReview)
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
        <div class="status-region" role="status">${renderRequestError(data, callbacks.onReload)}</div>
        ${renderBody(data)}`;
}
