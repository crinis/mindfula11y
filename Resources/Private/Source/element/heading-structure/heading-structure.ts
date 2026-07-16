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
import type { CSSResultGroup, TemplateResult } from 'lit';
import { html, nothing } from 'lit';
import { customElement } from 'lit/decorators.js';
import { repeat } from 'lit/directives/repeat.js';
import '@typo3/backend/element/icon-element.js';
import { noticeState, renderSeverityChip, renderViewportBadges } from '../../lib/status-render.js';
import type { HeadingNode, StructureError } from '../../lib/structure/types.js';
import { StructureErrorSeverity } from '../../lib/structure/types.js';
import { StructureView } from '../../lib/structure-view.js';
import componentStyles from './heading-structure.css.js';

/**
 * Heading structure of the analyzed page: one aligned level chip per heading
 * (editable select, locked display or relation jump button), finding cues
 * inside the affected row and dashed placeholder rows for skipped levels.
 *
 * The analyzer's tree renders FLATTENED into a single list (see
 * flattenTree()): every row announces its own heading level, so nested lists
 * would only double-book — and, around skips and containers, contradict —
 * that information; indentation is a purely visual, level-derived aid.
 * Everything renders in this component's single shadow root so every
 * aria-describedby pairing stays inside one root. The analyzer properties,
 * issue rendering, focus plumbing and save flow come from the shared
 * `StructureView` base.
 */
@customElement('mindfula11y-heading-structure')
export class HeadingStructure extends StructureView<HeadingNode> {
    static override styles: CSSResultGroup[] = [...StructureView.viewStyles, componentStyles];

    protected override readonly controlSelector: string = '[data-control="level"], [data-control="child-level"]';
    protected override readonly emptyLabelKey: string = 'mindfula11y.structure.headings.noHeadings';
    protected override readonly labelPrefix: string = 'mindfula11y.structure.headings';

    /**
     * Relation ids of headings actually present in the analyzed document,
     * rebuilt per render: a suppressed container heading registers a relation
     * without leaving a DOM node, so a jump affordance targeting it would
     * dead-end in an "Ancestor not found" notice — such targets get no jump.
     */
    private knownRelationIds: ReadonlySet<string> = new Set();

    protected override renderNodes(nodes: HeadingNode[]): TemplateResult {
        this.knownRelationIds = this.collectRelationIds(nodes, new Set());
        return this.renderTree(nodes);
    }

    private collectRelationIds(nodes: HeadingNode[], ids: Set<string>): Set<string> {
        for (const node of nodes) {
            if (node.relationId !== '') {
                ids.add(node.relationId);
            }
            this.collectRelationIds(node.children, ids);
        }
        return ids;
    }

    private relationTargetExists(node: HeadingNode): boolean {
        const targetId = node.relation?.targetRelationId ?? '';
        return targetId !== '' && this.knownRelationIds.has(targetId);
    }

    private renderTree(nodes: HeadingNode[]): TemplateResult {
        return html`<ol class="tree">
            ${repeat(
                this.flattenTree(nodes, 0),
                (item) => item.key,
                (item) => item.template,
            )}
        </ol>`;
    }

    /**
     * Pre-order flattening of the analyzer's tree into ONE list: nesting depth
     * would double-book (and in skip/container/pre-H1 cases contradict) the
     * heading level every row already announces, so hierarchy is conveyed by
     * the per-row level text and the indentation is purely visual, derived
     * from the heading level itself. Missing-level placeholders precede their
     * skipping heading at the absent levels' indents.
     */
    private flattenTree(nodes: HeadingNode[], parentIndent: number): Array<{ key: string; template: TemplateResult }> {
        const items: Array<{ key: string; template: TemplateResult }> = [];
        for (const node of nodes) {
            for (let missingLevel = node.level - node.skippedLevels; missingLevel < node.level; missingLevel++) {
                items.push({
                    key: `${node.id}#missing-${missingLevel}`,
                    template: this.renderPlaceholderItem(node, missingLevel),
                });
            }
            const indent = node.kind === 'container' ? this.containerIndent(node, parentIndent) : node.level;
            items.push({ key: node.id, template: this.renderItem(node, indent) });
            items.push(...this.flattenTree(node.children, indent));
        }
        return items;
    }

    /**
     * A container row's indent expresses its parental role, one step above the
     * level its children derive: the explicitly stored child type when set,
     * else the automatic derivation base — its own (unrendered) level, or the
     * tree parent when it has none. Its stored level itself is communicated by
     * the row's level select, not by indentation.
     */
    private containerIndent(node: HeadingNode, parentIndent: number): number {
        const childType = /^h([1-6])$/.exec(node.childTypeRecord?.storedValue ?? '');
        if (childType !== null) {
            return Number.parseInt(childType[1] ?? '0', 10) - 1;
        }
        return node.level > 0 ? node.level : parentIndent + 1;
    }

