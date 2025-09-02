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
 * @file heading-structure.js
 * @description Web component for visualizing and editing the heading structure of an HTML document in TYPO3.
 * @typedef {import('./types.js').HeadingTreeNode} HeadingTreeNode
 * @typedef {import('./types.js').StructureError} StructureError
 */
import { html, css } from "lit";
import HeadingType from "./heading-type.js";
import AccessibilityStructureBase from "./accessibility-structure-base.js";
import HeadingStructureService from "./heading-structure-service.js";
import ContentFetcher from "./content-fetcher.js";
import { ErrorRegistry } from "./error-registry.js";
import { ERROR_SEVERITY } from "./types.js";

/**
 * Web component for visualizing and editing the heading structure of an HTML document in TYPO3.
 *
 * This component analyzes HTML content for heading elements (h1-h6), displays them in a
 * hierarchical tree structure, and validates their accessibility compliance. It provides
 * error reporting for missing H1 elements, multiple H1 elements, empty headings, and
 * skipped heading levels.
 *
 * Key features:
 * - Hierarchical heading tree visualization with connecting lines
 * - Real-time accessibility error detection and reporting
 * - Inline heading type editing with AJAX persistence
 * - Bootstrap-styled error alerts and status indicators
 * - Responsive tree layout with proper nesting indicators
 * - Integration with TYPO3 backend notification system
 *
 * Error types detected:
 * - Missing H1 element (error)
 * - Multiple H1 elements (warning)
 * - Empty heading content (error)
 * - Skipped heading levels (error)
 *
 * @class HeadingStructure
 * @extends AccessibilityStructureBase
 */
export class HeadingStructure extends AccessibilityStructureBase {

  /**
   * CSS styles for the component tree visualization.
   *
   * @returns {import('lit').CSSResult} The CSSResult for the component styles.
   */
  static get styles() {
    return css`
      .mindfula11y-tree {
        --spacing: 2.25rem;
        --radius: 0.5rem;
        --color: var(--typo3-badge-success-border-color);
        --color-error: var(--typo3-badge-danger-border-color);
        --color-warning: var(--typo3-badge-warning-border-color);
        --border-width: 4px;
      }

      .mindfula11y-heading-structure__errors + .mindfula11y-tree {
        margin-block-start: 1.5rem;
      }

      .mindfula11y-tree .mindfula11y-tree__node {
        display: block;
        position: relative;
        padding-left: calc(
          2 * var(--spacing) - var(--radius) - var(--border-width)
        );
      }

      .mindfula11y-tree ol {
        margin-left: calc(var(--radius) - var(--spacing));
        padding-left: 0;
      }

      .mindfula11y-tree ol .mindfula11y-tree__node {
        border-left: var(--border-width) solid var(--color);
      }

      .mindfula11y-tree ol .mindfula11y-tree__node:last-child {
        border-color: transparent;
      }

      .mindfula11y-tree ol .mindfula11y-tree__node::before {
        content: "";
        display: block;
        position: absolute;
        top: calc(var(--spacing) / -2);
        left: calc(-1 * var(--border-width));
        width: calc(var(--spacing) + var(--border-width));
        height: calc(var(--spacing) + 1px);
        border: solid var(--color);
        border-width: 0 0 var(--border-width) var(--border-width);
      }

      .mindfula11y-tree .mindfula11y-tree__node::after {
        content: "";
        display: block;
        position: absolute;
        top: calc(var(--spacing) / 2 - var(--radius));
        left: calc(var(--spacing) - var(--radius) - 1px);
        width: calc(2 * var(--radius));
        height: calc(2 * var(--radius));
        border-radius: 50%;
        background: var(--color);
      }

      .mindfula11y-tree ol .mindfula11y-tree__node--error {
        border-color: var(--color-error);
      }

      .mindfula11y-tree ol .mindfula11y-tree__node--error::before {
        border-color: var(--color-error);
      }

      .mindfula11y-tree .mindfula11y-tree__node--error::after {
        background: var(--color-error);
      }

      .mindfula11y-tree ol .mindfula11y-tree__node--warning {
        border-color: var(--color-warning);
      }

      .mindfula11y-tree ol .mindfula11y-tree__node--warning::before {
        border-color: var(--color-warning);
      }

      .mindfula11y-tree .mindfula11y-tree__node--warning::after {
        background: var(--color-warning);
      }
    `;
  }

