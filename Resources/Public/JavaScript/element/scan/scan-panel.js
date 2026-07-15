import { lll } from "@typo3/core/lit-helper.js";
import { html, nothing } from "lit";
import "@typo3/backend/element/icon-element.js";
import "@typo3/backend/element/spinner-element.js";
import { AiAuditStatus, ScanStatus } from "../../lib/types.js";
import { withQueryParams } from "../../lib/url.js";
import "../notice/notice.js";
import "../scan-results/scan-results.js";
function urlListCovered(urlList, targets) {
  const targetSet = new Set(targets);
  return urlList.every((url) => targetSet.has(url));
}
function buildReportUrl(reportBaseUrl, scanId, format) {
  return withQueryParams(reportBaseUrl, { scanId, format });
}
function renderProgressNotice(title, progressText) {
  return html`<mindfula11y-notice state="info">
        <typo3-backend-spinner slot="icon" size="small"></typo3-backend-spinner>
        <span>${title}${progressText !== null ? html` — ${progressText}` : nothing}</span>
    </mindfula11y-notice>`;
}
function renderStatus(result, isCrawl) {
  switch (result.status) {
    case ScanStatus.Pending:
      return renderProgressNotice(lll("mindfula11y.scan.status.pending"), null);
    case ScanStatus.Running: {
      let progressText = null;
      if (isCrawl && result.progress !== null) {
        progressText = result.progress.pagesDiscovered === 0 ? lll("mindfula11y.scan.progress.discovering") : lll(
          "mindfula11y.scan.progress.pages",
          result.progress.pagesScanned,
          result.progress.pagesDiscovered
        );
        if (result.progress.pagesFailed > 0) {
          progressText += ` \u2014 ${lll("mindfula11y.scan.progress.pagesFailed", result.progress.pagesFailed)}`;
        }
      }
      return renderProgressNotice(lll("mindfula11y.scan.status.running"), progressText);
    }
    case ScanStatus.Analyzing: {
      const audit = result.aiAudit;
      const progressText = audit !== null && audit.tasksTotal > 0 ? lll("mindfula11y.scan.aiAudit.status.running", audit.tasksCompleted, audit.tasksTotal) : null;
      return renderProgressNotice(lll("mindfula11y.scan.status.analyzing"), progressText);
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
function renderUpdatedAt(result) {
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
function renderReportLinks(result, scanId, reportBaseUrl) {
  if (result.status !== ScanStatus.Completed || scanId === "" || reportBaseUrl === "") {
    return nothing;
  }
  return html`<div class="actions">
        <a class="button" href=${buildReportUrl(reportBaseUrl, scanId, "html")} target="_blank" rel="noreferrer">
            <typo3-backend-icon identifier="actions-document" size="small"></typo3-backend-icon>
            ${lll("mindfula11y.scan.report.html")}
            <span class="sr-only">${lll("mindfula11y.scan.opensNewTab")}</span>
        </a>
        <a class="button" href=${buildReportUrl(reportBaseUrl, scanId, "pdf")} download="accessibility-report.pdf">
            <typo3-backend-icon identifier="actions-download" size="small"></typo3-backend-icon>
            ${lll("mindfula11y.scan.report.pdf")}
        </a>
    </div>`;
}
function renderHints(data) {
  if (data.tab === "crawl") {
    if (data.result === null && !data.running && !data.actionBusy) {
      return html`<mindfula11y-notice state="info">
                <span>
                    <span class="notice-title">${lll("mindfula11y.scan.crawl.idle.title")}</span>
                    ${lll("mindfula11y.scan.crawl.idle.description")}
                </span>
            </mindfula11y-notice>`;
    }
    return nothing;
  }
  const urlList = data.urlList;
  const result = data.result;
  if (result !== null && result.mode !== "crawl" && urlList.length > 0 && !urlListCovered(urlList, result.targets)) {
    return html`<mindfula11y-notice state="info">
            <span>
                <span class="notice-title">${lll("mindfula11y.scan.scopeExpanded")}</span>
                ${lll("mindfula11y.scan.scopeExpanded.description")}
            </span>
        </mindfula11y-notice>`;
  }
  if (result === null && !data.actionBusy && data.controllerState !== "loading" && data.demand !== null && urlList.length > 1) {
    return html`<mindfula11y-notice state="info">
            <span>
                <span class="notice-title">${lll("mindfula11y.scan.multiPage.manualScan")}</span>
                ${lll("mindfula11y.scan.multiPage.manualScan.description")}
            </span>
        </mindfula11y-notice>`;
  }
  return nothing;
}
function renderActions(data, onTrigger, onCancel) {
  const { demand, result, running, scanId, actionBusy, tab } = data;
  if (demand === null && !running) {
    return nothing;
  }
  const triggerKey = `mindfula11y.scan.${tab === "crawl" ? "crawl." : ""}${result !== null ? "refresh" : "start"}`;
  return html`<div class="actions">
        ${demand !== null ? html`<button type="button" class="button" data-action="trigger" ?disabled=${actionBusy || running} @click=${onTrigger}>
                      ${actionBusy ? html`<typo3-backend-spinner size="small"></typo3-backend-spinner>` : html`<typo3-backend-icon
                                    identifier=${result !== null ? "actions-refresh" : "actions-search"}
                                    size="small"
                                ></typo3-backend-icon>`}
                      ${lll(actionBusy ? "mindfula11y.scan.processing" : triggerKey)}
                  </button>` : nothing}
        ${running && scanId !== "" ? html`<button type="button" class="button" data-action="cancel" ?disabled=${actionBusy} @click=${onCancel}>
                      <typo3-backend-icon identifier="actions-close" size="small"></typo3-backend-icon>
                      ${lll("mindfula11y.scan.cancel")}
                  </button>` : nothing}
    </div>`;
}
function renderAiToggle(data, onChange) {
  if (!data.aiAuditAvailable || data.demand === null) {
    return nothing;
  }
  return html`<span class="toggle">
        <input
            type="checkbox"
            id="ai-toggle-${data.tab}"
            class="checkbox"
            .checked=${data.aiAuditChecked}
            ?disabled=${data.actionBusy || data.running}
            aria-describedby="ai-toggle-description-${data.tab}"
            @change=${(event) => onChange(event.currentTarget.checked)}
        />
        <label class="toggle-label" for="ai-toggle-${data.tab}">${lll("mindfula11y.scan.aiAudit.toggle")}</label>
        <span class="toggle-description" id="ai-toggle-description-${data.tab}"
            >${lll("mindfula11y.scan.aiAudit.toggle.description")}</span
        >
    </span>`;
}
function renderRequestError(data, onReload) {
  if (data.actionError !== null) {
    return html`<mindfula11y-notice state="danger">
            <span>
                <span class="notice-title">${data.actionError.title}</span>
                ${data.actionError.description}
            </span>
        </mindfula11y-notice>`;
  }
  if (data.controllerState === "error") {
    return html`<mindfula11y-notice state="danger">
            <span>
                <span class="notice-title">${lll("mindfula11y.scan.error.loading")}</span>
                ${data.loadErrorDescription}
            </span>
            <button type="button" class="button" @click=${onReload}>${lll("mindfula11y.scan.refresh")}</button>
        </mindfula11y-notice>`;
  }
  return nothing;
}
function renderBody(data) {
  if (data.actionError !== null || data.controllerState === "error") {
    return nothing;
  }
  const result = data.result;
  if (result === null) {
    if (data.controllerState === "loading" && data.scanId !== "") {
      return html`<div class="placeholder">
                <typo3-backend-spinner size="default"></typo3-backend-spinner>
                <span>${lll("mindfula11y.scan.loading")}</span>
            </div>`;
    }
    return nothing;
  }
  const hasAiReview = result.aiAudit !== null && result.aiAudit.status !== AiAuditStatus.Skipped;
  return html`${renderStatus(result, data.tab === "crawl")} ${renderUpdatedAt(result)}
    ${result.status === ScanStatus.Completed && (result.totalIssueCount > 0 || hasAiReview) ? html`<mindfula11y-scan-results .result=${result}></mindfula11y-scan-results>` : nothing}
    ${renderReportLinks(result, data.scanId, data.reportBaseUrl)}`;
}
function renderPanelContent(data, callbacks) {
  return html`<p class="description">${lll(`mindfula11y.scan.tab.${data.tab}.description`)}</p>
        ${renderHints(data)}
        ${renderAiToggle(data, callbacks.onAiToggleChange)}
        ${renderActions(data, () => callbacks.onTrigger(data.tab), callbacks.onCancel)}
        <div class="status-region" role="status">${renderRequestError(data, callbacks.onReload)}</div>
        ${renderBody(data)}`;
}
export {
  buildReportUrl,
  renderPanelContent
};
