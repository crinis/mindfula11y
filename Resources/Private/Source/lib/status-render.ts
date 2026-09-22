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

/**
 * Shared presentation for every status surface built on the `.notice` pattern
 * (styles/notice.css): the notice-state type, its single state→icon map, the
 * severity chip that inline/pill notices render (structure issues, findings)
 * and the viewport badges attached to structure nodes and findings alike.
 */

import { lll } from '@typo3/core/lit-helper.js';
import type { TemplateResult } from 'lit';
import { html, nothing } from 'lit';
import type { ErrorView } from '../service/request-error.js';
import type { StructureViewport } from './structure/types.js';
import type { ImpactSeverity } from './types.js';
import { IMPACT_ORDER } from './types.js';

/**
 * The single implementation of the §5 new-tab link contract: every link that
 * opens a new tab pairs `rel="noreferrer"` with a visually hidden
 * "(opens in a new tab)" hint. Divergence at call sites (a missed hint, a
 * different rel) is exactly what routing them through one helper prevents.
 */
export const renderExternalLink = (options: {
    href: string;
    content: TemplateResult | string;
    className?: string;
}): TemplateResult =>
    html`<a class=${options.className ?? nothing} href=${options.href} target="_blank" rel="noreferrer">
        ${options.content}
        <span class="sr-only">${lll('mindfula11y.general.opensNewTab')}</span>
    </a>`;

/** Visual state of the shared `.notice` pattern (styles/notice.css). */
export type NoticeState = 'info' | 'success' | 'warning' | 'serious' | 'danger';

export { IMPACT_ORDER };

/**
 * Maps an axe impact (also used by agent findings) to the notice palette —
 * one distinct color per severity step, matching the scanner's PDF/HTML
 * report (critical red, serious orange, moderate yellow). The dedicated
 * `serious` notice state exists for exactly this scale.
 */
const IMPACT_STATES: Record<ImpactSeverity, NoticeState> = {
    critical: 'danger',
    serious: 'serious',
    moderate: 'warning',
    minor: 'info',
};

export function impactState(impact: ImpactSeverity): NoticeState {
    return IMPACT_STATES[impact];
}

/**
 * The worst impact a counts record actually contains, or `undefined` when it
 * is all zeros — the single derivation every badge and status row reads its
 * notice state from. {@link worstSeverity} is the same rule over a list of
 * items that carry their own severity.
 */
export function worstImpact(counts: Record<ImpactSeverity, number>): ImpactSeverity | undefined {
    return IMPACT_ORDER.find((impact) => counts[impact] > 0);
}

/** The worst impact present among `items`, or `undefined` when there are none. */
export function worstSeverity<T>(
    items: readonly T[],
    severityOf: (item: T) => ImpactSeverity,
): ImpactSeverity | undefined {
    return IMPACT_ORDER.find((impact) => items.some((item) => severityOf(item) === impact));
}

/** Total finding count across all severities. */
export function totalCount(counts: Record<ImpactSeverity, number>): number {
    return IMPACT_ORDER.reduce((sum, impact) => sum + counts[impact], 0);
}

/**
 * Compact issue-count pill — the extension's single marker for "how many",
 * on tab labels and on every status notice.
 *
 * Pass `srText` where the bare number would be ambiguous (a tab carrying one
 * badge per severity): the number is then hidden and the spelled-out text
 * read instead. Where the surrounding label already names what is counted
 * ("Page structure — issues found"), omit it and the number reads as-is.
 */
export function renderCountBadge(state: NoticeState, count: number, srText?: string): TemplateResult {
    return html`<span class="notice count" data-state=${state} data-variant="pill"
        >${
            srText === undefined
                ? count
                : html`<span aria-hidden="true">${count}</span><span class="sr-only">${srText}</span>`
        }</span
    >`;
}

/**
 * Inline-label key naming a severity for assistive technology — the state
 * icons are aria-hidden (core hardcodes that in Icon::render()), so text
 * must carry the severity distinction.
 */
export function severityLabelKey(severity: ImpactSeverity): string {
    return `mindfula11y.severity.${severity}`;
}

/** Single source of every notice-state → TYPO3 icon identifier mapping in the extension. */
const NOTICE_STATE_ICONS: Record<NoticeState, string> = {
    info: 'status-dialog-information',
    success: 'status-dialog-ok',
    warning: 'status-dialog-warning',
    serious: 'status-dialog-warning',
    danger: 'status-dialog-error',
};

/** Maps a notice state to its TYPO3 icon identifier. */
export function noticeStateIcon(state: NoticeState): string {
    return NOTICE_STATE_ICONS[state];
}

/**
 * A label prefixed with its severity for assistive technology: the state
 * icons are aria-hidden by TYPO3 core (Icon::render() hardcodes it) and two
 * impacts share one icon, so without this text the severity would be carried
 * by colour alone. The a11y invariant lives here once for every surface that
 * states a severity — the inline/pill chips and the widget status rows.
 */
export function renderSeverityLabel(
    severity: ImpactSeverity,
    labelKey: string,
    ...labelArguments: Array<string | number>
): TemplateResult {
    return html`<span
        ><span class="sr-only">${lll(severityLabelKey(severity))}: </span>${lll(labelKey, ...labelArguments)}</span
    >`;
}

/**
 * Renders a severity's icon + label for inline/pill notices.
 */
