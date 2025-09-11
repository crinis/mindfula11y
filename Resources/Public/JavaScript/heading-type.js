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
 * @typedef {Object} HeadingTypeProps
 * @property {string} type - The heading type (e.g., 'h1', 'h2', 'p', 'div')
 * @property {Object<string, string>} availableTypes - Mapping of heading type tags to their labels
 * @property {string} recordTableName - Database table name
 * @property {string} recordColumnName - Database column name
 * @property {number} recordUid - Record unique identifier
 * @property {string} recordEditLink - Edit link URL
 * @property {Array<string>} errorMessages - Array of error message keys for this heading
 * @property {string} label - Display label/content text
 */
import { LitElement, html } from "lit";
import AjaxDataHandler from "@typo3/backend/ajax-data-handler.js";
import Notification from "@typo3/backend/notification.js";

/**
 * Web component for displaying and editing a single heading type in TYPO3.
 *
 * This component renders a heading's content and type selector, providing inline editing
 * capabilities when the user has appropriate permissions. It integrates with TYPO3's
 * backend data handling system for persistent storage of heading type changes.
 *
 * Features:
 * - Visual heading type display with Bootstrap styling
 * - Inline type editing with dropdown selection
 * - AJAX persistence with error handling
 * - Permission-based readonly/editable modes
 * - Accessibility-compliant markup and ARIA attributes
 * - Error state visualization
 *
 * @class HeadingType
 * @extends LitElement
 */
export class HeadingType extends LitElement {
  /**
   * Component properties definition.
   *
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
      errorMessages: { type: Array },
      label: { type: String },
      relationId: { type: String },
      ancestorId: { type: String },
      siblingId: { type: String },
    };
  }

  /**
   * Creates an instance of HeadingType.
   *
   * Initializes all component properties with sensible defaults and generates
   * a unique component ID for accessibility purposes.
   */
  constructor() {
    super();
    this._initializeProperties();
    this.id = this._createUniqueId("mindfula11y-heading-type");
    // Visual highlight styles were moved to the heading-structure component's styles
    // to keep component CSS consolidated and avoid injecting global styles here.
  }

  /**
   * Initializes component properties with default values.
   *
   * @private
   */
  _initializeProperties() {
    this.recordTableName = "";
    this.recordColumnName = "";
    this.recordUid = 0;
    this.recordEditLink = "";
    this.type = "h2";
    this.availableTypes = {};
    this.errorMessages = [];
    this.label = "";
    this.relationId = "";
    this.ancestorId = "";
    this.siblingId = "";
  }

