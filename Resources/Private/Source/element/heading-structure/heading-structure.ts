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
import { impactState, renderViewportBadges } from '../../lib/status-render.js';
import type { HeadingNode, StructureError } from '../../lib/structure/types.js';
import { HEADING_ERROR_KEYS } from '../../lib/structure/types.js';
import { IMPACT_ORDER } from '../../lib/types.js';
import {
    type StructureIssueOptionsProvider,
    type StructureIssueRenderOptions,
    StructureView,
} from '../structure-view/structure-view.js';
import componentStyles from './heading-structure.css.js';

type HeadingIssueKind = 'page' | 'missing-level';

interface HeadingItemOptions {
    indent?: number | undefined;
    issueKind?: HeadingIssueKind | undefined;
    focusLabelId?: string | undefined;
}

interface IssueItemOptions extends HeadingItemOptions {
    issueKind: HeadingIssueKind;
    issueId?: string | undefined;
    issueOptions?: StructureIssueRenderOptions | undefined;
}

interface HeadingRowOptions {
    errors: StructureError[];
    content?: TemplateResult | undefined;
    nodeId?: string | undefined;
    relationId?: string | undefined;
    container?: boolean | undefined;
    childControl?: boolean | undefined;
    issueId?: string | undefined;
    issueOptions?: StructureIssueOptionsProvider | undefined;
}

