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

import Notification from '@typo3/backend/notification.js';
import { lll } from '@typo3/core/lit-helper.js';
import type { CSSResultGroup, PropertyValues, TemplateResult } from 'lit';
import { html, LitElement, nothing } from 'lit';
import { property, state } from 'lit/decorators.js';
import { live } from 'lit/directives/live.js';
import '@typo3/backend/element/icon-element.js';
import '@typo3/backend/element/spinner-element.js';
import { scrollIntoViewCentered } from '../../lib/dom.js';
import { noticeState, renderSeverityChip, renderViewportBadges } from '../../lib/status-render.js';
import type { StructureError, StructureViewport } from '../../lib/structure/types.js';
import type { RecordReference } from '../../lib/types.js';
import { dispatch } from '../../lib/types.js';
import { RecordService } from '../../service/record-service.js';
import { baseStyles } from '../../styles/base-styles.js';
import noticeStyles from '../../styles/notice.css.js';
import structureViewStyles from '../../styles/structure-view.css.js';
import viewportStyles from '../../styles/viewport.css.js';

/** Node shape the analyzers share, generic over the concrete node type. */
export interface StructureViewNode<T> {
    id: string;
    record: RecordReference | null;
    viewports: StructureViewport[];
    errors: StructureError[];
    children: T[];
}

/**
 * Shared behavior of the two structure views (heading tree, landmark
 * schematic): the analyzer-fed properties, page/node issue rendering, the
 * focus plumbing that restores focus after saves and jumps to findings, and
 * the DataHandler save flow dispatching `mindfula11y:structure:changed` for
 * the container to re-analyze. Subclasses render the node markup;
 * `styles/structure-view.css` owns the matching shared chrome.
 */
export abstract class StructureView<T extends StructureViewNode<T>> extends LitElement {
    /**
     * Shared foundation + the chrome/viewport/notice modules both structure
     * views adopt; each subclass appends its own component stylesheet:
     * `[...StructureView.viewStyles, componentStyles]`.
     */
    protected static readonly viewStyles: CSSResultGroup[] = [
        ...baseStyles,
        noticeStyles,
        structureViewStyles,
        viewportStyles,
    ];

    @property({ attribute: false }) nodes: T[] = [];
    @property({ attribute: false }) pageErrors: StructureError[] = [];

    @state() protected busyNodeIds: ReadonlySet<string> = new Set();

    private readonly recordService: RecordService = new RecordService();
    private pendingFocusId: string = '';
    /** `data-control` value of the just-saved select, so focus returns to that
     * exact control rather than the row's first `controlSelector` match — a
     * row can carry both an own-level and a child-level control. */
    private pendingFocusControl: string = '';

    /** Selector of a node row's primary control, preferred over the edit link as focus target. */
    protected abstract readonly controlSelector: string;
    /** Label key of the empty state's title; `<key>.description` carries the body. */
    protected abstract readonly emptyLabelKey: string;
    /**
     * Common root of this view's XLF keys (`mindfula11y.structure.headings` |
     * `mindfula11y.structure.landmarks`); `.edit`, `.edit.locked` and
     * `.error.store` are derived from it. Keys that don't share a suffix
     * across both views (e.g. the empty-state title) stay explicit via
     * `emptyLabelKey`.
     */
    protected abstract readonly labelPrefix: string;
    /** Renders the nested node markup (heading tree / landmark map). */
    protected abstract renderNodes(nodes: T[]): TemplateResult;

    override render(): TemplateResult {
        return html`<div class="view">
            ${this.pageErrors.map((error) => this.renderIssue(error, true))}
            ${this.nodes.length === 0 ? this.renderEmpty() : this.renderNodes(this.nodes)}
        </div>`;
    }

    protected override updated(changed: PropertyValues<this>): void {
        if (changed.has('nodes') && this.pendingFocusId !== '') {
            const nodeId = this.pendingFocusId;
            const controlName = this.pendingFocusControl;
            this.pendingFocusId = '';
            this.pendingFocusControl = '';
            this.focusControl(nodeId, controlName);
        }
    }

    /**
     * Moves focus to the control of the given node (used after saves and by the
     * container). `controlName` — a `data-control` value — prefers that exact
     * control over the row's first `controlSelector` match; a row can carry
     * both an own-level and a child-level control, and after saving one the
     * other must not steal focus. Omit it for the unchanged default behavior
     * (container jump / finding focus paths).
     */
    focusControl(nodeId: string, controlName: string = ''): void {
        const row = this.renderRoot.querySelector<HTMLElement>(`[data-node-id="${CSS.escape(nodeId)}"]`);
        if (row !== null) {
            this.focusRow(row, controlName);
        }
    }

