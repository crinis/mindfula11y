/**
 * @file file-reference.js
 * @description Web component for displaying and editing file references, including alternative text generation and saving in TYPO3.
 */
import { LitElement, html } from "lit";
import Notification from "@typo3/backend/notification.js";
import AjaxDataHandler from "@typo3/backend/ajax-data-handler.js";
import AltTextGeneratorService from "./alt-text-generator-service.js";

/** @typedef {import('./types.js').AltTextDemand} AltTextDemand */

/**
 * AltlessFileReference web component.
 *
 * Displays an image preview and provides an input for alternative text,
 * along with Generate and Save buttons. Uses Bootstrap classes for layout.
 *
 * @class AltlessFileReference
 * @extends LitElement
 *
 * @property {string} previewUrl - The URL for the image preview.
 * @property {string} originalUrl - The URL for the original image.
 * @property {string} alternative - The alternative text for the image.
 * @property {string} recordEditLink - The link to edit the original record associated with the file reference.
 * @property {string} recordEditLinkLabel - The label for the edit record link.
 * @property {number} uid - The unique identifier for the file reference.
 * @property {AltTextDemand} altTextDemand - The object containing the parameters for generating alt text.
 */
export class AltlessFileReference extends LitElement {
  static get properties() {
    return {
      previewUrl: { type: String },
      originalUrl: { type: String },
      alternative: { type: String },
      recordEditLink: { type: String },
      recordEditLinkLabel: { type: String },
      uid: { type: Number },
      altTextDemand: { type: Object },
      loading: { type: Boolean }, // Indicates if alt text is being generated
      statusMessage: { type: String }, // For screenreader status
      fallbackAlternative: { type: String }, // Optional fallback alt text
    };
  }

  constructor() {
    super();
    this.previewUrl = "";
    this.originalUrl = "";
    this.alternative = "";
    this.recordEditLink = "";
    this.recordEditLinkLabel = "";
    this.uid = 0;
    this.altTextDemand = null;
    this.fallbackAlternative = null;

    /**
     * Internal properties.
     */
    this.loading = false;
    this.statusMessage = "";

    this.inputId = this.createNodeId("mindfula11y-altless-file-reference");
    this.altTextGeneratorService = new AltTextGeneratorService(
      TYPO3.settings.ajaxUrls.mindfula11y_generatealttext
    );
  }

  /**
   * Disables shadow DOM to allow Bootstrap styling.
   * @returns {HTMLElement}
   */
  createRenderRoot() {
    return this;
  }

  /**
   * Creates a unique ID for nodes.
   *
   * @param {string} prefix - The prefix for the ID.
   * @returns {string} A unique ID string for the node.
   */
  createNodeId(prefix) {
    return `${prefix}-${Math.random().toString(36).substr(2, 9)}`;
  }

  /**
   * Handles input change for the alt text field.
   *
   * @param {InputEvent} e - The input event from the textarea.
   * @returns {void}
   */
  handleAlternativeInput(e) {
    this.alternative = e.target.value;
  }

  /**
   * Handles the Generate button click.
   * Uses AltTextGeneratorService to generate alt text.
   *
   * @returns {Promise<void>}
   */
  async handleGenerate() {
    this.loading = true;
    this.statusMessage =
      TYPO3.lang["mindfula11y.modules.missingAltText.generate.loading"];
    this.requestUpdate();

    const altText = await this.altTextGeneratorService.generateAltText(
      this.altTextDemand
    );

    this.loading = false;
    if (!altText) {
      this.statusMessage =
        TYPO3.lang["mindfula11y.modules.missingAltText.generate.error.unknown"];
      this.requestUpdate();
      return;
    }

    this.alternative = altText;
    this.statusMessage =
      TYPO3.lang["mindfula11y.modules.missingAltText.generate.success"];
    this.requestUpdate();
    Notification.success(
      TYPO3.lang["mindfula11y.modules.missingAltText.generate.success"],
      TYPO3.lang[
        "mindfula11y.modules.missingAltText.generate.success.description"
      ]
    );
  }

  /**
   * Handles the Save button click.
   * Sends the updated alternative text to the backend using AjaxDataHandler.
   *
   * @returns {void}
   */
  handleSave() {
    const params = {
      data: {
        sys_file_reference: {
          [this.uid]: {
            alternative: this.alternative,
          },
        },
      },
    };

    AjaxDataHandler.process(params)
      .then(() => {
        // Update the last saved value after successful save
        this._lastSavedAlternative = this.alternative;
        this.requestUpdate();
      })
      .catch(() => {
        Notification.error(
          TYPO3.lang["mindfula11y.modules.missingAltText.error.store"],
          TYPO3.lang[
            "mindfula11y.modules.missingAltText.error.store.description"
          ]
        );
      });
  }

  /**
   * Renders the AltlessFileReference component with a simple Bootstrap form group.
   *
   * @returns {import('lit').TemplateResult} The rendered template for the component.
   */
  render() {
    return html`
      <div class="card w-100 mb-0">
        <div class="card-body">
          <a
            href="${this.originalUrl}"
            target="_blank"
            rel="noopener"
            class="d-block mb-3"
          >
            <img
              src="${this.previewUrl}"
              class="img-fluid"
              alt="${this.alternative ||
              TYPO3.lang["mindfula11y.modules.missingAltText.imagePreview"]}"
            />
          </a>
          <label class="form-label" for="${this.inputId}">
            ${TYPO3.lang["mindfula11y.modules.missingAltText.altLabel"]}
          </label>
          <textarea
            id="${this.inputId}"
            class="form-control"
            placeholder="${TYPO3.lang[
              "mindfula11y.modules.missingAltText.altPlaceholder"
            ]}"
            rows="3"
            .value="${this.alternative}"
            ?readonly="${this.loading}"
            @input="${this.handleAlternativeInput}"
          ></textarea>

          <div class="d-flex gap-2 mt-2">
            <button
              class="btn btn-secondary"
              type="button"
              @click="${this.handleGenerate}"
              ?disabled="${this.loading}"
            >
              ${TYPO3.lang[
                "mindfula11y.modules.missingAltText.generate.button"
              ]}
            </button>
            <button
              class="btn btn-primary"
              type="button"
              @click="${this.handleSave}"
              ?disabled="${this.loading}"
            >
              ${TYPO3.lang["mindfula11y.modules.missingAltText.save"]}
            </button>
          </div>
          <div class="mt-2" role="status" aria-live="polite" aria-atomic="true">
            ${this.statusMessage
              ? html`<p class="alert alert-info">
                  ${this.loading
                    ? html`<span
                        class="spinner-border spinner-border-sm"
                        aria-hidden="true"
                      ></span>`
                    : null}
                  ${this.statusMessage}
                </p>`
              : null}
          </div>
          ${this.fallbackAlternative
            ? html`
                <p class="mt-2 alert alert-secondary">
                  ${TYPO3.lang[
                    "mindfula11y.modules.missingAltText.fallbackAltLabel"
                  ]}
                  ${this.fallbackAlternative}
                </p>
              `
            : ""}
        </div>
        <div class="card-footer text-end">
          <a
            href="${this.recordEditLink}"
            class="link-secondary"
            rel="noopener"
          >
            ${this.recordEditLinkLabel ||
            TYPO3.lang["mindfula11y.modules.missingAltText.editRecord"]}
          </a>
        </div>
      </div>
    `;
  }
}

customElements.define(
  "mindfula11y-altless-file-reference",
  AltlessFileReference
);

export default AltlessFileReference;
