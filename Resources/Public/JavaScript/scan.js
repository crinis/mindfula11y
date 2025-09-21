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
import { LitElement, html, css } from "lit";
import AjaxRequest from "@typo3/core/ajax/ajax-request.js";
import Notification from "@typo3/backend/notification.js";
import "./violation.js";

/**
 * Web component for displaying accessibility scan results and managing scans in TYPO3.
 *
 * This component provides a complete interface for running accessibility scans,
 * displaying results, and managing scan states. It handles the full scan lifecycle
 * from creation to completion, with proper error handling and user feedback.
 *
 * Key features:
 * - One-click scan creation and execution
 * - Real-time status updates with polling
 * - Comprehensive error handling with TYPO3 notifications
 * - Bootstrap-styled loading indicators and results display
 * - Cancel functionality for long-running scans
 * - Automatic retry logic for failed scans
 *
 * Scan states managed:
 * - Pending: Scan created, waiting to start
 * - Running: Scan actively processing
 * - Completed: Scan finished successfully
 * - Failed: Scan encountered an error
 * - Cancelled: Scan was cancelled by user
 *
 * @class Scan
 * @extends LitElement
 */
export class Scan extends LitElement {

  /**
   * Component properties definition.
   *
   * @returns {Object} The properties definition object for LitElement.
   */
  static get properties() {
    return {
      createScanDemand: { type: Object },
      scanId: { type: String },
      _scanStatus: { type: String },
      _scanResults: { type: Array },
      _loading: { type: Boolean },
      _pollInterval: { type: Number },
      _isNewScan: { type: Boolean },
      autoCreateScan: { type: Boolean },
    };
  }

  /**
   * Creates an instance of Scan.
   */
  constructor() {
    super();
    this.createScanDemand = null;
    this.scanId = '';
    this._scanStatus = '';
    this._scanResults = [];
    this._loading = false;
    this._pollInterval = null;
    this._isNewScan = false;
    this.autoCreateScan = true;
  }

  /**
   * Lifecycle callback when component is connected to DOM.
   * Automatically loads scan results if scanId is provided, or creates a new scan if autoCreateScan is enabled.
   */
  connectedCallback() {
    super.connectedCallback();
    if (this.scanId) {
      this._loadScanResults();
    } else if (this.autoCreateScan) {
      this._createNewScan();
    }
  }

  /**
   * Lifecycle callback when component is disconnected from DOM.
   * Cleans up polling interval.
   */
  disconnectedCallback() {
    super.disconnectedCallback();
    this._stopPolling();
  }

  /**
   * Handles the scan button click event.
   * Creates a new scan or refreshes existing results.
   *
   * @returns {Promise<void>}
   */
  async handleScan() {
    if (this._loading) {
      return;
    }
    await this._createNewScan();
  }

  /**
   * Creates a new accessibility scan.
   *
   * @private
   * @returns {Promise<void>}
   */
  async _createNewScan() {
    // Reset component state
    this._resetScanState();
    this._loading = true;
    this._scanStatus = 'pending';
    this._isNewScan = true; // Mark this as a new scan
    this.requestUpdate();

    try {
      const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.mindfula11y_createscan)
        .post({ ...this.createScanDemand });

      const responseData = await response.resolve();

      if (responseData) {
        this.scanId = responseData.scanId;
        this._scanStatus = responseData.status || 'pending';

        // Start polling for status updates
        this._startPolling();

        Notification.info(
          TYPO3.lang["mindfula11y.scan.created"],
          TYPO3.lang["mindfula11y.scan.created.description"]
        );
      }
    } catch (error) {
      this._handleScanError(error, 'createFailed');
    }