    /** Focuses the first element affected by the given error key; page-level issues focus their message. */
    focusFirstIssue(errorKey: string): void {
        if (this.pageErrors.some((error) => error.key === errorKey)) {
            const issue = this.renderRoot.querySelector<HTMLElement>('[data-scope="page"]');
            if (issue !== null) {
                issue.focus();
                scrollIntoViewCentered(issue);
            }
            return;
        }
        const node = this.findNode(this.nodes, (candidate) => candidate.errors.some((error) => error.key === errorKey));
        if (node !== null) {
            this.focusControl(node.id);
        }
    }

    private findNode(nodes: T[], matches: (node: T) => boolean): T | null {
        for (const node of nodes) {
            if (matches(node)) {
                return node;
            }
            const match = this.findNode(node.children, matches);
            if (match !== null) {
                return match;
            }
        }
        return null;
    }

    protected renderNodeIssues(node: T): TemplateResult {
        return html`<div class="issues" id="issue-${node.id}">
            ${node.errors.map((error) => this.renderIssue(error, false))}
        </div>`;
    }

    protected renderIssue(error: StructureError, pageScope: boolean): TemplateResult {
        return html`<p
            class="notice issue"
            data-state=${noticeState(error.severity)}
            data-variant="inline"
            data-scope=${pageScope ? 'page' : 'node'}
            tabindex=${pageScope ? '-1' : nothing}
        >
            ${renderSeverityChip(error.severity, error.key)} ${renderViewportBadges(error.viewports)}
        </p>`;
    }

    protected renderEmpty(): TemplateResult {
        return html`<p class="empty">
            <typo3-backend-icon identifier="status-dialog-information" size="small"></typo3-backend-icon>
            <span class="empty-title">${lll(this.emptyLabelKey)}</span>
            <span>${lll(`${this.emptyLabelKey}.description`)}</span>
        </p>`;
    }

    /** `issue-${node.id}` when the node has errors, else `nothing` — shared `aria-describedby` derivation. */
    protected describedby(node: T): string | typeof nothing {
        return node.errors.length > 0 ? `issue-${node.id}` : nothing;
    }

    /** Type guard narrowing `node.record` to non-null, for {@link renderEditLink} call sites. */
    protected hasRecord(node: T): node is T & { record: RecordReference } {
        return node.record !== null;
    }

    /** Edit link to the record's FormEngine field. Callers narrow via {@link hasRecord} before calling. */
    protected renderEditLink(node: T & { record: RecordReference }, label: string): TemplateResult {
        return html`<a class="edit" data-control="edit" href=${node.record.editLink}>
            <typo3-backend-icon identifier="actions-open" size="small"></typo3-backend-icon>
            <span class="sr-only">${lll(`${this.labelPrefix}.edit`)}: ${label}</span>
        </a>`;
    }

    /**
     * Busy spinner shown next to a row's controls while its save is in flight.
     * The spinner icon is a purely visual cue, so screen-reader-only text
     * carries the state (completion is announced by the container's existing
     * pre-rendered status region after re-analysis).
     */
    protected renderBusySpinner(node: T): TemplateResult | typeof nothing {
        return this.busyNodeIds.has(node.id)
            ? html`<typo3-backend-spinner size="small"></typo3-backend-spinner>
                  <span class="sr-only">${lll('mindfula11y.structure.saving')}</span>`
            : nothing;
    }

    /**
     * Icon + sr-only "not editable" text (key `<labelPrefix>.edit.locked`) for
     * a locked control chip; `content` is the visible value rendered before
     * the icon (e.g. `H2` or a role display name). The caller keeps the
     * wrapping element (class + `aria-describedby` differ per view).
     */
    protected renderLockedChip(content: unknown): TemplateResult {
        return html`${content}
            <typo3-backend-icon identifier="actions-lock" size="small"></typo3-backend-icon>
            <span class="sr-only">${lll(`${this.labelPrefix}.edit.locked`)}</span>`;
    }

