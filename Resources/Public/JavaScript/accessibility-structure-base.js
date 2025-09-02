/*
 * Mindful A11y extension for TYPO3 integrating accessibility tools into the backend.
 * Copyright (C) 2025  Mindful Markup, Felix Spittel
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of blic License as published by
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
 * @file accessibility-structure-base.js
 * @description Base class for accessibility structure components with unified error handling.
 */
import { LitElement, html } from "lit";
import { Task } from "@lit/task";
import Notification from "@typo3/backend/notification.js";
import { 
  getSeverityClass, 
  getSeverityBadgeClass, 
  getSeverityLabel 
} from "./types.js";

/**
 * Base class for accessibility structure components.
 *
 * Provides common functionality for analyzing HTML content, detecting accessibility
 * errors, and rendering error messages with consistent styling and severity levels.
 * This abstract base class defines the contract that all accessibility structure
 * components must follow.
 *
 * Key features:
 * - Unified error handling and notification system
 * - Consistent styling for error messages and severity levels
 * - Task-based content loading with proper error recovery
 * - Bootstrap-styled alert components for user feedback
 *
 * Subclasses must implement:
 * - `_analyzeContent()` method for specific content analysis
 * - Component-specific rendering logic
 * - Error detection and reporting
 *
 * @abstract
 * @class AccessibilityStructureBase
 * @extends LitElement
 */
export class AccessibilityStructureBase extends LitElement {
  /**
   * Component properties definition.
   *
   * @returns {Object} The properties definition object for LitElement.
   */
  static get properties() {
    return {
      previewUrl: { type: String },
    };
  }

  /**
   * Creates an instance of AccessibilityStructureBase.
   * 
   * Initializes the component with a task for loading and analyzing content from the preview URL.
   * The task is configured to not auto-run to give control over when analysis happens.
   */
  constructor() {
    super();
    this.previewUrl = "";
    this.firstRun = true; // Prevents alert notifications on initial load

    this.loadContentTask = new Task(
      this,
      this._analyzeContent.bind(this),
      () => [this.previewUrl],
      { autoRun: false }
    );
  }

  /**
   * Analyzes content from the preview URL.
   * Subclasses must override this method to implement specific analysis logic.
   * 
   * @private
   * @param {Array} args - Task arguments containing [previewUrl]
   * @returns {Promise<Array<HTMLElement>|null>} The elements found or null on error
   */
  async _analyzeContent([previewUrl]) {
    throw new Error('_analyzeContent must be implemented by subclass');
  }

  /**
   * Handles loading errors with user notification.
   * 
   * @private
   * @param {Error} error - The error that occurred during loading
   */
  _handleLoadingError(error) {
    console.error('Failed to load accessibility structure:', error);
    
    Notification.notice(
      TYPO3.lang["mindfula11y.features.accessibility.error.loading"],
      TYPO3.lang["mindfula11y.features.accessibility.error.loading.description"]
    );
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
   * Renders error alerts for accessibility issues.
   *
   * @protected
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
   * @protected
   * @param {Object} error - The error to render
   * @returns {import('lit').TemplateResult} The rendered error item
   */
  _renderSingleError(error) {
    const alertClass = getSeverityClass(error.severity);
    const badgeClass = getSeverityBadgeClass(error.severity);
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

  /**
   * Renders error messages for individual components (headings, landmarks).
   * 
   * @protected
   * @param {Array<string>} errorMessages - Array of error message strings
   * @returns {import('lit').TemplateResult|string} The rendered error messages or empty string
   */
  _renderIndividualErrors(errorMessages) {
    if (!errorMessages || errorMessages.length === 0) {
      return '';
    }

    return html`
      <ul class="list-unstyled mt-0 mb-2 small text-danger">
        ${errorMessages.map(message => html`<li>${message}</li>`)}
      </ul>
    `;
  }
}

export default AccessibilityStructureBase;
