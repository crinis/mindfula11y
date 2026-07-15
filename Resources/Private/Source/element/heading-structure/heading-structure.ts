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
import { renderViewportBadges } from '../../lib/status-render.js';
import type { HeadingNode } from '../../lib/structure/types.js';
import { StructureView } from '../../lib/structure/view.js';
import componentStyles from './heading-structure.css.js';

/**
 * Heading tree of the analyzed page: one aligned level chip per heading
 * (editable select, locked display or relation jump button), inline issues
 * under the affected row and dashed placeholder rows for skipped levels.
 *
 * The whole tree renders in this component's single shadow root so the nested
 * ordered lists keep native list semantics and every aria-describedby pairing
 * stays inside one root. The analyzer properties, issue rendering, focus
 * plumbing and save flow come from the shared `StructureView` base.
 */
@customElement('mindfula11y-heading-structure')
export class HeadingStructure extends StructureView<HeadingNode> {
    static override styles: CSSResultGroup[] = [...StructureView.viewStyles, componentStyles];

    protected override readonly controlSelector: string = '[data-control="level"]';
    protected override readonly emptyLabelKey: string = 'mindfula11y.structure.headings.noHeadings';
    protected override readonly labelPrefix: string = 'mindfula11y.structure.headings';

    protected override renderNodes(nodes: HeadingNode[]): TemplateResult {
        return this.renderTree(nodes);
    }

    private renderTree(nodes: HeadingNode[]): TemplateResult {
        return html`<ol class="tree">
            ${repeat(
                nodes,
                (node) => node.id,
                (node) => this.renderNode(node),
            )}
        </ol>`;
    }

    private renderNode(node: HeadingNode): TemplateResult {
        let content = html`<li class="node">
            ${this.renderRow(node)} ${node.errors.length > 0 ? this.renderNodeIssues(node) : nothing}
            ${node.children.length > 0 ? this.renderTree(node.children) : nothing}
        </li>`;

        // One dashed placeholder row per skipped level, the real subtree nested inside.
        // Built innermost-out: skip=0 wraps deepest, directly above the real heading;
        // missingLevel counts down from node.level−1.
        for (let skip = 0; skip < node.skippedLevels; skip++) {
            const missingLevel = node.level - skip - 1;
            content = html`<li class="node" data-placeholder>
                <div class="row">
                    <span class="level" data-missing>H${missingLevel}</span>
                    <span class="text">${lll('mindfula11y.structure.headings.error.skippedLevel.inline', missingLevel)}</span>
                </div>
                <ol class="tree">
                    ${content}
                </ol>
            </li>`;
        }
        return content;
    }

    private renderRow(node: HeadingNode): TemplateResult {
        const label = node.label !== '' ? node.label : lll('mindfula11y.structure.headings.unlabeled');
        const editable = node.relation === null && node.record !== null && node.record.editLink !== '';

        return html`<div class="row" data-node-id=${node.id} data-relation-id=${node.relationId}>
            ${this.renderLevelControl(node, label, editable)}
            <span class="text" ?data-empty=${node.label === ''}>${label}</span>
            ${renderViewportBadges(node.viewports)}
            ${editable && this.hasRecord(node) ? this.renderEditLink(node, label) : nothing}
            ${this.renderBusySpinner(node)}
        </div>`;
    }

    private renderLevelControl(node: HeadingNode, label: string, editable: boolean): TemplateResult {
        if (node.relation !== null) {
            const relationLabel =
                node.relation.kind === 'ancestor'
                    ? lll('mindfula11y.structure.headings.relation.descendant')
                    : lll('mindfula11y.structure.headings.relation.sibling');
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

        if (editable && node.record !== null) {
            const currentValue = `h${node.level}`;
            return this.renderValueSelect(node, {
                id: `level-${node.id}`,
                className: 'level',
                ariaLabel: `${lll('mindfula11y.structure.headings.type')}: ${label}`,
                currentValue,
                options: Object.fromEntries(Object.keys(node.availableTypes).map((type) => [type, type.toUpperCase()])),
            });
        }

        return html`<span class="level" data-locked aria-describedby=${this.describedby(node)}>
            ${this.renderLockedChip(`H${node.level}`)}
        </span>`;
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
