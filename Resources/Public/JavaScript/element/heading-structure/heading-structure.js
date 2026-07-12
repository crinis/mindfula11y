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
import Notification from "@typo3/backend/notification.js";
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
import componentStyles from "./heading-structure.css.js";
let HeadingStructure = class extends StructureView {
  constructor() {
    super(...arguments);
    this.controlSelector = '[data-control="level"]';
    this.emptyLabelKey = "mindfula11y.structure.headings.noHeadings";
  }
  renderNodes(nodes) {
    return this.renderTree(nodes);
  }
  renderTree(nodes) {
    return html`<ol class="tree">
            ${repeat(
      nodes,
      (node) => node.id,
      (node) => this.renderNode(node)
    )}
        </ol>`;
  }
  renderNode(node) {
    let content = html`<li class="node">
            ${this.renderRow(node)} ${node.errors.length > 0 ? this.renderNodeIssues(node) : nothing}
            ${node.children.length > 0 ? this.renderTree(node.children) : nothing}
        </li>`;
    for (let skip = 0; skip < node.skippedLevels; skip++) {
      const missingLevel = node.level - skip - 1;
      content = html`<li class="node" data-placeholder>
                <div class="row">
                    <span class="level" data-missing>H${missingLevel}</span>
                    <span class="text">${lll("mindfula11y.structure.headings.error.skippedLevel.inline", missingLevel)}</span>
                </div>
                <ol class="tree">
                    ${content}
                </ol>
            </li>`;
    }
    return content;
  }
  renderRow(node) {
    const label = node.label !== "" ? node.label : lll("mindfula11y.structure.headings.unlabeled");
    const editable = node.relation === null && node.record !== null && node.record.editLink !== "";
    return html`<div class="row" data-node-id=${node.id} data-relation-id=${node.relationId}>
            ${this.renderLevelControl(node, label, editable)}
            <span class="text" ?data-empty=${node.label === ""}>${label}</span>
            ${editable && node.record !== null ? html`<a class="edit" data-control="edit" href=${node.record.editLink}>
                          <typo3-backend-icon identifier="actions-open" size="small"></typo3-backend-icon>
                          <span class="sr-only">${lll("mindfula11y.structure.headings.edit")}: ${label}</span>
                      </a>` : nothing}
            ${this.busyNodeId === node.id ? html`<typo3-backend-spinner size="small"></typo3-backend-spinner>` : nothing}
        </div>`;
  }
  renderLevelControl(node, label, editable) {
    const describedby = node.errors.length > 0 ? `issue-${node.id}` : nothing;
    if (node.relation !== null) {
      const relationLabel = node.relation.kind === "ancestor" ? lll("mindfula11y.structure.headings.relation.descendant") : lll("mindfula11y.structure.headings.relation.sibling");
      return html`<button
                type="button"
                class="level"
                data-relation
                data-control="level"
                aria-label="H${node.level} — ${relationLabel}. ${lll("mindfula11y.structure.headings.relation.jump")}"
                aria-describedby=${describedby}
                @click=${() => this.handleRelationJump(node)}
            >
                H${node.level}
                <typo3-backend-icon identifier="actions-link" size="small"></typo3-backend-icon>
            </button>`;
    }
    if (editable && node.record !== null) {
      return html`<select
                id="level-${node.id}"
                class="level"
                data-control="level"
                aria-label="${lll("mindfula11y.structure.headings.type")}: ${label}"
                aria-describedby=${describedby}
                ?disabled=${this.busyNodeId === node.id}
                @change=${(event) => {
        void this.saveNodeValue(
          node,
          event,
          `h${node.level}`,
          "mindfula11y.structure.headings.error.store"
        );
      }}
            >
                ${Object.keys(node.availableTypes).map(
        (type) => html`<option value=${type} ?selected=${type === `h${node.level}`}>
                            ${type.toUpperCase()}
                        </option>`
      )}
            </select>`;
    }
    return html`<span class="level" data-locked aria-describedby=${describedby}>
            H${node.level}
            <typo3-backend-icon identifier="actions-lock" size="small"></typo3-backend-icon>
            <span class="sr-only">${lll("mindfula11y.structure.headings.edit.locked")}</span>
        </span>`;
  }
  handleRelationJump(node) {
    const targetId = node.relation?.targetRelationId ?? "";
    const target = targetId === "" ? null : this.renderRoot.querySelector(`[data-relation-id="${CSS.escape(targetId)}"]`);
    if (target === null) {
      Notification.warning(
        lll("mindfula11y.structure.headings.relation.notFound"),
        lll("mindfula11y.structure.headings.relation.notFound.description")
      );
      return;
    }
    this.focusRow(target);
  }
};
HeadingStructure.styles = [...baseStyles, noticeStyles, structureViewStyles, componentStyles];
HeadingStructure = __decorateClass([
  customElement("mindfula11y-heading-structure")
], HeadingStructure);
export {
  HeadingStructure
};
