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
 * @file issue.js
 * @description Web component for rendering individual accessibility issues.
 */
import { LitElement, html } from "lit";

/**
 * Web component for rendering individual accessibility issues.
 *
 * This component displays detailed information about a specific accessibility issue,
 * including the CSS selector, HTML context, and optional screenshot. It provides
 * developers with the exact location and context of accessibility problems.
 *
 * Key features:
 * - CSS selector display with syntax highlighting
 * - HTML context preview with proper formatting
 * - Screenshot integration for visual issues
 * - Bootstrap-styled layout with proper spacing
 * - Responsive design for different screen sizes
 *
 * @class Issue
 * @extends LitElement
 */
export class Issue extends LitElement {
  /**
   * Component properties definition.
   *
   * @returns {Object} The properties definition object for LitElement.
   */
  static get properties() {
    return {
      issueId: { type: Number },
      selector: { type: String },
      context: { type: String },
      screenshotUrl: { type: String },
    };
  }

  /**
   * Creates an instance of Issue.
   */
  constructor() {
    super();
    this.issueId = 0;
    this.selector = "";
    this.context = "";
    this.screenshotUrl = "";
  }

  /**
   * Renders the issue component.
   *
   * @returns {import('lit').TemplateResult} The rendered template for the component.
   */
  render() {
    return html`
      <dl class="row">
        ${this.selector
          ? html`
              <dt class="col-sm-3 text-muted">
                ${TYPO3.lang["mindfula11y.scan.selector"]}
              </dt>
              <dd class="col-sm-9 mb-2">
                <code
                  class="d-block bg-white p-2 rounded border text-break small"
                  >${this.selector}</code
                >
              </dd>
            `
          : null}
        ${this.screenshotUrl
          ? html`
              <dt class="col-sm-3 text-muted">
                ${TYPO3.lang["mindfula11y.scan.screenshot"]}
              </dt>
              <dd class="col-sm-9 mb-2">
                <div class="d-flex flex-column gap-2">
                  <img
                    src="${this.screenshotUrl}"
                    alt=""
                    class="img-fluid rounded border"
                    style="max-width: 300px; max-height: 200px;"
                  />
                  <a
                    href="${this.screenshotUrl}"
                    target="_blank"
                    rel="noopener"
                    class="btn btn-sm btn-outline-primary"
                  >
                    ${TYPO3.lang["mindfula11y.scan.viewScreenshot"]}
                  </a>
                </div>
              </dd>
            `
          : null}
        ${this.context
          ? html`
              <dt class="col-sm-3 text-muted">
                ${TYPO3.lang["mindfula11y.scan.context"]}
              </dt>
              <dd class="col-sm-9 mb-0">
                <pre class="d-block bg-white p-2 rounded border text-break small"><code>
${this.context}</code></pre
                >
              </dd>
            `
          : null}
      </dl>
    `;
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

customElements.define("mindfula11y-issue", Issue);

export default Issue;
