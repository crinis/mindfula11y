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
import ScanService from "./scan-service.js";
import "./violation.js";

/**
 * Web component for displaying accessibility scan results and managing scans.
 *
 * @class Scan
 * @extends LitElement
 */
export class Scan extends LitElement {
  static get properties() {
    return {
      createScanDemand: { type: Object },
      scanId: { type: String },
      autoCreateScan: { type: Boolean },
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

  async handleScan() {
    if (this._loading || this._scanService.isScanInProgress(this._status) || !this.createScanDemand) {
      return;
    }
    await this._createScan();
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
        // Scan not found
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
    return html`
      ${this.createScanDemand ? html`
        <div class="mb-4">
          <button
            class="btn btn-primary"
            type="button"
            @click="${this.handleScan}"
            ?disabled="${this._loading}"
          >
            ${this._loading ? html`
              <span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>
            ` : null}
            ${this._loading
              ? TYPO3.lang["mindfula11y.scan.processing"]
              : (this._scanId
                ? TYPO3.lang["mindfula11y.scan.refresh"]
                : TYPO3.lang["mindfula11y.scan.start"]
              )
            }
          </button>
        </div>
      ` : null}

      ${this._renderContent()}
    `;
  }

  _renderContent() {
    if (this._status === 'failed' && !this._loading) {
      return html`
        <div class="alert alert-danger text-center">
          <h2 class="alert-heading">${TYPO3.lang["mindfula11y.scan.status.failed"]}</h2>
          <p class="mb-0">${TYPO3.lang["mindfula11y.scan.status.failed.description"]}</p>
        </div>
      `;
    }

    if ((this._status === 'pending' || this._status === 'running') && this._scanId) {
      return html`
        <div class="text-center py-5">
          <div class="spinner-border text-primary mb-3" aria-hidden="true" style="width: 3rem; height: 3rem;"></div>
          <h2>${TYPO3.lang["mindfula11y.scan.loading"]}</h2>
        </div>
      `;
    }

    if (this._violations.length > 0) {
      return html`
        <ul class="list-group">
          ${this._violations.map(violation => html`
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

    if (this._loading && this._scanId) {
      return html`
        <div class="text-center py-5">
          <div class="spinner-border text-primary mb-3" aria-hidden="true" style="width: 3rem; height: 3rem;"></div>
          <h2>${TYPO3.lang["mindfula11y.scan.loading"]}</h2>
        </div>
      `;
    }

    if (this._status === 'completed' && !this._loading) {
      return html`
        <div class="alert alert-success text-center">
          <h2 class="alert-heading">${TYPO3.lang["mindfula11y.scan.noIssues"]}</h2>
        </div>
      `;
    }

    return null;
  }

  createRenderRoot() {
    return this;
  }
}

customElements.define("mindfula11y-scan", Scan);

export default Scan;