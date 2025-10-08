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
      issueCount: { type: Number },
      issues: { type: Array },
    };
  }

  /**
   * Creates an instance of Violation.
   */
  constructor() {
    super();
    this.rule = {};
    this.issueCount = 0;
    this.issues = [];
  }

  /**
   * Renders the violation component.
   *
   * @returns {import('lit').TemplateResult} The rendered template for the component.
   */
  render() {
    return html`
      <h2 class="mb-2">${this.rule.description}</h2>

      <div class="mb-3">
        <span class="${this._getSeverityBadgeClass()}">
          ${this._getSeverityLabel()}
        </span>
        <span class="badge badge-light ms-2">
          ${this.issueCount === 1
            ? TYPO3.lang["mindfula11y.scan.issueCount"].replace(
                "%d",
                this.issueCount
              )
            : TYPO3.lang["mindfula11y.scan.issuesCount"].replace(
                "%d",
                this.issueCount
              )}
        </span>
      </div>

      ${this.rule.urls && this.rule.urls.length > 0
        ? this.rule.urls.length === 1
          ? html`
              <div class="mb-3">
                <p class="mb-2">
                  ${TYPO3.lang["mindfula11y.scan.helpLinks"]}
                  <a href="${this.rule.urls[0]}" target="_blank" rel="noopener">
                    ${this.rule.urls[0]}
                  </a>
                </p>
              </div>
            `
          : html`
              <div class="mb-3">
                <h3 class="h3">${TYPO3.lang["mindfula11y.scan.helpLinks"]}</h3>
                <ul class="list-unstyled">
                  ${this.rule.urls.map(
                    (url) => html`
                      <li class="mb-1">
                        <a href="${url}" target="_blank" rel="noopener">
                          ${url}
                        </a>
                      </li>
                    `
                  )}
                </ul>
              </div>
            `
        : null}
      ${this.issues && this.issues.length > 0
        ? html`
            <h3 class="h3">${TYPO3.lang["mindfula11y.scan.issuesList"]}</h3>
            <ul class="list-group list-group-flush">
              ${this.issues.map(
                (issue) => html`
                  <li class="list-group-item">
                    <mindfula11y-issue
                      issueId="${issue.id}"
                      selector="${issue.selector}"
                      context="${issue.context}"
                      screenshotUrl="${issue.screenshotUrl}"
                    >
                    </mindfula11y-issue>
                  </li>
                `
              )}
            </ul>
          `
        : null}
    `;
  }

  /**
   * Gets the Bootstrap badge class for violation severity.
   *
   * @private
   * @returns {string} Bootstrap badge CSS classes.
   */
  _getSeverityBadgeClass() {
    switch (this.rule.impact) {
      case "error":
        return "badge badge-danger";
      case "warning":
        return "badge badge-warning";
      case "notice":
      default:
        return "badge badge-info";
    }
  }

  /**
   * Gets the localized severity label.
   *
   * @private
   * @returns {string} Localized severity label.
   */
    _getSeverityLabel() {
    return TYPO3.lang[`mindfula11y.severity.${this.rule.impact}`];
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
