import { lll } from "@typo3/core/lit-helper.js";
import { html } from "lit";
import { StructureErrorSeverity } from "./types.js";
function noticeState(severity) {
  return severity === StructureErrorSeverity.Error ? "danger" : "warning";
}
function severityLabelKey(severity) {
  return severity === StructureErrorSeverity.Error ? "mindfula11y.severity.error" : "mindfula11y.severity.warning";
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
function renderSeverityChip(severity, labelKey) {
  return html`<typo3-backend-icon
            identifier=${noticeStateIcon(noticeState(severity))}
            size="small"
        ></typo3-backend-icon>
        <span><span class="sr-only">${lll(severityLabelKey(severity))}: </span>${lll(labelKey)}</span>`;
}
const renderViewportBadges = (viewports) => html`<span class="viewports">
        ${viewports.map(
  (viewport) => html`<span class="viewport">${lll(`mindfula11y.structure.viewport.${viewport}`)}</span>`
)}
    </span>`;
export {
  noticeState,
  noticeStateIcon,
  renderSeverityChip,
  renderViewportBadges,
  severityLabelKey
};
