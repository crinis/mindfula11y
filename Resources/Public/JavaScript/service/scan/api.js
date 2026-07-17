import { isObject } from "../../lib/guards.js";
import { getJson, postJson } from "../backend-api.js";
import { RequestError } from "../request-error.js";
import { AiAuditStatus, ScanStatus } from "./types.js";
const SCAN_STATUSES = new Set(Object.values(ScanStatus));
function isScanStatus(value) {
  return typeof value === "string" && SCAN_STATUSES.has(value);
}
const IMPACT_SEVERITIES = /* @__PURE__ */ new Set(["critical", "serious", "moderate", "minor"]);
const AI_AUDIT_STATUSES = new Set(Object.values(AiAuditStatus));
const isNullableString = (value) => value === null || typeof value === "string";
const isIssue = (value) => isObject(value) && typeof value.id === "number" && isNullableString(value.pageUrl) && isNullableString(value.selector) && isNullableString(value.context);
const isRule = (value) => isObject(value) && typeof value.id === "string" && typeof value.description === "string" && isNullableString(value.helpUrl) && (value.tags === void 0 || Array.isArray(value.tags) && value.tags.every((tag) => typeof tag === "string"));
const isViolation = (value) => isObject(value) && isRule(value.rule) && typeof value.impact === "string" && IMPACT_SEVERITIES.has(value.impact) && Array.isArray(value.issues) && value.issues.every(isIssue);
const isProgress = (value) => isObject(value) && typeof value.pagesDiscovered === "number" && typeof value.pagesScanned === "number" && typeof value.pagesFailed === "number";
const isAiAudit = (value) => isObject(value) && typeof value.status === "string" && AI_AUDIT_STATUSES.has(value.status) && Array.isArray(value.requestedSkills) && value.requestedSkills.every((skill) => typeof skill === "string") && typeof value.tasksTotal === "number" && typeof value.tasksCompleted === "number" && typeof value.tasksFailed === "number";
const isAgentFinding = (value) => isObject(value) && typeof value.skill === "string" && typeof value.category === "string" && isNullableString(value.wcag) && typeof value.severity === "string" && IMPACT_SEVERITIES.has(value.severity) && typeof value.confidence === "number" && typeof value.needsHumanReview === "boolean" && isNullableString(value.pageUrl) && isNullableString(value.selector) && typeof value.message === "string" && isNullableString(value.suggestion) && (value.details === null || isObject(value.details)) && isNullableString(value.model);
const malformed = (member) => new Error(`The get-scan endpoint returned a malformed ${member} payload.`);
function parseScanResult(data) {
  if (!isObject(data)) {
    throw malformed("response");
  }
  if (!isScanStatus(data.status)) {
    throw new Error(`The get-scan endpoint returned an unrecognized scan status: ${String(data.status)}.`);
  }
  const violations = data.violations ?? [];
  if (!(Array.isArray(violations) && violations.every(isViolation))) {
    throw malformed("violations");
  }
  const progress = data.progress ?? null;
  if (progress !== null && !isProgress(progress)) {
    throw malformed("progress");
  }
  const aiAudit = data.aiAudit ?? null;
  if (aiAudit !== null && !isAiAudit(aiAudit)) {
    throw malformed("aiAudit");
  }
  const agentFindings = data.agentFindings ?? [];
  if (!(Array.isArray(agentFindings) && agentFindings.every(isAgentFinding))) {
    throw malformed("agentFindings");
  }
  const targets = data.targets ?? [];
  if (!(Array.isArray(targets) && targets.every((target) => typeof target === "string"))) {
    throw malformed("targets");
  }
  const totalIssueCount = data.totalIssueCount ?? 0;
  const mode = data.mode ?? null;
  const updatedAt = data.updatedAt ?? null;
  if (typeof totalIssueCount !== "number" || !isNullableString(mode) || !isNullableString(updatedAt)) {
    throw malformed("summary");
  }
  return {
    status: data.status,
    violations,
    totalIssueCount,
    mode,
    targets,
    progress,
    aiAudit,
    agentFindings,
    updatedAt
  };
}
class ScanApi {
  /**
   * Creates a scan from a signed demand. `aiAudit` rides alongside the
   * signed fields — it is an editor choice the backend authorizes via page
   * TSConfig, not a server-derived parameter covered by the HMAC.
   */
  async createScan(createScanDemand, aiAudit = false, signal) {
    const data = await postJson(
      "mindfula11y_scan_create",
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
      data = await getJson("mindfula11y_scan_get", params, { signal });
    } catch (error) {
      if (error instanceof RequestError && error.status === 404) {
        return null;
      }
      throw error;
    }
    return parseScanResult(data);
  }
  /** Requests cancellation of a running scan; resolves to the resulting status. */
  async cancelScan(scanId, signal) {
    const data = await postJson("mindfula11y_scan_cancel", { scanId }, { signal });
    if (!isScanStatus(data.status)) {
      throw new Error(`The cancel-scan endpoint returned an unrecognized scan status: ${String(data.status)}.`);
    }
    return data.status;
  }
}
export {
  ScanApi
};
