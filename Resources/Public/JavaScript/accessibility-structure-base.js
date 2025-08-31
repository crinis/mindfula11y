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
 * @file accessibility-structure-base.js
 * @description Base class for accessibility structure components with unified error handling.
 */
import { LitElement, html } from "lit";
import { Task } from "@lit/task";
import AjaxRequest from "@typo3/core/ajax/ajax-request.js";
import Notification from "@typo3/backend/notification.js";
import { 
  ERROR_SEVERITY, 
  createError, 
  getSeverityClass, 
  getSeverityBadgeClass, 
  getSeverityLabel 
} from "./types.js";

/**
 * Base class for accessibility structure components.
 * 
 * Provides common functionality for analyzing HTML content, detecting accessibility
 * errors, and rendering error messages with consistent styling and severity levels.
 * 
 * @class AccessibilityStructureBase
 * @extends LitElement
 */
export class AccessibilityStructureBase extends LitElement {
  /**
   * Static cache for preview content, shared across all instances.
   * Keyed by previewUrl.
   */
  /**
   * Static cache for preview content or in-flight Promises, shared across all instances.
   * Keyed by previewUrl. Value is either a string (HTML) or a Promise resolving to string.
   */
  static _previewCache = new Map();
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
   * Subclasses should override this method to implement specific analysis logic.
   * 
   * @private
   * @param {Array} args - Task arguments containing [previewUrl]
   * @returns {Promise<Array<HTMLElement>|null>} The elements found or null on error
   */
  async _analyzeContent([previewUrl]) {
    try {
      const previewHtml = await this._fetchPreview(previewUrl);
      return this._selectElements(previewHtml);
    } catch (error) {
      this._handleLoadingError(error);
      return null;
    }
  }

  /**
   * Selects elements from HTML content.
   * Subclasses should override this method to implement specific element selection.
   * 
   * @private
   * @param {string} htmlString - The HTML string to parse
   * @returns {Array<HTMLElement>} Array of elements
   */
  _selectElements(htmlString) {
    throw new Error('_selectElements must be implemented by subclass');
  }

  /**
   * Builds error list from elements.
   * Subclasses should override this method to implement specific error detection.
   * 
   * @private
   * @param {Array<HTMLElement>} elements - Array of elements to check
   * @returns {Array} Array of error objects
   */
  _buildErrorList(elements) {
    throw new Error('_buildErrorList must be implemented by subclass');
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
   * Fetches preview content from the server with proper headers, using a static cache.
   *
   * Caching and concurrency details:
   * - Uses a static Map to cache preview HTML by URL, shared across all instances.
   * - If a fetch is already in progress for a URL, returns the same in-flight Promise to all callers,
   *   ensuring only one network request is made for concurrent calls.
   * - Once the fetch completes, the resolved HTML is stored in the cache for future calls.
   * - If the cache contains the HTML, returns it immediately.
   *
   * @private
   * @returns {Promise<string>} Resolves to the preview HTML content for the current previewUrl.
   */
  async _fetchPreview() {
    // Use static cache to avoid duplicate fetches for the same URL
    const cache = AccessibilityStructureBase._previewCache;
    const url = this.previewUrl;

    if (cache.has(url)) {
      const cached = cache.get(url);
      // If cached value is a Promise (fetch in progress), return it
      if (cached && typeof cached.then === 'function') {
        return cached;
      }
      // If cached value is HTML, return it
      return cached;
    }

    // Start fetch and store the Promise immediately
    const fetchPromise = (async () => {
      const response = await new AjaxRequest(url).get({
        headers: {
          "Mindfula11y-Structure-Analysis": "1",
        },
      });
      const html = await response.resolve();
      // Replace the Promise in the cache with the resolved HTML
      cache.set(url, html);
      return html;
    })();
    cache.set(url, fetchPromise);
    return fetchPromise;
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
          ${error.title}
          <span class="badge rounded-pill ms-2">${error.count}</span>
        </p>
        <p class="mb-0">${error.description}</p>
      </li>
    `;
  }

  /**
   * Creates a standardized error object with severity.
   * 
   * @protected
   * @param {string} severity - Error severity (ERROR_SEVERITY.ERROR or ERROR_SEVERITY.WARNING)
   * @param {number} count - Number of occurrences of this error
   * @param {string} titleKey - Translation key for error title
   * @param {string} descKey - Translation key for error description
   * @returns {Object} Standardized error object
   */
  _createError(severity, count, titleKey, descKey) {
    return createError(
      severity,
      count,
      TYPO3.lang[titleKey],
      TYPO3.lang[descKey]
    );
  }

  /**
   * Gets error messages with severity for an element.
   * 
   * @protected
   * @param {Array<string>} errorKeys - Array of error type keys
   * @returns {Array<Object>} Array of error message objects with severity
   */
  _getErrorMessages(errorKeys) {
    return errorKeys.map(errorKey => {
      const messageKey = this._getErrorMessageKey(errorKey);
      return {
        message: TYPO3.lang[messageKey] || errorKey,
        severity: this._getErrorSeverity(errorKey)
      };
    });
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

  /**
   * Gets the severity for an error type.
   * Subclasses can override this to define specific severity mappings.
   * 
   * @protected
   * @param {string} errorKey - The error type key
   * @returns {string} The error severity
   */
  _getErrorSeverity(errorKey) {
    // Default mapping - subclasses can override
    const severityMap = {
      'emptyHeading': ERROR_SEVERITY.ERROR,
      'multipleH1': ERROR_SEVERITY.WARNING,
      'skippedLevel': ERROR_SEVERITY.ERROR,
      'missingH1': ERROR_SEVERITY.ERROR,
      'duplicateMain': ERROR_SEVERITY.ERROR,
      'duplicateRoleSameLabel': ERROR_SEVERITY.WARNING,
      'multipleUnlabeledSameRole': ERROR_SEVERITY.WARNING
    };
    
    return severityMap[errorKey] || ERROR_SEVERITY.ERROR;
  }

  /**
   * Gets the translation key for an error message.
   * Subclasses should override this to define specific message key mappings.
   * 
   * @protected
   * @param {string} errorKey - The error type key
   * @returns {string} The translation key
   */
  _getErrorMessageKey(errorKey) {
    // Base implementation - subclasses should override with their specific mappings
    return errorKey;
  }
}

export default AccessibilityStructureBase;