  /**
   * Creates a unique ID for the component.
   *
   * @private
   * @param {string} prefix - The prefix for the ID
   * @returns {string} A unique ID string
   */
  _createUniqueId(prefix) {
    return `${prefix}-${Math.random().toString(36).substr(2, 9)}`;
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
   * Creates a heading type display with heading text and type selector/input.
   * Supports both editable and readonly modes based on user permissions.
   *
   * @returns {import('lit').TemplateResult} The rendered template for the component.
   */
  render() {
    const componentInfo = this._analyzeComponent();

    return html`
      ${this._renderTypeControl(componentInfo)}
      <div class="d-flex align-items-center gap-2">
        ${this._renderHeadingText(componentInfo)}
        ${this._renderEditButton(componentInfo)}
      </div>
    `;
  }

  /**
   * Analyzes the current component to extract key information.
   *
   * @private
   * @returns {Object} Component analysis object
   */
  _analyzeComponent() {
    // A heading is considered a descendant only when an ancestorId is present.
    // A heading is considered a sibling only when a siblingId is present.
    // relationId is only relevant for the parent/sibling side and must not be required here.
    const isDescendant = !!this.ancestorId;
    const isSibling = !!this.siblingId;

    return {
      // Descendants and siblings are never editable even when a recordEditLink exists
      isEditable: !isDescendant && !isSibling && this.recordEditLink !== "",
      isDescendant,
      isSibling,
      hasValidType: this.type && this.type.trim() !== "",
      displayLabel: this._getDisplayLabel(),
    };
  }

  /**
   * Gets the display label for the heading.
   *
   * @private
   * @returns {string} The label to display
   */
  _getDisplayLabel() {
    return (
      this.label?.trim() ||
      TYPO3.lang["mindfula11y.headingStructure.unlabeled"]
    );
  }

  /**
   * Renders the type control (select or readonly input) with proper accessibility labels.
   *
   * @private
   * @param {Object} componentInfo - Analysis information about the component
   * @returns {import('lit').TemplateResult} The rendered type control
   */
  _renderTypeControl(componentInfo) {
    const uniqueId = `${this.id}-type`;

    return html`
      <label for="${uniqueId}" class="visually-hidden">
        ${TYPO3.lang["mindfula11y.headingStructure.type"]}
      </label>
      ${componentInfo.isEditable
        ? this._renderTypeSelect(uniqueId, componentInfo)
        : this._renderTypeInput(uniqueId, componentInfo)}
    `;
  }

  /**
   * Renders an interactive type selection dropdown.
   *
   * @private
   * @param {string} uniqueId - Unique identifier for the select
   * @param {Object} componentInfo - Analysis information about the component
   * @returns {import('lit').TemplateResult} The select dropdown template
   */
  _renderTypeSelect(uniqueId, componentInfo) {
    return html`
      <select
        id="${uniqueId}"
        class="form-select form-select-sm w-auto ${this._getInputBorderClass()}"
        style="max-width: 6rem;"
        @change="${this._handleTypeChange}"
        ?aria-invalid="${this.errorMessages?.length > 0}"
      >
        ${this._renderTypeOptions()}
      </select>
    `;
  }

  /**
   * Renders a readonly type input for disabled mode.
   *
   * @private
   * @param {string} uniqueId - Unique identifier for the input
   * @param {Object} componentInfo - Analysis information about the component
   * @returns {import('lit').TemplateResult} The readonly input template
   */
  _renderTypeInput(uniqueId, componentInfo) {
    return html`
      <input
        id="${uniqueId}"
        type="text"
        class="form-control form-control-sm w-auto text-center fw-bold pe-none ${this._getInputBorderClass()}"
        style="max-width: 6rem;"
        value="${this.type.toUpperCase()}"
        readonly
        ?aria-invalid="${this.errorMessages?.length > 0}"
      />
    `;
  }

  /**
   * Gets the appropriate CSS class for the input border based on error state.
   *
   * @private
   * @returns {string} CSS class for border styling
   */
  _getInputBorderClass() {
    // Check for errors first - they take priority
    if (this.errorMessages?.length > 0) {
      const hasError = this.errorMessages.some(
        (error) => typeof error === "string" || error.severity === "error"
      );
      const hasWarning = this.errorMessages.some(
        (error) => typeof error === "object" && error.severity === "warning"
      );

      if (hasError) {
        return "border-danger";
      } else if (hasWarning) {
        return "border-warning";
      }
    }

    // No errors - use success border
    return "border-success";
  }

  /**
   * Renders the type options for the select dropdown.
   *
   * @private
   * @returns {import('lit').TemplateResult[]} Array of option templates
   */
  _renderTypeOptions() {
    return Object.entries(this.availableTypes).map(
      ([type, label]) => html`
        <option value="${type}" ?selected="${this.type === type}">
          ${label || type.toUpperCase()}
        </option>
      `
    );
  }

  /**
   * Renders the heading text as a span (not a label).
   *
   * @private
   * @param {Object} componentInfo - Analysis information about the component
   * @returns {import('lit').TemplateResult} The rendered heading text
   */
  _renderHeadingText(componentInfo) {
    return html` <span class="fw-bold"> ${componentInfo.displayLabel} </span> `;
  }

  /**
   * Renders the edit button or lock icon.
   *
   * @private
   * @param {Object} componentInfo - Analysis information about the component
   * @returns {import('lit').TemplateResult} The rendered edit control
   */
  _renderEditButton(componentInfo) {
    if (componentInfo.isEditable) {
      return html`
        <a
          href="${this.recordEditLink}"
          class="btn btn-outline-primary btn-sm d-flex align-items-center gap-1"
        >
          ${this._renderEditIcon()}
          <span
            >${TYPO3.lang["mindfula11y.headingStructure.edit"]}</span
          >
        </a>
      `;
    } else if (componentInfo.isDescendant || componentInfo.isSibling) {
      return html`
        <button
          type="button"
          class="btn btn-sm d-flex align-items-center gap-1"
          @click="${this._handleRelationClick}"
          data-relation-id="${componentInfo.isDescendant ? this.ancestorId : this.siblingId}"
        >
          ${this._renderRelationIcon()}
          <span>
            ${componentInfo.isDescendant
              ? TYPO3.lang[
                  "mindfula11y.headingStructure.relation.descendant"
                ]
              : TYPO3.lang[
                  "mindfula11y.headingStructure.relation.sibling"
                ]}
          </span>
        </button>
      `;
    }

    return html`
      <span class="text-muted d-flex align-items-center gap-1">
        ${this._renderLockIcon()}
        <span class="fs-7"
          >${TYPO3.lang[
            "mindfula11y.headingStructure.edit.locked"
          ]}</span
        >
      </span>
    `;
  }

  /**
   * Renders the edit icon SVG.
   *
   * @private
   * @returns {import('lit').TemplateResult} The rendered edit icon
   */
  _renderEditIcon() {
    return html`
      <svg
        class="t3js-icon icon icon-size-small"
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 16 16"
        width="16"
        height="16"
        aria-hidden="true"
      >
        <g fill="currentColor">
          <path
            d="m9.293 3.293-8 8A.997.997 0 0 0 1 12v3h3c.265 0 .52-.105.707-.293l8-8-3.414-3.414zM8.999 5l.5.5-5 5-.5-.5 5-5zM4 14H3v-1H2v-1l1-1 2 2-1 1zM13.707 5.707l1.354-1.354a.5.5 0 0 0 0-.707L12.354.939a.5.5 0 0 0-.707 0l-1.354 1.354 3.414 3.414z"
          />
        </g>
      </svg>
    `;
  }

  /**
   * Renders the lock icon SVG.
   *
   * @private
   * @returns {import('lit').TemplateResult} The rendered lock icon
   */
  _renderLockIcon() {
    return html`
      <svg
        class="t3js-icon icon icon-size-small"
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 16 16"
        width="16"
        height="16"
        aria-hidden="true"
      >
        <g fill="currentColor">
          <path
            d="M13 7v7H3V7h10m.5-1h-11a.5.5 0 0 0-.5.5v8a.5.5 0 0 0 .5-.5h11a.5.5 0 0 0 .5-.5v-8a.5.5 0 0 0-.5-.5zM8 2c1.654 0 3 1.346 3 3v1h1V5a4 4 0 0 0-8 0v1h1V5c0-1.654 1.346-3 3-3z"
          />
          <path d="M8 9a1 1 0 1 0 0 2 1 1 0 0 0 0-2z" />
        </g>
      </svg>
    `;
  }

  /**
   * Renders the relation/link icon (used for descendants pointing to an ancestor).
   * @private
   */
  _renderRelationIcon() {
    return html`
      <svg
        xmlns="http://www.w3.org/2000/svg"
        width="16"
        height="16"
        fill="currentColor"
        class="t3js-icon icon icon-size-small"
        viewBox="0 0 16 16"
        aria-hidden="true"
      >
        <path
          d="M4.715 6.542 3.343 7.914a3 3 0 1 0 4.243 4.243l1.828-1.829A3 3 0 0 0 8.586 5.5L8 6.086a1 1 0 0 0-.154.199 2 2 0 0 1 .861 3.337L6.88 11.45a2 2 0 1 1-2.83-2.83l.793-.792a4 4 0 0 1-.128-1.287z"
        />
        <path
          d="M6.586 4.672A3 3 0 0 0 7.414 9.5l.775-.776a2 2 0 0 1-.896-3.346L9.12 3.55a2 2 0 1 1 2.83 2.83l-.793.792c.112.42.155.855.128 1.287l1.372-1.372a3 3 0 1 0-4.243-4.243z"
        />
      </svg>
    `;
  }

  /**
   * Finds a related element in the document given a relation id string.
   * Used for both ancestor and sibling relations.
   * @private
   * @param {string} relationId
   * @returns {Element|null}
   */
  _findRelatedElement(relationId) {
    if (!relationId) return null;
    return document.querySelector(`[relationId="${relationId}"]`);
  }

  /**
   * Click handler for relation icon: focuses and highlights the related heading (ancestor or sibling).
   * @private
   * @param {Event} event
   */
  _handleRelationClick(event) {
    event.preventDefault();
    const relationId = event.currentTarget.getAttribute("data-relation-id");
    
    if (!relationId) {
      Notification.error(
        TYPO3.lang["mindfula11y.headingStructure.relation.notFound"],
        TYPO3.lang[
          "mindfula11y.headingStructure.relation.notFound.description"
        ]
      );
      return;
    }

    const related = this._findRelatedElement(relationId);
    if (!related) {
      Notification.warning(
        TYPO3.lang["mindfula11y.headingStructure.relation.notFound"],
        TYPO3.lang[
          "mindfula11y.headingStructure.relation.notFound.description"
        ]
      );
      return;
    }

    const focusable = related.querySelector("select, input");
    if (null !== focusable) {
      focusable.focus();
    }
    related.scrollIntoView({ behavior: "smooth", block: "center" });
  }

  /**
   * Handles type selection change events.
   *
   * @private
   * @param {Event} event - The change event from the select element
   */
  _handleTypeChange(event) {
    const newType = event.target.value;
    this._saveType(newType);
  }

  /**
   * Saves the selected heading type to the database.
   *
   * Stores the updated heading type in the record via AjaxDataHandler and dispatches
   * a custom event to notify parent components of the change.
   *
   * @private
   * @param {string} type - The new heading type selected by the user
   */
  async _saveType(type) {
    const params = {
      data: {
        [this.recordTableName]: {
          [this.recordUid]: {
            [this.recordColumnName]: type,
          },
        },
      },
    };

    try {
      await AjaxDataHandler.process(params);
      this.type = type;
      this._dispatchChangeEvent();
    } catch (error) {
      this._handleSaveError(error);
    }
  }

  /**
   * Dispatches a custom event to notify parent components of type changes.
   *
   * @private
   */
  _dispatchChangeEvent() {
    this.dispatchEvent(
      new CustomEvent("mindfula11y-heading-type-changed", {
        bubbles: true,
        composed: true,
        detail: { type: this.type, uid: this.recordUid },
      })
    );
  }

  /**
   * Handles save errors with user notification.
   *
   * @private
   * @param {Error} error - The error that occurred during save
   */
  _handleSaveError(error) {
    console.error("Failed to save heading type:", error);

    Notification.error(
      TYPO3.lang["mindfula11y.headingStructure.error.store"],
      TYPO3.lang[
        "mindfula11y.headingStructure.error.store.description"
      ]
    );
  }
}

customElements.define("mindfula11y-heading-type", HeadingType);

export default HeadingType;