    private renderItem(node: HeadingNode, indent: number): TemplateResult {
        return html`<li class="node" style=${`--mindfula11y-heading-structure-indent: ${indent - 1}`}>
            ${this.renderRow(node)}
        </li>`;
    }

    /**
     * Dashed stand-in row for one skipped heading level, indented at the
     * missing level's own step. The level directly above the skipping heading
     * carries the `skip-…` id its describedby references.
     */
    private renderPlaceholderItem(node: HeadingNode, missingLevel: number): TemplateResult {
        return html`<li
            class="node"
            data-placeholder
            style=${`--mindfula11y-heading-structure-indent: ${missingLevel - 1}`}
        >
            <div class="row">
                <span class="level" data-missing>H${missingLevel}</span>
                <span class="text" id=${missingLevel === node.level - 1 ? `skip-${node.id}` : nothing}
                    >${lll('mindfula11y.structure.headings.error.skippedLevel.inline', missingLevel)}</span
                >
            </div>
        </li>`;
    }

    /**
     * Errors rendered as cues inside the affected row itself. Every node
     * finding renders in-row except an ordinary heading's skipped level: its
     * missing-level placeholder row (see renderNode()) already IS the finding,
     * placed where the missing level belongs, so an in-row chip would only
     * duplicate it. Container rows keep their attributed skip in-row — they
     * never render placeholders.
     */
    private inRowErrors(node: HeadingNode): StructureError[] {
        if (node.kind === 'container') {
            return node.errors;
        }
        return node.errors.filter((error) => error.key !== 'mindfula11y.structure.headings.error.skippedLevel');
    }

    /**
     * References everything describing the row's state: the in-row error cues
     * (`issue-…`) and, for an unattributed skip, the innermost missing-level
     * placeholder's message (`skip-…`) — so the select announces "Missing
     * heading level N …" instead of a generic chip.
     */
    protected override describedby(node: HeadingNode): string | typeof nothing {
        const ids: string[] = [];
        if (this.inRowErrors(node).length > 0) {
            ids.push(`issue-${node.id}`);
        }
        if (node.skippedLevels > 0) {
            ids.push(`skip-${node.id}`);
        }
        return ids.length > 0 ? ids.join(' ') : nothing;
    }

    private renderRow(node: HeadingNode): TemplateResult {
        const isContainer = node.kind === 'container';
        const label =
            node.label !== ''
                ? node.label
                : lll(
                      isContainer
                          ? 'mindfula11y.structure.headings.container'
                          : 'mindfula11y.structure.headings.unlabeled',
                  );
        const editable = node.record !== null && node.record.editLink !== '';
        const inRowErrors = this.inRowErrors(node);
        const hasErrorSeverity = inRowErrors.some((error) => error.severity === StructureErrorSeverity.Error);

        return html`<div
            class="row"
            data-node-id=${node.id}
            data-relation-id=${node.relationId}
            ?data-container=${isContainer}
            ?data-error=${hasErrorSeverity}
            ?data-warning=${inRowErrors.length > 0 && !hasErrorSeverity}
        >
            ${this.renderLevelControl(node, label, editable)}
            ${
                isContainer
                    ? html`<span class="text">
                          <span class="container-badge">
                              <typo3-backend-icon identifier="overlay-hidden" size="small"></typo3-backend-icon>
                              ${label}
                          </span>
                      </span>`
                    : html`<span class="text" ?data-empty=${node.label === ''}>${label}</span>`
            }
            ${this.renderChildLevelControl(node, label)}
            ${
                inRowErrors.length > 0
                    ? html`<span class="row-issues" id="issue-${node.id}"
                          >${inRowErrors.map((error) => this.renderRowIssue(node, error))}</span
                      >`
                    : nothing
            }
            ${renderViewportBadges(node.viewports)}
            ${editable && this.hasRecord(node) ? this.renderEditLink(node, label) : nothing}
            ${this.renderBusySpinner(node)}
        </div>`;
    }

    /**
     * A finding rendered as a cue inside the affected row. Same inline notice
     * as the base renderIssue(), but the finding's viewport badges are omitted
     * when they merely repeat the row's own badges rendered right beside it —
     * they only appear when the finding is viewport-specific.
     */
    private renderRowIssue(node: HeadingNode, error: StructureError): TemplateResult {
        const sameViewports =
            error.viewports.length === node.viewports.length &&
            error.viewports.every((viewport) => node.viewports.includes(viewport));
        return html`<p class="notice issue" data-state=${noticeState(error.severity)} data-variant="inline" data-scope="node">
            ${renderSeverityChip(error.severity, error.key)}
            ${sameViewports ? nothing : renderViewportBadges(error.viewports)}
        </p>`;
    }

