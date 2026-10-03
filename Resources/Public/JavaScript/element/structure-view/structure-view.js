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
import { scrollIntoViewCentered } from "../../lib/dom.js";
import { impactState, renderSeverityChip, renderViewportBadges } from "../../lib/status-render.js";
import { dispatch } from "../../lib/types.js";
import { RecordApi } from "../../service/record-api.js";
import { baseStyles } from "../../styles/base-styles.js";
import chipStyles from "../../styles/chip.css.js";
import noticeStyles from "../../styles/notice.css.js";
import structureViewStyles from "../../styles/structure-view.css.js";
import surfaceStyles from "../../styles/surface.css.js";
import viewportStyles from "../../styles/viewport.css.js";
class StructureView extends LitElement {
  constructor() {
    super(...arguments);
    this.nodes = [];
    this.pageErrors = [];
    this.busyNodeIds = /* @__PURE__ */ new Set();
    this.recordApi = new RecordApi();
    /** The saved control of a save whose re-analysed nodes are pending: the restore fallback; see {@link updated}. */
    this.pendingFocus = null;
    /**
     * Where focus returns: the control holding it when the last save started,
     * moved along as the editor focuses other controls inside this view.
     */
    this.restoreTarget = null;
    /** The editor is acting outside this view since the last save started; see {@link updated}. */
    this.editorMovedOn = false;
    this.watchingEditor = false;
    /**
     * Document-level (capture) tracking while a save is pending. A focus move
     * to another control inside this view becomes the restore target (the
     * editor is back in, or still in, the view). A pointer press or focus
     * move outside it means the editor moved on — except focus entering a
     * frame: that is the frame's doing, not the editor's (the hidden
     * analysis renders call focus() on load), a click inside a frame never
     * reaches this document, and a frame the editor really moved into keeps
     * focus, which the "focus is lost" condition respects anyway.
     */
    this.noteEditorMove = (event) => {
      const path = event.composedPath();
      if (path.includes(this)) {
        const control = event.type === "focusin" ? this.controlRefOf(path[0]) : null;
        if (control !== null) {
          this.restoreTarget = control;
          this.editorMovedOn = false;
        }
        return;
      }
      if (event.type === "focusin" && path[0] instanceof HTMLIFrameElement) {
        return;
      }
      this.editorMovedOn = true;
    };
  }
  static {
    /**
     * Shared foundation + the chrome/viewport/notice/chip/surface modules both
     * structure views adopt; each subclass appends its own component stylesheet:
     * `[...StructureView.viewStyles, componentStyles]`.
     */
    this.viewStyles = [
      ...baseStyles,
      noticeStyles,
      chipStyles,
      surfaceStyles,
      structureViewStyles,
      viewportStyles
    ];
  }
  render() {
    return html`<div class="view">
            ${this.renderPageErrors()}
            ${this.nodes.length === 0 ? this.renderEmpty() : this.renderNodes(this.nodes)}
        </div>`;
  }
  /** Page-level findings have no affected node; subclasses may place them in their own schematic. */
  renderPageErrors() {
    return this.pageErrors.length > 0 ? html`${this.pageErrors.map((error) => this.renderIssue(error, { pageScope: true }))}` : nothing;
  }
  disconnectedCallback() {
    super.disconnectedCallback();
    this.pendingFocus = null;
    this.restoreTarget = null;
    this.stopWatchingEditor();
  }
  /**
   * After a save, the nodes of the re-analysis arrive seconds later. Focus
   * returns to the control that held it last inside this view (when the
   * save started, or the one the editor moved to since) only when it got
   * lost meanwhile without the editor moving on: it sits on the body (the
   * re-render replaced the focused control, or something else took focus
   * and went away), this document still has focus, and the editor's last
   * pointer press or focus move did not leave this view. Where focus
   * survives, or the editor went elsewhere — the page, another frame — it
   * stays where it is. The saved control is the fallback when the target's
   * row is gone.
   */
  updated(changed) {
    if (changed.has("nodes") && this.pendingFocus !== null) {
      const saved = this.pendingFocus;
      const target = this.restoreTarget ?? saved;
      const movedOn = this.editorMovedOn;
      this.pendingFocus = null;
      if (this.busyNodeIds.size === 0) {
        this.stopWatchingEditor();
      }
      if (!movedOn && this.isFocusLost()) {
        const restore = this.findRow(target.nodeId) !== null ? target : saved;
        this.focusControl(restore.nodeId, restore.control);
      }
    }
  }
  watchEditor() {
    this.editorMovedOn = false;
    if (!this.watchingEditor) {
      this.ownerDocument.addEventListener("pointerdown", this.noteEditorMove, true);
      this.ownerDocument.addEventListener("focusin", this.noteEditorMove, true);
      this.watchingEditor = true;
    }
  }
  stopWatchingEditor() {
    if (this.watchingEditor) {
      this.ownerDocument.removeEventListener("pointerdown", this.noteEditorMove, true);
      this.ownerDocument.removeEventListener("focusin", this.noteEditorMove, true);
      this.watchingEditor = false;
    }
  }
  /** Focus fell back to the body (or nowhere) of a document that still has focus. */
  isFocusLost() {
    const doc = this.ownerDocument;
    const active = doc.activeElement;
    return doc.hasFocus() && (active === null || active === doc.body || active === doc.documentElement);
  }
  /**
   * The row control holding focus inside this view, if any. Walks down
   * from the document's focused element through the shadow roots (this
   * view may itself sit in another component's root) instead of asking
   * this view's root directly: the document is the authority on what holds
   * focus and never reports an element whose tree was removed — happy-dom's
   * `ShadowRoot.activeElement` does, and throws on it.
   */
  focusedControl() {
    let active = this.ownerDocument.activeElement;
    while (active !== null && active !== this) {
      active = active.shadowRoot?.activeElement ?? null;
    }
    return this.controlRefOf(active === this ? this.shadowRoot?.activeElement ?? null : null);
  }
  /** The row control an element is, by its row's `data-node-id` and its own `data-control`. */
  controlRefOf(element) {
    if (!(element instanceof Element)) {
      return null;
    }
    const nodeId = element.closest("[data-node-id]")?.dataset.nodeId ?? "";
    const control = element.getAttribute("data-control") ?? "";
    return nodeId !== "" && control !== "" ? { nodeId, control } : null;
  }
  findRow(nodeId) {
    return this.renderRoot.querySelector(`[data-node-id="${CSS.escape(nodeId)}"]`);
  }
  /**
   * Moves focus to the control of the given node (used after saves and by the
   * container). `controlName` — a `data-control` value — prefers that exact
   * control over the row's first `controlSelector` match; a row can carry
   * both an own-level and a child-level control, and after saving one the
   * other must not steal focus. Omit it for the unchanged default behavior
   * (container jump / finding focus paths).
   */
  focusControl(nodeId, controlName = "") {
    const row = this.findRow(nodeId);
    if (row !== null) {
      this.focusRow(row, { preferredControl: controlName });
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
    return this.renderIssueGroup(node.errors, { className: "issues", id: `issue-${node.id}` });
  }
  /** One group renderer for every layout that presents shared inline issues. */
  renderIssueGroup(errors, options) {
    return html`<div class=${options.className} id=${options.id ?? nothing}>
            ${errors.map((error) => this.renderIssue(error, options.issueOptions?.(error)))}
        </div>`;
  }
  renderIssue(error, options = {}) {
    const pageScope = options.pageScope ?? false;
    return html`<p
            class="notice issue"
            data-state=${impactState(error.severity)}
            data-variant="inline"
            data-scope=${pageScope ? "page" : "node"}
            tabindex=${pageScope ? "-1" : nothing}
        >
            ${renderSeverityChip(error.severity, options.labelKey ?? error.key, ...options.labelArguments ?? [])}
            ${options.showViewports === false ? nothing : renderViewportBadges(error.viewports)}
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
  /**
   * Busy spinner shown next to a row's controls while its save is in flight.
   * The spinner icon is a purely visual cue, so screen-reader-only text
   * carries the state (completion is announced by the container's existing
   * pre-rendered status region after re-analysis).
   */
  renderBusySpinner(node) {
    return this.busyNodeIds.has(node.id) ? html`<typo3-backend-spinner size="small"></typo3-backend-spinner>
                  <span class="sr-only">${lll("mindfula11y.structure.saving")}</span>` : nothing;
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
   * by the node leaving `busyNodeIds` in `saveNodeValue`'s `finally`) rather than a
   * manual DOM mutation, which can disagree with the template after
   * unrelated re-renders. The select is deliberately NOT disabled while its
   * save is in flight: disabling the focused element blurs it to the
   * document body, stranding keyboard/screen-reader users for the whole
   * save window (and permanently on failure) — re-entry is guarded in
   * {@link saveNodeValue} instead.
   */
  renderValueSelect(node, opts) {
    return html`<select
            id=${opts.id}
            class="chip ${opts.className}"
            data-control=${opts.className}
            aria-label=${opts.ariaLabel ?? nothing}
            aria-labelledby=${opts.ariaLabelledby ?? nothing}
            aria-describedby=${opts.describedby ?? this.describedby(node)}
            .value=${live(opts.currentValue)}
            @change=${(event) => {
      void this.saveNodeValue(
        node,
        event.currentTarget,
        opts.currentValue,
        opts.record ?? node.record
      );
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
   * `.description`); removing the node from `busyNodeIds` below re-renders the row, and
   * `renderValueSelect`'s `live()` binding reverts the select to
   * `currentValue` without a manual `select.value =` mutation.
   */
  async saveNodeValue(node, select, currentValue, record = node.record) {
    const value = select.value;
    if (this.busyNodeIds.has(node.id) || record === null || value === currentValue) {
      return;
    }
    const saved = { nodeId: node.id, control: select.dataset.control ?? "" };
    this.restoreTarget = this.focusedControl() ?? saved;
    this.busyNodeIds = new Set(this.busyNodeIds).add(node.id);
    this.watchEditor();
    try {
      await this.recordApi.updateField(record, value);
      this.pendingFocus = saved;
      dispatch(this, "mindfula11y:structure:changed", {
        nodeId: node.id,
        tableName: record.tableName,
        uid: record.uid,
        columnName: record.columnName,
        value
      });
    } catch {
      const errorKey = `${this.labelPrefix}.error.store`;
      Notification.error(lll(errorKey), lll(`${errorKey}.description`));
    } finally {
      const busyNodeIds = new Set(this.busyNodeIds);
      busyNodeIds.delete(node.id);
      this.busyNodeIds = busyNodeIds;
      if (this.pendingFocus === null && busyNodeIds.size === 0) {
        this.stopWatchingEditor();
      }
    }
  }
  /**
   * Focuses a node row's control and flashes the highlight. If no control is
   * available, a subclass can nominate a native containing element through
   * `data-focus-fallback`; its value references the visible content that
   * temporarily names the fallback while focused. A preferred `data-control`
   * value is tried before the `controlSelector`/edit-link fallback chain;
   * callers can disable that chain when only the exact control owns the
   * relationship being followed (see {@link focusControl}).
   */
  focusRow(row, options = {}) {
    const preferredControl = options.preferredControl ?? "";
    const preferred = preferredControl === "" ? null : row.querySelector(`[data-control="${CSS.escape(preferredControl)}"]`);
    const control = preferred ?? (options.fallbackToOtherControls === false ? null : row.querySelector(this.controlSelector) ?? row.querySelector('[data-control="edit"]'));
    if (control !== null) {
      control.focus();
    } else {
      const fallback = row.closest("[data-focus-fallback]") ?? row;
      const labelId = fallback.dataset.focusFallback ?? "";
      fallback.setAttribute("tabindex", "-1");
      if (labelId !== "") {
        fallback.setAttribute("aria-labelledby", labelId);
      }
      fallback.focus();
      fallback.addEventListener(
        "blur",
        () => {
          fallback.removeAttribute("tabindex");
          fallback.removeAttribute("aria-labelledby");
        },
        { once: true }
      );
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
], StructureView.prototype, "busyNodeIds", 2);
export {
  StructureView
};
