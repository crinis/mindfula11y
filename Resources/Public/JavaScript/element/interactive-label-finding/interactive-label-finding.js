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
import { customElement, property, state } from "lit/decorators.js";
import { live } from "lit/directives/live.js";
import "@typo3/backend/element/icon-element.js";
import "@typo3/backend/element/spinner-element.js";
import "../notice/notice.js";
import { renderNoticeBody, renderSaveButton, renderSaveStatusRegion } from "../../lib/status-render.js";
import { InteractiveLabelAiReviewApi } from "../../service/interactive-label-ai-review-api.js";
import { RecordApi } from "../../service/record-api.js";
import { errorView } from "../../service/request-error.js";
import { baseStyles } from "../../styles/base-styles.js";
import buttonStyles from "../../styles/button.css.js";
import componentStyles from "./interactive-label-finding.css.js";
const REPEATED_RULE = "repeated_generic_label";
const DIFFERENT_TARGETS_RULE = "generic_label_different_targets";
const AI_ASSESSMENT_STATES = {
  // biome-ignore lint/style/useNamingConvention: these are the exact wire values of the InteractiveLabelAssessment PHP enum, not identifiers to rename
  likely_clear: "success",
  // biome-ignore lint/style/useNamingConvention: see likely_clear above
  likely_unclear: "warning",
  uncertain: "info"
};
let InteractiveLabelFinding = class extends LitElement {
  constructor() {
    super(...arguments);
    this.finding = null;
    this.value = "";
    this.lastSavedValue = "";
    this.busy = "idle";
    this.saved = false;
    this.actionError = null;
    this.aiReviewBusy = false;
    this.aiAssessment = null;
    this.aiReviewError = null;
    this.recordApi = new RecordApi();
    this.aiReviewApi = new InteractiveLabelAiReviewApi();
  }
  willUpdate() {
    if (!this.hasUpdated) {
      this.value = this.finding?.value ?? "";
      this.lastSavedValue = this.value;
    }
  }
  render() {
    const finding = this.finding;
    if (finding === null) {
      return nothing;
    }
    return html`
      ${finding.editable ? this.renderEditor() : this.renderReadOnlyValue()}

      <div class="notices">
        ${this.renderPrimaryNotice(finding)}

        ${this.renderSecondaryNotice(finding, REPEATED_RULE, finding.isRepeated ?? false, finding.occurrenceCount)}

        ${this.renderSecondaryNotice(
      finding,
      DIFFERENT_TARGETS_RULE,
      finding.hasDifferentTargets ?? false,
      finding.distinctTargetCount
    )}
      </div>

      ${finding.editable ? this.renderActions() : nothing}

      ${finding.editable ? this.renderAiReview(finding) : nothing}
    `;
  }
  renderEditor() {
    return html`<div class="editor">
            <label class="label" for="value">${lll("mindfula11y.findings.label")}</label>
            <input
                id="value"
                type="text"
                class="input"
                .value=${live(this.value)}
                ?readonly=${this.busy !== "idle"}
                @input=${this.handleInput}
            />
        </div>`;
  }
  /** Mirrors altless-file-reference's read-only fallback for a finding the current user cannot edit. */
  renderReadOnlyValue() {
    if (this.value === "") {
      return nothing;
    }
    return html`<dl class="editor">
            <dt class="label">${lll("mindfula11y.findings.label")}</dt>
            <dd class="readonly-value">${this.value}</dd>
        </dl>`;
  }
  renderActions() {
    return html`
      <div class="actions">
        ${renderSaveButton({
      saving: this.busy === "saving",
      disabled: this.busy !== "idle" || this.value === this.lastSavedValue,
      labelKey: "mindfula11y.findings.save",
      onClick: () => this.handleSave()
    })}
      </div>

      ${renderSaveStatusRegion({
      error: this.actionError,
      saved: this.saved,
      successLabelKey: "mindfula11y.findings.save.success"
    })}
    `;
  }
  handleInput(event) {
    this.value = event.target.value;
    this.saved = false;
    this.aiAssessment = null;
    this.aiReviewError = null;
  }
  async handleSave() {
    if (this.busy !== "idle" || this.value === this.lastSavedValue) {
      return;
    }
    const finding = this.finding;
    if (finding?.editable !== true || finding.table === void 0 || finding.field === void 0 || finding.uid === void 0) {
      return;
    }
    this.busy = "saving";
    this.actionError = null;
    try {
      await this.recordApi.updateFields(finding.table, finding.uid, { [finding.field]: this.value });
      this.lastSavedValue = this.value;
      this.saved = true;
    } catch (error) {
      this.actionError = errorView(error, "mindfula11y.findings.save.error");
    } finally {
      this.busy = "idle";
    }
  }
  /**
   * Asks the AI for a second opinion on the label as it currently stands
   * in the input (not necessarily the saved value — an editor may want a
   * reaction to a draft before saving it). Never touches the record and
   * never applies anything on its own; see renderAiAssessmentResult() for
   * the explicit "Apply suggestion" step the editor must take themselves.
   */
  async handleAiReview() {
    if (this.aiReviewBusy) {
      return;
    }
    const finding = this.finding;
    if (finding?.aiReviewAvailable !== true || finding.pageId === void 0 || this.value === "") {
      return;
    }
    this.aiReviewBusy = true;
    this.aiReviewError = null;
    this.aiAssessment = null;
    try {
      this.aiAssessment = await this.aiReviewApi.assess({
        pageId: finding.pageId,
        label: this.value,
        elementType: finding.type ?? "",
        target: finding.target ?? "",
        rule: finding.rule ?? "",
        pageTitle: finding.pageTitle ?? "",
        surroundingContext: finding.surroundingContext ?? "",
        locale: finding.locale ?? "en"
      });
    } catch (error) {
      this.aiReviewError = errorView(error, "mindfula11y.findings.aiReview.error");
    } finally {
      this.aiReviewBusy = false;
    }
  }
  /**
   * Copies the AI's suggestion into the (still unsaved) input field only —
   * mirrors handleInput()'s dirty-marking exactly, so the existing Save
   * button/RecordApi flow is the only thing that ever persists it.
   */
  handleApplySuggestion() {
    const suggestedLabel = this.aiAssessment?.suggestedLabel;
    if (suggestedLabel == null) {
      return;
    }
    this.value = suggestedLabel;
    this.saved = false;
  }
  renderAiReview(finding) {
    if (finding.aiReviewAvailable !== true) {
      return nothing;
    }
    return html`
      <div class="ai-review">
        ${renderSaveButton({
      saving: this.aiReviewBusy,
      disabled: this.aiReviewBusy || this.value === "",
      labelKey: this.aiReviewBusy ? "mindfula11y.findings.aiReview.assessing" : "mindfula11y.findings.aiReview.button",
      icon: "actions-refresh",
      onClick: () => this.handleAiReview()
    })}

        ${this.aiReviewError !== null ? html`<mindfula11y-notice class="status" state="danger">${renderNoticeBody(this.aiReviewError)}</mindfula11y-notice>` : nothing}

        ${this.aiAssessment !== null ? this.renderAiAssessmentResult(this.aiAssessment) : nothing}
      </div>
    `;
  }
  renderAiAssessmentResult(assessment) {
    return html`
      <mindfula11y-notice class="ai-assessment" state=${AI_ASSESSMENT_STATES[assessment.assessment]}>
        <span>
          <strong>${lll(`mindfula11y.findings.aiReview.assessment.${assessment.assessment}`)}</strong>
          <br />
          ${assessment.reason}
          <br />
          <em class="ai-disclaimer">${lll("mindfula11y.findings.aiReview.disclaimer")}</em>
        </span>

        ${assessment.suggestedLabel !== null ? html`<button type="button" class="button" slot="trailing" @click=${() => this.handleApplySuggestion()}>
                  ${lll("mindfula11y.findings.aiReview.applySuggestion")}: “${assessment.suggestedLabel}”
              </button>` : nothing}
      </mindfula11y-notice>
    `;
  }
  renderPrimaryNotice(finding) {
    if (!finding.rule) {
      return nothing;
    }
    let count;
    if (finding.rule === REPEATED_RULE) {
      count = finding.occurrenceCount;
    } else if (finding.rule === DIFFERENT_TARGETS_RULE) {
      count = finding.distinctTargetCount;
    }
    return this.renderNoticeForRule(finding.rule, count);
  }
  /**
   * A page-wide issue is rendered separately only when it is not already
   * the primary issue of this finding (avoids reporting it twice).
   */
  renderSecondaryNotice(finding, ruleSlug, isActive, count) {
    if (!isActive || finding.rule === ruleSlug) {
      return nothing;
    }
    return this.renderNoticeForRule(ruleSlug, count);
  }
  renderNoticeForRule(ruleSlug, count) {
    const ruleTitleKey = `mindfula11y.findings.rule.title.${ruleSlug}`;
    const ruleDescriptionKey = `mindfula11y.findings.rule.description.${ruleSlug}`;
    const title = lll(ruleTitleKey);
    const description = lll(ruleDescriptionKey);
    if (!title && !description) {
      return nothing;
    }
    return html`
      <mindfula11y-notice
        state="warning"
        count=${count ?? nothing}
      >
        <span>
          ${title ? html`<strong>${title}</strong>` : nothing}

          ${title && description ? html`<br />` : nothing}

          ${description || nothing}
        </span>
      </mindfula11y-notice>
    `;
  }
};
InteractiveLabelFinding.styles = [...baseStyles, buttonStyles, componentStyles];
__decorateClass([
  property({ type: Object })
], InteractiveLabelFinding.prototype, "finding", 2);
__decorateClass([
  state()
], InteractiveLabelFinding.prototype, "value", 2);
__decorateClass([
  state()
], InteractiveLabelFinding.prototype, "lastSavedValue", 2);
__decorateClass([
  state()
], InteractiveLabelFinding.prototype, "busy", 2);
__decorateClass([
  state()
], InteractiveLabelFinding.prototype, "saved", 2);
__decorateClass([
  state()
], InteractiveLabelFinding.prototype, "actionError", 2);
__decorateClass([
  state()
], InteractiveLabelFinding.prototype, "aiReviewBusy", 2);
__decorateClass([
  state()
], InteractiveLabelFinding.prototype, "aiAssessment", 2);
__decorateClass([
  state()
], InteractiveLabelFinding.prototype, "aiReviewError", 2);
InteractiveLabelFinding = __decorateClass([
  customElement("mindfula11y-interactive-label-finding")
], InteractiveLabelFinding);
export {
  InteractiveLabelFinding
};
