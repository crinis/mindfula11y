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
 * @file scan-base.js
 * @description Base Web Component for scan logic sharing.
 */
import { LitElement } from "lit";
import ScanService from "./scan-service.js";
import { ScanStatus } from "./types.js";

/**
 * Base class for scan-related components.
 * Manages scan state, polling, and data fetching.
 *
 * @class ScanBase
 * @extends LitElement
 */
export class ScanBase extends LitElement {
  /**
   * Component properties definition.
   *
   * @returns {Object} The properties definition object for LitElement.
   */
  static get properties() {
    return {
      createScanDemand: { type: Object },
      scanId: { type: String },
      autoCreateScan: { type: Boolean },
      pageUrlFilter: { type: Array },
      _scanId: { state: true },
      _status: { state: true },
      _violations: { state: true },
      _isFetching: { state: true },
      _errorMessage: { state: true },
      _shouldAnnounce: { state: true },
      _totalIssueCount: { state: true },
      _scanUpdatedAt: { state: true },
    };
  }

  /**
   * Creates an instance of ScanBase.
   */
  constructor() {
    super();
    this.createScanDemand = null;
    this.scanId = "";
    this.autoCreateScan = true;
    this.pageUrlFilter = [];
    this._scanId = "";
    this._status = "";
    this._violations = [];
    this._isFetching = false;
    this._errorMessage = "";
    this._pollInterval = null;
    this._scanService = new ScanService();
    this._shouldAnnounce = false;
    this._totalIssueCount = 0;
    this._scanUpdatedAt = null;
  }

  /**
   * Limits logic duplication by determining the high-level view state.
   * Accepts optional parameters to allow subclasses to reuse the logic for
   * alternative state (e.g. the crawl tab in Scan component).
   *
   * @protected
   * @param {string} [status]
   * @param {number} [totalIssueCount]
   * @param {boolean} [isBusy]
   * @returns {string} One of the ScanStatus values
   */
  _getViewState(status = this._status, totalIssueCount = this._totalIssueCount, isBusy = this._isBusy) {
    if (isBusy) {
      return ScanStatus.LOADING;
    }

    if (status === "failed") {
      return ScanStatus.FAILED;
    }

    if (totalIssueCount > 0) {
      return ScanStatus.ISSUES;
    }

    if (status === "completed") {
      return ScanStatus.SUCCESS;
    }

    return ScanStatus.IDLE;
  }

  /**
   * Lifecycle callback invoked when the component is added to the document's DOM.
   */
  connectedCallback() {
    super.connectedCallback();
    if (this.scanId) {
      this._loadScan(this.scanId);
    } else if (this.autoCreateScan && this.createScanDemand) {
      this._createScan();
    }
  }

  /**
   * Lifecycle callback invoked when the component is removed from the document's DOM.
   */
  disconnectedCallback() {
    super.disconnectedCallback();
    this._stopPolling();
  }

  /**
   * Initiates a new scan.
   * Sets local state to pending and starts polling after successful creation.
   *
   * @returns {Promise<void>}
   * @protected
   */
  async _createScan() {
    this._isFetching = true;
    this._status = "pending";
    this._violations = [];
    this._errorMessage = "";
    this._scanUpdatedAt = null;

    try {
      const result = await this._scanService.createScan(this.createScanDemand);
      this._scanId = result.scanId;
      this._status = result.status;
      this._startPolling();
    } catch (error) {
      this._status = "failed";
      if (error.message) {
        this._errorMessage = error.message;
      }
    }

    this._isFetching = false;
  }

  /**
   * Loads the scan results for a given scan ID.
   * Updates local state with violations and status.
   *
   * @param {string} [scanId=this._scanId] - The ID of the scan to load.
   * @returns {Promise<void>}
   * @protected
   */
  async _loadScan(scanId = this._scanId) {
    if (!scanId) return;

    this._isFetching = true;
    this._errorMessage = "";

    try {
      const result = await this._scanService.loadScan(scanId, this.pageUrlFilter);
      if (result) {
        this._scanId = scanId;
        this._status = result.status;
        this._violations = result.violations;
        this._totalIssueCount = result.totalIssueCount;
        this._scanUpdatedAt = result.updatedAt ?? null;

        if (
          result.status === "completed" ||
          result.status === "failed"
        ) {
          if (this._shouldStopPolling()) {
            this._stopPolling();
          }
        }
      } else {
        // Scan not found
        this._scanId = "";
        this._status = "";
        this._violations = [];
        this._totalIssueCount = 0;
        this._scanUpdatedAt = null;
      }
    } catch (error) {
      this._status = "failed";
      if (error.message) {
        this._errorMessage = error.message;
      }
    }

    this._isFetching = false;
  }

  /**
   * Starts polling for scan results at a fixed interval.
   *
   * @protected
   */
  _startPolling() {
    this._stopPolling();
    this._pollInterval = setInterval(() => this._loadScan(), 5000);
  }

  /**
   * Stops the active polling mechanism.
   *
   * @protected
   */
  _stopPolling() {
    if (this._pollInterval) {
      clearInterval(this._pollInterval);
      this._pollInterval = null;
    }
  }

  /**
   * Hook allowing subclasses to delay stopping the poll when other
   * asynchronous views (e.g. the crawl tab) are still pending.
   * Returns true by default (stop as soon as the main scan is done).
   *
   * @protected
   * @returns {boolean}
   */
  _shouldStopPolling() {
    return true;
  }

  /**
   * Computed property to check if the component is busy.
   * Returns true if currently fetching data or if the scan is pending/running.
   *
   * @returns {boolean}
   * @protected
   */
  get _isBusy() {
    return (
      this._isFetching ||
      this._scanService.isScanInProgress(this._status)
    );
  }
}

export default ScanBase;