    /**
     * Editable value `<select>` shared by both views: binds `.value=${live(…)}`
     * so a failed save reverts visually through the next re-render (triggered
     * by the node leaving `busyNodeIds` in `saveNodeValue`'s `finally`) rather than a
     * manual DOM mutation, which can disagree with the template after
     * unrelated re-renders. The select is deliberately NOT disabled while its
     * save is in flight: disabling the focused element blurs it to the
     * document body, stranding keyboard/screen-reader users for the whole
     * save window (and permanently on failure) — re-entry is guarded in
     * {@link saveNodeValue} instead.
     */
    protected renderValueSelect(
        node: T,
        opts: {
            id: string;
            className: string;
            ariaLabel?: string;
            ariaLabelledby?: string;
            currentValue: string;
            options: Record<string, string>;
            /** Save target when the select edits a column other than `node.record` (e.g. a container's child-type column). */
            record?: RecordReference;
            /** Overrides the issue-derived aria-describedby (e.g. a purpose note for the child-type select). */
            describedby?: string;
        },
    ): TemplateResult {
        return html`<select
            id=${opts.id}
            class=${opts.className}
            data-control=${opts.className}
            aria-label=${opts.ariaLabel ?? nothing}
            aria-labelledby=${opts.ariaLabelledby ?? nothing}
            aria-describedby=${opts.describedby ?? this.describedby(node)}
            .value=${live(opts.currentValue)}
            @change=${(event: Event): void => {
                void this.saveNodeValue(
                    node,
                    event.currentTarget as HTMLSelectElement,
                    opts.currentValue,
                    opts.record ?? node.record,
                );
            }}
        >
            ${Object.entries(opts.options).map(
                ([value, label]) =>
                    html`<option value=${value} ?selected=${value === opts.currentValue}>${label}</option>`,
            )}
        </select>`;
    }

    /**
     * Persists a control's value via DataHandler and reports the change so the
     * container re-analyzes (focus returns to the node once new nodes arrive).
     * On failure a toast names the error (`<labelPrefix>.error.store` +
     * `.description`); removing the node from `busyNodeIds` below re-renders the row, and
     * `renderValueSelect`'s `live()` binding reverts the select to
     * `currentValue` without a manual `select.value =` mutation.
     */
    protected async saveNodeValue(
        node: T,
        select: HTMLSelectElement,
        currentValue: string,
        record: RecordReference | null = node.record,
    ): Promise<void> {
        const value = select.value;
        // The re-entry guard replaces disabling the select (see
        // renderValueSelect): a repeat change on the SAME row while its save
        // is in flight is dropped and reverted by the live() binding on the
        // next re-render. Tracking is per node — edits on other rows proceed.
        if (this.busyNodeIds.has(node.id) || record === null || value === currentValue) {
            return;
        }
        this.busyNodeIds = new Set(this.busyNodeIds).add(node.id);
        try {
            await this.recordService.updateField(record, value);
            this.pendingFocusId = node.id;
            this.pendingFocusControl = select.dataset.control ?? '';
            dispatch(this, 'mindfula11y:structure:changed', {
                nodeId: node.id,
                tableName: record.tableName,
                uid: record.uid,
                columnName: record.columnName,
                value,
            });
        } catch {
            const errorKey = `${this.labelPrefix}.error.store`;
            Notification.error(lll(errorKey), lll(`${errorKey}.description`));
        } finally {
            const busyNodeIds = new Set(this.busyNodeIds);
            busyNodeIds.delete(node.id);
            this.busyNodeIds = busyNodeIds;
        }
    }

    /**
     * Focuses a node row's control (or the row itself) and flashes the
     * highlight. `preferredControl` — a `data-control` value — is tried before
     * the `controlSelector`/edit-link fallback chain (see {@link focusControl}).
     */
    protected focusRow(row: HTMLElement, preferredControl: string = ''): void {
        const control =
            (preferredControl !== ''
                ? row.querySelector<HTMLElement>(`[data-control="${CSS.escape(preferredControl)}"]`)
                : null) ??
            row.querySelector<HTMLElement>(this.controlSelector) ??
            row.querySelector<HTMLElement>('[data-control="edit"]');
        if (control !== null) {
            control.focus();
        } else {
            row.setAttribute('tabindex', '-1');
            row.focus();
        }
        scrollIntoViewCentered(row);
        row.setAttribute('data-highlight', '');
        row.addEventListener('animationend', () => row.removeAttribute('data-highlight'), { once: true });
    }
}