  /**
   * Creates an instance of HeadingStructure.
   *
   * Inherits the task system from AccessibilityStructureBase for loading and analyzing headings.
   */
  constructor() {
    super(); // This initializes the base class task system
    this.structureService = new HeadingStructureService();
  }

  /**
   * Analyzes content from the preview URL using the heading structure service.
   *
   * @private
   * @param {Array} args - Task arguments containing [previewUrl]
   * @returns {Promise<Array<HTMLElement>|null>} The elements found or null on error
   */
  async _analyzeContent([previewUrl]) {
    try {
      const previewHtml = await ContentFetcher.fetchContent(previewUrl);
      return this.structureService.selectElements(previewHtml);
    } catch (error) {
      this._handleLoadingError(error);
      return null;
    }
  }

  /**
   * Renders the heading structure component, including errors and the heading tree.
   *
   * @returns {import('lit').TemplateResult} The rendered template for the component.
   */
  render() {
    return html`
      <style>
        ${this.constructor.styles}
      </style>
      ${this.loadContentTask.render({
        complete: (headings) => {
          if (this.firstRun) {
            this.firstRun = false;
          }

          const headingArray = Array.from(headings || []);
          const errors = this.structureService.buildErrorList(headingArray);
          const headingTree = this.structureService._buildHeadingTree(headingArray);

          return html`
            ${this._renderErrors(errors)}
            ${this._renderHeadingContent(headingTree)}
          `;
        },
      })}
    `;
  }

  /**
   * Renders the main heading content or no-headings message.
   *
   * @private
   * @param {Array<HeadingTreeNode>} headingTree - The processed heading tree
   * @returns {import('lit').TemplateResult} The rendered content
   */
  _renderHeadingContent(headingTree) {
    if (!headingTree || headingTree.length === 0) {
      return html`
        <div class="alert alert-info">
          <h4 class="alert-heading">
            ${TYPO3.lang["mindfula11y.features.headingStructure.noHeadings"]}
          </h4>
          <p class="mb-0">
            ${TYPO3.lang[
              "mindfula11y.features.headingStructure.noHeadings.description"
            ]}
          </p>
        </div>
      `;
    }

    return this._renderHeadingTree(headingTree);
  }

  _renderHeadingTree(nodes, isRoot = true) {
    if (!nodes || !nodes.length) return null;

    return html`
      <ol
        class="${isRoot ? "mindfula11y-tree list-unstyled" : "list-unstyled"}"
      >
        ${nodes.map((node) => this._renderHeadingNode(node))}
      </ol>
    `;
  }

  /**
   * Renders a single heading node with error handling for skipped levels.
   *
   * @private
   * @param {HeadingTreeNode} node - The heading node to render
   * @returns {import('lit').TemplateResult} The rendered node
   */
  _renderHeadingNode(node) {
    const errors = ErrorRegistry.getErrors(node.element);
    const hasError = errors && errors.length > 0;
    const errorMessages = hasError ? errors.map((error) => {
      return {
        message: TYPO3.lang[error.id] || error.id,
        severity: error.severity,
        count: error.count,
      };
    }) : [];
    const mostSevereError = this._getMostSevereError(errorMessages);

    // Determine the appropriate CSS class based on error severity
    let nodeClass = "mindfula11y-tree__node";
    if (mostSevereError) {
      if (mostSevereError.severity === ERROR_SEVERITY.ERROR) {
        nodeClass += " mindfula11y-tree__node--error";
      } else if (mostSevereError.severity === ERROR_SEVERITY.WARNING) {
        nodeClass += " mindfula11y-tree__node--warning";
      }
    }

    let content = html`
      <li class="${nodeClass}">
        ${this._createHeadingType(node)}
        ${this._renderHeadingTree(node.children, false)}
      </li>
    `;

    // Wrap with error indicators for skipped levels
    return this._wrapWithSkippedLevelErrors(content, node);
  }

