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
import type { PropertyValues, TemplateResult } from 'lit';
import { html, LitElement } from 'lit';
import { property, state } from 'lit/decorators.js';
import '@typo3/backend/element/icon-element.js';
import { RecordService } from '../service/record-service.js';
import { scrollIntoViewCentered } from './dom.js';
import type { RecordReference, StructureError } from './types.js';
import { noticeState, StructureErrorSeverity, severityLabelKey } from './types.js';

/** Node shape the analyzers share, generic over the concrete node type. */
export interface StructureViewNode<T> {
    id: string;
    record: RecordReference | null;
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
    @property({ attribute: false }) nodes: T[] = [];
    @property({ attribute: false }) pageErrors: StructureError[] = [];

    @state() protected busyNodeId: string = '';

    private readonly recordService: RecordService = new RecordService();
    private pendingFocusId: string = '';

    /** Selector of a node row's primary control, preferred over the edit link as focus target. */
    protected abstract readonly controlSelector: string;
    /** Label key of the empty state's title; `<key>.description` carries the body. */
    protected abstract readonly emptyLabelKey: string;
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
            this.pendingFocusId = '';
            this.focusControl(nodeId);
        }
    }

    /** Moves focus to the control of the given node (used after saves and by the container). */
    focusControl(nodeId: string): void {
        const row = this.renderRoot.querySelector<HTMLElement>(`[data-node-id="${CSS.escape(nodeId)}"]`);
        if (row !== null) {
            this.focusRow(row);
        }
    }

    /** Focuses the first element affected by the given error key; page-level issues focus their message. */
    focusFirstIssue(errorKey: string): void {
        if (this.pageErrors.some((error) => error.key === errorKey)) {
            const issue = this.renderRoot.querySelector<HTMLElement>('[data-scope="page"]');
            if (issue !== null) {
                issue.setAttribute('tabindex', '-1');
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
        >
            <typo3-backend-icon
                identifier=${error.severity === StructureErrorSeverity.Error ? 'status-dialog-error' : 'status-dialog-warning'}
                size="small"
            ></typo3-backend-icon>
            <span><span class="sr-only">${lll(severityLabelKey(error.severity))}: </span>${lll(error.key)}</span>
        </p>`;
    }

    protected renderEmpty(): TemplateResult {
        return html`<p class="empty">
            <typo3-backend-icon identifier="status-dialog-information" size="small"></typo3-backend-icon>
            <span class="empty-title">${lll(this.emptyLabelKey)}</span>
            <span>${lll(`${this.emptyLabelKey}.description`)}</span>
        </p>`;
    }

    /**
     * Persists a control's value via DataHandler and reports the change so the
     * container re-analyzes (focus returns to the node once new nodes arrive).
     * On failure the select reverts and a toast names the error
     * (`<errorKey>` + `.description`).
     */
    protected async saveNodeValue(node: T, event: Event, currentValue: string, errorKey: string): Promise<void> {
        const select = event.currentTarget as HTMLSelectElement;
        const value = select.value;
        if (node.record === null || value === currentValue) {
            return;
        }
        this.busyNodeId = node.id;
        try {
            await this.recordService.updateField(node.record, value);
            this.pendingFocusId = node.id;
            this.dispatchEvent(
                new CustomEvent('mindfula11y:structure:changed', {
                    bubbles: true,
                    composed: true,
                    detail: {
                        nodeId: node.id,
                        tableName: node.record.tableName,
                        uid: node.record.uid,
                        columnName: node.record.columnName,
                        value,
                    },
                }),
            );
        } catch {
            select.value = currentValue;
            Notification.error(lll(errorKey), lll(`${errorKey}.description`));
        } finally {
            this.busyNodeId = '';
        }
    }

    /** Focuses a node row's control (or the row itself) and flashes the highlight. */
    protected focusRow(row: HTMLElement): void {
        const control =
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
