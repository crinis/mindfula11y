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
import type { CSSResultGroup, TemplateResult } from 'lit';
import { html, nothing } from 'lit';
import { customElement } from 'lit/decorators.js';
import { repeat } from 'lit/directives/repeat.js';
import '@typo3/backend/element/icon-element.js';
import { renderViewportBadges } from '../../lib/status-render.js';
import type { LandmarkNode } from '../../lib/structure/types.js';
import { StructureView } from '../../lib/structure-view.js';
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
    static override styles: CSSResultGroup[] = [...StructureView.viewStyles, componentStyles];

    protected override readonly controlSelector: string = '[data-control="role"]';
    protected override readonly emptyLabelKey: string = 'mindfula11y.structure.landmarks.noLandmarks';
    protected override readonly labelPrefix: string = 'mindfula11y.structure.landmarks';

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
                ${renderViewportBadges(node.viewports)}
                ${editable && this.hasRecord(node) ? this.renderEditLink(node, label) : nothing}
                ${this.renderBusySpinner(node)}
            </div>
            ${node.errors.length > 0 ? this.renderNodeIssues(node) : nothing}
            ${node.children.length > 0 ? this.renderMap(node.children, false) : nothing}
        </li>`;
    }

    private renderRoleControl(node: LandmarkNode, label: string, editable: boolean): TemplateResult {
        if (editable && node.record !== null) {
            return this.renderValueSelect(node, {
                id: `role-${node.id}`,
                className: 'role',
                ariaLabel: `${lll('mindfula11y.structure.landmarks.role')}: ${label}`,
                currentValue: node.role,
                options: Object.fromEntries(
                    Object.keys(node.availableRoles).map((role) => [role, this.roleDisplayName(role)]),
                ),
            });
        }

        return html`<span class="role" data-locked>
            ${this.renderLockedChip(this.roleDisplayName(node.role))}
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
