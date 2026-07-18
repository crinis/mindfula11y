import { lll } from "@typo3/core/lit-helper.js";
import { html, nothing } from "lit";
import "@typo3/backend/element/icon-element.js";
import "@typo3/backend/element/spinner-element.js";
import { renderLoadingPlaceholder, renderNoticeBody } from "../../lib/status-render.js";
import { withQueryParams } from "../../lib/url.js";
import { scanStatusView } from "../../service/scan/status-view.js";
import { AiAuditStatus, ScanStatus } from "../../service/scan/types.js";
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
function progressDetail(result, isCrawl) {
  if (result.status === ScanStatus.Running) {
    if (!isCrawl || result.progress === null) {
      return null;
    }
    let progressText = result.progress.pagesDiscovered === 0 ? lll("mindfula11y.scan.progress.discovering") : lll("mindfula11y.scan.progress.pages", result.progress.pagesScanned, result.progress.pagesDiscovered);
    if (result.progress.pagesFailed > 0) {
      progressText += ` \u2014 ${lll("mindfula11y.scan.progress.pagesFailed", result.progress.pagesFailed)}`;
    }
    return progressText;
  }
  if (result.status === ScanStatus.Analyzing) {
    const audit = result.aiAudit;
    return audit !== null && audit.tasksTotal > 0 ? lll("mindfula11y.scan.aiAudit.status.running", audit.tasksCompleted, audit.tasksTotal) : null;
  }
  return null;
}
function renderStatus(result, isCrawl) {
  const view = scanStatusView(result);
  if (view.spinner === true) {
    return renderProgressNotice(lll(view.labelKey), progressDetail(result, isCrawl));
  }
  if (result.status === ScanStatus.Failed || result.status === ScanStatus.Canceled) {
    return html`<mindfula11y-notice state=${view.state}>
            ${renderNoticeBody({ title: lll(view.labelKey), description: lll(`${view.labelKey}.description`) })}
        </mindfula11y-notice>`;
  }
  return html`<mindfula11y-notice state=${view.state}>
        <span>${lll(view.labelKey, ...view.labelArgs ?? [])}</span>
    </mindfula11y-notice>`;
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
                ${renderNoticeBody({
        title: lll("mindfula11y.scan.crawl.idle.title"),
        description: lll("mindfula11y.scan.crawl.idle.description")
      })}
            </mindfula11y-notice>`;
    }
    return nothing;
  }
  const urlList = data.urlList;
  const result = data.result;
  if (result !== null && result.mode !== "crawl" && urlList.length > 0 && !urlListCovered(urlList, result.targets)) {
    return html`<mindfula11y-notice state="info">
            ${renderNoticeBody({
      title: lll("mindfula11y.scan.scopeExpanded"),
      description: lll("mindfula11y.scan.scopeExpanded.description")
    })}
        </mindfula11y-notice>`;
  }
  if (result === null && !data.actionBusy && data.controllerState !== "loading" && data.demand !== null && urlList.length > 1) {
    return html`<mindfula11y-notice state="info">
            ${renderNoticeBody({
      title: lll("mindfula11y.scan.multiPage.manualScan"),
      description: lll("mindfula11y.scan.multiPage.manualScan.description")
    })}
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
        ${demand !== null ? html`<button type="button" class="button" data-action="trigger" aria-disabled=${actionBusy || running ? "true" : nothing} @click=${onTrigger}>
                      ${actionBusy ? html`<typo3-backend-spinner size="small"></typo3-backend-spinner>` : html`<typo3-backend-icon
                                    identifier=${result !== null ? "actions-refresh" : "actions-search"}
                                    size="small"
                                ></typo3-backend-icon>`}
                      ${lll(actionBusy ? "mindfula11y.scan.processing" : triggerKey)}
                  </button>` : nothing}
        ${running && scanId !== "" ? html`<button type="button" class="button" data-action="cancel" aria-disabled=${actionBusy ? "true" : nothing} @click=${onCancel}>
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
function renderRequestError(data) {
  if (data.actionError !== null) {
    return html`<mindfula11y-notice state="danger">
            ${renderNoticeBody(data.actionError)}
        </mindfula11y-notice>`;
  }
  if (data.controllerState === "error") {
    return html`<mindfula11y-notice state="danger">
            ${renderNoticeBody({ title: lll("mindfula11y.scan.error.loading"), description: data.loadErrorDescription })}
        </mindfula11y-notice>`;
  }
  return nothing;
}
function renderErrorActions(data, onReload) {
  if (data.controllerState !== "error") {
    return nothing;
  }
  return html`<button type="button" class="button" @click=${onReload}>${lll("mindfula11y.scan.refresh")}</button>`;
}
function renderBody(data) {
  if (data.actionError !== null || data.controllerState === "error") {
    return nothing;
  }
  const result = data.result;
  if (result === null) {
    if (data.controllerState === "loading" && data.scanId !== "") {
      return renderLoadingPlaceholder(lll("mindfula11y.scan.loading"));
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
        <div class="status-region" role="status">${renderRequestError(data)}</div>
        ${renderErrorActions(data, callbacks.onReload)}
        ${renderBody(data)}`;
}
export {
  buildReportUrl,
  renderPanelContent
};
