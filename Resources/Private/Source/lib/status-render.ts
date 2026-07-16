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
import { html } from 'lit';
import type { ImpactSeverity } from '../service/scan/types.js';
import { StructureErrorSeverity, type StructureViewport } from './structure/types.js';

/** Visual state of the shared `.notice` pattern (styles/notice.css). */
export type NoticeState = 'info' | 'success' | 'warning' | 'serious' | 'danger';

/** Worst-first axe impact order, also used for chip and grouping order. */
export const IMPACT_ORDER: readonly ImpactSeverity[] = ['critical', 'serious', 'moderate', 'minor'];

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

/** Maps a structure finding's severity to the notice state presenting it. */
export function noticeState(severity: StructureErrorSeverity): NoticeState {
    return severity === StructureErrorSeverity.Error ? 'danger' : 'warning';
}

/**
 * Inline-label key naming a severity for assistive technology — the state
 * icons are aria-hidden (core hardcodes that in Icon::render()), so text
 * must carry the error/warning distinction.
 */
export function severityLabelKey(severity: StructureErrorSeverity): string {
    return severity === StructureErrorSeverity.Error ? 'mindfula11y.severity.error' : 'mindfula11y.severity.warning';
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
 * Renders a severity's icon + label for inline/pill notices: the icon is
 * aria-hidden by TYPO3 core (Icon::render() hardcodes it), so a
 * screen-reader-only severity prefix carries the error/warning distinction
 * before the label — this a11y invariant lives here once for every caller.
 */
export function renderSeverityChip(severity: StructureErrorSeverity, labelKey: string): TemplateResult {
    return html`<typo3-backend-icon
            identifier=${noticeStateIcon(noticeState(severity))}
            size="small"
        ></typo3-backend-icon>
        <span><span class="sr-only">${lll(severityLabelKey(severity))}: </span>${lll(labelKey)}</span>`;
}

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

/** Spinner + label placeholder shown while a view's initial load is running (styles/placeholder.css). */
export const renderLoadingPlaceholder = (label: string): TemplateResult =>
    html`<div class="placeholder">
        <typo3-backend-spinner size="default"></typo3-backend-spinner>
        <span>${label}</span>
    </div>`;
