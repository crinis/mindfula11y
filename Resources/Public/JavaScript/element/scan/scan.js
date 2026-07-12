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
import "@typo3/backend/element/icon-element.js";
import "@typo3/backend/element/spinner-element.js";
import "../notice/notice.js";
import "../scan-results/scan-results.js";
import { LiveAnnouncer } from "../../lib/live-announcer.js";
import { activateTabFromKeydown } from "../../lib/tablist.js";
import { AiAuditStatus, ScanStatus } from "../../lib/types.js";
import { RequestError } from "../../service/request-error.js";
import { ScanService } from "../../service/scan-service.js";
import { baseStyles } from "../../styles/base-styles.js";
import buttonStyles from "../../styles/button.css.js";
import noticeStyles from "../../styles/notice.css.js";
import placeholderStyles from "../../styles/placeholder.css.js";
import tabsStyles from "../../styles/tabs.css.js";
import { IMPACT_ORDER, impactState } from "../scan-results/scan-results.js";
import componentStyles from "./scan.css.js";
const POLL_DELAY_MS = 5e3;
let Scan = class extends LitElement {
  constructor() {
    super(...arguments);
    this.scanId = "";
    this.createScanDemand = null;
    this.crawlScanDemand = null;
    this.autoCreateScan = false;
    this.aiAuditAvailable = false;
    this.aiAuditDefault = false;
    this.aiAuditSkills = [];
    this.pageUrlFilter = [];
    this.urlList = [];
    this.reportBaseUrl = "";
    this.activeTab = "scan";
    this.createdScanId = "";
    this.invalidScanId = "";
    this.scanResult = null;
    this.crawlResult = null;
    this.actionBusy = false;
    this.actionError = null;
    this.aiAuditChecked = null;
    this.scanService = new ScanService();
    this.announcer = new LiveAnnouncer(this);
    this.lastStatus = "";
    this.autoCreateAttempted = false;
    this.loadTask = new Task(this, {
      args: () => [this.effectiveScanId(), this.pageUrlFilter ?? []],
      task: async ([scanId, pageUrlFilter]) => {
        if (scanId === "") {
          this.scanResult = null;
          this.crawlResult = null;
          await this.maybeAutoCreate();
          return;
        }
        const [filtered, unfiltered] = await Promise.all([
          this.scanService.loadScan(scanId, pageUrlFilter),
          this.crawlScanDemand !== null ? this.scanService.loadScan(scanId, []) : Promise.resolve(null)
        ]);
        if (filtered === null) {
          if (this.createdScanId === scanId) {
            this.createdScanId = "";
          } else {
            this.invalidScanId = scanId;
          }
          this.scanResult = null;
          this.crawlResult = null;
          this.lastStatus = "";
          return;
        }
        this.scanResult = filtered;
        this.crawlResult = unfiltered !== null && unfiltered.mode === "crawl" ? unfiltered : null;
        await this.handleStatusChange(filtered);
      }
    });
    this.handleTabKeydown = (event) => {
      void activateTabFromKeydown(this, event, this.enabledTabs(), this.activeTab, (tab) => {
        this.activeTab = tab;
      });
    };
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
  render() {
    const tabs = this.enabledTabs();
    return html`<div class="scan">
            ${tabs.length > 1 ? html`<div class="tabs" role="tablist" aria-label=${lll("mindfula11y.scan")}>
                          ${tabs.map((tab) => this.renderTab(tab))}
                      </div>` : nothing}
            ${this.announcer.render()}
            ${tabs.map((tab) => this.renderPanel(tab, tabs.length > 1))}
        </div>`;
  }
  enabledTabs() {
    return this.crawlScanDemand !== null ? ["scan", "crawl"] : ["scan"];
  }
  effectiveScanId() {
    if (this.createdScanId !== "") {
      return this.createdScanId;
    }
    return this.scanId !== this.invalidScanId ? this.scanId : "";
  }
  tabResult(tab) {
    return tab === "scan" ? this.scanResult : this.crawlResult;
  }
  tabDemand(tab) {
    return tab === "scan" ? this.createScanDemand : this.crawlScanDemand;
  }
  isScanRunning() {
    return this.scanResult !== null && this.scanService.isScanInProgress(this.scanResult.status);
  }
  isAiAuditChecked() {
    return this.aiAuditChecked ?? this.aiAuditDefault;
  }
  renderTab(tab) {
    const selected = this.activeTab === tab;
    return html`<button
            type="button"
            role="tab"
            id="tab-${tab}"
            data-tab=${tab}
            aria-selected=${selected ? "true" : "false"}
            aria-controls="panel-${tab}"
            tabindex=${selected ? "0" : "-1"}
            @click=${() => {
      this.activeTab = tab;
    }}
            @keydown=${this.handleTabKeydown}
        >
            ${lll(`mindfula11y.scan.tab.${tab}`)} ${this.renderTabBadge(tab)}
        </button>`;
  }
  renderTabBadge(tab) {
    const result = this.tabResult(tab);
    if (result === null || result.status !== ScanStatus.Completed || result.totalIssueCount === 0) {
      return nothing;
    }
    const worst = IMPACT_ORDER.find((impact) => result.violations.some((violation) => violation.impact === impact));
    return html`<span class="notice count" data-state=${impactState(worst ?? "minor")} data-variant="pill"
            ><span aria-hidden="true">${result.totalIssueCount}</span
            ><span class="sr-only"
                >${lll(
      result.totalIssueCount === 1 ? "mindfula11y.scan.issueCount" : "mindfula11y.scan.issuesCount",
      result.totalIssueCount
    )}</span
            ></span
        >`;
  }
  renderPanel(tab, withTabs) {
    const busy = this.loadTask.status === TaskStatus.PENDING || this.actionBusy;
    const content = html`<p class="description">${lll(`mindfula11y.scan.tab.${tab}.description`)}</p>
            ${this.renderHints(tab)} ${this.renderAiToggle(tab)} ${this.renderActions(tab)} ${this.renderBody(tab)}`;
    if (!withTabs) {
      return html`<div class="panel" aria-busy=${busy ? "true" : "false"}>${content}</div>`;
    }
    return html`<div
            class="panel"
            role="tabpanel"
            id="panel-${tab}"
            aria-labelledby="tab-${tab}"
            tabindex="0"
            aria-busy=${busy ? "true" : "false"}
            ?hidden=${this.activeTab !== tab}
        >
            ${content}
        </div>`;
  }
  renderHints(tab) {
    if (tab === "crawl") {
      if (this.crawlResult === null && !this.isScanRunning() && !this.actionBusy) {
        return html`<mindfula11y-notice state="info">
                    <span>
                        <span class="notice-title">${lll("mindfula11y.scan.crawl.idle.title")}</span>
                        ${lll("mindfula11y.scan.crawl.idle.description")}
                    </span>
                </mindfula11y-notice>`;
      }
      return nothing;
    }
    const urlList = this.urlList ?? [];
    if (this.scanResult !== null && this.scanResult.mode !== "crawl" && urlList.length > 0 && !this.urlListCovered(urlList, this.scanResult.targets)) {
      return html`<mindfula11y-notice state="info">
                <span>
                    <span class="notice-title">${lll("mindfula11y.scan.scopeExpanded")}</span>
                    ${lll("mindfula11y.scan.scopeExpanded.description")}
                </span>
            </mindfula11y-notice>`;
    }
    if (this.scanResult === null && !this.actionBusy && this.loadTask.status !== TaskStatus.PENDING && this.createScanDemand !== null && urlList.length > 1) {
      return html`<mindfula11y-notice state="info">
                <span>
                    <span class="notice-title">${lll("mindfula11y.scan.multiPage.manualScan")}</span>
                    ${lll("mindfula11y.scan.multiPage.manualScan.description")}
                </span>
            </mindfula11y-notice>`;
    }
    return nothing;
  }
  renderActions(tab) {
    const demand = this.tabDemand(tab);
    const result = this.tabResult(tab);
    const running = this.isScanRunning();
    const scanId = this.effectiveScanId();
    if (demand === null && !running) {
      return nothing;
    }
    const triggerKey = tab === "crawl" ? result !== null ? "mindfula11y.scan.crawl.refresh" : "mindfula11y.scan.crawl.start" : result !== null ? "mindfula11y.scan.refresh" : "mindfula11y.scan.start";
    return html`<div class="actions">
            ${demand !== null ? html`<button
                          type="button"
                          class="button"
                          data-action="trigger"
                          ?disabled=${this.actionBusy || running}
                          @click=${() => {
      void this.handleTrigger(tab);
    }}
                      >
                          ${this.actionBusy ? html`<typo3-backend-spinner size="small"></typo3-backend-spinner>` : html`<typo3-backend-icon
                                        identifier=${result !== null ? "actions-refresh" : "actions-search"}
                                        size="small"
                                    ></typo3-backend-icon>`}
                          ${lll(this.actionBusy ? "mindfula11y.scan.processing" : triggerKey)}
                      </button>` : nothing}
            ${running && scanId !== "" ? html`<button
                          type="button"
                          class="button"
                          data-action="cancel"
                          ?disabled=${this.actionBusy}
                          @click=${() => {
      void this.handleCancel();
    }}
                      >
                          <typo3-backend-icon identifier="actions-close" size="small"></typo3-backend-icon>
                          ${lll("mindfula11y.scan.cancel")}
                      </button>` : nothing}
        </div>`;
  }
  renderAiToggle(tab) {
    if (!this.aiAuditAvailable || this.tabDemand(tab) === null) {
      return nothing;
    }
    const skillNames = (this.aiAuditSkills ?? []).map((skill) => lll(`mindfula11y.scan.aiAudit.skill.${skill}`)).join(", ");
    return html`<span class="toggle">
            <input
                type="checkbox"
                id="ai-toggle-${tab}"
                class="checkbox"
                .checked=${this.isAiAuditChecked()}
                ?disabled=${this.actionBusy || this.isScanRunning()}
                aria-describedby="ai-toggle-description-${tab}"
                @change=${(event) => {
      this.aiAuditChecked = event.currentTarget.checked;
    }}
            />
            <label class="toggle-label" for="ai-toggle-${tab}">${lll("mindfula11y.scan.aiAudit.toggle")}</label>
            <span class="toggle-description" id="ai-toggle-description-${tab}"
                >${lll("mindfula11y.scan.aiAudit.toggle.description", skillNames)}</span
            >
        </span>`;
  }
  renderBody(tab) {
    if (this.actionError !== null) {
      return html`<mindfula11y-notice state="danger">
                <span>
                    <span class="notice-title">${this.actionError.title}</span>
                    ${this.actionError.description}
                </span>
            </mindfula11y-notice>`;
    }
    if (this.loadTask.status === TaskStatus.ERROR) {
      return html`<mindfula11y-notice state="danger">
                <span>
                    <span class="notice-title">${lll("mindfula11y.scan.error.loading")}</span>
                    ${this.loadErrorDescription()}
                </span>
                <button
                    type="button"
                    class="button"
                    @click=${() => {
        void this.loadTask.run();
      }}
                >
                    ${lll("mindfula11y.scan.refresh")}
                </button>
            </mindfula11y-notice>`;
    }
    const result = this.tabResult(tab);
    if (result === null) {
      if (this.loadTask.status === TaskStatus.PENDING && this.effectiveScanId() !== "") {
        return html`<div class="placeholder">
                    <typo3-backend-spinner size="default"></typo3-backend-spinner>
                    <span>${lll("mindfula11y.scan.loading")}</span>
                </div>`;
      }
      return nothing;
    }
    const hasAiReview = result.aiAudit !== null && result.aiAudit.status !== AiAuditStatus.Skipped;
    return html`${this.renderStatus(result, tab === "crawl")} ${this.renderUpdatedAt(result)}
        ${result.status === ScanStatus.Completed && (result.totalIssueCount > 0 || hasAiReview) ? html`<mindfula11y-scan-results .result=${result}></mindfula11y-scan-results>` : nothing}
        ${this.renderReportLinks(result)}`;
  }
  /** Download/view links for the stored report, closing the results. */
  renderReportLinks(result) {
    const scanId = this.effectiveScanId();
    if (result.status !== ScanStatus.Completed || scanId === "" || this.reportBaseUrl === "") {
      return nothing;
    }
    return html`<div class="actions">
            <a class="button" href=${this.buildReportUrl(scanId, "html")} target="_blank" rel="noreferrer">
                <typo3-backend-icon identifier="actions-document" size="small"></typo3-backend-icon>
                ${lll("mindfula11y.scan.report.html")}
                <span class="sr-only">${lll("mindfula11y.scan.opensNewTab")}</span>
            </a>
            <a class="button" href=${this.buildReportUrl(scanId, "pdf")} download="accessibility-report.pdf">
                <typo3-backend-icon identifier="actions-download" size="small"></typo3-backend-icon>
                ${lll("mindfula11y.scan.report.pdf")}
            </a>
        </div>`;
  }
  renderStatus(result, isCrawl) {
    switch (result.status) {
      case ScanStatus.Pending:
        return this.renderProgressNotice(lll("mindfula11y.scan.status.pending"), null);
      case ScanStatus.Running: {
        let progressText = null;
        if (isCrawl && result.progress !== null) {
          progressText = lll(
            "mindfula11y.scan.progress.pages",
            result.progress.pagesScanned,
            result.progress.pagesDiscovered
          );
          if (result.progress.pagesFailed > 0) {
            progressText += ` \u2014 ${lll("mindfula11y.scan.progress.pagesFailed", result.progress.pagesFailed)}`;
          }
        }
        return this.renderProgressNotice(lll("mindfula11y.scan.status.running"), progressText);
      }
      case ScanStatus.Analyzing: {
        const audit = result.aiAudit;
        const progressText = audit !== null && audit.tasksTotal > 0 ? lll("mindfula11y.scan.aiAudit.status.running", audit.tasksCompleted, audit.tasksTotal) : null;
        return this.renderProgressNotice(lll("mindfula11y.scan.status.analyzing"), progressText);
      }
      case ScanStatus.Failed:
        return html`<mindfula11y-notice state="danger">
                    <span>
                        <span class="notice-title">${lll("mindfula11y.scan.status.failed")}</span>
                        ${lll("mindfula11y.scan.status.failed.description")}
                    </span>
                </mindfula11y-notice>`;
      case ScanStatus.Canceled:
        return html`<mindfula11y-notice state="info">
                    <span>
                        <span class="notice-title">${lll("mindfula11y.scan.status.canceled")}</span>
                        ${lll("mindfula11y.scan.status.canceled.description")}
                    </span>
                </mindfula11y-notice>`;
      default:
        return result.totalIssueCount > 0 ? html`<mindfula11y-notice state="warning">
                          <span>${lll("mindfula11y.scan.issuesFound", result.totalIssueCount)}</span>
                      </mindfula11y-notice>` : html`<mindfula11y-notice state="success">
                          <span>${lll("mindfula11y.scan.noIssues")}</span>
                      </mindfula11y-notice>`;
    }
  }
  renderProgressNotice(title, progressText) {
    return html`<mindfula11y-notice state="info">
            <typo3-backend-spinner slot="icon" size="small"></typo3-backend-spinner>
            <span>${title}${progressText !== null ? html` — ${progressText}` : nothing}</span>
        </mindfula11y-notice>`;
  }
  renderUpdatedAt(result) {
    if (result.updatedAt === null) {
      return nothing;
    }
    const date = new Date(result.updatedAt);
    if (Number.isNaN(date.getTime())) {
      return nothing;
    }
    const formatted = new Intl.DateTimeFormat(void 0, { dateStyle: "medium", timeStyle: "short" }).format(date);
    return html`<p class="meta">
            ${lll("mindfula11y.scan.updatedAt")}
            <time datetime=${result.updatedAt}>${formatted}</time>
        </p>`;
  }
  loadErrorDescription() {
    const error = this.loadTask.error;
    if (error instanceof RequestError) {
      return error.description !== "" ? error.description : error.message;
    }
    return lll("mindfula11y.scan.error.getFailed.description");
  }
  buildReportUrl(scanId, format) {
    return `${this.reportBaseUrl}&scanId=${encodeURIComponent(scanId)}&format=${format}`;
  }
  urlListCovered(urlList, targets) {
    const targetSet = new Set(targets);
    return urlList.every((url) => targetSet.has(url));
  }
  /** Auto-creates the initial scan once — never with the AI audit (cost). */
  async maybeAutoCreate() {
    if (!this.autoCreateScan || this.createScanDemand === null || this.autoCreateAttempted) {
      return;
    }
    this.autoCreateAttempted = true;
    try {
      const created = await this.scanService.createScan(this.createScanDemand);
      this.lastStatus = created.status;
      this.createdScanId = created.scanId;
      await this.announcer.announce(lll("mindfula11y.scan.announce.started"));
    } catch (error) {
      this.actionError = this.toActionError(error, "mindfula11y.scan.error.createFailed");
      await this.announcer.announce(this.actionError.title);
    }
  }
  async handleTrigger(tab) {
    const demand = this.tabDemand(tab);
    if (demand === null || this.actionBusy) {
      return;
    }
    this.actionBusy = true;
    this.actionError = null;
    try {
      const created = await this.scanService.createScan(demand, this.aiAuditAvailable && this.isAiAuditChecked());
      this.lastStatus = created.status;
      this.scanResult = null;
      this.crawlResult = null;
      this.invalidScanId = this.scanId;
      this.createdScanId = created.scanId;
      await this.announcer.announce(lll("mindfula11y.scan.announce.started"));
    } catch (error) {
      this.actionError = this.toActionError(error, "mindfula11y.scan.error.createFailed");
      await this.announcer.announce(this.actionError.title);
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
    try {
      await this.scanService.cancelScan(scanId);
    } catch (error) {
      this.actionError = this.toActionError(error, "mindfula11y.scan.error.cancelFailed");
      await this.announcer.announce(this.actionError.title);
    } finally {
      this.actionBusy = false;
      void this.loadTask.run();
    }
  }
  toActionError(error, fallbackKey) {
    if (error instanceof RequestError) {
      return { title: error.message, description: error.description };
    }
    return { title: lll(fallbackKey), description: lll(`${fallbackKey}.description`) };
  }
  /** Announces terminal transitions and keeps polling while the scan runs. */
  async handleStatusChange(result) {
    const wasInProgress = this.lastStatus !== "" && this.scanService.isScanInProgress(this.lastStatus);
    if (this.scanService.isScanInProgress(result.status)) {
      this.schedulePoll();
    } else if (wasInProgress) {
      const scanId = this.effectiveScanId();
      if (result.status === ScanStatus.Completed) {
        this.dispatchEvent(
          new CustomEvent("mindfula11y:scan:completed", {
            bubbles: true,
            composed: true,
            detail: { scanId, totalIssueCount: result.totalIssueCount }
          })
        );
        await this.announcer.announce(lll("mindfula11y.scan.announce.completed", result.totalIssueCount));
      } else if (result.status === ScanStatus.Canceled) {
        this.dispatchEvent(
          new CustomEvent("mindfula11y:scan:canceled", {
            bubbles: true,
            composed: true,
            detail: { scanId }
          })
        );
        await this.announcer.announce(lll("mindfula11y.scan.announce.canceled"));
      } else if (result.status === ScanStatus.Failed) {
        await this.announcer.announce(lll("mindfula11y.scan.announce.failed"));
      }
    }
    this.lastStatus = result.status;
  }
  schedulePoll() {
    window.clearTimeout(this.pollTimer);
    this.pollTimer = window.setTimeout(() => {
      this.loadTask.run().catch(() => {
      });
    }, POLL_DELAY_MS);
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
  property({ type: Array, attribute: "ai-audit-skills" })
], Scan.prototype, "aiAuditSkills", 2);
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
], Scan.prototype, "activeTab", 2);
__decorateClass([
  state()
], Scan.prototype, "createdScanId", 2);
__decorateClass([
  state()
], Scan.prototype, "invalidScanId", 2);
__decorateClass([
  state()
], Scan.prototype, "scanResult", 2);
__decorateClass([
  state()
], Scan.prototype, "crawlResult", 2);
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
