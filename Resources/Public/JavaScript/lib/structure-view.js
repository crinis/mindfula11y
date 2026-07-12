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
import { html, LitElement } from "lit";
import { property, state } from "lit/decorators.js";
import "@typo3/backend/element/icon-element.js";
import { RecordService } from "../service/record-service.js";
import { scrollIntoViewCentered } from "./dom.js";
import { noticeState, StructureErrorSeverity, severityLabelKey } from "./types.js";
class StructureView extends LitElement {
  constructor() {
    super(...arguments);
    this.nodes = [];
    this.pageErrors = [];
    this.busyNodeId = "";
    this.recordService = new RecordService();
    this.pendingFocusId = "";
  }
  render() {
    return html`<div class="view">
            ${this.pageErrors.map((error) => this.renderIssue(error, true))}
            ${this.nodes.length === 0 ? this.renderEmpty() : this.renderNodes(this.nodes)}
        </div>`;
  }
  updated(changed) {
    if (changed.has("nodes") && this.pendingFocusId !== "") {
      const nodeId = this.pendingFocusId;
      this.pendingFocusId = "";
      this.focusControl(nodeId);
    }
  }
  /** Moves focus to the control of the given node (used after saves and by the container). */
  focusControl(nodeId) {
    const row = this.renderRoot.querySelector(`[data-node-id="${CSS.escape(nodeId)}"]`);
    if (row !== null) {
      this.focusRow(row);
    }
  }
  /** Focuses the first element affected by the given error key; page-level issues focus their message. */
  focusFirstIssue(errorKey) {
    if (this.pageErrors.some((error) => error.key === errorKey)) {
      const issue = this.renderRoot.querySelector('[data-scope="page"]');
      if (issue !== null) {
        issue.setAttribute("tabindex", "-1");
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
  findNode(nodes, matches) {
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
  renderNodeIssues(node) {
    return html`<div class="issues" id="issue-${node.id}">
            ${node.errors.map((error) => this.renderIssue(error, false))}
        </div>`;
  }
  renderIssue(error, pageScope) {
    return html`<p
            class="notice issue"
            data-state=${noticeState(error.severity)}
            data-variant="inline"
            data-scope=${pageScope ? "page" : "node"}
        >
            <typo3-backend-icon
                identifier=${error.severity === StructureErrorSeverity.Error ? "status-dialog-error" : "status-dialog-warning"}
                size="small"
            ></typo3-backend-icon>
            <span><span class="sr-only">${lll(severityLabelKey(error.severity))}: </span>${lll(error.key)}</span>
        </p>`;
  }
  renderEmpty() {
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
  async saveNodeValue(node, event, currentValue, errorKey) {
    const select = event.currentTarget;
    const value = select.value;
    if (node.record === null || value === currentValue) {
      return;
    }
    this.busyNodeId = node.id;
    try {
      await this.recordService.updateField(node.record, value);
      this.pendingFocusId = node.id;
      this.dispatchEvent(
        new CustomEvent("mindfula11y:structure:changed", {
          bubbles: true,
          composed: true,
          detail: {
            nodeId: node.id,
            tableName: node.record.tableName,
            uid: node.record.uid,
            columnName: node.record.columnName,
            value
          }
        })
      );
    } catch {
      select.value = currentValue;
      Notification.error(lll(errorKey), lll(`${errorKey}.description`));
    } finally {
      this.busyNodeId = "";
    }
  }
  /** Focuses a node row's control (or the row itself) and flashes the highlight. */
  focusRow(row) {
    const control = row.querySelector(this.controlSelector) ?? row.querySelector('[data-control="edit"]');
    if (control !== null) {
      control.focus();
    } else {
      row.setAttribute("tabindex", "-1");
      row.focus();
    }
    scrollIntoViewCentered(row);
    row.setAttribute("data-highlight", "");
    row.addEventListener("animationend", () => row.removeAttribute("data-highlight"), { once: true });
  }
}
__decorateClass([
  property({ attribute: false })
], StructureView.prototype, "nodes", 2);
__decorateClass([
  property({ attribute: false })
], StructureView.prototype, "pageErrors", 2);
__decorateClass([
  state()
], StructureView.prototype, "busyNodeId", 2);
export {
  StructureView
};
