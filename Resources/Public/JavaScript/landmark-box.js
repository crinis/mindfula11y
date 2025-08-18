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
 * @file landmark-box.js
 * @description Web component for displaying an individual landmark in a box layout with editing capabilities.
 * @typedef {Object} LandmarkBoxProps
 * @property {string} role - The landmark role
 * @property {string} label - The landmark label
 * @property {Array<string>} errorMessages - Array of error messages
 * @property {Object<string, string>} availableRoles - Available role options
 * @property {string} recordTableName - Database table name
 * @property {string} recordColumnName - Database column name
 * @property {number} recordUid - Record unique identifier
 * @property {string} recordEditLink - Edit link URL
 * @property {string} landmarkId - Unique landmark identifier
 */
import { LitElement, html, css } from "lit";
import AjaxDataHandler from "@typo3/backend/ajax-data-handler.js";
import Notification from "@typo3/backend/notification.js";
import { 
  LANDMARK_ROLES, 
  LANDMARK_CALLOUT_CLASSES, 
  LANDMARK_LABEL_KEYS, 
  LANDMARK_UI_CONSTANTS 
} from "./types.js";

/**
 * Web component for displaying and editing a single landmark in TYPO3.
 * 
 * This component renders a landmark as a styled callout with role selection capability,
 * error display, and integration with TYPO3's backend editing system. It supports
 * both editable and readonly modes depending on user permissions.
 * 
 * Features:
 * - Visual landmark representation with role-based styling
 * - Inline role editing with AJAX persistence
 * - Error message display for accessibility violations
 * - Integration with TYPO3 record editing
 * - Accessibility-compliant markup and ARIA attributes
 *
 * @class LandmarkBox
 * @extends LitElement
 */
export class LandmarkBox extends LitElement {
  /**
   * Component properties definition.
   *
   * @returns {Object} The properties definition object for LitElement.
   */
  static get properties() {
    return {
      role: { type: String },
      availableRoles: { type: Object },
      recordTableName: { type: String },
      recordColumnName: { type: String },
      recordUid: { type: Number },
      recordEditLink: { type: String },
      label: { type: String },
      children: { type: Array },
      landmarkId: { type: String },
      errorMessages: { type: Array }
    };
  }

