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
 * @file scan-issue-count.js
 * @description Web component for displaying the count of accessibility scan issues.
 * @typedef {import('./types.js').CreateScanDemand} CreateScanDemand
 */
import { html } from "lit";
import "@typo3/backend/element/icon-element.js";
import { ScanBase } from "./scan-base.js";
import { ScanStatus } from "./types.js";

/**
 * Web component for displaying the count of accessibility scan issues.
 *
 * @class ScanIssueCount
 * @extends ScanBase
 */
export class ScanIssueCount extends ScanBase {
  /**
   * Component properties definition.
   * Merges with base properties and adds scanUri.
   *
   * @returns {Object} The properties definition object for LitElement.
   */
  static get properties() {
    return {
      ...super.properties,
      scanUri: { type: String },
    };
  }

  /**
   * Creates an instance of ScanIssueCount.
   */
  constructor() {
    super();
    this.scanUri = "";
    // Note: scanService is initialized in ScanBase
  }

  /**
   * Renders the component template.
   *
   * @returns {import('lit').TemplateResult|null}
   */
  render() {
    if (!this.scanId && !this.createScanDemand) {
      return null;
    }

    const config = this._getStatusConfiguration();
    if (!config) return null;

    return html`
      <div
        aria-live="${this._shouldAnnounce ? "polite" : "off"}"
        aria-atomic="true"
      >
        ${this._renderStatusDisplay(config)}
      </div>
    `;
  }

  /**
   * Determines the current status configuration including view mode and text.
   *
   * @protected
   * @returns {Object|null} Configuration object
   */
  _getStatusConfiguration() {
    switch (this._getViewState()) {
      case ScanStatus.LOADING:
        let text = TYPO3.lang["mindfula11y.scan.loading"];
        if (!this._isFetching) {
          if (this._status === "pending") {
            text = TYPO3.lang["mindfula11y.scan.status.pending"];
          } else if (this._status === "running") {
            text = TYPO3.lang["mindfula11y.scan.status.running"];
          }
        }
        return {
          view: "info",
          text: text,
          showSpinner: true,
        };

      case ScanStatus.FAILED:
        return {
          view: "danger",
          text:
            this._errorMessage || TYPO3.lang["mindfula11y.scan.error.loading"],
        };

      case ScanStatus.ISSUES:
        return {
          view: "warning",
          text: TYPO3.lang["mindfula11y.scan.issuesFound"].replace(
            "%d",
            this._totalIssueCount
          ),
        };

      case ScanStatus.SUCCESS:
        return {
          view: "success",
          text: TYPO3.lang["mindfula11y.scan.noIssues"],
        };
    }

    return null;
  }

  /**
   * Renders the status display element.
   *
   * @protected
   * @param {Object} config
   * @returns {import('lit').TemplateResult}
   */
  _renderStatusDisplay(config) {
    const detailsLink = this.scanUri
      ? html`
          <a href="${this.scanUri}" class="btn btn-sm btn-default ms-2">
            ${TYPO3.lang["mindfula11y.general.viewDetails"]}
          </a>
        `
      : "";

    let icon = "";
    if (config.showSpinner) {
      icon = html`<span
        class="spinner-border spinner-border-sm"
        aria-hidden="true"
      ></span>`;
    } else if (config.view === "success") {
      icon = html`<typo3-backend-icon
        identifier="status-dialog-ok"
        size="small"
      ></typo3-backend-icon>`;
    } else if (config.view === "warning") {
      icon = html`<typo3-backend-icon
        identifier="status-dialog-warning"
        size="small"
      ></typo3-backend-icon>`;
    } else if (config.view === "danger") {
      icon = html`<typo3-backend-icon
        identifier="status-dialog-error"
        size="small"
      ></typo3-backend-icon>`;
    } else if (config.view === "info") {
      icon = html`<typo3-backend-icon
        identifier="status-dialog-information"
        size="small"
      ></typo3-backend-icon>`;
    }

    return html`
      <div class="callout callout-${config.view}">
        <div class="callout-icon">
          <span class="icon-emphasized">
            ${icon}
          </span>
        </div>
        <div class="callout-content">
          <div class="callout-title mb-0">${config.text} ${detailsLink}</div>
        </div>
      </div>
    `;
  }

  /**
   * Disables Shadow DOM.
   *
   * @returns {HTMLElement}
   */
  createRenderRoot() {
    return this;
  }
}

customElements.define("mindfula11y-scan-issue-count", ScanIssueCount);

export default ScanIssueCount;
