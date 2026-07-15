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
import { html, LitElement, nothing } from "lit";
import { property, state } from "lit/decorators.js";
import { live } from "lit/directives/live.js";
import "@typo3/backend/element/icon-element.js";
import "@typo3/backend/element/spinner-element.js";
import { RecordService } from "../../service/record-service.js";
import { baseStyles } from "../../styles/base-styles.js";
import noticeStyles from "../../styles/notice.css.js";
import structureViewStyles from "../../styles/structure-view.css.js";
import viewportStyles from "../../styles/viewport.css.js";
import { scrollIntoViewCentered } from "../dom.js";
import { noticeState, renderSeverityChip, renderViewportBadges } from "../status-render.js";
class StructureView extends LitElement {
  constructor() {
    super(...arguments);
    this.nodes = [];
    this.pageErrors = [];
    this.busyNodeId = "";
    this.recordService = new RecordService();
    this.pendingFocusId = "";
  }
  static {
    /**
     * Shared foundation + the chrome/viewport/notice modules both structure
     * views adopt; each subclass appends its own component stylesheet:
     * `[...StructureView.viewStyles, componentStyles]`.
     */
    this.viewStyles = [
      ...baseStyles,
      noticeStyles,
      structureViewStyles,
      viewportStyles
    ];
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
            tabindex=${pageScope ? "-1" : nothing}
        >
            ${renderSeverityChip(error.severity, error.key)} ${renderViewportBadges(error.viewports)}
        </p>`;
  }
  renderEmpty() {
    return html`<p class="empty">
            <typo3-backend-icon identifier="status-dialog-information" size="small"></typo3-backend-icon>
            <span class="empty-title">${lll(this.emptyLabelKey)}</span>
            <span>${lll(`${this.emptyLabelKey}.description`)}</span>
        </p>`;
  }
  /** `issue-${node.id}` when the node has errors, else `nothing` — shared `aria-describedby` derivation. */
  describedby(node) {
    return node.errors.length > 0 ? `issue-${node.id}` : nothing;
  }
  /** Type guard narrowing `node.record` to non-null, for {@link renderEditLink} call sites. */
  hasRecord(node) {
    return node.record !== null;
  }
  /** Edit link to the record's FormEngine field. Callers narrow via {@link hasRecord} before calling. */
  renderEditLink(node, label) {
    return html`<a class="edit" data-control="edit" href=${node.record.editLink}>
            <typo3-backend-icon identifier="actions-open" size="small"></typo3-backend-icon>
            <span class="sr-only">${lll(`${this.labelPrefix}.edit`)}: ${label}</span>
        </a>`;
  }
  /** Busy spinner shown next to a row's controls while its save is in flight. */
  renderBusySpinner(node) {
    return this.busyNodeId === node.id ? html`<typo3-backend-spinner size="small"></typo3-backend-spinner>` : nothing;
  }
  /**
   * Icon + sr-only "not editable" text (key `<labelPrefix>.edit.locked`) for
   * a locked control chip; `content` is the visible value rendered before
   * the icon (e.g. `H2` or a role display name). The caller keeps the
   * wrapping element (class + `aria-describedby` differ per view).
   */
  renderLockedChip(content) {
    return html`${content}
            <typo3-backend-icon identifier="actions-lock" size="small"></typo3-backend-icon>
            <span class="sr-only">${lll(`${this.labelPrefix}.edit.locked`)}</span>`;
  }
  /**
   * Editable value `<select>` shared by both views: binds `.value=${live(…)}`
   * so a failed save reverts visually through the next re-render (triggered
   * by `busyNodeId` resetting in `saveNodeValue`'s `finally`) rather than a
   * manual DOM mutation, which can disagree with the template after
   * unrelated re-renders.
   */
  renderValueSelect(node, opts) {
    return html`<select
            id=${opts.id}
            class=${opts.className}
            data-control=${opts.className}
            aria-label=${opts.ariaLabel}
            aria-describedby=${this.describedby(node)}
            ?disabled=${this.busyNodeId === node.id}
            .value=${live(opts.currentValue)}
            @change=${(event) => {
      void this.saveNodeValue(node, event.currentTarget, opts.currentValue);
    }}
        >
            ${Object.entries(opts.options).map(
      ([value, label]) => html`<option value=${value} ?selected=${value === opts.currentValue}>${label}</option>`
    )}
        </select>`;
  }
  /**
   * Persists a control's value via DataHandler and reports the change so the
   * container re-analyzes (focus returns to the node once new nodes arrive).
   * On failure a toast names the error (`<labelPrefix>.error.store` +
   * `.description`); resetting `busyNodeId` below re-renders the row, and
   * `renderValueSelect`'s `live()` binding reverts the select to
   * `currentValue` without a manual `select.value =` mutation.
   */
  async saveNodeValue(node, select, currentValue) {
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
      const errorKey = `${this.labelPrefix}.error.store`;
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
