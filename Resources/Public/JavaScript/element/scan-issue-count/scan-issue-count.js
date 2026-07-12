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
import { Task, TaskStatus } from "@lit/task";
import { lll } from "@typo3/core/lit-helper.js";
import { html, LitElement, nothing } from "lit";
import { customElement, property, state } from "lit/decorators.js";
import "@typo3/backend/element/spinner-element.js";
import { LiveAnnouncer } from "../../lib/live-announcer.js";
import { ScanStatus } from "../../lib/types.js";
import { ScanService } from "../../service/scan-service.js";
import { baseStyles } from "../../styles/base-styles.js";
import "../notice/notice.js";
const POLL_DELAY_MS = 5e3;
let ScanIssueCount = class extends LitElement {
  constructor() {
    super(...arguments);
    this.scanId = "";
    this.scanUri = "";
    this.createScanDemand = null;
    this.autoCreateScan = false;
    this.pageUrlFilter = [];
    this.createdScanId = "";
    this.scanService = new ScanService();
    this.announcer = new LiveAnnouncer(this);
    this.lastStatus = "";
    this.lastAnnounced = "";
    this.scanTask = new Task(this, {
      args: () => [
        this.scanId || this.createdScanId,
        this.createScanDemand,
        // Lit's JSON attribute converter yields null (not the default) for
        // a missing/malformed attribute value.
        this.pageUrlFilter ?? []
      ],
      task: async ([scanId, demand, pageUrlFilter]) => {
        if (scanId === "") {
          if (demand === null || !this.autoCreateScan) {
            return null;
          }
          const created = await this.scanService.createScan(demand);
          this.createdScanId = created.scanId;
          return { state: "info", text: lll("mindfula11y.scan.status.pending"), showSpinner: true };
        }
        const result = await this.scanService.loadScan(scanId, pageUrlFilter);
        if (result === null) {
          this.createdScanId = "";
          return null;
        }
        if (this.scanService.isScanInProgress(result.status)) {
          this.schedulePoll();
          let label = lll("mindfula11y.scan.status.pending");
          if (result.status === ScanStatus.Running) {
            label = lll("mindfula11y.scan.status.running");
          } else if (result.status === ScanStatus.Analyzing) {
            label = lll("mindfula11y.scan.status.analyzing");
          }
          this.lastStatus = result.status;
          return { state: "info", text: label, showSpinner: true };
        }
        if (result.status === ScanStatus.Failed) {
          this.lastStatus = result.status;
          return { state: "danger", text: lll("mindfula11y.scan.error.loading") };
        }
        if (result.status === ScanStatus.Canceled) {
          this.lastStatus = result.status;
          return { state: "info", text: lll("mindfula11y.scan.status.canceled") };
        }
        if (this.lastStatus !== ScanStatus.Completed && this.lastStatus !== "") {
          this.dispatchEvent(
            new CustomEvent("mindfula11y:scan:completed", {
              bubbles: true,
              composed: true,
              detail: { scanId, totalIssueCount: result.totalIssueCount }
            })
          );
        }
        this.lastStatus = result.status;
        if (result.totalIssueCount > 0) {
          return { state: "warning", text: lll("mindfula11y.scan.issuesFound", result.totalIssueCount) };
        }
        return { state: "success", text: lll("mindfula11y.scan.noIssues") };
      }
    });
  }
  connectedCallback() {
    super.connectedCallback();
    if (this.lastStatus !== "" && this.scanService.isScanInProgress(this.lastStatus)) {
      this.schedulePoll();
    }
  }
  disconnectedCallback() {
    super.disconnectedCallback();
    window.clearTimeout(this.pollTimer);
  }
  updated() {
    this.toggleAttribute("hidden", this.scanTask.status === TaskStatus.COMPLETE && this.scanTask.value === null);
    const view = this.scanTask.value;
    if (this.scanTask.status === TaskStatus.COMPLETE && view !== null && view !== void 0) {
      this.announceIfChanged(view.text);
    } else if (this.scanTask.status === TaskStatus.ERROR) {
      this.announceIfChanged(this.errorText(this.scanTask.error));
    }
  }
  render() {
    return html`${this.scanTask.render({
      pending: () => this.renderView({ state: "info", text: lll("mindfula11y.scan.loading"), showSpinner: true }),
      complete: (view) => view === null ? nothing : this.renderView(view),
      error: (error) => this.renderView({ state: "danger", text: this.errorText(error) })
    })}${this.announcer.render()}`;
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
  errorText(error) {
    if (error instanceof Error && error.message !== "") {
      return error.message;
    }
    return lll("mindfula11y.scan.error.loading");
  }
  schedulePoll() {
    window.clearTimeout(this.pollTimer);
    this.pollTimer = window.setTimeout(() => {
      this.scanTask.run().catch(() => {
      });
    }, POLL_DELAY_MS);
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
__decorateClass([
  state()
], ScanIssueCount.prototype, "createdScanId", 2);
ScanIssueCount = __decorateClass([
  customElement("mindfula11y-scan-issue-count")
], ScanIssueCount);
export {
  ScanIssueCount
};
