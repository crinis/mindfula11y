var ScanStatus = /* @__PURE__ */ ((ScanStatus2) => {
  ScanStatus2["Pending"] = "pending";
  ScanStatus2["Running"] = "running";
  ScanStatus2["Analyzing"] = "analyzing";
  ScanStatus2["Completed"] = "completed";
  ScanStatus2["Failed"] = "failed";
  ScanStatus2["Canceled"] = "canceled";
  return ScanStatus2;
})(ScanStatus || {});
function isScanInProgress(status) {
  return status === "pending" /* Pending */ || status === "running" /* Running */ || status === "analyzing" /* Analyzing */;
}
const SCAN_MODES = ["single_url", "url_list", "crawl"];
function scanModeOf(demand) {
  if (demand.crawl) {
    return "crawl";
  }
  return demand.pageLevels > 0 ? "url_list" : "single_url";
}
var AiAuditStatus = /* @__PURE__ */ ((AiAuditStatus2) => {
  AiAuditStatus2["Skipped"] = "skipped";
  AiAuditStatus2["Pending"] = "pending";
  AiAuditStatus2["Running"] = "running";
  AiAuditStatus2["Completed"] = "completed";
  return AiAuditStatus2;
})(AiAuditStatus || {});
function violationGroupKey(violation) {
  return `${violation.rule.id}::${violation.impact}`;
}
export {
  AiAuditStatus,
  SCAN_MODES,
  ScanStatus,
  isScanInProgress,
  scanModeOf,
  violationGroupKey
};
