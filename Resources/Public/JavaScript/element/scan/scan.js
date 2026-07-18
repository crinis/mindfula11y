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
import { customElement, property, state } from "lit/decorators.js";
import { LiveAnnouncer } from "../../lib/live-announcer.js";
import { IMPACT_ORDER, impactState, renderCountBadge } from "../../lib/status-render.js";
import { TabsController } from "../../lib/tabs.js";
import { dispatch } from "../../lib/types.js";
import { errorView, RequestError } from "../../service/request-error.js";
import { ScanApi } from "../../service/scan/api.js";
import { ScanSessionController } from "../../service/scan/session-controller.js";
import { isScanInProgress, ScanStatus } from "../../service/scan/types.js";
import { baseStyles } from "../../styles/base-styles.js";
import buttonStyles from "../../styles/button.css.js";
import noticeStyles from "../../styles/notice.css.js";
import placeholderStyles from "../../styles/placeholder.css.js";
import tabsStyles from "../../styles/tabs.css.js";
import componentStyles from "./scan.css.js";
import { renderPanelContent } from "./scan-panel.js";
let Scan = class extends LitElement {
  constructor() {
    super(...arguments);
    this.scanId = "";
    this.createScanDemand = null;
    this.crawlScanDemand = null;
    this.autoCreateScan = false;
    this.aiAuditAvailable = false;
    this.aiAuditDefault = false;
    this.pageUrlFilter = [];
    this.urlList = [];
    this.reportBaseUrl = "";
    this.actionBusy = false;
    this.actionError = null;
    this.aiAuditChecked = null;
    this.scanApi = new ScanApi();
    this.announcer = new LiveAnnouncer(this);
    this.tabs = new TabsController(this, () => this.enabledTabs(), "scan");
    this.controller = new ScanSessionController(this, {
      service: this.scanApi,
      scanId: () => this.scanId,
      // A demand only auto-creates when the editor opted in; the manual
      // trigger buttons call controller.createScan directly with their demand.
      demand: () => this.autoCreateScan ? this.createScanDemand : null,
      pageUrlFilter: () => this.pageUrlFilter ?? [],
      // The crawl tab reads the same scan unfiltered — only when the stored
      // scan actually is a crawl.
      withCrawlResult: () => this.crawlScanDemand !== null,
      onTransition: (previous, result) => {
        void this.handleTransition(previous, result);
      }
    });
    this.panelCallbacks = {
      onTrigger: (tab) => {
        void this.handleTrigger(tab);
      },
      onCancel: () => {
        void this.handleCancel();
      },
      onAiToggleChange: (checked) => {
        this.aiAuditChecked = checked;
      },
      onReload: () => {
        void this.controller.reload();
      }
    };
  }
  render() {
    const tabs = this.enabledTabs();
    return html`<div class="scan">
            ${tabs.length > 1 ? this.tabs.renderTablist({
      ariaLabel: lll("mindfula11y.scan"),
      tabs: tabs.map((tab) => this.tabDescriptor(tab))
    }) : nothing}
            ${this.announcer.render()}
            ${tabs.map((tab) => this.renderPanel(tab, tabs.length > 1))}
        </div>`;
  }
  enabledTabs() {
    return this.crawlScanDemand !== null ? ["scan", "crawl"] : ["scan"];
  }
  effectiveScanId() {
    return this.controller.effectiveScanId();
  }
  tabResult(tab) {
    return tab === "scan" ? this.controller.result : this.controller.crawlResult;
  }
  tabDemand(tab) {
    return tab === "scan" ? this.createScanDemand : this.crawlScanDemand;
  }
  isScanRunning() {
    return this.controller.result !== null && isScanInProgress(this.controller.result.status);
  }
  isAiAuditChecked() {
    return this.aiAuditChecked ?? this.aiAuditDefault;
  }
  tabDescriptor(tab) {
    return {
      id: tab,
      label: lll(`mindfula11y.scan.tab.${tab}`),
      badge: this.renderTabBadge(tab)
    };
  }
  renderTabBadge(tab) {
    const result = this.tabResult(tab);
    if (result === null || result.status !== ScanStatus.Completed || result.totalIssueCount === 0) {
      return nothing;
    }
    const worst = IMPACT_ORDER.find((impact) => result.violations.some((violation) => violation.impact === impact));
    return renderCountBadge(
      impactState(worst ?? "minor"),
      result.totalIssueCount,
      lll(
        result.totalIssueCount === 1 ? "mindfula11y.scan.issueCount" : "mindfula11y.scan.issuesCount",
        result.totalIssueCount
      )
    );
  }
  renderPanel(tab, withTabs) {
    const busy = this.actionBusy || this.controller.state === "loading" && this.controller.result === null;
    const content = renderPanelContent(this.panelData(tab), this.panelCallbacks);
    return this.tabs.renderPanel({
      tab,
      withTablist: withTabs,
      busy,
      content
    });
  }
  panelData(tab) {
    return {
      tab,
      result: this.tabResult(tab),
      demand: this.tabDemand(tab),
      running: this.isScanRunning(),
      scanId: this.effectiveScanId(),
      controllerState: this.controller.state,
      urlList: this.urlList ?? [],
      actionBusy: this.actionBusy,
      actionError: this.actionError,
      loadErrorDescription: this.loadErrorDescription(),
      aiAuditAvailable: this.aiAuditAvailable,
      aiAuditChecked: this.isAiAuditChecked(),
      reportBaseUrl: this.reportBaseUrl
    };
  }
  loadErrorDescription() {
    return errorView(this.controller.error, "mindfula11y.scan.error.getFailed").description;
  }
  async handleTrigger(tab) {
    const demand = this.tabDemand(tab);
    if (demand === null || this.actionBusy) {
      return;
    }
    this.actionBusy = true;
    this.actionError = null;
    try {
      await this.controller.createScan(demand, this.aiAuditAvailable && this.isAiAuditChecked());
    } catch (error) {
      this.actionError = errorView(error, "mindfula11y.scan.error.createFailed");
    } finally {
      this.actionBusy = false;
    }
  }
  async handleCancel() {
    const scanId = this.effectiveScanId();
    if (scanId === "" || this.actionBusy) {
      return;
    }
    this.actionBusy = true;
    this.actionError = null;
    try {
      await this.controller.cancelScan();
    } catch (error) {
      if (!(error instanceof RequestError && error.status === 409)) {
        this.actionError = errorView(error, "mindfula11y.scan.error.cancelFailed");
      }
    } finally {
      this.actionBusy = false;
    }
  }
  /**
   * Announces the session's transitions and dispatches the terminal events.
   * `previous === null` is a freshly created scan (the "started" transition);
   * otherwise a terminal status settled from an in-progress one.
   */
  async handleTransition(previous, result) {
    if (previous === null) {
      await this.announcer.announce(lll("mindfula11y.scan.announce.started"));
      return;
    }
    const scanId = this.effectiveScanId();
    if (result.status === ScanStatus.Completed) {
      dispatch(this, "mindfula11y:scan:completed", { scanId, totalIssueCount: result.totalIssueCount });
      await this.announcer.announce(lll("mindfula11y.scan.announce.completed", result.totalIssueCount));
    } else if (result.status === ScanStatus.Canceled) {
      dispatch(this, "mindfula11y:scan:canceled", { scanId });
      await this.announcer.announce(lll("mindfula11y.scan.announce.canceled"));
    } else if (result.status === ScanStatus.Failed) {
      await this.announcer.announce(lll("mindfula11y.scan.announce.failed"));
    }
  }
};
Scan.styles = [
  ...baseStyles,
  noticeStyles,
  tabsStyles,
  buttonStyles,
  placeholderStyles,
  componentStyles
];
__decorateClass([
  property({ attribute: "scan-id" })
], Scan.prototype, "scanId", 2);
__decorateClass([
  property({ type: Object, attribute: "create-scan-demand" })
], Scan.prototype, "createScanDemand", 2);
__decorateClass([
  property({ type: Object, attribute: "crawl-scan-demand" })
], Scan.prototype, "crawlScanDemand", 2);
__decorateClass([
  property({ type: Boolean, attribute: "auto-create-scan" })
], Scan.prototype, "autoCreateScan", 2);
__decorateClass([
  property({ type: Boolean, attribute: "ai-audit-available" })
], Scan.prototype, "aiAuditAvailable", 2);
__decorateClass([
  property({ type: Boolean, attribute: "ai-audit-default" })
], Scan.prototype, "aiAuditDefault", 2);
__decorateClass([
  property({ type: Array, attribute: "page-url-filter" })
], Scan.prototype, "pageUrlFilter", 2);
__decorateClass([
  property({ type: Array, attribute: "url-list" })
], Scan.prototype, "urlList", 2);
__decorateClass([
  property({ attribute: "report-base-url" })
], Scan.prototype, "reportBaseUrl", 2);
__decorateClass([
  state()
], Scan.prototype, "actionBusy", 2);
__decorateClass([
  state()
], Scan.prototype, "actionError", 2);
__decorateClass([
  state()
], Scan.prototype, "aiAuditChecked", 2);
Scan = __decorateClass([
  customElement("mindfula11y-scan")
], Scan);
export {
  Scan
};
