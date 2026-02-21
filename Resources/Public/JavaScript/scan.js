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
 * @file scan.js
 * @description Web component for displaying accessibility scan results and managing scans.
 * @typedef {import('./types.js').CreateScanDemand} CreateScanDemand
 */
import { html } from "lit";
import "@typo3/backend/element/icon-element.js";
import { ScanBase } from "./scan-base.js";
import { ScanStatus } from "./types.js";
import "./violation.js";

/**
 * Web component for displaying accessibility scan results and managing scans.
 *
 * @class Scan
 * @extends ScanBase
 */
export class Scan extends ScanBase {
  /**
   * Component properties definition.
   * Merges with base properties.
   *
   * @returns {Object} The properties definition object for LitElement.
   */
  static get properties() {
    return {
      ...super.properties,
    };
  }

  /**
   * Handles the manual scan trigger.
   * Checks if busy and initiates scan creation.
   *
   * @returns {Promise<void>}
   */
  async handleScan() {
    if (this._isBusy || !this.createScanDemand) {
      return;
    }
    this._shouldAnnounce = true;
    await this._createScan();
  }

  /**
   * Renders the component template.
   *
   * @returns {import('lit').TemplateResult}
   */
  render() {
    return html`
      ${this.createScanDemand
        ? html`
            <div class="mb-4">
              <button
                class="btn btn-default"
                type="button"
                @click="${this.handleScan}"
                ?disabled="${this._isBusy}"
              >
                ${this._isBusy
                  ? html`
                      <span
                        class="spinner-border spinner-border-sm me-2"
                        aria-hidden="true"
                      ></span>
                      ${TYPO3.lang["mindfula11y.scan.processing"]}
                    `
                  : html`
                      <typo3-backend-icon
                        identifier="${this._scanId
                          ? "actions-refresh"
                          : "actions-search"}"
                        size="small"
                        class="me-2"
                        aria-hidden="true"
                      ></typo3-backend-icon>
                      ${this._scanId
                        ? TYPO3.lang["mindfula11y.scan.refresh"]
                        : TYPO3.lang["mindfula11y.scan.start"]}
                    `}
              </button>
            </div>
          `
        : null}

      ${this._renderContent()}
    `;
  }

  /**
   * Renders the content based on current state.
   *
   * @protected
   * @returns {import('lit').TemplateResult|null}
   */
  _renderContent() {
    const config = this._getStatusConfiguration();
    if (!config) return null;

    return html`
      <div
        class="mb-3"
        aria-live="${this._shouldAnnounce ? "polite" : "off"}"
        aria-atomic="true"
      >
        ${this._renderStatusDisplay(config)}
      </div>
      ${this._renderViolationsList(config)}
    `;
  }

  /**
   * Determines the current status configuration including view mode and texts.
   * Shared logic for both visual and screen reader outputs.
   *
   * @protected
   * @returns {Object|null} Configuration object with view, text, and other properties
   */
  _getStatusConfiguration() {
    // Specific override: Scan component only shows loading in main area if scanId exists
    // (otherwise it might be initial state managed by the button)
    if (this._getViewState() === ScanStatus.LOADING && this._scanId) {
      return {
        view: "loading",
        text: TYPO3.lang["mindfula11y.scan.loading"],
      };
    }

    switch (this._getViewState()) {
      case ScanStatus.FAILED:
        const title =
          this._errorMessage || TYPO3.lang["mindfula11y.scan.status.failed"];
        const description = !this._errorMessage
          ? TYPO3.lang["mindfula11y.scan.status.failed.description"]
          : "";
        return {
          view: "failed",
          text: title,
          description: description,
        };

      case ScanStatus.ISSUES:
        return {
          view: "violations",
          text: TYPO3.lang["mindfula11y.scan.issuesFound"].replace(
            "%d",
            this._totalIssueCount
          ),
          violations: this._violations,
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
   * Renders the visible status message.
   * This is wrapped in an aria-live region in _renderContent.
   *
   * @protected
   * @param {Object} config The status configuration
   * @returns {import('lit').TemplateResult|null}
   */
  _renderStatusDisplay(config) {
    switch (config.view) {
      case "loading":
        return html`
          <div class="text-center py-5">
            <div
              class="spinner-border text-primary mb-3 mindfula11y-scan__loading-spinner"
              aria-hidden="true"
            ></div>
            <h2>${config.text}</h2>
          </div>
        `;

      case "failed":
        return html`
          <div class="callout callout-danger">
            <div class="callout-icon">
              <span class="icon-emphasized">
                <typo3-backend-icon
                  identifier="status-dialog-error"
                  size="small"
                ></typo3-backend-icon>
              </span>
            </div>
            <div class="callout-content">
              <h3 class="callout-title">${config.text}</h3>
              ${config.description
                ? html`<div class="callout-body">${config.description}</div>`
                : html``}
            </div>
          </div>
        `;

      case "violations":
        return html`
          <div class="callout callout-warning">
            <div class="callout-icon">
              <span class="icon-emphasized">
                <typo3-backend-icon
                  identifier="status-dialog-warning"
                  size="small"
                ></typo3-backend-icon>
              </span>
            </div>
            <div class="callout-content">
              <h3 class="callout-title mb-0">${config.text}</h3>
            </div>
          </div>
        `;

      case "success":
        return html`
          <div class="callout callout-success">
            <div class="callout-icon">
              <span class="icon-emphasized">
                <typo3-backend-icon
                  identifier="status-dialog-ok"
                  size="small"
                ></typo3-backend-icon>
              </span>
            </div>
            <div class="callout-content">
              <h3 class="callout-title mb-0">${config.text}</h3>
            </div>
          </div>
        `;

      default:
        return null;
    }
  }

  /**
   * Renders the list of violations.
   * This is kept outside the live region to prevent verbosity.
   *
   * @protected
   * @param {Object} config The status configuration
   * @returns {import('lit').TemplateResult|null}
   */
  _renderViolationsList(config) {
    if (config.view !== "violations") {
      return null;
    }

    return html`
      <ul class="list-group">
        ${config.violations.map(
          (violation) => html`
            <li class="list-group-item">
              <mindfula11y-violation
                .rule="${violation.rule}"
                impact="${violation.impact}"
                .issues="${violation.issues}"
              >
              </mindfula11y-violation>
            </li>
          `
        )}
      </ul>
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

customElements.define("mindfula11y-scan", Scan);

export default Scan;