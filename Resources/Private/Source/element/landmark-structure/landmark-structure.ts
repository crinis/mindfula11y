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
import type { CSSResult, TemplateResult } from 'lit';
import { html, nothing } from 'lit';
import { customElement } from 'lit/decorators.js';
import { repeat } from 'lit/directives/repeat.js';
import '@typo3/backend/element/icon-element.js';
import '@typo3/backend/element/spinner-element.js';
import { StructureView } from '../../lib/structure-view.js';
import type { LandmarkNode } from '../../lib/types.js';
import { baseStyles } from '../../styles/base-styles.js';
import noticeStyles from '../../styles/notice.css.js';
import structureViewStyles from '../../styles/structure-view.css.js';
import componentStyles from './landmark-structure.css.js';

/**
 * Page schematic of the analyzed document's landmarks: an outer page frame
 * containing role-accented region cards stacked in document order, with nested
 * landmarks rendered as cards physically inside their parent card. We have no
 * layout geometry (the analyzer parses HTML), so regions are always full-width
 * blocks — order and containment are the truth the schematic shows.
 *
 * The whole schematic renders in one shadow root so the nested ordered lists
 * keep native list semantics. The analyzer properties, issue rendering, focus
 * plumbing and save flow come from the shared `StructureView` base.
 */
@customElement('mindfula11y-landmark-structure')
export class LandmarkStructure extends StructureView<LandmarkNode> {
    static override styles: CSSResult[] = [...baseStyles, noticeStyles, structureViewStyles, componentStyles];

    protected override readonly controlSelector: string = '[data-control="role"]';
    protected override readonly emptyLabelKey: string = 'mindfula11y.structure.landmarks.noLandmarks';

    protected override renderNodes(nodes: LandmarkNode[]): TemplateResult {
        return this.renderMap(nodes, true);
    }

    private renderMap(nodes: LandmarkNode[], isRoot: boolean): TemplateResult {
        return html`<ol class="map" ?data-root=${isRoot}>
            ${repeat(
                nodes,
                (node) => node.id,
                (node) => this.renderRegion(node),
            )}
        </ol>`;
    }

    private renderRegion(node: LandmarkNode): TemplateResult {
        const label = node.label !== '' ? node.label : lll('mindfula11y.structure.landmarks.unlabelledLandmark');
        const editable = node.record !== null && node.record.editLink !== '';

        return html`<li class="region" data-role=${node.role}>
            <div class="head" data-node-id=${node.id}>
                ${this.renderRoleControl(node, label, editable)}
                <span class="name" ?data-unlabelled=${node.label === ''}>${label}</span>
                ${
                    editable && node.record !== null
                        ? html`<a class="edit" data-control="edit" href=${node.record.editLink}>
                              <typo3-backend-icon identifier="actions-open" size="small"></typo3-backend-icon>
                              <span class="sr-only">${lll('mindfula11y.structure.landmarks.edit')}: ${label}</span>
                          </a>`
                        : nothing
                }
                ${
                    this.busyNodeId === node.id
                        ? html`<typo3-backend-spinner size="small"></typo3-backend-spinner>`
                        : nothing
                }
            </div>
            ${node.errors.length > 0 ? this.renderNodeIssues(node) : nothing}
            ${node.children.length > 0 ? this.renderMap(node.children, false) : nothing}
        </li>`;
    }

    private renderRoleControl(node: LandmarkNode, label: string, editable: boolean): TemplateResult {
        const describedby = node.errors.length > 0 ? `issue-${node.id}` : nothing;

        if (editable && node.record !== null) {
            return html`<select
                id="role-${node.id}"
                class="role"
                data-control="role"
                aria-label="${lll('mindfula11y.structure.landmarks.role')}: ${label}"
                aria-describedby=${describedby}
                ?disabled=${this.busyNodeId === node.id}
                @change=${(event: Event): void => {
                    void this.saveNodeValue(node, event, node.role, 'mindfula11y.structure.landmarks.error.store');
                }}
            >
                ${Object.keys(node.availableRoles).map(
                    (role) =>
                        html`<option value=${role} ?selected=${role === node.role}>
                            ${this.roleDisplayName(role)}
                        </option>`,
                )}
            </select>`;
        }

        return html`<span class="role" data-locked aria-describedby=${describedby}>
            ${this.roleDisplayName(node.role)}
            <typo3-backend-icon identifier="actions-lock" size="small"></typo3-backend-icon>
            <span class="sr-only">${lll('mindfula11y.structure.landmarks.edit.locked')}</span>
        </span>`;
    }

    private roleDisplayName(role: string): string {
        if (role === '') {
            return lll('mindfula11y.structure.landmarks.role.none');
        }
        const label = lll(`mindfula11y.structure.landmarks.role.${role}`);
        return label !== '' ? label : role;
    }
}

declare global {
    interface HTMLElementTagNameMap {
        'mindfula11y-landmark-structure': LandmarkStructure;
    }
}
