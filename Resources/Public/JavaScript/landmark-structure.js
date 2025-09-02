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
import { html, css } from "lit";
import LandmarkBox from "./landmark-box.js";
import AccessibilityStructureBase from "./accessibility-structure-base.js";
import LandmarkStructureService from "./landmark-structure-service.js";
import ContentFetcher from "./content-fetcher.js";
import { ErrorRegistry } from "./error-registry.js";

/**
 * Web component for visualizing and editing the landmark structure of an HTML document in TYPO3.
 *
 * This component analyzes HTML content for ARIA landmarks and semantic HTML elements,
 * displays them in a hierarchical structure, and validates their accessibility compliance.
 * It provides error reporting for missing, duplicate, or improperly labeled landmarks.
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
 * @extends AccessibilityStructureBase
 */
export class LandmarkStructure extends AccessibilityStructureBase {
  /**
   * CSS styles for the component.
   *
   * @returns {import('lit').CSSResult} The CSSResult for the component styles.
   */
  static get styles() {
    return css`
      .mindfula11y-landmark-structure__errors + .mindfula11y-landmark-boxes {
        margin-block-start: 1.5rem;
      }
    `;
  }

  /**
   * Creates an instance of LandmarkStructure.
   *
   * Inherits the task system from AccessibilityStructureBase for loading and analyzing landmarks.
   */
  constructor() {
    super(); // This initializes the base class task system
    this.structureService = new LandmarkStructureService();
  }

  /**
   * Analyzes content from the preview URL using the landmark structure service.
   *
   * @private
   * @param {Array} args - Task arguments containing [previewUrl]
   * @returns {Promise<Array<HTMLElement>|null>} The elements found or null on error
   */
  async _analyzeContent([previewUrl]) {
    try {
      const previewHtml = await ContentFetcher.fetchContent(previewUrl);
      return this.structureService.selectElements(previewHtml);
    } catch (error) {
      this._handleLoadingError(error);
      return null;
    }
  }

  /**
   * Renders the landmark structure component, including errors and the landmark boxes.
   *
   * @returns {import('lit').TemplateResult} The rendered template for the component.
   */
  render() {
    return html`
      <style>
        ${this.constructor.styles}
      </style>
      ${this.loadContentTask.render({
        complete: (landmarkElements) => {
          if (this.firstRun) {
            this.firstRun = false;
          }
          const landmarkData = this.structureService.buildLandmarkList(landmarkElements || []);

          // Run error checking on the landmark data to attach error reasons
          const errors = this.structureService.buildErrorList(landmarkData);

          return html`
            ${this._renderErrors(errors)}
            ${this._renderLandmarkContent(landmarkData)}
          `;
        },
      })}
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
        <div class="alert alert-info">
          <strong
            >${TYPO3.lang["mindfula11y.features.landmarkStructure.noLandmarks.title"]}</strong
          >
          <p>${TYPO3.lang["mindfula11y.features.landmarkStructure.noLandmarks.description"]}</p>
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
   * Handles landmark change events by clearing the cache and reloading content.
   *
   * @private
   * @param {CustomEvent} event - The landmark change event
   */
  _handleLandmarkChange(event) {
    // Clear the cache for the current preview URL to ensure fresh content
    if (this.previewUrl) {
      ContentFetcher.clearCache(this.previewUrl);
    }
    // Run the task to reload content
    this.loadContentTask.run();
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
   * Fetches the preview content from the server.
   *
   * Sends an AJAX request to the server to fetch the preview content.
   * The response is expected to be HTML content for landmark analysis.
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
        @mindfula11y-landmark-changed="${this._handleLandmarkChange}"
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
            ${TYPO3.lang["mindfula11y.features.landmarkStructure.nestedLandmarks"]}
          </div>
          <div class="d-flex flex-column gap-3">${nestedChildren}</div>
        </div>
      </section>
    `;
  }

  /**
   * Handles landmark change events by clearing the cache and reloading content.
   *
   * @private
   * @param {CustomEvent} event - The landmark change event
   */
  _handleLandmarkChange(event) {
    // Clear the cache for the current preview URL to ensure fresh content
    if (this.previewUrl) {
      ContentFetcher.clearCache(this.previewUrl);
    }
    // Run the task to reload content
    this.loadContentTask.run();
  }
}

// Register the custom element
customElements.define("mindfula11y-landmark-structure", LandmarkStructure);

export default LandmarkStructure;
