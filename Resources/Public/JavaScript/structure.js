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
 * @file structure.js
 * @description Web component for displaying combined heading and landmark structures.
 * @typedef {import('./types.js').StructureError} StructureError
 */
import { html, css, LitElement } from "lit";
import { Task } from "@lit/task";
import HeadingStructureService from "./heading-structure-service.js";
import LandmarkStructureService from "./landmark-structure-service.js";
import ContentFetcher from "./content-fetcher.js";
import { ErrorRegistry } from "./error-registry.js";
import ErrorList from "./error-list.js";
import HeadingStructure from "./heading-structure.js";
import LandmarkStructure from "./landmark-structure.js";
import Notification from "@typo3/backend/notification.js";

/**
 * Web component for displaying combined heading and landmark structures.
 *
 * This component analyzes HTML content for both heading and landmark accessibility issues,
 * displays them in separate tabs with error count badges, and provides detailed views
 * for each structure type.
 *
 * Key features:
 * - Combined error list above tabs
 * - Tabbed interface with Bootstrap styling
 * - Error count badges in tab titles
 * - Separate heading and landmark structure analysis
 * - Integration with TYPO3 backend notification system
 *
 * Error types detected:
 * - Heading structure errors (missing H1, multiple H1, empty headings, skipped levels)
 * - Landmark structure errors (missing main, multiple main, duplicate labels, unlabeled groups)
 *
 * @class Structure
 * @extends LitElement
 */
