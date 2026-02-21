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
 * @file violation.js
 * @description Web component for rendering individual accessibility violations.
 */
import { LitElement, html } from "lit";
import "@typo3/backend/element/icon-element.js";
import "./issue.js";

/**
 * Web component for rendering individual accessibility violations.
 *
 * This component displays a single accessibility violation with its rule information,
 * severity level, and associated issues. It provides a structured view of accessibility
 * problems found during scans, with proper Bootstrap styling and help links.
 *
 * Key features:
 * - Rule description and severity badge display
 * - Issue count with proper pluralization
 * - Help links to accessibility guidelines
 * - Bootstrap-styled severity indicators
 * - Expandable issue details
 *
 * Severity levels:
 * - Error: Critical accessibility violations
 * - Warning: Potential accessibility issues
 * - Notice: Informational accessibility suggestions
 *
 * @class Violation
 * @extends LitElement
 */
export class Violation extends LitElement {
  /**
   * Component properties definition.
   *
   * @returns {Object} The properties definition object for LitElement.
   */
  static get properties() {
    return {
      rule: { type: Object },
      impact: { type: String },
      issues: { type: Array },
      isOpen: { type: Boolean, state: true },
    };
  }

  /**
   * Creates an instance of Violation.
   */
  constructor() {
    super();
    this.rule = {};
    this.impact = "";
    this.issues = [];
    this.isOpen = false;
    this._uniqueId = `mindfula11y-violation-${Math.random()
      .toString(36)
      .substr(2, 9)}`;
  }

  connectedCallback() {
    super.connectedCallback();
    this.classList.add("card", "mb-0");
  }

  /**
   * Toggles the accordion state.
   *
   * @private
   */
  _toggle() {
    this.isOpen = !this.isOpen;
  }

  /**
   * Renders the violation component.
   *
   * @returns {import('lit').TemplateResult} The rendered template for the component.
   */
  render() {
    const headingId = `mindfula11y-violation-heading-${this._uniqueId}`;
    const contentId = `mindfula11y-violation-content-${this._uniqueId}`;

    return html`
      <h2 class="card-header p-0 mb-0" id="${headingId}">
        <button
          type="button"
          class="btn btn-link mindfula11y-violation__toggle-btn"
          aria-expanded="${this.isOpen}"
          aria-controls="${contentId}"
          @click="${this._toggle}"
        >
          <div class="d-flex align-items-center text-start gap-2">
            <typo3-backend-icon
              identifier="actions-chevron-right"
              size="medium"
              class="mindfula11y-violation__toggle-icon"
              aria-hidden="true"
            ></typo3-backend-icon>
            <span class="h5 mb-0">${this.rule.description}</span>
          </div>
          <div>
            <span class="${this._getSeverityBadgeClass()}">
              ${this._getSeverityLabel()}
            </span>
            <span class="badge badge-light ms-2">
              ${this.issues.length === 1
                ? TYPO3.lang["mindfula11y.scan.issueCount"].replace(
                    "%d",
                    this.issues.length
                  )
                : TYPO3.lang["mindfula11y.scan.issuesCount"].replace(
                    "%d",
                    this.issues.length
                  )}
            </span>
          </div>
        </button>
      </h2>
      <div
        id="${contentId}"
        class="card-body ${this.isOpen ? "" : "d-none"}"
        role="region"
        aria-labelledby="${headingId}"
      >
        ${this.rule.helpUrl
          ? html`
              <div class="mb-3">
                <a
                  href="${this.rule.helpUrl}"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="btn btn-default btn-sm"
                >
                  <typo3-backend-icon
                    identifier="actions-info"
                    size="small"
                    aria-hidden="true"
                  ></typo3-backend-icon>
                  ${TYPO3.lang["mindfula11y.scan.helpLinks"]}
                </a>
              </div>
            `
          : null}
        ${this.issues && this.issues.length > 0
          ? html`
              <ul class="list-group list-group-flush">
                ${this.issues.map(
                  (issue) => html`
                    <li class="list-group-item">
                      <mindfula11y-issue
                        issueId="${issue.id}"
                        selector="${issue.selector}"
                        context="${issue.context}"
                      >
                      </mindfula11y-issue>
                    </li>
                  `
                )}
              </ul>
            `
          : null}
      </div>
    `;
  }

  /**
   * Gets the Bootstrap badge class for violation impact.
   *
   * @private
   * @returns {string} Bootstrap badge CSS classes.
   */
  _getSeverityBadgeClass() {
    switch (this.impact) {
      case "critical":
      case "serious":
        return "badge badge-danger";
      case "moderate":
        return "badge badge-warning";
      case "minor":
      default:
        return "badge badge-info";
    }
  }

  /**
   * Gets the localized impact label.
   *
   * @private
   * @returns {string} Localized impact label.
   */
    _getSeverityLabel() {
    return TYPO3.lang[`mindfula11y.severity.${this.impact}`] || this.impact;
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

customElements.define("mindfula11y-violation", Violation);

export default Violation;
