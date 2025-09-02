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
 * @file structure-errors.js
 * @description Web component for displaying combined accessibility errors from heading and landmark structures.
 * @typedef {import('./types.js').StructureError} StructureError
 */
import { html, css } from "lit";
import AccessibilityStructureBase from "./accessibility-structure-base.js";
import HeadingStructureService from "./heading-structure-service.js";
import LandmarkStructureService from "./landmark-structure-service.js";
import ContentFetcher from "./content-fetcher.js";
import { ErrorRegistry } from "./error-registry.js";
import ErrorList from "./error-list.js";

/**
 * Web component for displaying combined accessibility errors from heading and landmark structures.
 *
 * This component analyzes HTML content for both heading and landmark accessibility issues,
 * displays them in a unified error list, and provides navigation links to detailed views.
 *
 * Key features:
 * - Combined error detection from headings and landmarks
 * - Unified error list display with severity levels
 * - Navigation links to detailed structure views
 * - Bootstrap-styled error alerts and status indicators
 * - Integration with TYPO3 backend notification system
 *
 * Error types detected:
 * - Heading structure errors (missing H1, multiple H1, empty headings, skipped levels)
 * - Landmark structure errors (missing main, multiple main, duplicate labels, unlabeled groups)
 *
 * @class StructureErrors
 * @extends AccessibilityStructureBase
 */
export class StructureErrors extends AccessibilityStructureBase {

  /**
   * CSS styles for the component.
   *
   * @returns {import('lit').CSSResult} The CSSResult for the component styles.
   */
  static get styles() {
    return css``;
  }

  /**
   * Component properties definition.
   *
   * @returns {Object} The properties definition object for LitElement.
   */
  static get properties() {
    return {
      ...super.properties,
      headingStructureUrl: { type: String },
      landmarkStructureUrl: { type: String },
      enableHeadingStructure: { type: Boolean },
      enableLandmarkStructure: { type: Boolean },
    };
  }

  /**
   * Creates an instance of StructureErrors.
   *
   * Inherits the task system from AccessibilityStructureBase for loading and analyzing content.
   */
  constructor() {
    super();
    this.headingService = new HeadingStructureService();
    this.landmarkService = new LandmarkStructureService();
    this.headingStructureUrl = "";
    this.landmarkStructureUrl = "";
    this.enableHeadingStructure = false;
    this.enableLandmarkStructure = false;
  }

  /**
   * Analyzes content from the preview URL using the structure services.
   *
   * @private
   * @param {Array} args - Task arguments containing [previewUrl]
   * @returns {Promise<Object>|null} The elements found or null on error
   */
  async _analyzeContent([previewUrl]) {
    try {
      const previewHtml = await ContentFetcher.fetchContent(previewUrl);
      const headings = this.enableHeadingStructure ? this.headingService.selectElements(previewHtml) : [];
      const landmarkElements = this.enableLandmarkStructure ? this.landmarkService.selectElements(previewHtml) : [];

      return {
        headings,
        landmarkElements
      };
    } catch (error) {
      this._handleLoadingError(error);
      return null;
    }
  }

  /**
   * Renders the structure errors component, including errors and navigation links.
   *
   * @returns {import('lit').TemplateResult} The rendered template for the component.
   */
  render() {
    return html`
      <style>
        ${this.constructor.styles}
      </style>
      ${this.loadContentTask.render({
        complete: (elements) => {
          if (this.firstRun) {
            this.firstRun = false;
          }

          // Clear all previous errors
          ErrorRegistry.clearAll();

          // Analyze headings if enabled and present
          if (this.enableHeadingStructure && elements?.headings?.length) {
            this.headingService.detectAllHeadingErrors(elements.headings);
          }

          // Analyze landmarks if enabled and present
          if (this.enableLandmarkStructure && elements?.landmarkElements?.length) {
            const landmarkData = this.landmarkService.buildLandmarkList(elements.landmarkElements);
            this.landmarkService.detectAllLandmarkErrors(landmarkData);
          }

          // Get all aggregated errors
          const errors = ErrorRegistry.getAllAggregatedErrors();

          return html`
            ${this._renderNavigationLinks()}
            <mindfula11y-error-list .errors="${errors}" .firstRun="${this.firstRun}"></mindfula11y-error-list>
          `;
        },
      })}
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
   * Renders navigation links to detailed structure views.
   *
   * @private
   * @returns {import('lit').TemplateResult} The rendered navigation links
   */
  _renderNavigationLinks() {
    if ((!this.enableHeadingStructure || !this.headingStructureUrl) && (!this.enableLandmarkStructure || !this.landmarkStructureUrl)) {
      return html``;
    }

    return html`
      <div class="mb-3">
        ${this.enableHeadingStructure && this.headingStructureUrl ? html`
          <a href="${this.headingStructureUrl}" class="btn btn-default btn-sm">
            ${TYPO3.lang["mindfula11y.features.structureErrors.viewHeadingStructure"]}
          </a>
        ` : ''}
        ${this.enableLandmarkStructure && this.landmarkStructureUrl ? html`
          <a href="${this.landmarkStructureUrl}" class="btn btn-default btn-sm">
            ${TYPO3.lang["mindfula11y.features.structureErrors.viewLandmarkStructure"]}
          </a>
        ` : ''}
      </div>
    `;
  }
}

customElements.define("mindfula11y-structure-errors", StructureErrors);

export default StructureErrors;
