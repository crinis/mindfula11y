var ScanStatus = /* @__PURE__ */ ((ScanStatus2) => {
  ScanStatus2["Pending"] = "pending";
  ScanStatus2["Running"] = "running";
  ScanStatus2["Analyzing"] = "analyzing";
  ScanStatus2["Completed"] = "completed";
  ScanStatus2["Failed"] = "failed";
  ScanStatus2["Canceled"] = "canceled";
  return ScanStatus2;
})(ScanStatus || {});
var AiAuditStatus = /* @__PURE__ */ ((AiAuditStatus2) => {
  AiAuditStatus2["Skipped"] = "skipped";
  AiAuditStatus2["Pending"] = "pending";
  AiAuditStatus2["Running"] = "running";
  AiAuditStatus2["Completed"] = "completed";
  return AiAuditStatus2;
})(AiAuditStatus || {});
var StructureErrorSeverity = /* @__PURE__ */ ((StructureErrorSeverity2) => {
  StructureErrorSeverity2["Error"] = "error";
  StructureErrorSeverity2["Warning"] = "warning";
  return StructureErrorSeverity2;
})(StructureErrorSeverity || {});
function noticeState(severity) {
  return severity === "error" /* Error */ ? "danger" : "warning";
}
function severityLabelKey(severity) {
  return severity === "error" /* Error */ ? "mindfula11y.severity.error" : "mindfula11y.severity.warning";
}
export {
  AiAuditStatus,
  ScanStatus,
  StructureErrorSeverity,
  noticeState,
  severityLabelKey
};
