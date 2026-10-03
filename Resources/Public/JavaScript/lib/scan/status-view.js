import { ScanStatus } from "./types.js";
function scanStatusView(result) {
  switch (result.status) {
    case ScanStatus.Pending:
      return { state: "info", labelKey: "mindfula11y.scan.status.pending", spinner: true };
    case ScanStatus.Running:
      return { state: "info", labelKey: "mindfula11y.scan.status.running", spinner: true };
    case ScanStatus.Analyzing:
      return { state: "info", labelKey: "mindfula11y.scan.status.analyzing", spinner: true };
    case ScanStatus.Failed:
      return {
        state: "danger",
        labelKey: "mindfula11y.scan.status.failed",
        descriptionKey: "mindfula11y.scan.status.failed.description"
      };
    case ScanStatus.Canceled:
      return {
        state: "info",
        labelKey: "mindfula11y.scan.status.canceled",
        descriptionKey: "mindfula11y.scan.status.canceled.description"
      };
    default:
      return completedStatusView(result);
  }
}
function completedStatusView(result) {
  const scanned = result.progress?.pagesScanned ?? 0;
  const failed = result.progress?.pagesFailed ?? 0;
  if (failed > 0 && scanned === 0) {
    return {
      state: "danger",
      labelKey: "mindfula11y.scan.noPagesScanned",
      descriptionKey: "mindfula11y.scan.noPagesScanned.description"
    };
  }
  const pagesFailed = failed > 0 ? { pagesFailed: { failed, total: scanned + failed } } : {};
  if (result.totalIssueCount > 0) {
    return {
      state: "warning",
      labelKey: "mindfula11y.scan.issuesFound",
      announceLabelKey: "mindfula11y.scan.announce.issuesFound",
      count: result.totalIssueCount,
      ...pagesFailed
    };
  }
  return failed > 0 ? { state: "warning", labelKey: "mindfula11y.scan.noIssuesOnScannedPages", ...pagesFailed } : { state: "success", labelKey: "mindfula11y.scan.noIssues" };
}
export {
  scanStatusView
};