/**
 * Heading structure of the analyzed page: one aligned level chip per heading
 * (editable select, locked display or relation jump button), finding cues
 * inside the affected row and issue-only placeholder rows for skipped levels.
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

    /** Heading page errors render as rows in the same flat list as node errors. */
    protected override renderPageErrors(): typeof nothing {
        return nothing;
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
            ${this.pageErrors.map((error) => this.renderPageIssueItem(error))}
            ${repeat(
                this.flattenTree(nodes, 0),
                (item) => item.key,
                (item) => item.template,
            )}
        </ol>`;
    }

    /** A page-level heading finding has no affected node, so it becomes its own unindented issue row. */
    private renderPageIssueItem(error: StructureError): TemplateResult {
        return this.renderIssueItem(error, {
            issueKind: 'page',
            issueOptions: { pageScope: true },
        });
    }

    /** One consistent list row for issue-only cases. */
    private renderIssueItem(error: StructureError, options: IssueItemOptions): TemplateResult {
        return this.renderListItem(
            this.renderHeadingRow({
                errors: [error],
                issueId: options.issueId,
                issueOptions: options.issueOptions,
            }),
            options,
        );
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
        return this.renderListItem(this.renderRow(node), {
            indent,
            focusLabelId: this.rowLabelId(node.id),
        });
    }

    /** Visible row content that names the native list-item focus fallback. */
    private rowLabelId(nodeId: string): string {
        return `heading-row-label-${nodeId}`;
    }

    /** The single list-item shell used by every ordinary and issue-only row. */
    private renderListItem(content: TemplateResult, options: HeadingItemOptions): TemplateResult {
        return html`<li
            class="node"
            data-issue-kind=${options.issueKind ?? nothing}
            data-focus-fallback=${options.focusLabelId ?? nothing}
            style=${
                options.indent === undefined ? nothing : `--mindfula11y-heading-structure-indent: ${options.indent - 1}`
            }
        >
            ${content}
        </li>`;
    }

    /**
     * Issue-only stand-in row for one skipped heading level, indented at the
     * missing level's own step. The level directly above the skipping heading
     * carries the `skip-…` id its describedby references.
     */
    private renderPlaceholderItem(node: HeadingNode, missingLevel: number): TemplateResult {
        const error = node.errors.find((candidate) => candidate.key === HEADING_ERROR_KEYS.skippedLevel) ?? {
            key: HEADING_ERROR_KEYS.skippedLevel,
            severity: 'moderate' as const,
            nodeId: node.id,
            viewports: node.viewports,
        };
        return this.renderIssueItem(error, {
            issueKind: 'missing-level',
            indent: missingLevel,
            ...(missingLevel === node.level - 1 ? { issueId: `skip-${node.id}` } : {}),
            issueOptions: {
                labelKey: 'mindfula11y.structure.headings.error.skippedLevel.inline',
                labelArguments: [missingLevel],
            },
        });
    }

    /**
     * Errors rendered as cues inside the affected row itself. Every node
     * finding renders in-row except an ordinary heading's skipped level: its
     * missing-level placeholder row (see renderPlaceholderItem()) already IS the finding,
     * placed where the missing level belongs, so an in-row chip would only
     * duplicate it. Container rows keep their attributed skip in-row — they
     * never render placeholders.
     */
    private inRowErrors(node: HeadingNode): StructureError[] {
        if (node.kind === 'container') {
            return node.errors;
        }
        return node.errors.filter((error) => error.key !== HEADING_ERROR_KEYS.skippedLevel);
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
        const hasChildControl = this.hasChildLevelControl(node);
        const inRowErrors = this.inRowErrors(node);

        const content = html`<div class="heading" id=${this.rowLabelId(node.id)}>
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
            </div>
            ${this.renderChildLevelControl(node, label)}
            <div class="meta" ?data-child-control=${hasChildControl}>
                ${renderViewportBadges(node.viewports)}
                <span class="actions">
                    ${editable && this.hasRecord(node) ? this.renderEditLink(node, label) : nothing}
                    ${this.renderBusySpinner(node)}
                </span>
            </div>`;

        return this.renderHeadingRow({
            errors: inRowErrors,
            content,
            nodeId: node.id,
            relationId: node.relationId,
            container: isContainer,
            childControl: hasChildControl,
            issueId: `issue-${node.id}`,
            issueOptions: (error: StructureError) => ({ showViewports: !this.hasSameViewports(error, node) }),
        });
    }

    /** The single row shell used by issue-only, ordinary heading and hidden-container rows. */
    private renderHeadingRow(options: HeadingRowOptions): TemplateResult {
        // The row surface takes the notice state of its worst finding, so the
        // border accent always matches the inline severity notices it carries.
        const worst = IMPACT_ORDER.find((impact) => options.errors.some((error) => error.severity === impact));
        return html`<div
            class="row"
            data-node-id=${options.nodeId ?? nothing}
            data-relation-id=${options.relationId ?? nothing}
            ?data-container=${options.container ?? false}
            ?data-child-control=${options.childControl ?? false}
            data-issue-state=${worst === undefined ? nothing : impactState(worst)}
        >
            ${options.content ?? nothing}
            ${
                options.errors.length > 0
                    ? this.renderIssueGroup(options.errors, {
                          className: 'row-issues',
                          id: options.issueId,
                          issueOptions: options.issueOptions,
                      })
                    : nothing
            }
        </div>`;
    }

    /** Whether issue viewport badges would merely repeat the affected row's badges. */
    private hasSameViewports(error: StructureError, node: HeadingNode): boolean {
        return (
            error.viewports.length === node.viewports.length &&
            error.viewports.every((viewport) => node.viewports.includes(viewport))
        );
    }

    private renderLevelControl(node: HeadingNode, label: string, editable: boolean): TemplateResult {
        // A relation owns the level even if unexpected record metadata is
        // present on the derived heading: never offer a misleading local edit.
        if (node.relation !== null) {
            const hasTarget = this.relationTargetExists(node);
            const content = this.renderRelationLevelContent(node, hasTarget);
            if (!hasTarget) {
                // The referenced publisher is not in this document, so retain
                // the explicit read-only explanation without a dead-end action.
                return html`<span class="level" data-relation data-relation-kind=${node.relation.kind}>${content}</span>`;
            }
            return html`<button
                type="button"
                class="level"
                data-relation
                data-relation-kind=${node.relation.kind}
                data-control="level"
                aria-describedby=${this.describedby(node)}
                @click=${(): void => this.handleRelationJump(node)}
            >
                ${content}
            </button>`;
        }

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

        return html`<span class="level" data-locked>
            ${this.renderLockedChip(node.level > 0 ? this.headingLevelLabel(node.level) : '—')}
        </span>`;
    }

    /** Shared visible and screen-reader explanation for both relation variants. */
    private renderRelationLevelContent(node: HeadingNode, hasTarget: boolean): TemplateResult {
        const relationKey =
            node.relation?.kind === 'ancestor'
                ? 'mindfula11y.structure.headings.relation.descendant'
                : 'mindfula11y.structure.headings.relation.sibling';
        return html`${this.headingLevelLabel(node.level)}
            <span class="relation-label">${lll(relationKey)}</span>
            <typo3-backend-icon
                identifier=${hasTarget ? 'actions-link' : 'actions-lock'}
                size="small"
                aria-hidden="true"
            ></typo3-backend-icon>
            <span class="sr-only">
                ${lll('mindfula11y.structure.headings.relation.readonly')}
                ${hasTarget ? lll('mindfula11y.structure.headings.relation.jump') : nothing}
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
        if (!this.hasChildLevelControl(node)) {
            return nothing;
        }
        const record = node.childTypeRecord;
        if (record === null) {
            return nothing;
        }
        const currentValue = record.storedValue ?? '';
        const childLevel = node.level > 0 ? node.level + 1 : null;
        // One field group: the visible label stays associated with the select
        // while the group changes from a mobile stack to a wider inline row.
        return html`<span class="child-control">
            <label id="child-level-label-${node.id}" class="child-label" for="child-level-${node.id}"
                >${lll('mindfula11y.structure.headings.childType')}</label
            >
            <span id="child-level-context-${node.id}" class="sr-only">: ${label}</span>
            ${this.renderValueSelect(node, {
                id: `child-level-${node.id}`,
                className: 'child-level',
                ariaLabelledby: `child-level-label-${node.id} child-level-context-${node.id}`,
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

    private hasChildLevelControl(node: HeadingNode): boolean {
        return (
            node.childTypeRecord !== null &&
            node.childTypeRecord.editLink !== '' &&
            Object.keys(node.availableChildTypes).length > 0
        );
    }

    /** Same localized level label used by FormEngine options and every module badge. */
    private headingLevelLabel(level: number): string {
        return lll(`mindfula11y.structure.headings.level.h${level}`);
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
     * Select option labels use the intent-based, translated TCA labels. A currently
     * selected "automatic" option carries the
     * effective level (own level for the level select, own level + 1 for the
     * child-type select), so the level stays visible in the collapsed select.
     * An effective level past H6 shows the paragraph label: HeadingType::increment() overflows
     * derived levels beyond h6 to a paragraph, so an H6 container's automatic
     * children must never be promised an "H7".
     */
    private levelOptionLabel(
        type: string,
        typeLabel: string,
        currentValue: string,
        effectiveLevel: number | null,
    ): string {
        if (type === '' && currentValue === '' && effectiveLevel !== null) {
            const effectiveLabel =
                effectiveLevel > 6
                    ? lll('mindfula11y.structure.headings.level.p')
                    : this.headingLevelLabel(effectiveLevel);
            return `${typeLabel}: ${effectiveLabel}`;
        }
        return typeLabel;
    }

    private handleRelationJump(node: HeadingNode): void {
        const targetId = node.relation?.targetRelationId ?? '';
        const rows =
            targetId === ''
                ? []
                : Array.from(
                      this.renderRoot.querySelectorAll<HTMLElement>(`[data-relation-id="${CSS.escape(targetId)}"]`),
                  );
        // A relation resolves to its nearest PRECEDING publisher — duplicate
        // relation ids re-register (HeadingRelationRegistry semantics, mirrored
        // by the analyzer) — so land on the last matching row above this
        // heading's own row, not the first match on the page.
        const own = this.renderRoot.querySelector(`[data-node-id="${CSS.escape(node.id)}"]`);
        const target =
            rows
                .filter(
                    (row) =>
                        own === null || (row.compareDocumentPosition(own) & Node.DOCUMENT_POSITION_FOLLOWING) !== 0,
                )
                .at(-1) ??
            rows.at(0) ??
            null;
        if (target === null) {
            Notification.warning(
                lll('mindfula11y.structure.headings.relation.notFound'),
                lll('mindfula11y.structure.headings.relation.notFound.description'),
            );
            return;
        }
        // Descendants are controlled by the publisher's "Headings inside"
        // field when available; siblings derive from its own level field.
        this.focusRow(target, {
            preferredControl: node.relation?.kind === 'ancestor' ? 'child-level' : 'level',
            fallbackToOtherControls: false,
        });
    }
}

declare global {
    interface HTMLElementTagNameMap {
        'mindfula11y-heading-structure': HeadingStructure;
    }
}
