var __defProp = Object.defineProperty;
var __getOwnPropDesc = Object.getOwnPropertyDescriptor;
var __decorateClass = (decorators, target, key, kind) => {
  var result = kind > 1 ? void 0 : kind ? __getOwnPropDesc(target, key) : target;
  for (var i = decorators.length - 1, decorator; i >= 0; i--)
    if (decorator = decorators[i])
      result = (kind ? decorator(target, key, result) : decorator(result)) || result;
  if (kind && result) __defProp(target, key, result);
  return result;
};
import { lll } from "@typo3/core/lit-helper.js";
import { html, LitElement, nothing } from "lit";
import { customElement, property } from "lit/decorators.js";
import "@typo3/backend/element/icon-element.js";
import { scrollIntoViewCentered } from "../../lib/dom.js";
import { AiAuditStatus } from "../../lib/types.js";
import { safeHttpUrl } from "../../lib/url.js";
import "../notice/notice.js";
import { baseStyles } from "../../styles/base-styles.js";
import findingsStyles from "../../styles/findings.css.js";
import noticeStyles from "../../styles/notice.css.js";
import componentStyles from "./scan-results.css.js";
const IMPACT_ORDER = ["critical", "serious", "moderate", "minor"];
const IMPACT_STATES = {
  critical: "danger",
  serious: "serious",
  moderate: "warning",
  minor: "info"
};
function impactState(impact) {
  return IMPACT_STATES[impact];
}
const SKILL_ORDER = [
  "image_alt_text",
  "heading_structure",
  "link_purpose",
  "form_labels",
  "page_title"
];
let ScanResults = class extends LitElement {
  constructor() {
    super(...arguments);
    this.result = null;
  }
  render() {
    if (this.result === null) {
      return nothing;
    }
    return html`<div class="results">
            ${this.renderSummary(this.result.violations)} ${this.renderViolations(this.result.violations)}
            ${this.renderAiReview(this.result)}
        </div>`;
  }
  /** Opens and focuses the first violation card of the given impact. */
  focusFirstViolation(impact) {
    const details = this.renderRoot.querySelector(`details[data-impact="${impact}"]`);
    if (details === null) {
      return;
    }
    details.open = true;
    const summary = details.querySelector("summary");
    summary?.focus();
    scrollIntoViewCentered(details);
  }
  renderSummary(violations) {
    const counts = /* @__PURE__ */ new Map();
    for (const violation of violations) {
      counts.set(violation.impact, (counts.get(violation.impact) ?? 0) + violation.issues.length);
    }
    const impacts = IMPACT_ORDER.filter((impact) => (counts.get(impact) ?? 0) > 0);
    if (impacts.length === 0) {
      return nothing;
    }
    return html`<ul class="findings">
            ${impacts.map(
      (impact) => html`<li>
                    <button
                        type="button"
                        class="notice finding"
                        data-state=${impactState(impact)}
                        data-variant="pill"
                        @click=${() => this.focusFirstViolation(impact)}
                    >
                        <span>${lll(`mindfula11y.severity.${impact}`)}</span>
                        <span class="finding-count">${counts.get(impact)}</span>
                        <span class="sr-only">${lll("mindfula11y.scan.summary.jumpHint")}</span>
                    </button>
                </li>`
    )}
        </ul>`;
  }
  renderViolations(violations) {
    if (violations.length === 0) {
      return nothing;
    }
    const sorted = [...violations].sort((a, b) => IMPACT_ORDER.indexOf(a.impact) - IMPACT_ORDER.indexOf(b.impact));
    return html`<ul class="violations">
            ${sorted.map((violation) => this.renderViolation(violation))}
        </ul>`;
  }
  renderViolation(violation) {
    const issueCount = violation.issues.length;
    const helpUrl = violation.rule.helpUrl !== null ? safeHttpUrl(violation.rule.helpUrl) : "#";
    return html`<li>
            <details class="violation" data-impact=${violation.impact}>
                <summary class="disclosure">
                    <typo3-backend-icon class="marker" identifier="actions-chevron-down" size="small"></typo3-backend-icon>
                    <span class="rule-description">${violation.rule.description}</span>
                    <code class="rule-id">${violation.rule.id}</code>
                    <span class="notice" data-state=${impactState(violation.impact)} data-variant="pill"
                        >${lll(`mindfula11y.severity.${violation.impact}`)}</span
                    >
                    <span class="issue-count"
                        >${lll(issueCount === 1 ? "mindfula11y.scan.issueCount" : "mindfula11y.scan.issuesCount", issueCount)}</span
                    >
                </summary>
                <div class="body">
                    ${helpUrl !== "#" ? html`<a class="help" href=${helpUrl} target="_blank" rel="noreferrer">
                                  ${lll("mindfula11y.scan.helpUrl")}
                                  <span class="sr-only">${lll("mindfula11y.scan.opensNewTab")}</span>
                              </a>` : nothing}
                    <ul class="issues">
                        ${violation.issues.map(
      (issue) => html`<li class="issue">
                                ${this.renderPageUrl(issue.pageUrl)}
                                ${issue.selector !== null && issue.selector !== "" ? html`<p class="detail">
                                              <span class="detail-label">${lll("mindfula11y.scan.selector")}</span>
                                              <code class="code">${issue.selector}</code>
                                          </p>` : nothing}
                                ${issue.context !== null && issue.context !== "" ? html`<pre class="context"><code class="code">${issue.context}</code></pre>` : nothing}
                            </li>`
    )}
                    </ul>
                </div>
            </details>
        </li>`;
  }
  renderPageUrl(pageUrl) {
    if (pageUrl === null || pageUrl === "") {
      return nothing;
    }
    const href = safeHttpUrl(pageUrl);
    let display = pageUrl;
    try {
      const parsed = new URL(pageUrl);
      display = `${parsed.pathname}${parsed.search}`;
    } catch {
    }
    return html`<p class="detail">
            <span class="detail-label">${lll("mindfula11y.scan.pageUrl")}</span>
            <a href=${href} target="_blank" rel="noreferrer">
                ${display}
                <span class="sr-only">${lll("mindfula11y.scan.opensNewTab")}</span>
            </a>
        </p>`;
  }
  renderAiReview(result) {
    const audit = result.aiAudit;
    if (audit === null || audit.status === AiAuditStatus.Skipped) {
      return nothing;
    }
    const appropriate = result.agentFindings.filter((finding) => finding.category === "appropriate");
    const flagged = result.agentFindings.filter((finding) => finding.category !== "appropriate");
    return html`<section class="ai">
            <h2 class="ai-title">${lll("mindfula11y.scan.aiAudit.section")}</h2>
            <mindfula11y-notice state="warning">
                <span>
                    <span class="notice-title">${lll("mindfula11y.scan.aiAudit.disclaimer.title")}</span>
                    ${lll("mindfula11y.scan.aiAudit.disclaimer.description")}
                </span>
            </mindfula11y-notice>
            ${audit.tasksFailed > 0 ? html`<p class="notice" data-state="warning" data-variant="inline">
                          <span>${lll("mindfula11y.scan.aiAudit.tasksFailed", audit.tasksFailed)}</span>
                      </p>` : nothing}
            ${flagged.length === 0 ? html`<p class="notice" data-state="success" data-variant="inline">
                          <span>${lll("mindfula11y.scan.aiAudit.noFindings")}</span>
                      </p>` : this.renderSkillGroups(flagged)}
            ${appropriate.length > 0 ? html`<p class="ai-appropriate">
                          ${lll("mindfula11y.scan.aiAudit.appropriateCount", appropriate.length)}
                      </p>` : nothing}
        </section>`;
  }
  renderSkillGroups(findings) {
    const groups = SKILL_ORDER.map(
      (skill) => [skill, findings.filter((finding) => finding.skill === skill)]
    ).filter(([, skillFindings]) => skillFindings.length > 0);
    return html`${groups.map(
      ([skill, skillFindings]) => html`<section class="skill">
                <h3 class="skill-title">
                    ${lll(`mindfula11y.scan.aiAudit.skill.${skill}`)}
                    <span class="skill-count"
                        >${lll(
        skillFindings.length === 1 ? "mindfula11y.scan.aiAudit.findingCount" : "mindfula11y.scan.aiAudit.findingsCount",
        skillFindings.length
      )}</span
                    >
                </h3>
                <ul class="ai-findings">
                    ${skillFindings.map((finding) => this.renderFinding(finding))}
                </ul>
            </section>`
    )}`;
  }
  renderFinding(finding) {
    return html`<li class="card">
            <p class="card-head">
                <span class="notice" data-state=${impactState(finding.severity)} data-variant="pill"
                    >${lll(`mindfula11y.severity.${finding.severity}`)}</span
                >
                ${finding.wcag !== null && finding.wcag !== "" ? html`<span class="wcag">${lll("mindfula11y.scan.aiAudit.wcag", finding.wcag)}</span>` : nothing}
                ${finding.needsHumanReview ? html`<span class="notice" data-state="warning" data-variant="pill">
                              <typo3-backend-icon identifier="status-dialog-warning" size="small"></typo3-backend-icon>
                              <span>${lll("mindfula11y.scan.aiAudit.needsHumanReview")}</span>
                          </span>` : nothing}
            </p>
            <p class="message">${finding.message}</p>
            ${finding.suggestion !== null && finding.suggestion !== "" ? html`<p class="suggestion">
                          <span class="detail-label">${lll("mindfula11y.scan.aiAudit.suggestion")}:</span>
                          ${finding.suggestion}
                      </p>` : nothing}
            ${this.renderPageUrl(finding.pageUrl)}
            ${finding.selector !== null && finding.selector !== "" ? html`<p class="detail">
                          <span class="detail-label">${lll("mindfula11y.scan.selector")}</span>
                          <code class="code">${finding.selector}</code>
                      </p>` : nothing}
            <p class="card-meta">
                <span>${lll("mindfula11y.scan.aiAudit.confidence", Math.round(finding.confidence * 100))}</span>
                ${finding.model !== null && finding.model !== "" ? html`<span>${lll("mindfula11y.scan.aiAudit.model", finding.model)}</span>` : nothing}
            </p>
        </li>`;
  }
};
ScanResults.styles = [...baseStyles, noticeStyles, findingsStyles, componentStyles];
__decorateClass([
  property({ attribute: false })
], ScanResults.prototype, "result", 2);
ScanResults = __decorateClass([
  customElement("mindfula11y-scan-results")
], ScanResults);
export {
  IMPACT_ORDER,
  ScanResults,
  impactState
};
