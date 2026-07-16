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
      return { state: "danger", labelKey: "mindfula11y.scan.status.failed" };
    case ScanStatus.Canceled:
      return { state: "info", labelKey: "mindfula11y.scan.status.canceled" };
    default:
      return result.totalIssueCount > 0 ? { state: "warning", labelKey: "mindfula11y.scan.issuesFound", labelArgs: [result.totalIssueCount] } : { state: "success", labelKey: "mindfula11y.scan.noIssues" };
  }
}
export {
  scanStatusView
};