export class Structure extends LitElement {
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
      previewUrl: { type: String },
      hasHeadingStructureAccess: { type: Boolean },
      hasLandmarkStructureAccess: { type: Boolean },
      headingLevel: { type: Number },
      _currentTab: { type: String, state: true },
      _headingTree: { type: Array, state: true },
      _headingErrors: { type: Array, state: true },
      _landmarkData: { type: Array, state: true },
      _landmarkErrors: { type: Array, state: true },
      _firstRun: { type: Boolean, state: true },
    };
  }

  /**
   * Creates an instance of Structure.
   */
  constructor() {
    super();
    this.previewUrl = "";
    this.hasHeadingStructureAccess = false;
    this.hasLandmarkStructureAccess = false;
    this.headingLevel = 2; // Default heading level for titles
    this._currentTab = this._getDefaultActiveTab();
    this._headingTree = [];
    this._headingErrors = [];
    this._landmarkData = [];
    this._landmarkErrors = [];
    this._firstRun = true;

    this.headingService = new HeadingStructureService();
    this.landmarkService = new LandmarkStructureService();

    this.loadContentTask = new Task(
      this,
      this._analyzeContent.bind(this),
      () => [this.previewUrl],
      { autoRun: false }
    );
  }

  /**
   * Gets the default active tab based on enabled structures.
   *
   * @private
   * @returns {string} The default active tab
   */
  _getDefaultActiveTab() {
    if (this.hasHeadingStructureAccess) {
      return "headings";
    } else if (this.hasLandmarkStructureAccess) {
      return "landmarks";
    }
    return "headings"; // fallback
  }

  /**
   * Handles loading errors with user notification.
   *
   * @private
   * @param {Error} error - The error that occurred during loading
   */
  _handleLoadingError(error) {
    console.error("Accessibility structure could not be loaded.");

    Notification.error(
      TYPO3.lang["mindfula11y.accessibility.error.loading"],
      TYPO3.lang["mindfula11y.accessibility.error.loading.description"]
    );
  }

  /**
   * Handles property updates and adjusts active tab if necessary.
   *
   * @param {Map} changedProperties - Map of changed properties
   */
  updated(changedProperties) {
    super.updated(changedProperties);

    if (
      changedProperties.has("hasHeadingStructureAccess") ||
      changedProperties.has("hasLandmarkStructureAccess")
    ) {
      const newDefaultTab = this._getDefaultActiveTab();
      if (!this._isTabEnabled(this._currentTab)) {
        this._currentTab = newDefaultTab;
      }
    }

    // Reload content when previewUrl changes
    if (changedProperties.has("previewUrl") && this.previewUrl) {
      this.loadContentTask.run();
    }
  }

  /**
   * Checks if a tab is currently enabled.
   *
   * @private
   * @param {string} tabName - The tab name to check
   * @returns {boolean} Whether the tab is enabled
   */
  _isTabEnabled(tabName) {
    if (tabName === "headings") {
      return this.hasHeadingStructureAccess;
    } else if (tabName === "landmarks") {
      return this.hasLandmarkStructureAccess;
    }
    return false;
  }

  /**
   * Handles tab change events.
   *
   * @private
   * @param {string} tabName - The name of the tab to activate
   */
  _handleTabChange(tabName) {
    this._currentTab = tabName;
    this.requestUpdate();
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
      const parser = new DOMParser();
      const doc = parser.parseFromString(previewHtml, "text/html");
      const headings = this.hasHeadingStructureAccess
        ? this.headingService.selectElements(doc)
        : [];
      const landmarkElements = this.hasLandmarkStructureAccess
        ? this.landmarkService.selectElements(doc)
        : [];
      
      return {
        headings,
        landmarkElements,
      };
    } catch (error) {
      this._handleLoadingError(error);
      return null;
    }
  }

  /**
   * Renders the structure errors component with combined error list and tabbed interface.
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
          if (this._firstRun) {
            this._firstRun = false;
          }

          // Clear all previous errors
          ErrorRegistry.clearAll();

          // Analyze headings if enabled and present
          if (this.hasHeadingStructureAccess && elements?.headings?.length) {
            this.headingService.detectAllHeadingErrors(elements.headings);
            this._headingErrors = ErrorRegistry.getAllAggregatedErrors().filter(
              (error) => error.tag === "headings"
            );
            this._headingTree = this.headingService._buildHeadingTree(
              elements.headings
            );
          } else {
            this._headingErrors = [];
            this._headingTree = [];
          }

          // Analyze landmarks if enabled and present
          if (
            this.hasLandmarkStructureAccess &&
            elements?.landmarkElements?.length
          ) {
            this._landmarkData = this.landmarkService.buildLandmarkList(
              elements.landmarkElements
            );
            this.landmarkService.detectAllLandmarkErrors(this._landmarkData);
            this._landmarkErrors =
              ErrorRegistry.getAllAggregatedErrors().filter(
                (error) => error.tag === "landmarks"
              );
          } else {
            this._landmarkErrors = [];
            this._landmarkData = [];
          }

          // Force re-render to update badges
          this.requestUpdate();

          // Combine all errors for the top error list
          const allErrors = [...this._headingErrors, ...this._landmarkErrors];

          return html`
            ${allErrors.length > 0
              ? this._renderHeading(
                  TYPO3.lang["mindfula11y.structureErrors"],
                  "mt-0"
                )
              : ""}
            ${allErrors.length > 0
              ? html`<mindfula11y-error-list
                  .errors="${allErrors}"
                  .firstRun="${this._firstRun}"
                ></mindfula11y-error-list>`
              : ""}
            ${this._renderHeading(TYPO3.lang["mindfula11y.structure"])}
            ${this._renderTabs(
              this._headingErrors.reduce((sum, error) => sum + error.count, 0),
              this._landmarkErrors.reduce((sum, error) => sum + error.count, 0)
            )}
            ${this._renderTabContent()}
          `;
        },
      })}
    `;
  }

  /**
   * Renders a single heading dynamically based on headingLevel.
   *
   * @private
   * @param {string} content - The text content of the heading
   * @param {string} className - The CSS class for the heading
   * @returns {import('lit').TemplateResult} The rendered heading
   */
  _renderHeading(content, className = "") {
    switch (this.headingLevel) {
      case 1:
        return html`<h1 class="${className}">${content}</h1>`;
      case 2:
        return html`<h2 class="${className}">${content}</h2>`;
      case 3:
        return html`<h3 class="${className}">${content}</h3>`;
      case 4:
        return html`<h4 class="${className}">${content}</h4>`;
      case 5:
        return html`<h5 class="${className}">${content}</h5>`;
      case 6:
        return html`<h6 class="${className}">${content}</h6>`;
      default:
        return html`<h2 class="${className}">${content}</h2>`;
    }
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
   * Handles structure change events by clearing cache and reloading.
   *
   * @private
   * @param {CustomEvent} event - The structure change event
   */
  _handleStructureChanged(event) {
    // Clear the cache for the current preview URL to ensure fresh content
    if (this.previewUrl) {
      ContentFetcher.clearCache(this.previewUrl);
    }
    // Reload the content
    this.loadContentTask.run();
  }

  /**
   * Renders the Bootstrap tab navigation with error count badges.
   *
   * @private
   * @param {number} headingErrorCount - Number of heading errors
   * @param {number} landmarkErrorCount - Number of landmark errors
   * @returns {import('lit').TemplateResult} The rendered tab navigation
   */
  _renderTabs(headingErrorCount, landmarkErrorCount) {
    return html`
      <ul class="nav nav-tabs" id="mindfula11y-structure-tabs" role="tablist">
        ${this.hasHeadingStructureAccess
          ? html`
              <li class="nav-item">
                <button
                  class="nav-link ${this._currentTab === "headings"
                    ? "active"
                    : ""}"
                  id="mindfula11y-headings-tab"
                  data-bs-toggle="tab"
                  data-bs-target="#mindfula11y-headings"
                  type="button"
                  role="tab"
                  aria-controls="mindfula11y-headings"
                  aria-selected="${this._currentTab === "headings"}"
                  @click="${() => this._handleTabChange("headings")}"
                >
                  ${TYPO3.lang["mindfula11y.headingStructure"] || "Headings"}
                  ${headingErrorCount > 0
                    ? html`<span class="badge badge-danger ms-2"
                        >${headingErrorCount}</span
                      >`
                    : ""}
                </button>
              </li>
            `
          : ""}
        ${this.hasLandmarkStructureAccess
          ? html`
              <li class="nav-item">
                <button
                  class="nav-link ${this._currentTab === "landmarks"
                    ? "active"
                    : ""}"
                  id="mindfula11y-landmarks-tab"
                  data-bs-toggle="tab"
                  data-bs-target="#mindfula11y-landmarks"
                  type="button"
                  role="tab"
                  aria-controls="mindfula11y-landmarks"
                  aria-selected="${this._currentTab === "landmarks"}"
                  @click="${() => this._handleTabChange("landmarks")}"
                >
                  ${TYPO3.lang["mindfula11y.landmarkStructure"] || "Landmarks"}
                  ${landmarkErrorCount > 0
                    ? html`<span class="badge badge-danger ms-2"
                        >${landmarkErrorCount}</span
                      >`
                    : ""}
                </button>
              </li>
            `
          : ""}
      </ul>
    `;
  }

  /**
   * Renders the tab content with heading and landmark structure components.
   *
   * @private
   * @returns {import('lit').TemplateResult} The rendered tab content
   */
  _renderTabContent() {
    return html`
      <div
        class="tab-content mt-3"
        id="mindfula11y-structure-tab-content"
        @mindfula11y-heading-type-changed="${this._handleStructureChanged}"
        @mindfula11y-landmark-changed="${this._handleStructureChanged}"
      >
        ${this.hasHeadingStructureAccess
          ? html`
              <div
                class="tab-pane fade ${this._currentTab === "headings"
                  ? "show active"
                  : ""}"
                id="mindfula11y-headings"
                role="tabpanel"
                aria-labelledby="mindfula11y-headings-tab"
              >
                <mindfula11y-heading-structure
                  .headingTree="${this._headingTree}"
                  .errors="${this._headingErrors}"
                ></mindfula11y-heading-structure>
              </div>
            `
          : ""}
        ${this.hasLandmarkStructureAccess
          ? html`
              <div
                class="tab-pane fade ${this._currentTab === "landmarks"
                  ? "show active"
                  : ""}"
                id="mindfula11y-landmarks"
                role="tabpanel"
                aria-labelledby="mindfula11y-landmarks-tab"
              >
                <mindfula11y-landmark-structure
                  .landmarkData="${this._landmarkData}"
                  .errors="${this._landmarkErrors}"
                ></mindfula11y-landmark-structure>
              </div>
            `
          : ""}
      </div>
    `;
  }
}

customElements.define("mindfula11y-structure", Structure);

export default Structure;
