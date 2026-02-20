/*
 * Mindful A11y extension for TYPO3 integrating accessibility tools into the backend.
 * Copyright (C) 2025  Mindful Markup, Felix Spittel
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 */

/**
 * @file file-reference.js
 * @description Web component for displaying and editing file references, including alternative text generation and saving in TYPO3.
 */
import { LitElement, html } from "lit";
import "@typo3/backend/element/icon-element.js";
import Notification from "@typo3/backend/notification.js";
import AjaxDataHandler from "@typo3/backend/ajax-data-handler.js";
import AltTextGeneratorService from "./alt-text-generator-service.js";

/** @typedef {import('./types.js').GenerateAltTextDemand} GenerateAltTextDemand */

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
 * @property {GenerateAltTextDemand} generateAltTextDemand - The object containing the parameters for generating alt text.
 * @property {string} fallbackAlternative - Optional fallback alternative text to display if no alt text is provided.
 * @property {boolean} _loading - Indicates if alt text is currently being generated. Used internally to manage UI state.
 * @property {string} _statusMessage - Status message for screen readers, indicating the current state of the component. Used internally to provide feedback during alt text generation and saving.
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
      generateAltTextDemand: { type: Object },
      fallbackAlternative: { type: String }, // Optional fallback alt text
      _loading: { type: Boolean }, // Indicates if alt text is being generated
      _statusMessage: { type: String }, // For screenreader status
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
    this.generateAltTextDemand = null;
    this.fallbackAlternative = null;
    this._loading = false;
    this._statusMessage = "";
    this._inputId = this.createNodeId("mindfula11y-altless-file-reference");
    this._altTextGeneratorService = new AltTextGeneratorService(
      TYPO3.settings.ajaxUrls.mindfula11y_generatealttext
    );
    this._lastSavedAlternative = this.alternative;
  }

  updated(changedProps) {
    if (
      changedProps.has("alternative") &&
      this._lastSavedAlternative === undefined
    ) {
      this._lastSavedAlternative = this.alternative;
    }
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
    this.requestUpdate();
  }

  /**
   * Handles the Generate button click.
   * Uses AltTextGeneratorService to generate alt text.
   *
   * @returns {Promise<void>}
   */
  async handleGenerate() {
    this._loading = true;
    this._statusMessage =
      TYPO3.lang["mindfula11y.altText.generate.loading"];
    this.requestUpdate();

    const altText = await this._altTextGeneratorService.generateAltText(
      this.generateAltTextDemand
    );

    this._loading = false;
    if (!altText) {
      this._statusMessage =
        TYPO3.lang[
          "mindfula11y.altText.generate.error.unknown"
        ];
      this.requestUpdate();
      return;
    }

    this.alternative = altText;
    this._statusMessage =
      TYPO3.lang["mindfula11y.altText.generate.success"];
    this.requestUpdate();
    Notification.success(
      TYPO3.lang["mindfula11y.altText.generate.success"],
      TYPO3.lang[
        "mindfula11y.altText.generate.success.description"
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
        this._lastSavedAlternative = this.alternative;
        this.requestUpdate();
      })
      .catch(() => {
        Notification.error(
          TYPO3.lang[
            "mindfula11y.altText.generate.error.unknown"
          ],
          TYPO3.lang[
            "mindfula11y.altText.generate.error.unknown.description"
          ]
        );
      });
  }

  isSaveDisabled() {
    return this._loading || this.alternative === this._lastSavedAlternative;
  }

  /**
   * Renders the AltlessFileReference component with a simple Bootstrap form group.
   *
   * @returns {import('lit').TemplateResult} The rendered template for the component.
   */
  render() {
    return html`
      <div class="card w-100">
        <div class="card-body">
          <a
            href="${this.originalUrl}"
            target="_blank"
            rel="noopener noreferrer"
            class="d-block mb-3"
          >
            <img
              src="${this.previewUrl}"
              class="img-fluid"
              alt="${this.alternative ||
              TYPO3.lang["mindfula11y.altText.imagePreview"]}"
            />
          </a>
          ${this.recordEditLink
            ? html`<label class="form-label" for="${this._inputId}">
                  ${TYPO3.lang["mindfula11y.altText.altLabel"]}
                </label>
                <textarea
                  id="${this._inputId}"
                  class="form-control"
                  placeholder="${TYPO3.lang[
                    "mindfula11y.altText.altPlaceholder"
                  ]}"
                  rows="3"
                  .value="${this.alternative}"
                  ?readonly="${this._loading}"
                  @input="${this.handleAlternativeInput}"
                ></textarea>

                <div class="d-flex gap-2 mt-2">
                  ${null !== this.generateAltTextDemand
                    ? html`
                        <button
                          class="btn btn-secondary"
                          type="button"
                          @click="${this.handleGenerate}"
                          ?disabled="${this._loading}"
                        >
                          ${TYPO3.lang[
                            "mindfula11y.altText.generate.button"
                          ]}
                        </button>
                      `
                    : null}
                  <button
                    class="btn btn-primary"
                    type="button"
                    @click="${this.handleSave}"
                    ?disabled="${this.isSaveDisabled()}"
                  >
                    ${TYPO3.lang["mindfula11y.altText.save"]}
                  </button>
                </div>
                <div
                  class="mt-2"
                  role="status"
                  aria-live="polite"
                  aria-atomic="true"
                >
                  ${this._statusMessage
                    ? html`
                        <div class="callout callout-info">
                          <div class="callout-icon">
                            ${this._loading
                              ? html`<span
                                  class="spinner-border spinner-border-sm"
                                  aria-hidden="true"
                                ></span>`
                              : html`<span class="icon-emphasized"
                                  ><typo3-backend-icon
                                    identifier="status-dialog-information"
                                    size="small"
                                  ></typo3-backend-icon
                                ></span>`}
                          </div>
                          <div class="callout-content">
                            <div class="callout-title mb-0">
                              ${this._statusMessage}
                            </div>
                          </div>
                        </div>
                      `
                    : null}
                </div> `
            : null}
          ${this.fallbackAlternative
            ? html`
                <div class="callout callout-notice mt-2">
                  <div class="callout-icon">
                    <span class="icon-emphasized">
                      <typo3-backend-icon
                        identifier="status-dialog-information"
                        size="small"
                      ></typo3-backend-icon>
                    </span>
                  </div>
                  <div class="callout-content">
                    <div class="callout-body">
                      ${TYPO3.lang[
                        "mindfula11y.altText.fallbackAltLabel"
                      ]}
                      ${this.fallbackAlternative}
                    </div>
                  </div>
                </div>
              `
            : ""}
        </div>
        ${this.recordEditLink
          ? html`
              <div class="card-footer text-end">
                <a
                  href="${this.recordEditLink}"
                  class="link-secondary"
                  rel="noopener"
                >
                  ${this.recordEditLinkLabel}
                </a>
              </div>
            `
          : ""}
      </div>
    `;
  }
}

customElements.define(
  "mindfula11y-altless-file-reference",
  AltlessFileReference
);

export default AltlessFileReference;
