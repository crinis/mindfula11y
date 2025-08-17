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
 * @file heading-type.js
 * @description Web component for displaying and editing a single heading type in TYPO3, with AJAX save support.
 */
import { LitElement, html, svg } from "lit";
import AjaxDataHandler from "@typo3/backend/ajax-data-handler.js";

/**
 * Web component for displaying and editing a single heading type in TYPO3.
 *
 * Renders a heading's content and type. If it has a valid edit configuration, it provides a select input
 * to change the type of the heading.
 *
 * @class HeadingType
 * @extends LitElement
 */
export class HeadingType extends LitElement {
  /**
   * Component properties.
   *
   * @property {string} type - The heading type (e.g., 'h1' for <h1>, 'h2' for <h2>, 'p' for <p>, etc.).
   * @property {Object.<string, string>} availableTypes - Mapping of heading type tags to their labels.
   * @property {string} recordTableName - The name of the database table associated with the record.
   * @property {string} recordColumnName - The field name in the database record that stores the heading type.
   * @property {number} recordUid - The unique identifier (UID) of the database record.
   * @property {string} recordEditLink - The edit link for the record.
   * @property {boolean} hasError - Indicates if there is an error in the component.
   * @property {string} label - The label or text content of the heading.
   * @returns {Object} The properties definition object for LitElement.
   */
  static get properties() {
    return {
      type: { type: String },
      availableTypes: { type: Object },
      recordTableName: { type: String },
      recordColumnName: { type: String },
      recordUid: { type: Number },
      recordEditLink: { type: String },
      hasError: { type: Boolean },
      label: { type: String },
    };
  }

  /**
   * Constructor for the HeadingType component.
   *
   * Initializes the component properties.
   */
  constructor() {
    super();
    this.recordTableName = "";
    this.recordColumnName = "";
    this.recordUid = 0;
    this.recordEditLink = "";
    this.type = "h2";
    this.availableTypes = {};
    this.hasError = false;
    this.label = "";
    this.id = this.createNodeId("mindfula11y-heading-type");
  }

  /**
   * Disables the default shadow DOM and renders into the light DOM.
   *
   * @returns {HTMLElement} The root element for the component (this).
   */
  createRenderRoot() {
    return this;
  }

  /**
   * Renders the heading type component.
   *
   * Creates a single heading type component with a select input for editing the type if allowed.
   *
   * @returns {import('lit').TemplateResult} The rendered template for the component.
   */
  render() {
    const isEditable = "" !== this.recordEditLink;

    return html`${this.createTypeSelect(!isEditable)}
      <label for="${this.id}" class="fw-bold">${this.label}</label>
        ${
          isEditable
            ? html` <a
                href="${this.recordEditLink}"
                class="btn btn-default btn-sm"
                ><svg
                  class="t3js-icon icon icon-size-small"
                  xmlns="http://www.w3.org/2000/svg"
                  viewBox="0 0 16 16"
                >
                  <title>
                    ${TYPO3.lang["mindfula11y.features.headingStructure.edit"]}
                  </title>
                  <g fill="currentColor">
                    <path
                      d="m9.293 3.293-8 8A.997.997 0 0 0 1 12v3h3c.265 0 .52-.105.707-.293l8-8-3.414-3.414zM8.999 5l.5.5-5 5-.5-.5 5-5zM4 14H3v-1H2v-1l1-1 2 2-1 1zM13.707 5.707l1.354-1.354a.5.5 0 0 0 0-.707L12.354.939a.5.5 0 0 0-.707 0l-1.354 1.354 3.414 3.414z"
                    />
                  </g>
                </svg>
              </a>`
            : html`<svg
                class="t3js-icon icon icon-size-small"
                xmlns="http://www.w3.org/2000/svg"
                viewBox="0 0 16 16"
              >
                <title>
                  ${TYPO3.lang["mindfula11y.features.headingStructure.edit.locked"]}
                </title>
                <g fill="currentColor">
                  <path
                    d="M13 7v7H3V7h10m.5-1h-11a.5.5 0 0 0-.5.5v8a.5.5 0 0 0 .5.5h11a.5.5 0 0 0 .5-.5v-8a.5.5 0 0 0-.5-.5zM8 2c1.654 0 3 1.346 3 3v1h1V5a4 4 0 0 0-8 0v1h1V5c0-1.654 1.346-3 3-3z"
                  />
                  <path
                    d="M10 10a2 2 0 1 0-4 0c0 .738.405 1.376 1 1.723V13h2v-1.277c.595-.347 1-.985 1-1.723z"
                  />
                </g>
              </svg>`
        }</a
      >`;
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
   * Creates a type select input element.
   *
   * Returns a <select> element with all available heading types as options. If editing is disabled,
   * only the current type is shown as a single option.
   *
   * @param {boolean} [disabled=false] - Whether the select input should be disabled.
   * @returns {import('lit').TemplateResult} The type select element.
   */
  createTypeSelect(disabled = false) {
    let options = [];
    if (disabled) {
      options.push(
        html`<option value="${this.type}" selected>${this.type.toUpperCase()}</option>`
      );
    } else {
      options = Object.entries(this.availableTypes).map(
        ([type, label]) => html`
          <option
            value="${type}"
            ?selected="${this.type === type}"
          >
            ${label || type.toUpperCase()}
          </option>
        `
      );
    }

    return disabled
      ? html`<input
          id="${this.id}"
          type="text"
          class="badge mindfula11y-type__input"
          value="${this.type.toUpperCase()}"
          readonly
          ?aria-invalid="${this.hasError}"
        />`
      : html`
          <select
            id="${this.id}"
            class="badge mindfula11y-type__input"
            @change="${(e) => this.handleSave(e.target.value)}"
            ?aria-invalid="${this.hasError}"
          >
            ${options}
          </select>
        `;
  }

  /**
   * Store the selected type on change.
   *
   * Stores the updated heading type in the record via AjaxDataHandler. Serves
   * as a callback for the select input's change event.
   *
   * @param {string} type - The new heading type selected by the user (tag name like 'h1', 'h2', 'p', 'div', etc.).
   * @returns {void}
   */
  handleSave(type) {
    const params = {
      data: {
        [this.recordTableName]: {
          [this.recordUid]: {
            [this.recordColumnName]: type,
          },
        },
      },
    };

    AjaxDataHandler.process(params)
      .then(() => {
        this.type = type;
        this.dispatchEvent(
          new CustomEvent("mindfula11y-heading-type-changed", {
            bubbles: true,
            composed: true,
          })
        );
      })
      .catch(() => {
        Notification.error(
          TYPO3.lang["mindfula11y.features.headingStructure.error.store"],
          TYPO3.lang[
            "mindfula11y.features.headingStructure.error.store.description"
          ]
        );
      });
  }
}

customElements.define("mindfula11y-heading-type", HeadingType);

export default HeadingType;
