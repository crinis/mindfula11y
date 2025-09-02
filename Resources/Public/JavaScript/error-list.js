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
 * @file error-list.js
 * @description Web component for rendering accessibility error lists.
 */
import { LitElement, html } from "lit";
import { ERROR_SEVERITY, getSeverityLabel } from "./types.js";

/**
 * Web component for rendering accessibility error lists.
 *
 * This component displays a list of accessibility errors with consistent styling,
 * severity levels, and Bootstrap-styled alerts.
 *
 * @class ErrorList
 * @extends LitElement
 */
export class ErrorList extends LitElement {

  /**
   * Component properties definition.
   *
   * @returns {Object} The properties definition object for LitElement.
   */
  static get properties() {
    return {
      errors: { type: Array },
      firstRun: { type: Boolean },
    };
  }

  /**
   * Creates an instance of ErrorList.
   */
  constructor() {
    super();
    this.errors = [];
    this.firstRun = true;
  }

  /**
   * Disables the default shadow DOM and renders into the light DOM.
   *
   * @returns {HTMLElement} The root element for the component (this).
   */
  createRenderRoot() {
    return this;
  }

  /**
   * Renders the error list component.
   *
   * @returns {import('lit').TemplateResult} The rendered template for the component.
   */
  render() {
    return this._renderErrors(this.errors);
  }

  /**
   * Renders error alerts for accessibility issues.
   *
   * @private
   * @param {Array} errors - Array of error objects
   * @returns {import('lit').TemplateResult} Rendered error section
   */
  _renderErrors(errors) {
    if (errors.length === 0) {
      return html``;
    }

    return html`
      <section
        class="mindfula11y-accessibility-structure__errors"
        role="${this.firstRun ? '' : 'alert'}"
      >
        <ul class="list-unstyled">
          ${errors.map(error => this._renderSingleError(error))}
        </ul>
      </section>
    `;
  }

  /**
   * Renders a single error item with unified styling.
   * 
   * @private
   * @param {Object} error - The error to render
   * @returns {import('lit').TemplateResult} The rendered error item
   */
  _renderSingleError(error) {
    const alertClass = error.severity === ERROR_SEVERITY.WARNING ? "alert-warning" : "alert-danger";
    const badgeClass = error.severity === ERROR_SEVERITY.WARNING ? "badge-warning" : "badge-danger";
    const severityLabel = getSeverityLabel(error.severity);
    
    return html`
      <li class="alert ${alertClass}">
        <p class="lead mb-2">
          <span class="badge ${badgeClass} me-2">${severityLabel}</span>
          ${TYPO3.lang[error.id]}
          <span class="badge rounded-pill ms-2">${error.count}</span>
        </p>
        <p class="mb-0">${TYPO3.lang[`${error.id}.description`]}</p>
      </li>
    `;
  }
}

customElements.define("mindfula11y-error-list", ErrorList);

export default ErrorList;