export function renderSeverityChip(
    severity: ImpactSeverity,
    labelKey: string,
    ...labelArguments: Array<string | number>
): TemplateResult {
    return html`<typo3-backend-icon
            identifier=${noticeStateIcon(impactState(severity))}
            size="small"
        ></typo3-backend-icon>
        ${renderSeverityLabel(severity, labelKey, ...labelArguments)}`;
}

/**
 * One findings-summary pill (styles/findings.css) — the single implementation
 * of the `.notice finding` markup contract both summary rows render: a pill
 * that doubles as navigation into the first occurrence below it. Callers
 * supply only what the pill says; the wrapper, palette and jump behaviour are
 * the same everywhere.
 */
export const renderFindingPill = (
    severity: ImpactSeverity,
    onSelect: () => void,
    content: TemplateResult,
): TemplateResult =>
    html`<li>
        <button
            type="button"
            class="notice finding"
            data-state=${impactState(severity)}
            data-variant="pill"
            @click=${onSelect}
        >
            ${content}
        </button>
    </li>`;

/**
 * Neutral badges naming the viewports a node or finding applies to. The
 * pill styling is a visual convention, so a screen-reader-only prefix names
 * what the badges mean before they are read.
 */
export const renderViewportBadges = (viewports: readonly StructureViewport[]): TemplateResult =>
    html`<span class="viewports">
        <span class="sr-only">${lll('mindfula11y.structure.viewports')}: </span>
        ${viewports.map(
            (viewport) => html`<span class="viewport">${lll(`mindfula11y.structure.viewport.${viewport}`)}</span>`,
        )}
    </span>`;

/**
 * The title + description body every block notice slots in — the single
 * implementation of the `<span><span class="notice-title">…</span>…</span>`
 * markup contract styles/notice.css relies on.
 */
export const renderNoticeBody = (view: { title: string; description: string }): TemplateResult =>
    html`<span>
        <span class="notice-title">${view.title}</span>
        ${view.description}
    </span>`;

/**
 * The save button every inline-editable card (altless-file-reference,
 * interactive-label-finding) renders: spinner-or-icon swap while saving, plus
 * the aria-disabled busy/dirty guard — a real `disabled` would strand
 * keyboard focus on `<body>` for the whole async window, so callers keep the
 * button focusable and rely on their own click-handler guard instead.
 */
export const renderSaveButton = (options: {
    saving: boolean;
    disabled: boolean;
    labelKey: string;
    onClick: () => void;
    /** Icon shown while idle (swapped for a spinner while saving). Defaults to the save icon. */
    icon?: string;
}): TemplateResult =>
    html`<button type="button" class="button" aria-disabled=${options.disabled ? 'true' : nothing} @click=${options.onClick}>
        ${
            options.saving
                ? html`<typo3-backend-spinner size="small"></typo3-backend-spinner>`
                : html`<typo3-backend-icon identifier=${options.icon ?? 'actions-save'} size="small"></typo3-backend-icon>`
        }
        ${lll(options.labelKey)}
    </button>`;

/**
 * The feedback region below a save button: the failed-save notice (via
 * {@link renderNoticeBody}) takes priority over the transient "saved"
 * success notice, matching every save flow's own error > success precedence.
 */
export const renderSaveStatusRegion = (options: {
    error: ErrorView | null;
    saved: boolean;
    successLabelKey: string;
}): TemplateResult =>
    html`<div class="status-region" role="status">
        ${
            options.error !== null
                ? html`<mindfula11y-notice class="status" state="danger">${renderNoticeBody(options.error)}</mindfula11y-notice>`
                : options.saved
                  ? html`<mindfula11y-notice class="status" state="success"
                        ><span>${lll(options.successLabelKey)}</span></mindfula11y-notice
                    >`
                  : nothing
        }
    </div>`;

/**
 * The indicator of a native disclosure's `<summary>` — the markup half of the
 * shared disclosure chrome (styles/disclosure.css), which replaces the UA
 * marker and flips this icon via the native `open` attribute. Pass `slot`
 * when the summary's content is a slotting element (the structure widget's
 * status row puts the marker into `<mindfula11y-notice>`'s trailing slot).
 */
export const renderDisclosureMarker = (slot: string | null = null): TemplateResult =>
    html`<typo3-backend-icon
        slot=${slot ?? nothing}
        class="marker"
        identifier="actions-chevron-down"
        size="small"
    ></typo3-backend-icon>`;

/**
 * In-progress status notice: the shared "spinner in the icon slot" contract
 * every busy row uses (scan status, structure analysis), with an optional
 * progress suffix (crawl page counts, AI-audit tasks).
 */
export const renderProgressNotice = (title: string, progressText: string | null = null): TemplateResult =>
    html`<mindfula11y-notice state="info">
        <typo3-backend-spinner slot="icon" size="small"></typo3-backend-spinner>
        <span>${title}${progressText !== null ? html` — ${progressText}` : nothing}</span>
    </mindfula11y-notice>`;

/** Spinner + label placeholder shown while a view's initial load is running (styles/placeholder.css). */
export const renderLoadingPlaceholder = (label: string): TemplateResult =>
    html`<div class="placeholder">
        <typo3-backend-spinner size="default"></typo3-backend-spinner>
        <span>${label}</span>
    </div>`;