    private renderLevelControl(node: HeadingNode, label: string, editable: boolean): TemplateResult {
        // No availableTypes means the column is missing from the record type's showitem
        // (enrichment strips it) — an optionless select would be broken, fall through.
        if (editable && node.record !== null && Object.keys(node.availableTypes).length > 0) {
            const currentValue = node.record.storedValue ?? `h${node.level}`;
            return this.renderValueSelect(node, {
                id: `level-${node.id}`,
                className: 'level',
                ariaLabel: `${lll('mindfula11y.structure.headings.type')}: ${label}`,
                currentValue,
                options: this.buildLevelOptions(node.availableTypes, currentValue, node.level > 0 ? node.level : null),
            });
        }

        if (node.relation !== null) {
            const relationLabel =
                node.relation.kind === 'ancestor'
                    ? lll('mindfula11y.structure.headings.relation.descendant')
                    : lll('mindfula11y.structure.headings.relation.sibling');
            if (!this.relationTargetExists(node)) {
                // The referenced container is not in the document at all — show
                // the derived state without a dead-end jump.
                return html`<span class="level" data-relation>
                    H${node.level}
                    <typo3-backend-icon identifier="actions-link" size="small"></typo3-backend-icon>
                    <span class="sr-only">${relationLabel}</span>
                </span>`;
            }
            return html`<button
                type="button"
                class="level"
                data-relation
                data-control="level"
                aria-label="H${node.level} — ${relationLabel}. ${lll('mindfula11y.structure.headings.relation.jump')}"
                aria-describedby=${this.describedby(node)}
                @click=${(): void => this.handleRelationJump(node)}
            >
                H${node.level}
                <typo3-backend-icon identifier="actions-link" size="small"></typo3-backend-icon>
            </button>`;
        }

        return html`<span class="level" data-locked>
            ${this.renderLockedChip(node.level > 0 ? `H${node.level}` : '—')}
        </span>`;
    }

    /**
     * Select writing the container-owned child-type column, rendered on the row
     * of the element that stores it: changing the children's level is visibly an
     * action on the container, and every derived row stays read-only with a jump
     * here. Omitted without editable coordinates (no perms, column not in the
     * record type's showitem, custom table without the column).
     */
    private renderChildLevelControl(node: HeadingNode, label: string): TemplateResult | typeof nothing {
        const record = node.childTypeRecord;
        if (record === null || record.editLink === '' || Object.keys(node.availableChildTypes).length === 0) {
            return nothing;
        }
        const currentValue = record.storedValue ?? '';
        const childLevel = node.level > 0 ? node.level + 1 : null;
        // One wrapping unit: the visible label must never separate from its
        // select when the row wraps on narrow widths.
        return html`<span class="child-control">
            <span class="child-label" aria-hidden="true">${lll('mindfula11y.structure.headings.childType')}</span>
            ${this.renderValueSelect(node, {
                id: `child-level-${node.id}`,
                className: 'child-level',
                ariaLabel: `${lll('mindfula11y.structure.headings.childType')}: ${label}`,
                currentValue,
                options: this.buildLevelOptions(node.availableChildTypes, currentValue, childLevel),
                record,
                describedby: `child-level-note-${node.id}`,
            })}
            <span id="child-level-note-${node.id}" class="sr-only"
                >${lll('mindfula11y.structure.headings.childType.applies')}</span
            >
        </span>`;
    }

    /**
     * Maps a level/child-type option map to display labels via levelOptionLabel(),
     * shared by the level and child-type selects (they differ only in the source
     * map and the effective level passed to the "automatic" option).
     */
    private buildLevelOptions(
        available: Record<string, string>,
        currentValue: string,
        effectiveLevel: number | null,
    ): Record<string, string> {
        return Object.fromEntries(
            Object.entries(available).map(([type, typeLabel]) => [
                type,
                this.levelOptionLabel(type, typeLabel, currentValue, effectiveLevel),
            ]),
        );
    }

    /**
     * Select option labels: h1-h6 as compact uppercase levels; other values keep
     * their translated label. A currently selected "automatic" option carries the
     * effective level (own level for the level select, own level + 1 for the
     * child-type select), so the level stays visible in the collapsed select.
     */
    private levelOptionLabel(
        type: string,
        typeLabel: string,
        currentValue: string,
        effectiveLevel: number | null,
    ): string {
        if (/^h[1-6]$/.test(type)) {
            return type.toUpperCase();
        }
        if (type === '' && currentValue === '' && effectiveLevel !== null) {
            return `${typeLabel} (H${effectiveLevel})`;
        }
        return typeLabel;
    }

    private handleRelationJump(node: HeadingNode): void {
        const targetId = node.relation?.targetRelationId ?? '';
        const target =
            targetId === ''
                ? null
                : this.renderRoot.querySelector<HTMLElement>(`[data-relation-id="${CSS.escape(targetId)}"]`);
        if (target === null) {
            Notification.warning(
                lll('mindfula11y.structure.headings.relation.notFound'),
                lll('mindfula11y.structure.headings.relation.notFound.description'),
            );
            return;
        }
        this.focusRow(target);
    }
}

declare global {
    interface HTMLElementTagNameMap {
        'mindfula11y-heading-structure': HeadingStructure;
    }
}
