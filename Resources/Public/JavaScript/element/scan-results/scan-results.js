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
import { repeat } from "lit/directives/repeat.js";
import "@typo3/backend/element/icon-element.js";
import { scrollIntoViewCentered } from "../../lib/dom.js";
import { AiAuditStatus } from "../../lib/scan/types.js";
import {
  IMPACT_ORDER,
  impactState,
  renderDisclosureMarker,
  renderExternalLink,
  renderFindingPill,
  renderNoticeBody
} from "../../lib/status-render.js";
import { safeHttpUrl } from "../../lib/url.js";
import "../notice/notice.js";
import { baseStyles } from "../../styles/base-styles.js";
import disclosureStyles from "../../styles/disclosure.css.js";
import findingsStyles from "../../styles/findings.css.js";
import noticeStyles from "../../styles/notice.css.js";
import surfaceStyles from "../../styles/surface.css.js";
import componentStyles from "./scan-results.css.js";
const skillLabel = (skill) => {
  const translated = lll(`mindfula11y.scan.aiAudit.skill.${skill}`);
  if (translated !== "") {
    return translated;
  }
  return skill.split("_").filter((part) => part !== "").map((part) => `${part.charAt(0).toUpperCase()}${part.slice(1)}`).join(" ");
};
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
      (impact) => renderFindingPill(
        impact,
        () => this.focusFirstViolation(impact),
        html`<span>${lll(`mindfula11y.severity.${impact}`)}</span>
                    <span class="finding-count">${counts.get(impact)}</span>
                    <span class="sr-only">${lll("mindfula11y.scan.summary.jumpHint")}</span>`
      )
    )}
        </ul>`;
  }
  renderViolations(violations) {
    if (violations.length === 0) {
      return nothing;
    }
    const sorted = violations.toSorted((a, b) => IMPACT_ORDER.indexOf(a.impact) - IMPACT_ORDER.indexOf(b.impact));
    return html`<ul class="violations">
            ${repeat(
      sorted,
      (violation) => violation.rule.id,
      (violation) => this.renderViolation(violation)
    )}
        </ul>`;
  }
  renderViolation(violation) {
    const issueCount = violation.issues.length;
    const helpUrl = violation.rule.helpUrl !== null ? safeHttpUrl(violation.rule.helpUrl) : "#";
    return html`<li>
            <details class="surface violation" data-impact=${violation.impact}>
                <summary class="disclosure">
                    ${renderDisclosureMarker()}
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
                    ${helpUrl !== "#" ? renderExternalLink({
      className: "help",
      href: helpUrl,
      content: lll("mindfula11y.scan.helpUrl")
    }) : nothing}
                    <ul class="issues">
                        ${repeat(
      violation.issues,
      (issue) => issue.id,
      (issue) => html`<li class="issue">
                                ${this.renderPageUrl(issue.pageUrl)}
                                ${issue.selector !== null && issue.selector !== "" ? html`<p class="detail">
                                              <span class="detail-label">${lll("mindfula11y.scan.selector")}</span>
                                              <code class="code">${issue.selector}</code>
                                          </p>` : nothing}
                                ${issue.context !== null && issue.context !== "" ? html`<pre
                                              class="context"
                                              tabindex="0"
                                              role="region"
                                              aria-label=${lll("mindfula11y.scan.issueContext")}
                                          ><code class="code">${issue.context}</code></pre>` : nothing}
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
            ${renderExternalLink({ href, content: display })}
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
                ${renderNoticeBody({
      title: lll("mindfula11y.scan.aiAudit.disclaimer.title"),
      description: lll("mindfula11y.scan.aiAudit.disclaimer.description")
    })}
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
    const groups = Map.groupBy(findings, (finding) => finding.skill);
    return html`${[...groups].map(
      ([skill, skillFindings]) => html`<section class="skill">
                <h3 class="skill-title">
                    ${skillLabel(skill)}
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
    return html`<li class="surface card">
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
ScanResults.styles = [
  ...baseStyles,
  noticeStyles,
  findingsStyles,
  disclosureStyles,
  surfaceStyles,
  componentStyles
];
__decorateClass([
  property({ attribute: false })
], ScanResults.prototype, "result", 2);
ScanResults = __decorateClass([
  customElement("mindfula11y-scan-results")
], ScanResults);
export {
  ScanResults
};
