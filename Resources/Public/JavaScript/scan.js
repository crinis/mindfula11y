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
      crawlScanDemand: { type: Object },
      reportBaseUrl: { type: String },
      _activeTab: { state: true },
      _crawlStatus: { state: true },
      _crawlViolations: { state: true },
      _crawlTotalIssueCount: { state: true },
      _crawlIsFetching: { state: true },
      _crawlErrorMessage: { state: true },
      _crawlProgress: { state: true },
      _crawlUpdatedAt: { state: true },
    };
  }

  constructor() {
    super();
    this.crawlScanDemand = null;
    this.reportBaseUrl = "";
    this._activeTab = "scan";
    this._crawlStatus = "";
    this._crawlViolations = [];
    this._crawlTotalIssueCount = 0;
    this._crawlIsFetching = false;
    this._crawlErrorMessage = "";
    this._crawlProgress = null;
    this._crawlUpdatedAt = null;
  }

  connectedCallback() {
    super.connectedCallback();
    if (this.scanId && this.crawlScanDemand) {
      this._loadCrawlView(this.scanId);
    }
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
    // Clear any stale crawl state — starting a regular scan replaces the shared scan ID
    this._crawlStatus = "";
    this._crawlViolations = [];
    this._crawlTotalIssueCount = 0;
    this._crawlErrorMessage = "";
    this._crawlProgress = null;
    this._crawlUpdatedAt = null;
    this._shouldAnnounce = true;
    await this._createScan();
  }

  /**
   * Handles the crawl scan trigger.
   * Creates a crawl scan and updates shared state.
   *
   * @returns {Promise<void>}
   */
  async _handleCrawl() {
    if (this._isCrawlBusy || !this.crawlScanDemand) {
      return;
    }
    this._shouldAnnounce = true;
    this._crawlIsFetching = true;
    this._crawlStatus = "pending";
    this._crawlViolations = [];
    this._crawlErrorMessage = "";
    this._crawlProgress = null;
    this._crawlUpdatedAt = null;
    // Reset filtered view state too since they share _scanId
    this._status = "pending";
    this._violations = [];

    try {
      const result = await this._scanService.createScan(this.crawlScanDemand);
      this._scanId = result.scanId;
      this._status = result.status;
      this._crawlStatus = result.status;
      this._startPolling();
    } catch (error) {
      this._crawlStatus = "failed";
      if (error.message) {
        this._crawlErrorMessage = error.message;
      }
    }

    this._crawlIsFetching = false;
  }

  /**
   * Loads the crawl (unfiltered) view of the current scan.
   *
   * @param {string} [scanId]
   * @returns {Promise<void>}
   */
  async _loadCrawlView(scanId = this._scanId) {
    if (!scanId || !this.crawlScanDemand) return;

    this._crawlIsFetching = true;

    try {
      const result = await this._scanService.loadScan(scanId, []); // no URL filter
      if (result && result.mode === "crawl") {
        this._crawlStatus = result.status;
        this._crawlViolations = result.violations;
        this._crawlTotalIssueCount = result.totalIssueCount;
        this._crawlProgress = result.progress ?? null;
        this._crawlUpdatedAt = result.updatedAt ?? null;
      } else {
        // Not a crawl scan — clear stale crawl state so the tab shows idle
        this._crawlStatus = "";
        this._crawlViolations = [];
        this._crawlTotalIssueCount = 0;
        this._crawlProgress = null;
        this._crawlUpdatedAt = null;
      }
    } catch (error) {
      this._crawlStatus = "failed";
      if (error.message) {
        this._crawlErrorMessage = error.message;
      }
    }

    this._crawlIsFetching = false;
  }

  /**
   * Override polling to also refresh the crawl view.
   * @protected
   */
  _startPolling() {
    this._stopPolling();
    this._pollInterval = setInterval(() => {
      this._loadScan();
      if (this.crawlScanDemand) {
        this._loadCrawlView();
      }
    }, 5000);
  }

  /**
   * Keep polling as long as either the filtered or the crawl view is still running.
   * @protected
   * @returns {boolean}
   */
  _shouldStopPolling() {
    if (!this.crawlScanDemand) return true;
    return (
      !this._scanService.isScanInProgress(this._status) &&
      !this._scanService.isScanInProgress(this._crawlStatus)
    );
  }

  /**
   * Whether the crawl tab is currently busy.
   * @returns {boolean}
   */
  get _isCrawlBusy() {
    return (
      this._crawlIsFetching ||
      this._scanService.isScanInProgress(this._crawlStatus)
    );
  }

  /**
   * Builds the URL for a scan report download.
   * Uses the pre-signed backend route URL passed from the server, which includes
   * TYPO3's request token and is directly browser-navigable.
   *
   * @param {string} format - 'html' or 'pdf'
   * @returns {string}
   */
  _buildReportUrl(format) {
    return (
      this.reportBaseUrl +
      "&scanId=" +
      encodeURIComponent(this._scanId) +
      "&format=" +
      format
    );
  }

  /**
   * Formats an ISO 8601 date string for display using the browser locale.
   *
   * @param {string|null} isoDate
   * @returns {string|null}
   */
  _formatScanDate(isoDate) {
    if (!isoDate) return null;
    try {
      return new Intl.DateTimeFormat(undefined, {
        dateStyle: "medium",
        timeStyle: "short",
      }).format(new Date(isoDate));
    } catch {
      return null;
    }
  }

  /**
   * Renders the component template.
   *
   * @returns {import('lit').TemplateResult}
   */
  render() {
    if (this.crawlScanDemand) {
      return this._renderWithTabs();
    }
    return this._renderSinglePanel(
      this.createScanDemand,
      this._isBusy,
      () => this.handleScan(),
      this._getStatusConfiguration(),
      false
    );
  }

  /**
   * Renders the two-tab layout for root pages (Scan + Crawl tabs).
   *
   * @returns {import('lit').TemplateResult}
   */
  _renderWithTabs() {
    const scanTabId = "mindfula11y-tab-scan";
    const crawlTabId = "mindfula11y-tab-crawl";
    return html`
      <ul class="nav nav-tabs mb-3" role="tablist">
        <li class="nav-item" role="presentation">
          <button
            type="button"
            class="nav-link ${this._activeTab === "scan" ? "active" : ""}"
            role="tab"
            aria-selected="${this._activeTab === "scan"}"
            aria-controls="${scanTabId}"
            @click="${() => (this._activeTab = "scan")}"
          >
            ${TYPO3.lang["mindfula11y.scan.tab.scan"]}
          </button>
        </li>
        <li class="nav-item" role="presentation">
          <button
            type="button"
            class="nav-link ${this._activeTab === "crawl" ? "active" : ""}"
            role="tab"
            aria-selected="${this._activeTab === "crawl"}"
            aria-controls="${crawlTabId}"
            @click="${() => (this._activeTab = "crawl")}"
          >
            ${TYPO3.lang["mindfula11y.scan.tab.crawl"]}
          </button>
        </li>
      </ul>
      <div id="${scanTabId}" role="tabpanel" class="${this._activeTab !== "scan" ? "d-none" : ""}">
        ${this._renderSinglePanel(
          this.createScanDemand,
          this._isBusy,
          () => this.handleScan(),
          this._getStatusConfiguration(),
          false
        )}
      </div>
      <div id="${crawlTabId}" role="tabpanel" class="${this._activeTab !== "crawl" ? "d-none" : ""}">
        ${this._renderSinglePanel(
          this.crawlScanDemand,
          this._isCrawlBusy,
          () => this._handleCrawl(),
          this._getCrawlStatusConfiguration(),
          true
        )}
      </div>
    `;
  }

  /**
   * Renders a single scan panel (reused for both tabs).
   *
   * @param {Object|null} demand
   * @param {boolean} isBusy
   * @param {Function} onScan
   * @param {Object|null} config
   * @param {boolean} isCrawl
   * @returns {import('lit').TemplateResult}
   */
  _renderSinglePanel(demand, isBusy, onScan, config, isCrawl) {
    const startLabel = isCrawl
      ? TYPO3.lang["mindfula11y.scan.crawl.start"]
      : TYPO3.lang["mindfula11y.scan.start"];
    const refreshLabel = isCrawl
      ? TYPO3.lang["mindfula11y.scan.crawl.refresh"]
      : TYPO3.lang["mindfula11y.scan.refresh"];
    const hasScanId = isCrawl ? !!this._crawlStatus : !!this._scanId;

    return html`
      ${demand
        ? html`
            <div class="mb-4">
              <button
                class="btn btn-default"
                type="button"
                @click="${onScan}"
                ?disabled="${isBusy}"
              >
                ${isBusy
                  ? html`
                      <span
                        class="spinner-border spinner-border-sm me-2"
                        aria-hidden="true"
                      ></span>
                      ${TYPO3.lang["mindfula11y.scan.processing"]}
                    `
                  : html`
                      <typo3-backend-icon
                        identifier="${hasScanId ? "actions-refresh" : "actions-search"}"
                        size="small"
                        class="me-2"
                        aria-hidden="true"
                      ></typo3-backend-icon>
                      ${hasScanId ? refreshLabel : startLabel}
                    `}
              </button>
            </div>
            ${!hasScanId && !isBusy
              ? html`<p class="text-body-secondary small mb-3">
                  ${isCrawl
                    ? TYPO3.lang["mindfula11y.scan.tab.crawl.description"]
                    : TYPO3.lang["mindfula11y.scan.tab.scan.description"]}
                </p>`
              : null}
          `
        : null}
      ${!config && isCrawl && !this._isCrawlBusy
        ? html`
            <div class="callout callout-info">
              <div class="callout-icon">
                <span class="icon-emphasized">
                  <typo3-backend-icon
                    identifier="actions-info"
                    size="small"
                  ></typo3-backend-icon>
                </span>
              </div>
              <div class="callout-content">
                <h3 class="callout-title">${TYPO3.lang["mindfula11y.scan.crawl.idle.title"]}</h3>
                <div class="callout-body">${TYPO3.lang["mindfula11y.scan.crawl.idle.description"]}</div>
              </div>
            </div>
          `
        : null}
      ${!isCrawl && this._urlCoverageIncomplete
        ? html`
            <div class="callout callout-info mb-3">
              <div class="callout-icon">
                <span class="icon-emphasized">
                  <typo3-backend-icon
                    identifier="actions-info"
                    size="small"
                  ></typo3-backend-icon>
                </span>
              </div>
              <div class="callout-content">
                <h3 class="callout-title">${TYPO3.lang["mindfula11y.scan.scopeExpanded"]}</h3>
                <div class="callout-body">${TYPO3.lang["mindfula11y.scan.scopeExpanded.description"]}</div>
              </div>
            </div>
          `
        : null}
      ${!isCrawl && !hasScanId && !isBusy && this.urlList?.length > 1
        ? html`
            <div class="callout callout-info mb-3">
              <div class="callout-icon">
                <span class="icon-emphasized">
                  <typo3-backend-icon
                    identifier="actions-info"
                    size="small"
                  ></typo3-backend-icon>
                </span>
              </div>
              <div class="callout-content">
                <h3 class="callout-title">${TYPO3.lang["mindfula11y.scan.multiPage.manualScan"]}</h3>
                <div class="callout-body">${TYPO3.lang["mindfula11y.scan.multiPage.manualScan.description"]}</div>
              </div>
            </div>
          `
        : null}
      ${config
        ? html`
            <div
              class="mb-3"
              aria-live="${this._shouldAnnounce ? "polite" : "off"}"
              aria-atomic="true"
            >
              ${this._renderStatusDisplay(config)}
            </div>
            ${(() => {
              const updatedAt = isCrawl ? this._crawlUpdatedAt : this._scanUpdatedAt;
              const formatted = this._formatScanDate(updatedAt);
              return formatted
                ? html`<p class="text-body-secondary small mb-3">
                    ${TYPO3.lang["mindfula11y.scan.updatedAt"]}
                    <time datetime="${updatedAt}">${formatted}</time>
                  </p>`
                : null;
            })()}
            ${this._renderViolationsList(config)}
            ${(config.view === "violations" || config.view === "success") && this._scanId && this.reportBaseUrl
              ? html`
                  <div class="mt-3">
                    <a
                      href="${this._buildReportUrl("html")}"
                      target="_blank"
                      rel="noopener noreferrer"
                      class="btn btn-sm btn-default me-2"
                    >
                      <typo3-backend-icon
                        identifier="actions-document"
                        size="small"
                        class="me-1"
                        aria-hidden="true"
                      ></typo3-backend-icon>
                      ${TYPO3.lang["mindfula11y.scan.report.html"]}
                    </a>
                    <a
                      href="${this._buildReportUrl("pdf")}"
                      download="accessibility-report.pdf"
                      class="btn btn-sm btn-default"
                    >
                      <typo3-backend-icon
                        identifier="actions-download"
                        size="small"
                        class="me-1"
                        aria-hidden="true"
                      ></typo3-backend-icon>
                      ${TYPO3.lang["mindfula11y.scan.report.pdf"]}
                    </a>
                  </div>
                `
              : null}
          `
        : null}
    `;
  }

  /**
   * Renders the content based on current state (single-panel mode).
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
   * Status configuration for the crawl tab.
   * Mirrors _getStatusConfiguration but uses crawl-specific state.
   *
   * @protected
   * @returns {Object|null}
   */
  _getCrawlStatusConfiguration() {
    const viewState = this._getViewState(
      this._crawlStatus,
      this._crawlTotalIssueCount,
      this._isCrawlBusy
    );

    switch (viewState) {
      case ScanStatus.LOADING: {
        const progress = this._crawlProgress;
        const progressText =
          progress && progress.pagesScanned > 0
            ? TYPO3.lang["mindfula11y.scan.crawl.progress"]
                ?.replace("%scanned", progress.pagesScanned)
                ?.replace("%discovered", progress.pagesDiscovered)
            : null;
        return {
          view: "loading",
          text: TYPO3.lang["mindfula11y.scan.loading"],
          description: progressText || "",
        };
      }

      case ScanStatus.FAILED:
        const title = this._crawlErrorMessage || TYPO3.lang["mindfula11y.scan.status.failed"];
        return {
          view: "failed",
          text: title,
          description: !this._crawlErrorMessage
            ? TYPO3.lang["mindfula11y.scan.status.failed.description"]
            : "",
        };

      case ScanStatus.ISSUES:
        return {
          view: "violations",
          text: TYPO3.lang["mindfula11y.scan.issuesFound"].replace(
            "%d",
            this._crawlTotalIssueCount
          ),
          violations: this._crawlViolations,
        };

      case ScanStatus.SUCCESS:
        return { view: "success", text: TYPO3.lang["mindfula11y.scan.noIssues"] };
    }

    return null;
  }

  /**
   * Determines the current status configuration including view mode and texts.
   * Shared logic for both visual and screen reader outputs.
   *
   * @protected
   * @returns {Object|null} Configuration object with view, text, and other properties
   */
  _getStatusConfiguration() {
    switch (this._getViewState()) {
      case ScanStatus.LOADING:
        if (!this._scanId) return null; // No scan yet; button handles state
        return {
          view: "loading",
          text: TYPO3.lang["mindfula11y.scan.loading"],
        };

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