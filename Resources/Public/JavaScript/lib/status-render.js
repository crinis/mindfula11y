import { lll } from "@typo3/core/lit-helper.js";
import { html, nothing } from "lit";
import { IMPACT_ORDER } from "./types.js";
const renderExternalLink = (options) => html`<a class=${options.className ?? nothing} href=${options.href} target="_blank" rel="noreferrer">
        ${options.content}
        <span class="sr-only">${lll("mindfula11y.general.opensNewTab")}</span>
    </a>`;
const IMPACT_STATES = {
  critical: "danger",
  serious: "serious",
  moderate: "warning",
  minor: "info"
};
function impactState(impact) {
  return IMPACT_STATES[impact];
}
function worstImpact(counts) {
  return IMPACT_ORDER.find((impact) => counts[impact] > 0);
}
function worstSeverity(items, severityOf) {
  return IMPACT_ORDER.find((impact) => items.some((item) => severityOf(item) === impact));
}
function totalCount(counts) {
  return IMPACT_ORDER.reduce((sum, impact) => sum + counts[impact], 0);
}
function renderCountBadge(state, count, srText) {
  return html`<span class="notice count" data-state=${state} data-variant="pill"
        >${srText === void 0 ? count : html`<span aria-hidden="true">${count}</span><span class="sr-only">${srText}</span>`}</span
    >`;
}
function renderImpactCountBadge(worst, count, srText) {
  return renderCountBadge(impactState(worst), count, srText);
}
function severityLabelKey(severity) {
  return `mindfula11y.severity.${severity}`;
}
const NOTICE_STATE_ICONS = {
  info: "status-dialog-information",
  success: "status-dialog-ok",
  warning: "status-dialog-warning",
  serious: "status-dialog-warning",
  danger: "status-dialog-error"
};
function noticeStateIcon(state) {
  return NOTICE_STATE_ICONS[state];
}
function renderSeverityLabel(severity, labelKey, ...labelArguments) {
  return html`<span
        ><span class="sr-only">${lll(severityLabelKey(severity))}: </span>${lll(labelKey, ...labelArguments)}</span
    >`;
}
function renderSeverityChip(severity, labelKey, ...labelArguments) {
  return html`<typo3-backend-icon
            identifier=${noticeStateIcon(impactState(severity))}
            size="small"
        ></typo3-backend-icon>
        ${renderSeverityLabel(severity, labelKey, ...labelArguments)}`;
}
const renderFindingPill = (severity, onSelect, content) => html`<li>
        <button
            type="button"
            class="notice finding"
            data-state=${impactState(severity)}
            data-variant="pill"
            @click=${onSelect}
        >
            ${content}
        </button>
    </li>`;
const renderViewportBadges = (viewports) => html`<span class="viewports">
        <span class="sr-only">${lll("mindfula11y.structure.viewports")}: </span>
        ${viewports.map(
  (viewport) => html`<span class="viewport">${lll(`mindfula11y.structure.viewport.${viewport}`)}</span>`
)}
    </span>`;
const renderNoticeBody = (view) => html`<span>
        <span class="notice-title">${view.title}</span>
        ${view.description}
    </span>`;
const renderDisclosureMarker = (slot = null) => html`<typo3-backend-icon
        slot=${slot ?? nothing}
        class="marker"
        identifier="actions-chevron-down"
        size="small"
    ></typo3-backend-icon>`;
const renderStatusRegion = (content) => html`<div class="status-region" role="status">${content}</div>`;
const renderProgressNotice = (title, progressText = null) => html`<mindfula11y-notice state="info">
        <typo3-backend-spinner slot="icon" size="small"></typo3-backend-spinner>
        <span>${title}${progressText !== null ? html` — ${progressText}` : nothing}</span>
    </mindfula11y-notice>`;
const renderLoadingPlaceholder = (label) => html`<div class="placeholder">
        <typo3-backend-spinner size="default"></typo3-backend-spinner>
        <span>${label}</span>
    </div>`;
export {
  IMPACT_ORDER,
  impactState,
  noticeStateIcon,
  renderCountBadge,
  renderDisclosureMarker,
  renderExternalLink,
  renderFindingPill,
  renderImpactCountBadge,
  renderLoadingPlaceholder,
  renderNoticeBody,
  renderProgressNotice,
  renderSeverityChip,
  renderSeverityLabel,
  renderStatusRegion,
  renderViewportBadges,
  severityLabelKey,
  totalCount,
  worstImpact,
  worstSeverity
};
