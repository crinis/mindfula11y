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
 * @typedef {import('./types.js').HeadingStructureError} HeadingStructureError
 */
import { LitElement, html, css } from "lit";
import { Task } from "@lit/task";
import AjaxRequest from "@typo3/core/ajax/ajax-request.js";
import Notification from "@typo3/backend/notification.js";
import HeadingType from "./heading-type.js";

/**
 * Web component for visualizing and editing the heading structure of an HTML document in TYPO3.
 * 
 * This component analyzes HTML content for heading elements (h1-h6), displays them in a 
 * hierarchical tree structure, and validates their accessibility compliance. It provides 
 * error reporting for missing H1 elements and skipped heading levels.
 * 
 * Features:
 * - Hierarchical heading tree visualization with connecting lines
 * - Accessibility error detection and reporting
 * - Inline heading type editing with AJAX persistence
 * - Bootstrap-styled error alerts and status indicators
 * - Real-time structure updates on heading changes
 *
 * @class HeadingStructure
 * @extends LitElement
 */
export class HeadingStructure extends LitElement {
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
    `;
  }

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
   * Creates an instance of HeadingStructure.
   * 
   * Initializes the component with a task for loading and analyzing headings from the preview URL.
   * The task is configured to not auto-run to give control over when analysis happens.
   */
  constructor() {
    super();
    this.previewUrl = "";
    this.firstRun = true; // Prevents alert notifications on initial load

    this.loadHeadingsTask = new Task(
      this,
      this._analyzeHeadings.bind(this),
      () => [this.previewUrl],
      { autoRun: false }
    );
  }

  /**
   * Analyzes headings from the preview URL.
   * 
   * @private
   * @param {Array} args - Task arguments containing [previewUrl]
   * @returns {Promise<NodeListOf<HTMLElement>|null>} The headings found or null on error
   */
  async _analyzeHeadings([previewUrl]) {
    try {
      const previewHtml = await this._fetchPreview(previewUrl);
      return this._selectHeadings(previewHtml);
    } catch (error) {
      this._handleLoadingError(error);
      return null;
    }
  }

  /**
   * Handles loading errors with user notification.
   * 
   * @private
   * @param {Error} error - The error that occurred during loading
   */
  _handleLoadingError(error) {
    console.error('Failed to load heading structure:', error);
    
    Notification.notice(
      TYPO3.lang["mindfula11y.features.headingStructure.error.loading"],
      TYPO3.lang["mindfula11y.features.headingStructure.error.loading.description"]
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
   * Renders the heading structure component, including errors and the heading tree.
   *
   * @returns {import('lit').TemplateResult} The rendered template for the component.
   */
  render() {
    return html`
      <style>
        ${this.constructor.styles}
      </style>
      ${this.loadHeadingsTask.render({
        complete: (headings) => {
          if (this.firstRun) {
            this.firstRun = false;
          }
          
          const headingArray = Array.from(headings || []);
          const errors = this._buildErrorList(headingArray);
          const headingTree = this._buildHeadingTree(headingArray);
          
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
            ${TYPO3.lang["mindfula11y.features.headingStructure.noHeadings.description"]}
          </p>
        </div>
      `;
    }
    
    return this._renderHeadingTree(headingTree);
  }

  /**
   * Builds a tree structure from a flat array of heading elements.
   * Also detects skipped heading levels for accessibility validation.
   *
   * @private
   * @param {Array<HTMLElement>} headings - Array of heading elements
   * @returns {Array<HeadingTreeNode>} Tree structure of headings
   */
  _buildHeadingTree(headings) {
    const rootNodes = [];
    const parentStack = [];

    headings.forEach((element) => {
      const level = this._extractHeadingLevel(element);
      const skippedLevels = this._calculateSkippedLevels(level, parentStack);
      
      // Update parent stack for proper nesting
      this._updateParentStack(level, parentStack);

      const node = {
        element,
        level,
        children: [],
        skippedLevels,
        hasError: skippedLevels > 0
      };

      // Add to appropriate parent or root
      if (parentStack.length === 0) {
        rootNodes.push(node);
      } else {
        parentStack[parentStack.length - 1].children.push(node);
      }

      parentStack.push(node);
    });

    return rootNodes;
  }

  /**
   * Extracts the heading level from an HTML element.
   * 
   * @private
   * @param {HTMLElement} element - The heading element
   * @returns {number} The heading level (1-6)
   */
  _extractHeadingLevel(element) {
    const tagName = element.tagName.toLowerCase();
    if (tagName.startsWith('h') && tagName.length === 2) {
      return parseInt(tagName.charAt(1), 10);
    }
    return 0; // Not a heading
  }

  /**
   * Calculates skipped heading levels for accessibility validation.
   * 
   * @private
   * @param {number} currentLevel - The current heading level
   * @param {Array} parentStack - Stack of parent headings
   * @returns {number} Number of skipped levels
   */
  _calculateSkippedLevels(currentLevel, parentStack) {
    if (parentStack.length > 0) {
      const parentLevel = parentStack[parentStack.length - 1].level;
      return currentLevel > parentLevel + 1 ? currentLevel - parentLevel - 1 : 0;
    }
    
    // No parent: if not h1, treat as skipped levels
    return currentLevel > 1 ? currentLevel - 1 : 0;
  }

  /**
   * Updates the parent stack for proper tree nesting.
   * 
   * @private
   * @param {number} currentLevel - The current heading level
   * @param {Array} parentStack - Stack of parent headings to modify
   */
  _updateParentStack(currentLevel, parentStack) {
    // Remove parents that are at the same level or deeper
    while (
      parentStack.length &&
      parentStack[parentStack.length - 1].level >= currentLevel
    ) {
      parentStack.pop();
    }
  }

  /**
   * Recursively renders the heading tree as nested lists with connecting lines.
   * 
   * @private
   * @param {Array<HeadingTreeNode>} nodes - The heading tree nodes
   * @param {boolean} [isRoot=true] - Whether this is the root level
   * @returns {import('lit').TemplateResult|null} The rendered heading tree
   */
  _renderHeadingTree(nodes, isRoot = true) {
    if (!nodes || !nodes.length) return null;

    return html`
      <ol class="${isRoot ? 'mindfula11y-tree list-unstyled' : 'list-unstyled'}">
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
    const hasError = node.skippedLevels > 0;
    let content = html`
      <li class="mindfula11y-tree__node${hasError ? ' mindfula11y-tree__node--error' : ''}">
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
              ${TYPO3.lang["mindfula11y.features.headingStructure.error.skippedLevel.inline"]?.replace("%1$d", skippedLevel)}
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

    return html`
      <mindfula11y-heading-type
        class="d-flex align-items-center gap-3 py-2 ${node.hasError ? 'border-start border-danger border-3 ps-2' : ''}"
        .type="${node.element.dataset.mindfula11yType || node.element.tagName.toLowerCase()}"
        .availableTypes="${availableTypes}"
        recordTableName="${node.element.dataset.mindfula11yRecordTableName || ''}"
        recordColumnName="${node.element.dataset.mindfula11yRecordColumnName || ''}"
        recordUid="${node.element.dataset.mindfula11yRecordUid || 0}"
        recordEditLink="${node.element.dataset.mindfula11yRecordEditLink || ''}"
        .hasError="${node.hasError}"
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
    return element.innerText?.trim() || 
           TYPO3.lang["mindfula11y.features.headingStructure.unlabeled"];
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
      console.warn('Failed to parse available types:', error);
      return {};
    }
  }

  /**
   * Handles heading type change events by reloading the structure.
   * 
   * @private
   * @param {CustomEvent} event - The heading type change event
   */
  _handleHeadingTypeChange(event) {
    // Reload the entire structure to reflect changes
    this.loadHeadingsTask.run();
  }

  /**
   * Selects heading elements from HTML content.
   *
   * @private
   * @param {string} htmlString - The HTML string to parse
   * @returns {NodeListOf<HTMLElement>} NodeList of heading elements
   */
  _selectHeadings(htmlString) {
    const parser = new DOMParser();
    return parser
      .parseFromString(htmlString, "text/html")
      .querySelectorAll("h1, h2, h3, h4, h5, h6");
  }

  /**
   * Fetches preview content from the server with proper headers.
   *
   * @private
   * @returns {Promise<string>} The HTML content
   */
  async _fetchPreview() {
    const response = await new AjaxRequest(this.previewUrl).get({
      headers: {
        "Mindfula11y-Structure-Analysis": "1",
      },
    });

    return await response.resolve();
  }

  /**
   * Builds a list of accessibility errors for the heading structure.
   *
   * @private
   * @param {Array<HTMLElement>} headings - Array of heading elements
   * @returns {Array<HeadingStructureError>} Array of error objects
   */
  _buildErrorList(headings) {
    const errors = [];
    
    // Check for missing H1
    this._checkMissingH1(headings, errors);
    
    // Check for skipped levels
    this._checkSkippedLevels(headings, errors);
    
    return errors;
  }

  /**
   * Checks for missing H1 element and adds error if needed.
   * 
   * @private
   * @param {Array<HTMLElement>} headings - Array of heading elements
   * @param {Array} errors - Error array to modify
   */
  _checkMissingH1(headings, errors) {
    const hasH1 = headings.some((h) => h.tagName === "H1");
    
    if (!hasH1) {
      errors.push({
        count: 1,
        title: TYPO3.lang["mindfula11y.features.headingStructure.error.missingH1"],
        description: TYPO3.lang["mindfula11y.features.headingStructure.error.missingH1.description"]
      });
    }
  }

  /**
   * Checks for skipped heading levels and adds error if needed.
   * 
   * @private
   * @param {Array<HTMLElement>} headings - Array of heading elements
   * @param {Array} errors - Error array to modify
   */
  _checkSkippedLevels(headings, errors) {
    const headingTree = this._buildHeadingTree(headings);
    let skippedErrorCount = 0;
    
    const countSkippedHeadings = (nodes) => {
      nodes.forEach((node) => {
        if (node.skippedLevels > 0) {
          skippedErrorCount++;
        }
        if (node.children && node.children.length > 0) {
          countSkippedHeadings(node.children);
        }
      });
    };
    
    countSkippedHeadings(headingTree);

    if (skippedErrorCount > 0) {
      errors.push({
        count: skippedErrorCount,
        title: TYPO3.lang["mindfula11y.features.headingStructure.error.skippedLevel"],
        description: TYPO3.lang["mindfula11y.features.headingStructure.error.skippedLevel.description"]
      });
    }
  }

  /**
   * Renders error alerts for accessibility issues.
   *
   * @private
   * @param {Array<HeadingStructureError>} errors - Array of error objects
   * @returns {import('lit').TemplateResult} Rendered error section
   */
  _renderErrors(errors) {
    if (errors.length === 0) {
      return html``;
    }

    return html`
      <section
        class="mindfula11y-heading-structure__errors"
        role="${this.firstRun ? '' : 'alert'}"
      >
        <ul class="list-unstyled">
          ${errors.map(error => this._renderSingleError(error))}
        </ul>
      </section>
    `;
  }

  /**
   * Renders a single error item.
   * 
   * @private
   * @param {HeadingStructureError} error - The error to render
   * @returns {import('lit').TemplateResult} The rendered error item
   */
  _renderSingleError(error) {
    return html`
      <li class="alert alert-danger">
        <p class="lead mb-2">
          ${error.title}
          <span class="badge rounded-pill">${error.count}</span>
        </p>
        <p class="mb-0">${error.description}</p>
      </li>
    `;
  }
}

customElements.define("mindfula11y-heading-structure", HeadingStructure);

export default HeadingStructure;
