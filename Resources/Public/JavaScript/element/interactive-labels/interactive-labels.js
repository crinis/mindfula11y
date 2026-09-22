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
import { html, LitElement } from "lit";
import { customElement, property } from "lit/decorators.js";
import "../notice/notice.js";
import { impactState, renderCountBadge } from "../../lib/status-render.js";
import { baseStyles } from "../../styles/base-styles.js";
import findingsStyles from "../../styles/findings.css.js";
import labelStyles from "./interactive-labels.css.js";
let InteractiveLabels = class extends LitElement {
  constructor() {
    super(...arguments);
    this.findings = [];
  }
  render() {
    const rows = this.groupFindings();
    if (rows.length === 0) {
      return html`
        <mindfula11y-notice state="success">
                    <span>
                        ${lll("mindfula11y.interactiveLabels.noFindings")}
                    </span>
        </mindfula11y-notice>
      `;
    }
    return html`
      <div class="interactive-labels">
        ${rows.map((row) => this.renderRow(row))}
      </div>
    `;
  }
  groupFindings() {
    const grouped = /* @__PURE__ */ new Map();
    for (const finding of this.findings) {
      if (!finding.rule) {
        continue;
      }
      const normalizedValue = finding.value.trim().toLocaleLowerCase();
      const key = `${finding.rule}:${normalizedValue}`;
      const existing = grouped.get(key);
      if (existing) {
        existing.count += finding.overviewCount ?? 1;
        continue;
      }
      const ruleTitleKey = `mindfula11y.findings.rule.title.${finding.rule}`;
      const ruleDescriptionKey = `mindfula11y.findings.rule.description.${finding.rule}`;
      const ruleTitle = lll(ruleTitleKey);
      const ruleDescription = lll(ruleDescriptionKey);
      grouped.set(key, {
        value: finding.value,
        ruleTitle,
        ruleDescription,
        severity: finding.severity,
        count: finding.overviewCount ?? 1
      });
    }
    return [...grouped.values()];
  }
  renderRow(row) {
    return html`
      <details class="interactive-label-row">
        <summary class="row-summary">
                <span class="value">
                    ${row.value}
                </span>

          <span class="rule-title">
                    ${row.ruleTitle}
                </span>

          ${renderCountBadge(impactState(row.severity), row.count)}
        </summary>

        <div class="rule-description">
          <p class="rule-description-text">
            ${row.ruleDescription}
          </p>
        </div>
      </details>
    `;
  }
};
InteractiveLabels.styles = [...baseStyles, findingsStyles, labelStyles];
__decorateClass([
  property({ type: Array })
], InteractiveLabels.prototype, "findings", 2);
InteractiveLabels = __decorateClass([
  customElement("mindfula11y-interactive-labels")
], InteractiveLabels);
export {
  InteractiveLabels
};
