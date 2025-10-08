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
import { LitElement, html, css } from "lit";
import ScanService from "./scan-service.js";

/**
 * Web component for displaying the count of accessibility scan issues.
 *
 * @class ScanIssueCount
 * @extends LitElement
 */
export class ScanIssueCount extends LitElement {
  static get properties() {
    return {
      createScanDemand: { type: Object },
      scanId: { type: String },
      autoCreateScan: { type: Boolean },
      scanUri: { type: String },
      _scanId: { state: true },
      _status: { state: true },
      _violations: { state: true },
      _loading: { state: true },
    };
  }

  constructor() {
    super();
    this.createScanDemand = null;
    this.scanId = '';
    this.scanUri = '';
    this.autoCreateScan = true;
    this._scanId = '';
    this._status = '';
    this._violations = [];
    this._loading = false;
    this._pollInterval = null;
    this._scanService = new ScanService();
  }

  connectedCallback() {
    super.connectedCallback();
    if (this.scanId) {
      this._loadScan(this.scanId);
    } else if (this.autoCreateScan && this.createScanDemand) {
      this._createScan();
    }
  }

  disconnectedCallback() {
    super.disconnectedCallback();
    this._stopPolling();
  }

  async _createScan() {
    this._loading = true;
    this._status = 'pending';
    this._violations = [];
    
    try {
      const result = await this._scanService.createScan(this.createScanDemand);
      this._scanId = result.scanId;
      this._status = result.status;
      this._startPolling();
    } catch (error) {
      this._status = 'failed';
    }
    
    this._loading = false;
  }

  async _loadScan(scanId = this._scanId) {
    if (!scanId) return;
    
    this._loading = true;
    
    try {
      const result = await this._scanService.loadScan(scanId);
      if (result) {
        this._status = result.status;
        this._violations = result.violations;
        
        if (result.status === 'completed' || result.status === 'failed') {
          this._stopPolling();
        }
      } else {
        this._scanId = '';
        this._status = '';
        this._violations = [];
      }
    } catch (error) {
      this._status = 'failed';
    }
    
    this._loading = false;
  }

  _startPolling() {
    this._stopPolling();
    this._pollInterval = setInterval(() => this._loadScan(), 5000);
  }

  _stopPolling() {
    if (this._pollInterval) {
      clearInterval(this._pollInterval);
      this._pollInterval = null;
    }
  }

  render() {
    if (!this.scanId && !this.createScanDemand) {
      return null;
    }

    const issueCount = this._scanService.getTotalIssues(this._violations);
    const detailsLink = this.scanUri ? html`
      <a href="${this.scanUri}" class="btn btn-sm btn-default ms-2">
        ${TYPO3.lang["mindfula11y.viewDetails"]}
      </a>
    ` : '';

    if (this._loading) {
      return html`
        <p class="alert alert-info">
          <span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>
          ${TYPO3.lang["mindfula11y.scan.loading"]}
          ${detailsLink}
        </p>
      `;
    }

    if (this._status === 'pending' || this._status === 'running') {
      return html`
        <p class="alert alert-info">
          <span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>
          ${this._status === 'pending' 
            ? TYPO3.lang["mindfula11y.scan.status.pending"] 
            : TYPO3.lang["mindfula11y.scan.status.running"]}
          ${detailsLink}
        </p>
      `;
    }

    if (this._status === 'failed') {
      return html`
        <p class="alert alert-danger">
          ${TYPO3.lang["mindfula11y.scan.error.loading"]}
          ${detailsLink}
        </p>
      `;
    }

    if (this._status === 'completed' && issueCount > 0) {
      return html`
        <p class="alert alert-warning">
          ${TYPO3.lang["mindfula11y.scan.issuesFound"].replace('%d', issueCount)}
          ${detailsLink}
        </p>
      `;
    }

    if (this._status === 'completed') {
      return html`
        <p class="alert alert-success">
          ${TYPO3.lang["mindfula11y.scan.noIssues"]}
          ${detailsLink}
        </p>
      `;
    }

    return null;
  }

  createRenderRoot() {
    return this;
  }
}

customElements.define("mindfula11y-scan-issue-count", ScanIssueCount);

export default ScanIssueCount;