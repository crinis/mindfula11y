import { getJson, postJson } from "../backend-api.js";
import { RequestError } from "../request-error.js";
import { ScanStatus } from "./types.js";
const SCAN_STATUSES = new Set(Object.values(ScanStatus));
function isScanStatus(value) {
  return typeof value === "string" && SCAN_STATUSES.has(value);
}
class ScanApi {
  /**
   * Creates a scan from a signed demand. `aiAudit` rides alongside the
   * signed fields — it is an editor choice the backend authorizes via page
   * TSConfig, not a server-derived parameter covered by the HMAC.
   */
  async createScan(createScanDemand, aiAudit = false, signal) {
    const data = await postJson(
      "mindfula11y_createscan",
      { ...createScanDemand, aiAudit },
      { signal }
    );
    if (typeof data.scanId !== "string" || data.scanId === "") {
      throw new Error("The create-scan endpoint returned no scan id.");
    }
    if (!isScanStatus(data.status)) {
      throw new Error(`The create-scan endpoint returned an unrecognized scan status: ${String(data.status)}.`);
    }
    return { scanId: data.scanId, status: data.status };
  }
  /** Loads scan results; resolves to null when the scan no longer exists. */
  async loadScan(scanId, pageUrls = [], signal) {
    let data;
    try {
      const params = pageUrls.length > 0 ? { scanId, pageUrls } : { scanId };
      data = await getJson("mindfula11y_getscan", params, { signal });
    } catch (error) {
      if (error instanceof RequestError && error.status === 404) {
        return null;
      }
      throw error;
    }
    if (!isScanStatus(data.status)) {
      throw new Error(`The get-scan endpoint returned an unrecognized scan status: ${String(data.status)}.`);
    }
    return {
      status: data.status,
      violations: data.violations ?? [],
      totalIssueCount: data.totalIssueCount ?? 0,
      mode: data.mode ?? null,
      targets: data.targets ?? [],
      progress: data.progress ?? null,
      aiAudit: data.aiAudit ?? null,
      agentFindings: data.agentFindings ?? [],
      updatedAt: data.updatedAt ?? null
    };
  }
  /** Requests cancellation of a running scan; resolves to the resulting status. */
  async cancelScan(scanId, signal) {
    const data = await postJson("mindfula11y_cancelscan", { scanId }, { signal });
    if (!isScanStatus(data.status)) {
      throw new Error(`The cancel-scan endpoint returned an unrecognized scan status: ${String(data.status)}.`);
    }
    return data.status;
  }
  isScanInProgress(status) {
    return status === ScanStatus.Pending || status === ScanStatus.Running || status === ScanStatus.Analyzing;
  }
}
export {
  ScanApi
};
