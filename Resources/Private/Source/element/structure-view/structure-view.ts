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
import { impactState, renderSeverityChip, renderViewportBadges } from '../../lib/status-render.js';
import type { StructureError, StructureNodeBase } from '../../lib/structure/types.js';
import type { RecordReference } from '../../lib/types.js';
import { dispatch } from '../../lib/types.js';
import { RecordApi } from '../../service/record-api.js';
import { baseStyles } from '../../styles/base-styles.js';
import chipStyles from '../../styles/chip.css.js';
import noticeStyles from '../../styles/notice.css.js';
import structureViewStyles from '../../styles/structure-view.css.js';
import surfaceStyles from '../../styles/surface.css.js';
import viewportStyles from '../../styles/viewport.css.js';

/** Minor presentation adjustments supported by the shared inline issue renderer. */
export interface StructureIssueRenderOptions {
    pageScope?: boolean;
    labelKey?: string;
    labelArguments?: Array<string | number>;
    showViewports?: boolean;
}

/** Layout-specific wrapper configuration for a group of shared inline issues. */
export interface StructureIssueGroupOptions {
    className: string;
    id?: string | undefined;
    /** Per-error presentation adjustments; every issue renders with the defaults when omitted. */
    issueOptions?: ((error: StructureError) => StructureIssueRenderOptions) | undefined;
}

interface StructureFocusOptions {
    preferredControl?: string | undefined;
    fallbackToOtherControls?: boolean | undefined;
}

/** A row control, by the row's `data-node-id` and the control's `data-control`. */
interface StructureControlRef {
    nodeId: string;
    control: string;
}

/** Where focus returns once the re-analysed nodes of a save arrive; see {@link StructureView.updated}. */
interface PendingFocusRestore {
    /** The control that held focus when the save started. */
    target: StructureControlRef;
    /** The saved control: the fallback when the target's row is gone. */
    saved: StructureControlRef;
}

/**
 * Shared behavior of the two structure views (heading tree, landmark
 * schematic): the analyzer-fed properties, page/node issue rendering, the
 * focus plumbing that restores focus after saves and jumps to findings, and
 * the DataHandler save flow dispatching `mindfula11y:structure:changed` for
 * the container to re-analyze. Subclasses render the node markup;
 * `styles/structure-view.css` owns the matching shared chrome.
 */
export abstract class StructureView<T extends StructureNodeBase<T>> extends LitElement {
    /**
     * Shared foundation + the chrome/viewport/notice/chip/surface modules both
     * structure views adopt; each subclass appends its own component stylesheet:
     * `[...StructureView.viewStyles, componentStyles]`.
     */
    protected static readonly viewStyles: CSSResultGroup[] = [
        ...baseStyles,
        noticeStyles,
        chipStyles,
        surfaceStyles,
        structureViewStyles,
        viewportStyles,
    ];

    @property({ attribute: false }) nodes: T[] = [];
    @property({ attribute: false }) pageErrors: StructureError[] = [];

    @state() protected busyNodeIds: ReadonlySet<string> = new Set();

