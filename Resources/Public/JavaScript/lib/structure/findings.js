import { IMPACT_ORDER } from "../types.js";
import { mergeViewports } from "./analysis.js";
const DOMAIN_ORDER = ["headings", "landmarks"];
const enabledDomains = (enabled) => DOMAIN_ORDER.filter((domain) => enabled[domain]);
const domainErrors = (analysis, domain) => {
  if (analysis === null) {
    return [];
  }
  const slice = domain === "headings" ? analysis.headings : analysis.landmarks;
  return slice?.errors ?? [];
};
const pageErrors = (analysis, domain) => domainErrors(analysis, domain).filter((error) => error.nodeId === null);
const severityCounts = (analysis, domain) => {
  const counts = { critical: 0, serious: 0, moderate: 0, minor: 0 };
  for (const error of domainErrors(analysis, domain)) {
    counts[error.severity] += 1;
  }
  return counts;
};
const aggregateFindings = (analysis, enabled) => {
  const findings = /* @__PURE__ */ new Map();
  for (const domain of enabledDomains(enabled)) {
    for (const error of domainErrors(analysis, domain)) {
      const findingKey = `${domain} ${error.key}`;
      const existing = findings.get(findingKey);
      if (existing === void 0) {
        findings.set(findingKey, {
          key: error.key,
          severity: error.severity,
          count: 1,
          domain,
          viewports: [...error.viewports]
        });
      } else {
        existing.count += 1;
        existing.viewports = mergeViewports(existing.viewports, error.viewports);
      }
    }
  }
  return Array.from(findings.values()).sort(
    (a, b) => IMPACT_ORDER.indexOf(a.severity) - IMPACT_ORDER.indexOf(b.severity)
  );
};
export {
  aggregateFindings,
  domainErrors,
  enabledDomains,
  pageErrors,
  severityCounts
};