    this.requestUpdate();
  }

  /**
   * Loads scan results from the server.
   *
   * @private
   * @returns {Promise<void>}
   */
  async _loadScanResults() {
    if (!this.scanId) return;

    this._loading = true;
    this.requestUpdate();

    try {
      const request = new AjaxRequest(TYPO3.settings.ajaxUrls.mindfula11y_getscan)
        .withQueryArguments({ scanId: this.scanId });

      const response = await request.get();
      const responseData = await response.resolve();

      if (responseData) {
        this._scanStatus = responseData.status || 'completed';
        this._scanResults = responseData.violations || [];

        // Show notifications only for new scans, not when loading existing results
        this._handleScanCompletion(this._isNewScan);
      }
    } catch (error) {
      this._handleScanError(error, 'loadFailed');
    }

    this.requestUpdate();
  }

  /**
   * Handles successful scan completion and notifications.
   *
   * @private
   * @param {boolean} showNotifications - Whether to show TYPO3 notifications
   */
  _handleScanCompletion(showNotifications = true) {
    this._loading = false;
    this._stopPolling();

    if (showNotifications) {
      if (this._scanStatus === 'completed') {
        Notification.success(
          TYPO3.lang["mindfula11y.scan.status.completed"],
          this._scanResults.length > 0
            ? TYPO3.lang["mindfula11y.scan.issuesFound"].replace('%d', this._scanResults.length)
            : TYPO3.lang["mindfula11y.scan.noIssues"]
        );
      } else if (this._scanStatus === 'failed') {
        Notification.error(
          TYPO3.lang["mindfula11y.scan.status.failed"],
          'The accessibility scan could not be completed'
        );
      }
    }
  }

  /**
   * Handles scan-related errors with appropriate user feedback.
   *
   * @private
   * @param {Error} error - The error object
   * @param {string} errorType - Type of error ('createFailed' or 'loadFailed')
   */
  _handleScanError(error, errorType) {
    this._loading = false;

    if (!error.response) {
      // Network error - show notification
      Notification.error(
        TYPO3.lang[`mindfula11y.scan.error.${errorType}`],
        'Network error occurred'
      );
      return;
    }

    // Check for 404 (scan not found) - trigger new scan
    if (error.response.status === 404 && errorType === 'loadFailed') {
      this._resetScanState();
      this._createNewScan();
      return;
    }

    try {
      const responseData = error.response.json();
      if (responseData?.error) {
        // Other API errors - show notification
        Notification.error(
          responseData.error.title,
          responseData.error.description
        );
      } else if (errorType === 'loadFailed') {
        // For other load failures, try to create a new scan automatically
        this._resetScanState();
        this._createNewScan();
        return;
      }
    } catch (parseError) {
      Notification.error(
        TYPO3.lang[`mindfula11y.scan.error.${errorType}`],
        TYPO3.lang["mindfula11y.missingAltText.generate.error.unknown.description"]
      );
    }
  }

  /**
   * Resets the component to prepare for a new scan.
   *
   * @private
   */
  _resetScanState() {
    this.scanId = '';
    this._scanStatus = '';
    this._scanResults = [];
    this._isNewScan = false;
    this._stopPolling();
  }

  /**
   * Starts polling for scan status updates.
   *
   * @private
   */
  _startPolling() {
    if (this._pollInterval) {
      clearInterval(this._pollInterval);
    }

    this._pollInterval = setInterval(() => {
      this._loadScanResults();
    }, 5000); // Poll every 5 seconds
  }

  /**
   * Stops polling for scan status updates.
   *
   * @private
   */
  _stopPolling() {
    if (this._pollInterval) {
      clearInterval(this._pollInterval);
      this._pollInterval = null;
    }
  }

  /**
   * Renders the scan component.
   *
   * @returns {import('lit').TemplateResult} The rendered template for the component.
   */
  render() {
    return html`
      <div class="mb-4">
        <button
          class="btn btn-primary"
          type="button"
          @click="${this.handleScan}"
          ?disabled="${this._loading}"
        >
          ${this._loading ? html`
            <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
          ` : null}
          ${this._loading
            ? (TYPO3.lang["mindfula11y.scan.processing"])
            : (this.scanId
              ? TYPO3.lang["mindfula11y.scan.refresh"]
              : TYPO3.lang["mindfula11y.scan.start"]
            )
          }
        </button>
      </div>

      ${this._renderScanContent()}
    `;
  }

  /**
   * Renders the main scan content based on current state.
   *
   * @private
   * @returns {import('lit').TemplateResult} The rendered content
   */
  _renderScanContent() {
    // Show failed status as message instead of notification
    if (this._scanStatus === 'failed' && !this._loading) {
      return html`
        <div class="alert alert-danger text-center">
          <h2 class="alert-heading">${TYPO3.lang["mindfula11y.scan.status.failed"]}</h2>
          <p class="mb-0">The accessibility scan could not be completed</p>
        </div>
      `;
    }

    // Show pending/running status with loading spinner
    if ((this._scanStatus === 'pending' || this._scanStatus === 'running') && this.scanId) {
      return html`
        <div class="text-center py-5">
          <div class="spinner-border text-primary mb-3" role="status" style="width: 3rem; height: 3rem;"></div>
          <h2>${TYPO3.lang["mindfula11y.scan.loading"]}</h2>
        </div>
      `;
    }

    if (this._scanResults.length > 0) {
      return html`
        <ul class="list-group">
          ${this._scanResults.map(violation => html`
            <li class="list-group-item">
              <mindfula11y-violation
                .rule="${violation.rule}"
                .issueCount="${violation.issueCount}"
                .issues="${violation.issues}">
              </mindfula11y-violation>
            </li>
          `)}
        </ul>
      `;
    }

    if (this._loading && this.scanId) {
      return html`
        <div class="text-center py-5">
          <div class="spinner-border text-primary mb-3" role="status" style="width: 3rem; height: 3rem;"></div>
          <h2>${TYPO3.lang["mindfula11y.scan.loading"]}</h2>
        </div>
      `;
    }

    if (this._scanStatus === 'completed' && !this._loading) {
      return html`
        <div class="alert alert-success text-center">
          <h2 class="alert-heading">${TYPO3.lang["mindfula11y.scan.noIssues"]}</h2>
        </div>
      `;
    }

    return null;
  }

  /**
   * Disables the default shadow DOM and renders into the light DOM.
   *
   * @returns {HTMLElement} The root element for the component (this).
   */
  createRenderRoot() {
    return this;
  }
}

customElements.define("mindfula11y-scan", Scan);

export default Scan;