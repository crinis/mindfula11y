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
import { dispatch } from "../../lib/types.js";
import { errorView } from "../../service/request-error.js";
import { ScanApi } from "../../service/scan/api.js";
import { ScanSessionController } from "../../service/scan/session-controller.js";
import { scanStatusView } from "../../service/scan/status-view.js";
import { ScanStatus } from "../../service/scan/types.js";
import { baseStyles } from "../../styles/base-styles.js";
import "../notice/notice.js";
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
    this.lastAnnounced = "";
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
  }
  updated() {
    const view = this.statusView();
    this.toggleAttribute("hidden", view === null);
    if (view === null) {
      return;
    }
    if (!(this.controller.result === null && this.controller.state === "loading")) {
      this.announceIfChanged(view.text);
    }
  }
  render() {
    const view = this.statusView();
    return html`${view === null ? nothing : this.renderView(view)}${this.announcer.render()}`;
  }
  /** Maps the controller's state to the callout, or null when there is nothing to show. */
  statusView() {
    const result = this.controller.result;
    if (result !== null) {
      return this.viewFromResult(result);
    }
    if (this.controller.state === "error") {
      return { state: "danger", text: errorView(this.controller.error, "mindfula11y.scan.error.loading").title };
    }
    if (this.controller.state === "loading") {
      return { state: "info", text: lll("mindfula11y.scan.loading"), showSpinner: true };
    }
    return null;
  }
  viewFromResult(result) {
    if (result.status === ScanStatus.Failed) {
      return { state: "danger", text: lll("mindfula11y.scan.error.loading") };
    }
    const view = scanStatusView(result);
    return {
      state: view.state,
      text: lll(view.labelKey, ...view.labelArgs ?? []),
      ...view.spinner === true ? { showSpinner: true } : {}
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
    return html`<mindfula11y-notice state=${view.state}>
            ${view.showSpinner === true ? html`<typo3-backend-spinner slot="icon" size="small"></typo3-backend-spinner>` : nothing}
            <span>${view.text}</span>
            ${this.scanUri !== "" && view.showSpinner !== true ? html`<a href=${this.scanUri}>${lll("mindfula11y.general.viewDetails")}</a>` : nothing}
        </mindfula11y-notice>`;
  }
};
ScanIssueCount.styles = [...baseStyles];
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
