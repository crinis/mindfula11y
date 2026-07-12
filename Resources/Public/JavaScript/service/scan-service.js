import AjaxRequest from "@typo3/core/ajax/ajax-request.js";
import { ScanStatus } from "../lib/types.js";
import { toRequestError } from "./request-error.js";
class ScanService {
  /**
   * Creates a scan from a signed demand. `aiAudit` rides alongside the
   * signed fields — it is an editor choice the backend authorizes via page
   * TSConfig, not a server-derived parameter covered by the HMAC.
   */
  async createScan(createScanDemand, aiAudit = false) {
    try {
      const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.mindfula11y_createscan ?? "").post(
        { ...createScanDemand, aiAudit },
        { headers: { "Content-Type": "application/json; charset=utf-8" } }
      );
      const data = await response.resolve();
      return { scanId: data.scanId, status: data.status ?? ScanStatus.Pending };
    } catch (error) {
      throw await toRequestError(error);
    }
  }
  /** Loads scan results; resolves to null when the scan no longer exists. */
  async loadScan(scanId, pageUrls = []) {
    try {
      const params = new URLSearchParams({ scanId });
      for (const url of pageUrls) {
        params.append("pageUrls", url);
      }
      const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.mindfula11y_getscan ?? "").withQueryArguments(params).get();
      const data = await response.resolve();
      return {
        status: data.status ?? ScanStatus.Completed,
        violations: data.violations ?? [],
        totalIssueCount: data.totalIssueCount ?? 0,
        mode: data.mode ?? null,
        targets: data.targets ?? [],
        progress: data.progress ?? null,
        aiAudit: data.aiAudit ?? null,
        agentFindings: data.agentFindings ?? [],
        updatedAt: data.updatedAt ?? null
      };
    } catch (error) {
      if (error.response?.status === 404) {
        return null;
      }
      throw await toRequestError(error);
    }
  }
  /** Requests cancellation of a running scan; resolves to the resulting status. */
  async cancelScan(scanId) {
    try {
      const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.mindfula11y_cancelscan ?? "").post(
        { scanId },
        { headers: { "Content-Type": "application/json; charset=utf-8" } }
      );
      const data = await response.resolve();
      return data.status ?? ScanStatus.Canceled;
    } catch (error) {
      throw await toRequestError(error);
    }
  }
  isScanInProgress(status) {
    return status === ScanStatus.Pending || status === ScanStatus.Running || status === ScanStatus.Analyzing;
  }
}
var scan_service_default = ScanService;
export {
  ScanService,
  scan_service_default as default
};