  /**
   * Wraps content with error indicators for skipped heading levels.
   *
   * @private
   * @param {import('lit').TemplateResult} content - The content to wrap
   * @param {HeadingTreeNode} node - The heading node with potential errors
   * @returns {import('lit').TemplateResult} The wrapped content
   */
  _wrapWithSkippedLevelErrors(content, node) {
    let wrappedContent = content;

    // Add error layers for each skipped level
    for (let i = 0; i < node.skippedLevels; i++) {
      const skippedLevel = node.level - i - 1;
      wrappedContent = html`
        <li class="mindfula11y-tree__node mindfula11y-tree__node--error">
          <div class="alert alert-danger py-2 px-3 mb-2">
            <span class="fw-bold">
              ${TYPO3.lang[
                "mindfula11y.features.headingStructure.error.skippedLevel.inline"
              ]?.replace("%1$d", skippedLevel)}
            </span>
          </div>
          <ol class="list-unstyled">
            ${wrappedContent}
          </ol>
        </li>
      `;
    }

    return wrappedContent;
  }

  /**
   * Creates a heading-type component for a given heading node.
   *
   * @private
   * @param {HeadingTreeNode} node - The heading tree node
   * @returns {import('lit').TemplateResult} The heading-type component
   */
  _createHeadingType(node) {
    const availableTypes = this._parseAvailableTypes(node.element);
    const errors = ErrorRegistry.getErrors(node.element);
    const errorMessages = errors ? errors.map((error) => {
      return {
        message: TYPO3.lang[error.id] || error.id,
        severity: error.severity,
        count: error.count,
      };
    }) : [];

    return html`
      <mindfula11y-heading-type
        class="d-flex align-items-center gap-3 py-2"
        .type="${node.element.tagName.toLowerCase()}"
        .availableTypes="${availableTypes}"
        relationId="${node.element.dataset.mindfula11yRelationId || ""}"
        ancestorId="${node.element.dataset.mindfula11yAncestorId || ""}"
        siblingId="${node.element.dataset.mindfula11ySiblingId || ""}"
        recordTableName="${node.element.dataset.mindfula11yRecordTableName ||
        ""}"
        recordColumnName="${node.element.dataset.mindfula11yRecordColumnName ||
        ""}"
        recordUid="${node.element.dataset.mindfula11yRecordUid || 0}"
        recordEditLink="${node.element.dataset.mindfula11yRecordEditLink || ""}"
        .errorMessages="${errorMessages}"
        label="${this._extractHeadingLabel(node.element)}"
        @mindfula11y-heading-type-changed="${this._handleHeadingTypeChange}"
      >
      </mindfula11y-heading-type>
    `;
  }

  /**
   * Extracts and cleans the heading label text.
   *
   * @private
   * @param {HTMLElement} element - The heading element
   * @returns {string} The cleaned heading text
   */
  _extractHeadingLabel(element) {
    return (
      element.innerText?.trim() ||
      TYPO3.lang["mindfula11y.features.headingStructure.unlabeled"]
    );
  }

  /**
   * Parses available types from element dataset.
   *
   * @private
   * @param {HTMLElement} element - The heading element
   * @returns {Object} Available heading types
   */
  _parseAvailableTypes(element) {
    try {
      return JSON.parse(element.dataset.mindfula11yAvailableTypes || "{}");
    } catch (error) {
      console.warn("Failed to parse available types:", error);
      return {};
    }
  }

  /**
   * Gets the most severe error from an array of error messages.
   *
   * @private
   * @param {Array<Object>} errorMessages - Array of error message objects with severity
   * @returns {Object|null} The most severe error or null if no errors
   */
  _getMostSevereError(errorMessages) {
    if (!errorMessages || errorMessages.length === 0) {
      return null;
    }

    // ERROR severity takes precedence over WARNING
    const errorSeverityError = errorMessages.find(
      (error) => error.severity === ERROR_SEVERITY.ERROR
    );
    return errorSeverityError || errorMessages[0];
  }

  /**
   * Handles heading type change events by clearing the cache and reloading content.
   *
   * @private
   * @param {CustomEvent} event - The heading type change event
   */
  _handleHeadingTypeChange(event) {
    // Clear the cached preview content since the content has changed on the server
    if (this.previewUrl) {
      ContentFetcher.clearCache(this.previewUrl);
    }

    // Reload the entire structure to reflect changes
    this.loadContentTask.run();
  }
}

customElements.define("mindfula11y-heading-structure", HeadingStructure);

export default HeadingStructure;