  /**
   * Creates an instance of LandmarkBox.
   * 
   * Initializes all component properties with sensible defaults and generates
   * a unique component ID for accessibility purposes.
   */
  constructor() {
    super();
    this._initializeProperties();
    this.id = this._createUniqueId("mindfula11y-landmark-box");
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
    this.role = "";
    this.availableRoles = {};
    this.label = "";
    this.children = [];
    this.landmarkId = "";
    this.errorMessages = [];
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
   * Renders the landmark box component.
   *
   * Creates a styled callout container with landmark information, role selection,
   * error messages, and edit controls. Supports both editable and readonly modes.
   *
   * @returns {import('lit').TemplateResult} The rendered template for the component.
   */
  render() {
    const landmarkInfo = this._analyzeLandmark();
    const calloutClass = this.getCalloutClass(landmarkInfo.hasErrors);
    
    return html`
      <div class="callout ${calloutClass} shadow-sm mb-3" data-uid="${this.recordUid}">
        ${this._renderLandmarkContent(landmarkInfo)}
        ${this._renderEditControls(landmarkInfo.isEditable)}
      </div>
    `;
  }

  /**
   * Analyzes the current landmark to extract key information.
   * 
   * @private
   * @returns {Object} Landmark analysis object
   */
  _analyzeLandmark() {
    return {
      hasChildren: this.children?.length > 0,
      isEditable: this.recordEditLink !== '',
      hasErrors: this.errorMessages?.length > 0,
      displayLabel: this._getDisplayLabel()
    };
  }

  /**
   * Gets the display label for the landmark.
   * 
   * @private
   * @returns {string} The label to display
   */
  _getDisplayLabel() {
    if (this.label?.trim()) {
      return this.label;
    }
    return TYPO3.lang[LANDMARK_LABEL_KEYS.COMPONENT.UNLABELED_LANDMARK] || '';
  }

  /**
   * Renders the main content of the landmark box.
   * 
   * @private
   * @param {Object} landmarkInfo - Analysis information about the landmark
   * @returns {import('lit').TemplateResult} The rendered content
   */
  _renderLandmarkContent(landmarkInfo) {
    return html`
      <div class="flex-grow-1">
        ${this._renderLandmarkHeader(landmarkInfo)}
        ${this._renderErrorMessages(landmarkInfo.hasErrors)}
        ${this._renderLandmarkControls(landmarkInfo)}
      </div>
    `;
  }

  /**
   * Renders the landmark header with title.
   * 
   * @private
   * @param {Object} landmarkInfo - Analysis information about the landmark
   * @returns {import('lit').TemplateResult} The rendered header
   */
  _renderLandmarkHeader(landmarkInfo) {
    return html`
      <div id="${this.landmarkId}" class="fw-bold h3 mb-2">
        ${landmarkInfo.displayLabel}
      </div>
    `;
  }

  /**
   * Renders error messages if present.
   * 
   * @private
   * @param {boolean} hasErrors - Whether the landmark has errors
   * @returns {import('lit').TemplateResult|string} The rendered error messages or empty string
   */
  _renderErrorMessages(hasErrors) {
    if (!hasErrors) {
      return '';
    }

    return html`
      <ul class="list-unstyled mt-0 mb-2 small text-danger">
        ${this.errorMessages.map(message => html`<li>${message}</li>`)}
      </ul>
    `;
  }

  /**
   * Renders the landmark controls (role selector and badges).
   * 
   * @private
   * @param {Object} landmarkInfo - Analysis information about the landmark
   * @returns {import('lit').TemplateResult} The rendered controls
   */
  _renderLandmarkControls(landmarkInfo) {
    return html`
      <div class="d-flex flex-column gap-2">
        ${this.createRoleSelect(!landmarkInfo.isEditable)}
        ${this._renderLandmarkBadges(landmarkInfo)}
      </div>
    `;
  }

  /**
   * Renders landmark badges (role and children count).
   * 
   * @private
   * @param {Object} landmarkInfo - Analysis information about the landmark
   * @returns {import('lit').TemplateResult} The rendered badges
   */
  _renderLandmarkBadges(landmarkInfo) {
    return html`
      <div class="d-flex flex-wrap gap-2">
        <span class="badge rounded-pill px-2 py-1">
          <code>${this.role}</code>
        </span>
        ${landmarkInfo.hasChildren ? html`
          <span class="badge rounded-pill">
            ${this.children.length} ${TYPO3.lang[LANDMARK_LABEL_KEYS.UI.NESTED_LANDMARKS]}
          </span>
        ` : ''}
      </div>
    `;
  }

  /**
   * Renders edit controls (edit button or locked indicator).
   * 
   * @private
   * @param {boolean} isEditable - Whether the landmark can be edited
   * @returns {import('lit').TemplateResult} The rendered edit controls
   */
  _renderEditControls(isEditable) {
    return html`
      <div class="flex-shrink-0 align-self-start ms-lg-auto">
        ${isEditable ? this._renderEditButton() : this._renderLockedIndicator()}
      </div>
    `;
  }

  /**
   * Renders the edit button for editable landmarks.
   * 
   * @private
   * @returns {import('lit').TemplateResult} The rendered edit button
   */
  _renderEditButton() {
    return html`
      <a href="${this.recordEditLink}" class="btn btn-outline-primary btn-sm d-flex align-items-center gap-1">
        ${this._renderEditIcon()}
        <span>${TYPO3.lang[LANDMARK_LABEL_KEYS.COMPONENT.EDIT]}</span>
      </a>
    `;
  }

  /**
   * Renders the locked indicator for non-editable landmarks.
   * 
   * @private
   * @returns {import('lit').TemplateResult} The rendered locked indicator
   */
  _renderLockedIndicator() {
    return html`
      <span class="text-muted d-flex align-items-center gap-1">
        ${this._renderLockIcon()}
        <span class="fs-7">${TYPO3.lang[LANDMARK_LABEL_KEYS.COMPONENT.EDIT_LOCKED]}</span>
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
        width="14"
        height="14"
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
        width="14"
        height="14"
        aria-hidden="true"
      >
        <g fill="currentColor">
          <path
            d="M13 7v7H3V7h10m.5-1h-11a.5.5 0 0 0-.5.5v8a.5.5 0 0 0 .5-.5h11a.5.5 0 0 0 .5-.5v-8a.5.5 0 0 0-.5-.5zM8 2c1.654 0 3 1.346 3 3v1h1V5a4 4 0 0 0-8 0v1h1V5c0-1.654 1.346-3 3-3z"
          />
          <path
            d="M10 10a2 2 0 1 0-4 0c0 .738.405 1.376 1 1.723V13h2v-1.277c.595-.347 1-.985 1-1.723z"
          />
        </g>
      </svg>
    `;
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
   * Get the appropriate callout CSS class for the landmark role.
   *
   * @returns {string} The callout CSS class based on the landmark role.
   */
  getCalloutClass(hasError = false) {
    if (hasError) {
      return 'callout-danger';
    }
    const roleClassMap = {
      main: 'callout-info',
      banner: 'callout-primary',
      contentinfo: 'callout-secondary',
      navigation: 'callout-success',
      complementary: 'callout-warning',
      region: 'callout-info',
      search: 'callout-primary',
      form: 'callout-info'
    };
    return roleClassMap[this.role] || 'callout-danger';
  }

  /**
   * Creates a role selection dropdown with better organization and error handling.
   *
   * Returns a select dropdown for editable landmarks or a readonly input badge for non-editable ones.
   * Both variants include proper accessibility labels and ARIA attributes.
   *
   * @param {boolean} [disabled=false] - Whether the input should be disabled (readonly).
   * @returns {import('lit').TemplateResult} The role input element with accessibility support.
   */
  createRoleSelect(disabled = false) {
    try {
      const uniqueId = `${this.id}-role`;
      const labelId = `${this.id}-role-label`;

      return html`
        <label id="${labelId}" for="${uniqueId}" class="visually-hidden">
          ${TYPO3.lang[LANDMARK_LABEL_KEYS.COMPONENT.ROLE_LABEL]}
        </label>
        ${disabled ? this._renderRoleInput(uniqueId, labelId) : this._renderRoleSelect(uniqueId, labelId)}
      `;
    } catch (error) {
      console.error(`Error creating role select for landmark ${this.landmarkId}:`, error);
      return this._renderRoleSelectError();
    }
  }

  /**
   * Renders a readonly role input for disabled mode.
   * 
   * @private
   * @param {string} uniqueId - Unique identifier for the input
   * @param {string} labelId - Label identifier for accessibility
   * @returns {import('lit').TemplateResult} The readonly input template
   */
  _renderRoleInput(uniqueId, labelId) {
    return html`
      <input
        id="${uniqueId}"
        type="text"
        class="badge rounded-pill w-auto"
        style="max-width: 12rem;"
        value="${this.getRoleDisplayName(this.role)}"
        readonly
        aria-labelledby="${labelId}"
        ?aria-invalid="${this.hasError}"
      />
    `;
  }

  /**
   * Renders an interactive role selection dropdown.
   * 
   * @private
   * @param {string} uniqueId - Unique identifier for the select
   * @param {string} labelId - Label identifier for accessibility
   * @returns {import('lit').TemplateResult} The select dropdown template
   */
  _renderRoleSelect(uniqueId, labelId) {
    return html`
      <select
        id="${uniqueId}"
        class="form-select form-select-sm w-auto"
        style="max-width: 12rem;"
        @change="${this._handleRoleSelectChange}"
        aria-labelledby="${labelId}"
        ?aria-invalid="${this.hasError}"
      >
        ${this._renderRoleSelectOptions()}
      </select>
    `;
  }

  /**
   * Renders the options for the role selection dropdown.
   * 
   * @private
   * @returns {import('lit').TemplateResult[]} Array of option templates
   */
  _renderRoleSelectOptions() {
    return Object.keys(this.availableRoles).map((value) => {
      const translatedLabel = this._getRoleOptionLabel(value);
      return html`
        <option value="${value}" ?selected="${this.role === value}">
          ${translatedLabel}
        </option>
      `;
    });
  }

  /**
   * Gets the translated label for a role option.
   * 
   * @private
   * @param {string} value - The role value
   * @returns {string} The translated label
   */
  _getRoleOptionLabel(value) {
    const keySuffix = value === '' ? 'none' : value;
    const translationKey = `mindfula11y.features.landmarkStructure.role.${keySuffix}`;
    return TYPO3.lang[translationKey] || this.availableRoles[value] || value;
  }

  /**
   * Handles role selection changes from the dropdown.
   * 
   * @private
   * @param {Event} event - The change event
   */
  _handleRoleSelectChange(event) {
    const newRole = event.target.value;
    this.handleSave(newRole);
  }

  /**
   * Renders an error state for the role selector.
   * 
   * @private
   * @returns {import('lit').TemplateResult} The error template
   */
  _renderRoleSelectError() {
    return html`
      <div class="alert alert-danger alert-sm">
        ${TYPO3.lang[LANDMARK_LABEL_KEYS.ERROR_HANDLING.ROLE_SELECT_ERROR]}
      </div>
    `;
  }

  /**
   * Store the selected role on change with improved error handling.
   *
   * Updates the landmark role in the database via TYPO3's AjaxDataHandler and dispatches
   * a change event to trigger UI updates in parent components.
   *
   * @param {string} role - The new landmark role selected by the user.
   * @returns {void}
   */
  handleSave(role) {
    try {
      if (role === this.role) {
        return; // No change needed
      }

      const params = this._buildSaveParams(role);
      this._processRoleSave(params, role);
    } catch (error) {
      console.error(`Error saving role for landmark ${this.landmarkId}:`, error);
      this._showSaveError();
    }
  }

  /**
   * Builds the parameters for saving the role.
   * 
   * @private
   * @param {string} role - The new role value
   * @returns {Object} The parameters object for AjaxDataHandler
   */
  _buildSaveParams(role) {
    return {
      data: {
        [this.recordTableName]: {
          [this.recordUid]: {
            [this.recordColumnName]: role,
          },
        },
      },
    };
  }

  /**
   * Processes the role save operation.
   * 
   * @private
   * @param {Object} params - The save parameters
   * @param {string} role - The new role value
   */
  _processRoleSave(params, role) {
    AjaxDataHandler.process(params)
      .then(() => {
        this._handleSaveSuccess(role);
      })
      .catch((error) => {
        console.error('AjaxDataHandler save failed:', error);
        this._handleSaveError();
      });
  }

  /**
   * Handles successful role save.
   * 
   * @private
   * @param {string} role - The saved role value
   */
  _handleSaveSuccess(role) {
    this.role = role;
    this.dispatchEvent(
      new CustomEvent("mindfula11y-landmark-changed", {
        bubbles: true,
        composed: true,
        detail: {
          landmarkId: this.landmarkId,
          newRole: role,
          recordUid: this.recordUid
        }
      })
    );
  }

  /**
   * Handles save errors.
   * 
   * @private
   */
  _handleSaveError() {
    Notification.error(
      TYPO3.lang[LANDMARK_LABEL_KEYS.ERROR_HANDLING.STORE_FAILED],
      TYPO3.lang[LANDMARK_LABEL_KEYS.ERROR_HANDLING.STORE_FAILED_DESC]
    );
  }

  /**
   * Shows a general save error message.
   * 
   * @private
   */
  _showSaveError() {
    const errorMessage = TYPO3.lang[LANDMARK_LABEL_KEYS.ERROR_HANDLING.STORE_FAILED];
    console.error(errorMessage);
  }

  /**
   * Get the display name for a landmark role with improved organization.
   *
   * Provides human-readable labels for landmark roles, primarily used for readonly inputs.
   * Uses constants and improved fallback handling for consistent display.
   *
   * @param {string} role - The landmark role value.
   * @returns {string} The human-readable display name for the role.
   */
  getRoleDisplayName(role) {
    if (role === '') {
      return TYPO3.lang[LANDMARK_LABEL_KEYS.COMPONENT.ROLE_NONE] || '';
    }
    const translationKey = `mindfula11y.features.landmarkStructure.role.${role}`;
    return TYPO3.lang[translationKey] || role;
  }
}

customElements.define("mindfula11y-landmark-box", LandmarkBox);

export default LandmarkBox;
