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
import { LiveAnnouncer } from "../../lib/live-announcer.js";
import { renderNoticeBody } from "../../lib/status-render.js";
import { AltTextApi } from "../../service/alt-text-api.js";
import { RecordApi } from "../../service/record-api.js";
import { errorView } from "../../service/request-error.js";
import { baseStyles } from "../../styles/base-styles.js";
import buttonStyles from "../../styles/button.css.js";
import noticeStyles from "../../styles/notice.css.js";
import componentStyles from "./altless-file-reference.css.js";
const DECORATIVE_FIELD = "tx_mindfula11y_decorative";
let AltlessFileReference = class extends LitElement {
  constructor() {
    super(...arguments);
    this.previewUrl = "";
    this.originalUrl = "";
    this.uid = 0;
    this.recordEditLink = "";
    this.recordEditLinkLabel = "";
    this.decorativeEditable = false;
    this.decorative = false;
    this.alternative = "";
    this.generateAltTextDemand = null;
    this.fallbackAlternative = "";
    this.value = "";
    this.lastSavedValue = "";
    this.lastSavedDecorative = false;
    this.busy = "idle";
    this.actionError = null;
    this.saved = false;
    this.altTextApi = new AltTextApi();
    this.recordApi = new RecordApi();
    this.announcer = new LiveAnnouncer(this);
  }
  willUpdate(changedProperties) {
    if (!this.hasUpdated) {
      if (changedProperties.has("decorative")) {
        this.lastSavedDecorative = this.decorative;
      }
      if (changedProperties.has("alternative")) {
        this.value = this.alternative;
        this.lastSavedValue = this.alternative;
      }
    }
  }
  render() {
    return html`<div class="card">
            <div class="body">
                ${this.renderPreview()}
                <div class="content">
                    ${this.renderEditor()} ${this.renderFallback()}
                    ${this.recordEditLink !== "" ? html`<p class="footer">
                                  <a class="edit" href=${this.recordEditLink}>${this.recordEditLinkLabel}</a>
                              </p>` : nothing}
                </div>
            </div>
        </div>`;
  }
  renderPreview() {
    if (this.previewUrl === "") {
      return nothing;
    }
    const image = html`<img class="image" src=${this.previewUrl} alt=${this.imageAlternative()} />`;
    if (this.originalUrl === "") {
      return html`<div class="preview">${image}</div>`;
    }
    return html`<div class="preview">
            <a href=${this.originalUrl} target="_blank" rel="noreferrer">
                ${image}
                <span class="sr-only">${lll("mindfula11y.altText.opensNewTab")}</span>
            </a>
        </div>`;
  }
  renderEditor() {
    if (this.recordEditLink === "") {
      return this.renderReadOnlyState();
    }
    if (this.decorative && !this.decorativeEditable) {
      return this.renderReadOnlyState();
    }
    return html`<div class="editor">
            ${this.decorativeEditable ? html`<div class="decorative-option">
                          <label class="decorative-label">
                              <input
                                  type="checkbox"
                                  .checked=${live(this.decorative)}
                                  ?disabled=${this.busy !== "idle"}
                                  aria-describedby="decorative-description"
                                  @change=${this.handleDecorativeChange}
                              />
                              <span>${lll("mindfula11y.altText.decorative.label")}</span>
                          </label>
                          <p id="decorative-description" class="hint">
                              ${lll("mindfula11y.altText.decorative.description")}
                          </p>
                      </div>` : nothing}
            ${this.decorative ? nothing : html`<label class="label" for="alt">${lll("mindfula11y.altText.altLabel")}</label>
                          <textarea
                              id="alt"
                              class="input"
                              rows="3"
                              .value=${live(this.value)}
                              placeholder=${lll("mindfula11y.altText.altPlaceholder")}
                              ?readonly=${this.busy !== "idle"}
                              @input=${this.handleInput}
                          ></textarea>`}
            <div class="actions">
                ${this.generateAltTextDemand !== null && !this.decorative ? html`<button
                              type="button"
                              class="button"
                              aria-disabled=${this.busy !== "idle" ? "true" : nothing}
                              @click=${this.handleGenerate}
                          >
                              ${this.busy === "generating" ? html`<typo3-backend-spinner size="small"></typo3-backend-spinner>` : html`<typo3-backend-icon
                                            identifier="actions-refresh"
                                            size="small"
                                        ></typo3-backend-icon>`}
                              ${lll("mindfula11y.altText.generate.button")}
                          </button>` : nothing}
                <button
                    type="button"
                    class="button"
                    aria-disabled=${this.busy !== "idle" || this.value === this.lastSavedValue && this.decorative === this.lastSavedDecorative ? "true" : nothing}
                    @click=${this.handleSave}
                >
                    ${this.busy === "saving" ? html`<typo3-backend-spinner size="small"></typo3-backend-spinner>` : html`<typo3-backend-icon identifier="actions-save" size="small"></typo3-backend-icon>`}
                    ${lll("mindfula11y.altText.save")}
                </button>
            </div>
            ${this.announcer.render()}
            <div class="status-region" role="status">
                ${this.actionError !== null ? html`<mindfula11y-notice class="status" state="danger">
                              ${renderNoticeBody(this.actionError)}
                          </mindfula11y-notice>` : this.saved ? html`<mindfula11y-notice class="status" state="success">
                                <span>${lll("mindfula11y.altText.save.success")}</span>
                            </mindfula11y-notice>` : nothing}
            </div>
        </div>`;
  }
  renderReadOnlyState() {
    if (this.decorative) {
      return html`<p class="decorative-state">${lll("mindfula11y.altText.decorative.label")}</p>`;
    }
    if (this.value === "") {
      return nothing;
    }
    return html`<dl class="alternative-readonly">
            <dt class="label">${lll("mindfula11y.altText.altLabel")}</dt>
            <dd>${this.value}</dd>
        </dl>`;
  }
  renderFallback() {
    if (this.fallbackAlternative === "") {
      return nothing;
    }
    return html`<mindfula11y-notice class="status" state="info">
            ${renderNoticeBody({ title: lll("mindfula11y.altText.fallbackAltLabel"), description: this.fallbackAlternative })}
        </mindfula11y-notice>`;
  }
  /** Alt for the preview itself: the drafted text or a generic image label. */
  imageAlternative() {
    return this.value !== "" ? this.value : lll("mindfula11y.altText.imagePreview");
  }
  handleInput(event) {
    this.value = event.target.value;
    this.saved = false;
  }
  handleDecorativeChange(event) {
    this.decorative = event.target.checked;
    this.saved = false;
  }
  async handleGenerate() {
    if (this.generateAltTextDemand === null || this.busy !== "idle") {
      return;
    }
    this.busy = "generating";
    this.actionError = null;
    this.saved = false;
    await this.announcer.announce(lll("mindfula11y.altText.generate.loading"));
    try {
      this.value = await this.altTextApi.generateAltText(this.generateAltTextDemand);
      await this.announcer.announce(lll("mindfula11y.altText.generate.success"));
    } catch (error) {
      this.actionError = errorView(error, "mindfula11y.altText.generate.error.unknown");
    } finally {
      this.busy = "idle";
    }
  }
  async handleSave() {
    if (this.busy !== "idle" || this.value === this.lastSavedValue && this.decorative === this.lastSavedDecorative) {
      return;
    }
    this.busy = "saving";
    this.actionError = null;
    try {
      const fields = {
        alternative: this.decorative ? "" : this.value
      };
      if (this.decorativeEditable) {
        fields[DECORATIVE_FIELD] = this.decorative ? "1" : "0";
      }
      await this.recordApi.updateFields("sys_file_reference", this.uid, fields);
      if (this.decorative) {
        this.value = "";
      }
      this.lastSavedValue = this.value;
      this.lastSavedDecorative = this.decorative;
      this.saved = true;
    } catch (error) {
      this.actionError = errorView(error, "mindfula11y.altText.save.error");
    } finally {
      this.busy = "idle";
    }
  }
};
AltlessFileReference.styles = [...baseStyles, noticeStyles, buttonStyles, componentStyles];
__decorateClass([
  property({ attribute: "preview-url" })
], AltlessFileReference.prototype, "previewUrl", 2);
__decorateClass([
  property({ attribute: "original-url" })
], AltlessFileReference.prototype, "originalUrl", 2);
__decorateClass([
  property({ type: Number })
], AltlessFileReference.prototype, "uid", 2);
__decorateClass([
  property({ attribute: "record-edit-link" })
], AltlessFileReference.prototype, "recordEditLink", 2);
__decorateClass([
  property({ attribute: "record-edit-link-label" })
], AltlessFileReference.prototype, "recordEditLinkLabel", 2);
__decorateClass([
  property({ attribute: "decorative-editable", type: Boolean })
], AltlessFileReference.prototype, "decorativeEditable", 2);
__decorateClass([
  property({ type: Boolean })
], AltlessFileReference.prototype, "decorative", 2);
__decorateClass([
  property()
], AltlessFileReference.prototype, "alternative", 2);
__decorateClass([
  property({ attribute: "generate-alt-text-demand", type: Object })
], AltlessFileReference.prototype, "generateAltTextDemand", 2);
__decorateClass([
  property({ attribute: "fallback-alternative" })
], AltlessFileReference.prototype, "fallbackAlternative", 2);
__decorateClass([
  state()
], AltlessFileReference.prototype, "value", 2);
__decorateClass([
  state()
], AltlessFileReference.prototype, "lastSavedValue", 2);
__decorateClass([
  state()
], AltlessFileReference.prototype, "lastSavedDecorative", 2);
__decorateClass([
  state()
], AltlessFileReference.prototype, "busy", 2);
__decorateClass([
  state()
], AltlessFileReference.prototype, "actionError", 2);
__decorateClass([
  state()
], AltlessFileReference.prototype, "saved", 2);
AltlessFileReference = __decorateClass([
  customElement("mindfula11y-altless-file-reference")
], AltlessFileReference);
export {
  AltlessFileReference
};
