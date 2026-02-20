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
 * @file landmark-structure.js
 * @description Web component for visualizing and editing the landmark structure of an HTML document in TYPO3.
 * @typedef {import('./types.js').LandmarkData} LandmarkData
 * @typedef {import('./types.js').StructureError} StructureError
 */
import { html, LitElement } from "lit";
import LandmarkBox from "./landmark-box.js";
import { ErrorRegistry } from "./error-registry.js";

/**
 * Web component for visualizing and editing the landmark structure of an HTML document in TYPO3.
 *
 * This component renders the landmark structure in a hierarchical structure
 * and validates their accessibility compliance. It provides error reporting for
 * missing, duplicate, or improperly labeled landmarks.
 *
 * Key features:
 * - Hierarchical landmark structure visualization
 * - Real-time accessibility error detection and reporting
 * - Landmark role identification and labeling
 * - Bootstrap-styled error alerts and status indicators
 * - Integration with TYPO3 backend notification system
 * - Support for both ARIA landmarks and semantic HTML elements
 *
 * Error types detected:
 * - Missing main landmark (error)
 * - Multiple main landmarks (error)
 * - Duplicate landmark labels (warning)
 * - Unlabeled landmark groups (warning)
 *
 * Supported landmark roles:
 * - banner, main, navigation, complementary, contentinfo
 * - search, region, form (with accessible names)
 *
 * @class LandmarkStructure
 * @extends LitElement
 */
export class LandmarkStructure extends LitElement {
  /**
   * Component properties definition.
   *
   * @returns {Object} The properties definition object for LitElement.
   */
  static get properties() {
    return {
      landmarkData: { type: Array },
      errors: { type: Array },
    };
  }

  /**
   * Creates an instance of LandmarkStructure.
   */
  constructor() {
    super();
    this.landmarkData = [];
    this.errors = [];
  }

  /**
   * Renders the landmark structure component.
   *
   * @returns {import('lit').TemplateResult} The rendered template for the component.
   */
  render() {
    return html`
      ${this._renderLandmarkContent(this.landmarkData)}
    `;
  }

  /**
   * Renders the main landmark content or no-landmarks message.
   *
   * @private
   * @param {Array<LandmarkData>} landmarkData - The landmark data to render
   * @returns {import('lit').TemplateResult} The rendered landmark content
   */
  _renderLandmarkContent(landmarkData) {
    if (landmarkData.length === 0) {
      return html`
        <div class="callout callout-info">
          <div class="callout-icon">
            <span class="icon-emphasized">
              <typo3-backend-icon
                identifier="status-dialog-information"
                size="small"
              ></typo3-backend-icon>
            </span>
          </div>
          <div class="callout-content">
            <div class="callout-title">
              ${TYPO3.lang["mindfula11y.structure.landmarks.noLandmarks"]}
            </div>
            <div class="callout-body">
              ${TYPO3.lang[
                "mindfula11y.structure.landmarks.noLandmarks.description"
              ]}
            </div>
          </div>
        </div>
      `;
    }

    return html`
      <div class="d-flex flex-column gap-3 mt-3">
        ${landmarkData.map((landmark) => this.renderLandmarkBox(landmark))}
      </div>
    `;
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
   * Creates a unique ID for a landmark element.
   *
   * Uses the landmark role as a prefix and the record UID if available,
   * falling back to a random string for non-editable landmarks.
   *
   * @param {LandmarkData} landmarkData - The landmark data to create an ID for.
   * @returns {string} A unique ID for the landmark.
   */
  createLandmarkId(landmarkData) {
    const rolePrefix = landmarkData.role || "landmark";
    const uid =
      landmarkData.element.dataset.mindfula11yRecordUid ||
      Math.random().toString(36).substr(2, 9);
    return `mindfula11y-landmark-${rolePrefix}-${uid}`;
  }

  /**
   * Renders a single landmark box component with its nested children.
   *
   * @param {LandmarkData} landmarkData - The landmark data to render.
   * @returns {import('lit').TemplateResult} The rendered landmark box with nested children.
   */
  renderLandmarkBox(landmarkData) {
    const hasChildren = landmarkData.children?.length > 0;
    const errors = ErrorRegistry.getErrors(landmarkData.element);
    const errorMessages = errors && errors.length > 0 ? errors.map((error) => {
      return {
        message: TYPO3.lang[error.id] || error.id,
        severity: error.severity,
        count: error.count,
      };
    }) : [];
    const landmarkBoxProps = this._buildLandmarkBoxProps(
      landmarkData,
      errorMessages
    );

    if (!hasChildren) {
      return this._renderSimpleLandmark(landmarkBoxProps);
    }

    return this._renderLandmarkWithChildren(landmarkData, landmarkBoxProps);
  }

  /**
   * Builds properties object for landmark box component.
   *
   * @private
   * @param {LandmarkData} landmarkData - The landmark data
   * @param {Array<string>} errorMessages - Array of error messages
   * @returns {Object} Properties object for landmark box
   */
  _buildLandmarkBoxProps(landmarkData, errorMessages) {
    const { element, role, label, isEditable } = landmarkData;

    return {
      role,
      label,
      errorMessages,
      children: [],
      availableRoles: isEditable
        ? JSON.parse(element.dataset.mindfula11yAvailableRoles || "{}")
        : {},
      recordTableName: element.dataset.mindfula11yRecordTableName || "",
      recordColumnName: element.dataset.mindfula11yRecordColumnName || "",
      recordUid: element.dataset.mindfula11yRecordUid || "",
      recordEditLink: element.dataset.mindfula11yRecordEditLink || "",
      landmarkId: this.createLandmarkId(landmarkData),
    };
  }

  /**
   * Renders a simple landmark without children.
   *
   * @private
   * @param {Object} props - Landmark box properties
   * @returns {import('lit').TemplateResult} The rendered simple landmark
   */
  _renderSimpleLandmark(props) {
    return html`
      <mindfula11y-landmark-box
        .role="${props.role}"
        .label="${props.label}"
        .errorMessages=${props.errorMessages}
        .children="${props.children}"
        .availableRoles="${props.availableRoles}"
        recordTableName="${props.recordTableName}"
        recordColumnName="${props.recordColumnName}"
        recordUid="${props.recordUid}"
        recordEditLink="${props.recordEditLink}"
        landmarkId="${props.landmarkId}"
      ></mindfula11y-landmark-box>
    `;
  }

  /**
   * Renders a landmark with nested children.
   *
   * @private
   * @param {LandmarkData} landmarkData - The landmark data
   * @param {Object} props - Landmark box properties
   * @returns {import('lit').TemplateResult} The rendered landmark with children
   */
  _renderLandmarkWithChildren(landmarkData, props) {
    const nestedChildren = landmarkData.children.map((child) =>
      this.renderLandmarkBox(child)
    );

    return html`
      <section class="mb-4">
        ${this._renderSimpleLandmark(props)}
        <div class="ms-4 mt-3">
          <div class="fw-bold text-muted text-uppercase fs-7 mb-2">
            ${TYPO3.lang["mindfula11y.structure.landmarks.nested"]}
          </div>
          <div class="d-flex flex-column gap-3">${nestedChildren}</div>
        </div>
      </section>
    `;
  }

}

// Register the custom element
customElements.define("mindfula11y-landmark-structure", LandmarkStructure);

export default LandmarkStructure;