    private readonly recordApi: RecordApi = new RecordApi();
    private pendingFocus: PendingFocusRestore | null = null;
    /** The editor acted outside this view since the last save started; see {@link updated}. */
    private editorMovedOn: boolean = false;
    private watchingEditor: boolean = false;
    /**
     * Document-level (capture) signal that the editor moved on: a pointer
     * press or a focus move outside this view. Focus entering a frame is
     * the frame's doing, not the editor's — the hidden analysis renders call
     * focus() on load — and a click inside a frame never reaches this
     * document; a frame the editor really moved into keeps focus, which the
     * "focus is lost" condition respects anyway.
     */
    private readonly noteEditorMove = (event: Event): void => {
        const path = event.composedPath();
        if (path.includes(this) || (event.type === 'focusin' && path[0] instanceof HTMLIFrameElement)) {
            return;
        }
        this.editorMovedOn = true;
    };

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
            ${this.renderPageErrors()}
            ${this.nodes.length === 0 ? this.renderEmpty() : this.renderNodes(this.nodes)}
        </div>`;
    }

    /** Page-level findings have no affected node; subclasses may place them in their own schematic. */
    protected renderPageErrors(): TemplateResult | typeof nothing {
        return this.pageErrors.length > 0
            ? html`${this.pageErrors.map((error) => this.renderIssue(error, { pageScope: true }))}`
            : nothing;
    }

    override disconnectedCallback(): void {
        super.disconnectedCallback();
        this.pendingFocus = null;
        this.stopWatchingEditor();
    }

    /**
     * After a save, the nodes of the re-analysis arrive seconds later. Focus
     * returns to the control that held it when the save started only when
     * it got lost meanwhile without the editor moving on: it sits on the
     * body (the re-render replaced the focused control, or something else
     * took focus and went away), this document still has focus, and no
     * pointer press or focus move outside this view happened since the save
     * started. Where focus survives, or the editor went elsewhere — another
     * control, the page, another frame — it stays where it is.
     */
    protected override updated(changed: PropertyValues<this>): void {
        if (changed.has('nodes') && this.pendingFocus !== null) {
            const { target, saved } = this.pendingFocus;
            const movedOn = this.editorMovedOn;
            this.pendingFocus = null;
            if (this.busyNodeIds.size === 0) {
                this.stopWatchingEditor();
            }
            if (!movedOn && this.isFocusLost()) {
                const restore = this.findRow(target.nodeId) !== null ? target : saved;
                this.focusControl(restore.nodeId, restore.control);
            }
        }
    }

    private watchEditor(): void {
        this.editorMovedOn = false;
        if (!this.watchingEditor) {
            this.ownerDocument.addEventListener('pointerdown', this.noteEditorMove, true);
            this.ownerDocument.addEventListener('focusin', this.noteEditorMove, true);
            this.watchingEditor = true;
        }
    }

    private stopWatchingEditor(): void {
        if (this.watchingEditor) {
            this.ownerDocument.removeEventListener('pointerdown', this.noteEditorMove, true);
            this.ownerDocument.removeEventListener('focusin', this.noteEditorMove, true);
            this.watchingEditor = false;
        }
    }

    /** Focus fell back to the body (or nowhere) of a document that still has focus. */
    private isFocusLost(): boolean {
        const doc = this.ownerDocument;
        const active = doc.activeElement;
        return doc.hasFocus() && (active === null || active === doc.body || active === doc.documentElement);
    }

    /**
     * The row control holding focus inside this view, if any. Walks down
     * from the document's focused element through the shadow roots (this
     * view may itself sit in another component's root) instead of asking
     * this view's root directly: the document is the authority on what holds
     * focus and never reports an element whose tree was removed — happy-dom's
     * `ShadowRoot.activeElement` does, and throws on it.
     */
    private focusedControl(): StructureControlRef | null {
        let active: Element | null = this.ownerDocument.activeElement;
        while (active !== null && active !== this) {
            active = active.shadowRoot?.activeElement ?? null;
        }
        const focused = active === this ? (this.shadowRoot?.activeElement ?? null) : null;
        const nodeId = focused?.closest<HTMLElement>('[data-node-id]')?.dataset.nodeId ?? '';
        const control = focused?.getAttribute('data-control') ?? '';
        return nodeId !== '' && control !== '' ? { nodeId, control } : null;
    }

    private findRow(nodeId: string): HTMLElement | null {
        return this.renderRoot.querySelector<HTMLElement>(`[data-node-id="${CSS.escape(nodeId)}"]`);
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
        const row = this.findRow(nodeId);
        if (row !== null) {
            this.focusRow(row, { preferredControl: controlName });
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
        return this.renderIssueGroup(node.errors, { className: 'issues', id: `issue-${node.id}` });
    }

    /** One group renderer for every layout that presents shared inline issues. */
    protected renderIssueGroup(errors: StructureError[], options: StructureIssueGroupOptions): TemplateResult {
        return html`<div class=${options.className} id=${options.id ?? nothing}>
            ${errors.map((error) => this.renderIssue(error, options.issueOptions?.(error)))}
        </div>`;
    }

    protected renderIssue(error: StructureError, options: StructureIssueRenderOptions = {}): TemplateResult {
        const pageScope = options.pageScope ?? false;
        return html`<p
            class="notice issue"
            data-state=${impactState(error.severity)}
            data-variant="inline"
            data-scope=${pageScope ? 'page' : 'node'}
            tabindex=${pageScope ? '-1' : nothing}
        >
            ${renderSeverityChip(error.severity, options.labelKey ?? error.key, ...(options.labelArguments ?? []))}
            ${options.showViewports === false ? nothing : renderViewportBadges(error.viewports)}
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
            class="chip ${opts.className}"
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
        // Sampled before the request: the editor may move on while it runs.
        const saved: StructureControlRef = { nodeId: node.id, control: select.dataset.control ?? '' };
        const target = this.focusedControl() ?? saved;
        this.busyNodeIds = new Set(this.busyNodeIds).add(node.id);
        this.watchEditor();
        try {
            await this.recordApi.updateField(record, value);
            this.pendingFocus = { target, saved };
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
            if (this.pendingFocus === null && busyNodeIds.size === 0) {
                this.stopWatchingEditor();
            }
        }
    }

    /**
     * Focuses a node row's control and flashes the highlight. If no control is
     * available, a subclass can nominate a native containing element through
     * `data-focus-fallback`; its value references the visible content that
     * temporarily names the fallback while focused. A preferred `data-control`
     * value is tried before the `controlSelector`/edit-link fallback chain;
     * callers can disable that chain when only the exact control owns the
     * relationship being followed (see {@link focusControl}).
     */
    protected focusRow(row: HTMLElement, options: StructureFocusOptions = {}): void {
        const preferredControl = options.preferredControl ?? '';
        const preferred =
            preferredControl === ''
                ? null
                : row.querySelector<HTMLElement>(`[data-control="${CSS.escape(preferredControl)}"]`);
        const control =
            preferred ??
            (options.fallbackToOtherControls === false
                ? null
                : (row.querySelector<HTMLElement>(this.controlSelector) ??
                  row.querySelector<HTMLElement>('[data-control="edit"]')));
        if (control !== null) {
            control.focus();
        } else {
            const fallback = row.closest<HTMLElement>('[data-focus-fallback]') ?? row;
            const labelId = fallback.dataset.focusFallback ?? '';
            fallback.setAttribute('tabindex', '-1');
            if (labelId !== '') {
                fallback.setAttribute('aria-labelledby', labelId);
            }
            fallback.focus();
            fallback.addEventListener(
                'blur',
                () => {
                    fallback.removeAttribute('tabindex');
                    fallback.removeAttribute('aria-labelledby');
                },
                { once: true },
            );
        }
        scrollIntoViewCentered(row);
        row.setAttribute('data-highlight', '');
        row.addEventListener('animationend', () => row.removeAttribute('data-highlight'), { once: true });
    }
}
