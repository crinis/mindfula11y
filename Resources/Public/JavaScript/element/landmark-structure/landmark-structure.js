var __defProp = Object.defineProperty;
var __getOwnPropDesc = Object.getOwnPropertyDescriptor;
var __decorateClass = (decorators, target, key, kind) => {
  var result = kind > 1 ? void 0 : kind ? __getOwnPropDesc(target, key) : target;
  for (var i = decorators.length - 1, decorator; i >= 0; i--)
    if (decorator = decorators[i])
      result = (kind ? decorator(target, key, result) : decorator(result)) || result;
  if (kind && result) __defProp(target, key, result);
  return result;
};
import { lll } from "@typo3/core/lit-helper.js";
import { html, nothing } from "lit";
import { customElement } from "lit/decorators.js";
import { repeat } from "lit/directives/repeat.js";
import "@typo3/backend/element/icon-element.js";
import "@typo3/backend/element/spinner-element.js";
import { StructureView } from "../../lib/structure-view.js";
import { baseStyles } from "../../styles/base-styles.js";
import noticeStyles from "../../styles/notice.css.js";
import structureViewStyles from "../../styles/structure-view.css.js";
import componentStyles from "./landmark-structure.css.js";
let LandmarkStructure = class extends StructureView {
  constructor() {
    super(...arguments);
    this.controlSelector = '[data-control="role"]';
    this.emptyLabelKey = "mindfula11y.structure.landmarks.noLandmarks";
  }
  renderNodes(nodes) {
    return this.renderMap(nodes, true);
  }
  renderMap(nodes, isRoot) {
    return html`<ol class="map" ?data-root=${isRoot}>
            ${repeat(
      nodes,
      (node) => node.id,
      (node) => this.renderRegion(node)
    )}
        </ol>`;
  }
  renderRegion(node) {
    const label = node.label !== "" ? node.label : lll("mindfula11y.structure.landmarks.unlabelledLandmark");
    const editable = node.record !== null && node.record.editLink !== "";
    return html`<li class="region" data-role=${node.role}>
            <div class="head" data-node-id=${node.id}>
                ${this.renderRoleControl(node, label, editable)}
                <span class="name" ?data-unlabelled=${node.label === ""}>${label}</span>
                ${editable && node.record !== null ? html`<a class="edit" data-control="edit" href=${node.record.editLink}>
                              <typo3-backend-icon identifier="actions-open" size="small"></typo3-backend-icon>
                              <span class="sr-only">${lll("mindfula11y.structure.landmarks.edit")}: ${label}</span>
                          </a>` : nothing}
                ${this.busyNodeId === node.id ? html`<typo3-backend-spinner size="small"></typo3-backend-spinner>` : nothing}
            </div>
            ${node.errors.length > 0 ? this.renderNodeIssues(node) : nothing}
            ${node.children.length > 0 ? this.renderMap(node.children, false) : nothing}
        </li>`;
  }
  renderRoleControl(node, label, editable) {
    const describedby = node.errors.length > 0 ? `issue-${node.id}` : nothing;
    if (editable && node.record !== null) {
      return html`<select
                id="role-${node.id}"
                class="role"
                data-control="role"
                aria-label="${lll("mindfula11y.structure.landmarks.role")}: ${label}"
                aria-describedby=${describedby}
                ?disabled=${this.busyNodeId === node.id}
                @change=${(event) => {
        void this.saveNodeValue(node, event, node.role, "mindfula11y.structure.landmarks.error.store");
      }}
            >
                ${Object.keys(node.availableRoles).map(
        (role) => html`<option value=${role} ?selected=${role === node.role}>
                            ${this.roleDisplayName(role)}
                        </option>`
      )}
            </select>`;
    }
    return html`<span class="role" data-locked aria-describedby=${describedby}>
            ${this.roleDisplayName(node.role)}
            <typo3-backend-icon identifier="actions-lock" size="small"></typo3-backend-icon>
            <span class="sr-only">${lll("mindfula11y.structure.landmarks.edit.locked")}</span>
        </span>`;
  }
  roleDisplayName(role) {
    if (role === "") {
      return lll("mindfula11y.structure.landmarks.role.none");
    }
    const label = lll(`mindfula11y.structure.landmarks.role.${role}`);
    return label !== "" ? label : role;
  }
};
LandmarkStructure.styles = [...baseStyles, noticeStyles, structureViewStyles, componentStyles];
LandmarkStructure = __decorateClass([
  customElement("mindfula11y-landmark-structure")
], LandmarkStructure);
export {
  LandmarkStructure
};
