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
import { renderViewportBadges } from "../../lib/status-render.js";
import { StructureView } from "../../lib/structure/view.js";
import componentStyles from "./heading-structure.css.js";
let HeadingStructure = class extends StructureView {
  constructor() {
    super(...arguments);
    this.controlSelector = '[data-control="level"]';
    this.emptyLabelKey = "mindfula11y.structure.headings.noHeadings";
    this.labelPrefix = "mindfula11y.structure.headings";
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
            ${renderViewportBadges(node.viewports)}
            ${editable && this.hasRecord(node) ? this.renderEditLink(node, label) : nothing}
            ${this.renderBusySpinner(node)}
        </div>`;
  }
  renderLevelControl(node, label, editable) {
    if (node.relation !== null) {
      const relationLabel = node.relation.kind === "ancestor" ? lll("mindfula11y.structure.headings.relation.descendant") : lll("mindfula11y.structure.headings.relation.sibling");
      return html`<button
                type="button"
                class="level"
                data-relation
                data-control="level"
                aria-label="H${node.level} — ${relationLabel}. ${lll("mindfula11y.structure.headings.relation.jump")}"
                aria-describedby=${this.describedby(node)}
                @click=${() => this.handleRelationJump(node)}
            >
                H${node.level}
                <typo3-backend-icon identifier="actions-link" size="small"></typo3-backend-icon>
            </button>`;
    }
    if (editable && node.record !== null) {
      const currentValue = `h${node.level}`;
      return this.renderValueSelect(node, {
        id: `level-${node.id}`,
        className: "level",
        ariaLabel: `${lll("mindfula11y.structure.headings.type")}: ${label}`,
        currentValue,
        options: Object.fromEntries(Object.keys(node.availableTypes).map((type) => [type, type.toUpperCase()]))
      });
    }
    return html`<span class="level" data-locked aria-describedby=${this.describedby(node)}>
            ${this.renderLockedChip(`H${node.level}`)}
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
HeadingStructure.styles = [...StructureView.viewStyles, componentStyles];
HeadingStructure = __decorateClass([
  customElement("mindfula11y-heading-structure")
], HeadingStructure);
export {
  HeadingStructure
};
