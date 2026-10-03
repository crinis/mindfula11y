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
import { html, LitElement, nothing } from "lit";
import { customElement, property } from "lit/decorators.js";
import "@typo3/backend/element/spinner-element.js";
import { LiveAnnouncer } from "../../lib/live-announcer.js";
import { scanStatusView } from "../../lib/scan/status-view.js";
import { isScanInProgress, ScanStatus } from "../../lib/scan/types.js";
import { renderProgressNotice } from "../../lib/status-render.js";
import { dispatch } from "../../lib/types.js";
import { errorView } from "../../service/request-error.js";
import { ScanApi } from "../../service/scan/api.js";
import { ScanSessionController } from "../../service/scan/session-controller.js";
import { baseStyles } from "../../styles/base-styles.js";
import buttonStyles from "../../styles/button.css.js";
import "../notice/notice.js";
import componentStyles from "./scan-issue-count.css.js";
const announcementFor = (view) => {
  const text = view.announceLabelKey === void 0 ? view.text : lll(view.announceLabelKey, view.count ?? 0);
  return view.detail === void 0 ? text : `${text} ${view.detail}`;
};
let ScanIssueCount = class extends LitElement {
  constructor() {
    super(...arguments);
    this.scanId = "";
    this.scanUri = "";
    this.createScanDemand = null;
    this.autoCreateScan = false;
    this.pageUrlFilter = [];
    this.scanApi = new ScanApi();
    this.announcer = new LiveAnnouncer(this);
    /**
     * Custom-state set for the `--empty` host state (styled in the component
     * stylesheet). Guarded: below Baseline 2024 (`attachInternals` Safari
     * < 16.4, `CustomStateSet` Safari < 17.4 / Firefox < 126) the host keeps
     * an empty flex slot — a cosmetic regression, never a crash.
     */
    this.states = typeof this.attachInternals === "function" ? this.attachInternals().states ?? null : null;
    this.lastAnnounced = "";
    /** Set while a Retry is loading: its button leaves the DOM meanwhile. */
    this.refocusRetry = false;
    this.controller = new ScanSessionController(this, {
      service: this.scanApi,
      scanId: () => this.scanId,
      // A demand only auto-creates when the editor opted in; there is no
      // manual trigger in this compact callout.
      demand: () => this.autoCreateScan ? this.createScanDemand : null,
      // Lit's JSON attribute converter yields null (not the default) for a
      // missing/malformed attribute value.
      pageUrlFilter: () => this.pageUrlFilter ?? [],
      onTransition: (previous, result) => this.handleTransition(previous, result)
    });
    this.handleRetry = () => {
      this.refocusRetry = true;
      void this.controller.reload();
    };
  }
  updated() {
    const view = this.statusView();
    if (view === null) {
      this.states?.add("--empty");
      return;
    }
    this.states?.delete("--empty");
    if (!(this.controller.result === null && this.controller.state === "loading")) {
      this.announceIfChanged(announcementFor(view));
    }
    if (this.refocusRetry && this.controller.state !== "loading") {
      this.refocusRetry = false;
      const active = this.ownerDocument.activeElement;
      if (active === null || active === this.ownerDocument.body) {
        this.renderRoot.querySelector('button[data-action="retry"]')?.focus();
      }
    }
  }
  render() {
    const view = this.statusView();
    return html`${view === null ? nothing : this.renderView(view)}${this.announcer.render()}`;
  }
  /**
   * Maps the controller's state to the callout, or null when there is nothing to show.
   *
   * A failed load wins over a result that is still in progress: polling
   * backs off and stops after repeated failures (at once on a 401/403), so
   * that result's "Scan running" would otherwise stay up with its spinner
   * for good. A settled result stays — no later load can change it. With a
   * scan to load, the error offers Retry, which also restarts polling; this
   * compact callout has no other way back after, say, a re-login.
   */
  statusView() {
    const result = this.controller.result;
    if (this.controller.state === "error" && (result === null || isScanInProgress(result.status))) {
      return {
        state: "danger",
        text: errorView(this.controller.error, "mindfula11y.scan.error.loading").title,
        retry: this.controller.effectiveScanId() !== ""
      };
    }
    if (result !== null) {
      return this.viewFromResult(result);
    }
    if (this.controller.state === "loading") {
      return { state: "info", text: lll("mindfula11y.scan.loading"), spinner: true };
    }
    return null;
  }
  viewFromResult(result) {
    if (result.status === ScanStatus.Failed) {
      return { state: "danger", text: lll("mindfula11y.scan.error.loading") };
    }
    const { labelKey, descriptionKey: _description, pagesFailed, ...view } = scanStatusView(result);
    return {
      ...view,
      text: lll(labelKey),
      ...pagesFailed === void 0 ? {} : { detail: lll("mindfula11y.scan.pagesFailed", pagesFailed.failed, pagesFailed.total) }
    };
  }
  handleTransition(previous, result) {
    if (previous !== null && previous !== ScanStatus.Completed && result.status === ScanStatus.Completed) {
      dispatch(this, "mindfula11y:scan:completed", {
        scanId: this.controller.effectiveScanId(),
        totalIssueCount: result.totalIssueCount
      });
    }
  }
  announceIfChanged(text) {
    if (text === this.lastAnnounced) {
      return;
    }
    this.lastAnnounced = text;
    void this.announcer.announce(text);
  }
  renderView(view) {
    if (view.spinner === true) {
      return renderProgressNotice(view.text);
    }
    return html`<mindfula11y-notice state=${view.state} count=${view.count ?? nothing}>
            <span>${view.text}${view.detail === void 0 ? nothing : html` — ${view.detail}`}</span>
            ${view.retry === true ? html`<button type="button" slot="trailing" class="button" data-action="retry" @click=${this.handleRetry}>
                          ${lll("mindfula11y.scan.retry")}<span class="sr-only"> ${lll("mindfula11y.scan")}</span>
                      </button>` : nothing}
            ${this.scanUri === "" ? nothing : html`<a slot="trailing" href=${this.scanUri}
                          >${lll("mindfula11y.general.viewDetails")}<span class="sr-only">
                              ${lll("mindfula11y.scan")}</span
                          ></a
                      >`}
        </mindfula11y-notice>`;
  }
};
ScanIssueCount.styles = [...baseStyles, buttonStyles, componentStyles];
__decorateClass([
  property({ attribute: "scan-id" })
], ScanIssueCount.prototype, "scanId", 2);
__decorateClass([
  property({ attribute: "scan-uri" })
], ScanIssueCount.prototype, "scanUri", 2);
__decorateClass([
  property({ type: Object, attribute: "create-scan-demand" })
], ScanIssueCount.prototype, "createScanDemand", 2);
__decorateClass([
  property({ type: Boolean, attribute: "auto-create-scan" })
], ScanIssueCount.prototype, "autoCreateScan", 2);
__decorateClass([
  property({ type: Array, attribute: "page-url-filter" })
], ScanIssueCount.prototype, "pageUrlFilter", 2);
ScanIssueCount = __decorateClass([
  customElement("mindfula11y-scan-issue-count")
], ScanIssueCount);
export {
  ScanIssueCount
};
