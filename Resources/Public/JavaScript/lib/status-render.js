import { lll } from "@typo3/core/lit-helper.js";
import { html } from "lit";
import { IMPACT_ORDER } from "./types.js";
const IMPACT_STATES = {
  critical: "danger",
  serious: "serious",
  moderate: "warning",
  minor: "info"
};
function impactState(impact) {
  return IMPACT_STATES[impact];
}
function renderCountBadge(state, count, srText) {
  return html`<span class="notice count" data-state=${state} data-variant="pill"
        ><span aria-hidden="true">${count}</span><span class="sr-only">${srText}</span></span
    >`;
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
function renderSeverityChip(severity, labelKey, ...labelArguments) {
  return html`<typo3-backend-icon
            identifier=${noticeStateIcon(impactState(severity))}
            size="small"
        ></typo3-backend-icon>
        <span
            ><span class="sr-only">${lll(severityLabelKey(severity))}: </span
            >${lll(labelKey, ...labelArguments)}</span
        >`;
}
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
const renderLoadingPlaceholder = (label) => html`<div class="placeholder">
        <typo3-backend-spinner size="default"></typo3-backend-spinner>
        <span>${label}</span>
    </div>`;
export {
  IMPACT_ORDER,
  impactState,
  noticeStateIcon,
  renderCountBadge,
  renderLoadingPlaceholder,
  renderNoticeBody,
  renderSeverityChip,
  renderViewportBadges,
  severityLabelKey
};
