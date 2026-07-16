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
import { renderViewportBadges } from "../../lib/status-render.js";
import { StructureView } from "../../lib/structure-view.js";
import componentStyles from "./landmark-structure.css.js";
let LandmarkStructure = class extends StructureView {
  constructor() {
    super(...arguments);
    this.controlSelector = '[data-control="role"]';
    this.emptyLabelKey = "mindfula11y.structure.landmarks.noLandmarks";
    this.labelPrefix = "mindfula11y.structure.landmarks";
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
                ${renderViewportBadges(node.viewports)}
                ${editable && this.hasRecord(node) ? this.renderEditLink(node, label) : nothing}
                ${this.renderBusySpinner(node)}
            </div>
            ${node.errors.length > 0 ? this.renderNodeIssues(node) : nothing}
            ${node.children.length > 0 ? this.renderMap(node.children, false) : nothing}
        </li>`;
  }
  renderRoleControl(node, label, editable) {
    if (editable && node.record !== null) {
      return this.renderValueSelect(node, {
        id: `role-${node.id}`,
        className: "role",
        ariaLabel: `${lll("mindfula11y.structure.landmarks.role")}: ${label}`,
        currentValue: node.role,
        options: Object.fromEntries(
          Object.keys(node.availableRoles).map((role) => [role, this.roleDisplayName(role)])
        )
      });
    }
    return html`<span class="role" data-locked>
            ${this.renderLockedChip(this.roleDisplayName(node.role))}
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
LandmarkStructure.styles = [...StructureView.viewStyles, componentStyles];
LandmarkStructure = __decorateClass([
  customElement("mindfula11y-landmark-structure")
], LandmarkStructure);
export {
  LandmarkStructure
};
