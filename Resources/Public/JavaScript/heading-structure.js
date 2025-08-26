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
import { html, css } from "lit";
import HeadingType from "./heading-type.js";
import AccessibilityStructureBase from "./accessibility-structure-base.js";
import { ERROR_SEVERITY } from "./types.js";

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
 * @extends AccessibilityStructureBase
 */
export class HeadingStructure extends AccessibilityStructureBase {
  /**
   * Constants for heading validation.
   */
  static get HEADING_CONSTANTS() {
    return {
      MIN_HEADING_LEVEL: 1,
      MAX_HEADING_LEVEL: 6,
      ROOT_PARENT_LEVEL: 0,
    };
  }

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
   * Inherits the task system from AccessibilityStructureBase for loading and analyzing headings.
   */
  constructor() {
    super(); // This initializes the base class task system
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
      ${this.loadContentTask.render({
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
            ${TYPO3.lang[
              "mindfula11y.features.headingStructure.noHeadings.description"
            ]}
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
    if (!headings || headings.length === 0) {
      return [];
    }

    const state = this._initializeTreeBuildingState();

    headings.forEach((element) => {
      const headingData = this._analyzeHeadingElement(
        element,
        state.parentStack
      );
      const node = this._processHeadingForTree(headingData, state);
      this._addNodeToTree(node, state.parentStack, state.rootNodes);
    });

    return state.rootNodes;
  }

  /**
   * Initializes the state object for tree building.
   *
   * @private
   * @returns {Object} Initial state for tree building
   */
  _initializeTreeBuildingState() {
    return {
      rootNodes: [],
      parentStack: [],
      skippedCombinations: new Map(), // parentLevel -> Set<childLevel>
    };
  }

  /**
   * Analyzes a heading element to extract all relevant data.
   *
   * @private
   * @param {HTMLElement} element - The heading element
   * @param {Array} parentStack - Current parent stack
   * @returns {Object} Comprehensive heading analysis data
   */
  _analyzeHeadingElement(element, parentStack) {
    const level = this._extractHeadingLevel(element);
    const parentLevel = this._findHierarchicalParentLevel(level, parentStack);
    const skippedLevels = this._calculateSkippedLevels(level, parentStack);
    const errorReasons = this._getHeadingErrors(element);

    return {
      element,
      level,
      parentLevel,
      skippedLevels,
      errorReasons,
    };
  }

  /**
   * Processes heading data to determine error status and create the tree node.
   *
   * @private
   * @param {Object} headingData - Data from _analyzeHeadingElement
   * @param {Object} state - Tree building state
   * @returns {Object} The processed heading tree node
   */
  _processHeadingForTree(headingData, state) {
    const { element, level, parentLevel, skippedLevels, errorReasons } =
      headingData;

    const skippedLevelStatus = this._determineSkippedLevelStatus(
      level,
      parentLevel,
      skippedLevels,
      state.skippedCombinations
    );

    if (skippedLevelStatus.shouldFlag) {
      errorReasons.push("skippedLevel");
    }

    return this._createHeadingNode(
      element,
      level,
      skippedLevelStatus.visualSkips,
      errorReasons
    );
  }

  /**
   * Finds the hierarchical parent level for a given heading level.
   *
   * @private
   * @param {number} currentLevel - The current heading level
   * @param {Array} parentStack - Stack of parent headings
   * @returns {number} The hierarchical parent level (0 if no parent)
   */
  _findHierarchicalParentLevel(currentLevel, parentStack) {
    // Iterate backwards to find the closest parent with a lower level
    for (let i = parentStack.length - 1; i >= 0; i--) {
      const parentLevel = parentStack[i].level;
      if (parentLevel < currentLevel) {
        return parentLevel;
      }
    }
    return HeadingStructure.HEADING_CONSTANTS.ROOT_PARENT_LEVEL;
  }

  /**
   * Determines if a heading should be flagged for skipped levels and calculates visual skips.
   *
   * @private
   * @param {number} level - Current heading level
   * @param {number} parentLevel - Hierarchical parent level
   * @param {number} directSkips - Number of directly skipped levels
   * @param {Map} skippedCombinations - Map tracking problematic combinations
   * @returns {Object} Object with shouldFlag and visualSkips properties
   */
  _determineSkippedLevelStatus(
    level,
    parentLevel,
    directSkips,
    skippedCombinations
  ) {
    // Case 1: Direct skip - this heading skips levels from its parent
    if (directSkips > 0) {
      this._trackSkippedCombination(parentLevel, level, skippedCombinations);
      return { shouldFlag: true, visualSkips: directSkips };
    }

    // Case 2: Same problematic combination - flag with calculated skips for visual consistency
    if (this._isSkippedCombination(parentLevel, level, skippedCombinations)) {
      const calculatedSkips = level - parentLevel - 1;
      return { shouldFlag: true, visualSkips: calculatedSkips };
    }

    // Case 3: No skipped levels
    return { shouldFlag: false, visualSkips: 0 };
  }

  /**
   * Tracks a parent-child level combination as having skipped levels.
   *
   * @private
   * @param {number} parentLevel - The parent level
   * @param {number} childLevel - The child level that skips
   * @param {Map} skippedCombinations - Map to update
   */
  _trackSkippedCombination(parentLevel, childLevel, skippedCombinations) {
    if (!skippedCombinations.has(parentLevel)) {
      skippedCombinations.set(parentLevel, new Set());
    }
    skippedCombinations.get(parentLevel).add(childLevel);
  }

  /**
   * Checks if a parent-child level combination is known to have skipped levels.
   *
   * @private
   * @param {number} parentLevel - The parent level
   * @param {number} childLevel - The child level
   * @param {Map} skippedCombinations - Map of tracked combinations
   * @returns {boolean} True if this combination has skipped levels
   */
  _isSkippedCombination(parentLevel, childLevel, skippedCombinations) {
    return (
      skippedCombinations.has(parentLevel) &&
      skippedCombinations.get(parentLevel).has(childLevel)
    );
  }

  /**
   * Creates a heading tree node with the given properties.
   *
   * @private
   * @param {HTMLElement} element - The heading element
   * @param {number} level - The heading level
   * @param {number} skippedLevels - Number of skipped levels for visual display
   * @param {Array<string>} errorReasons - Array of error reason keys
   * @returns {Object} The heading tree node
   */
  _createHeadingNode(element, level, skippedLevels, errorReasons) {
    return {
      element,
      level,
      children: [],
      skippedLevels,
      hasError: errorReasons.length > 0,
      errorReasons,
    };
  }

  /**
   * Adds a node to the tree structure and updates the parent stack.
   *
   * @private
   * @param {Object} node - The heading node to add
   * @param {Array} parentStack - Stack of parent headings to modify
   * @param {Array} rootNodes - Root nodes array to modify if needed
   */
  _addNodeToTree(node, parentStack, rootNodes) {
    // Update parent stack first to determine proper nesting
    this._updateParentStack(node.level, parentStack);

    // Add to appropriate parent or root
    if (parentStack.length === 0) {
      rootNodes.push(node);
    } else {
      parentStack[parentStack.length - 1].children.push(node);
    }

    // Add current node to parent stack
    parentStack.push(node);
  }

  /**
   * Gets specific errors for a heading element.
   *
   * @private
   * @param {HTMLElement} element - The heading element
   * @returns {Array<string>} Array of error message keys
   */
  _getHeadingErrors(element) {
    const errors = [];

    // Check for empty heading
    if (this._isEmptyHeading(element)) {
      errors.push("emptyHeading");
    }

    // Check for multiple H1s
    if (this._isMultipleH1(element)) {
      errors.push("multipleH1");
    }

    return errors;
  }

  /**
   * Checks if a heading element is empty.
   *
   * @private
   * @param {HTMLElement} element - The heading element
   * @returns {boolean} True if the heading is empty
   */
  _isEmptyHeading(element) {
    const text = element.innerText?.trim() || "";
    return text.length === 0;
  }

  /**
   * Checks if a heading is one of multiple H1 elements.
   *
   * @private
   * @param {HTMLElement} element - The heading element
   * @returns {boolean} True if this is a duplicate H1
   */
  _isMultipleH1(element) {
    if (element.tagName !== "H1") {
      return false;
    }

    const allH1s = element.ownerDocument.querySelectorAll("h1");
    return allH1s.length > 1;
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
    if (tagName.startsWith("h") && tagName.length === 2) {
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
    const expectedLevel = this._getExpectedHeadingLevel(parentStack);
    return Math.max(0, currentLevel - expectedLevel);
  }

  /**
   * Gets the expected next heading level based on the parent stack.
   *
   * @private
   * @param {Array} parentStack - Stack of parent headings
   * @returns {number} The expected next heading level
   */
  _getExpectedHeadingLevel(parentStack) {
    if (parentStack.length === 0) {
      return HeadingStructure.HEADING_CONSTANTS.MIN_HEADING_LEVEL; // First heading should be H1
    }

    const lastParentLevel = parentStack[parentStack.length - 1].level;
    return lastParentLevel + 1; // Next level should be parent + 1
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
    const hasError = node.errorReasons && node.errorReasons.length > 0;
    const errorMessages = hasError
      ? this._getErrorMessages(node.errorReasons)
      : [];
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
    const errorMessages = this._getErrorMessages(node.errorReasons || []);

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
   * Handles heading type change events by reloading the structure.
   *
   * @private
   * @param {CustomEvent} event - The heading type change event
   */
  _handleHeadingTypeChange(event) {
    // Reload the entire structure to reflect changes
    this.loadContentTask.run();
  }

  /**
   * Selects heading elements from HTML content.
   * Implementation of abstract method from AccessibilityStructureBase.
   *
   * @private
   * @param {string} htmlString - The HTML string to parse
   * @returns {Array<HTMLElement>} Array of heading elements
   */
  _selectElements(htmlString) {
    const parser = new DOMParser();
    return Array.from(
      parser
        .parseFromString(htmlString, "text/html")
        .querySelectorAll("h1, h2, h3, h4, h5, h6")
    );
  }

  /**
   * Builds a list of accessibility errors for the heading structure.
   *
   * @private
   * @param {Array<HTMLElement>} headings - Array of heading elements
   * @returns {Array<HeadingStructureError>} Array of error objects
   */
  _buildErrorList(headings) {
    if (!headings || headings.length === 0) {
      return [];
    }

    const errors = [];
    const h1Elements = headings.filter((h) => h.tagName === "H1");

    // Check for missing H1
    this._checkMissingH1(h1Elements, errors);

    // Check for multiple H1 elements
    this._checkMultipleH1(h1Elements, errors);

    // Check for empty headings
    this._checkEmptyHeadings(headings, errors);

    // Check for skipped levels
    this._checkSkippedLevels(headings, errors);

    return errors;
  }

  /**
   * Checks for missing H1 element and adds error if needed.
   *
   * @private
   * @param {Array<HTMLElement>} h1Elements - Array of H1 elements (pre-filtered)
   * @param {Array} errors - Error array to modify
   */
  _checkMissingH1(h1Elements, errors) {
    if (h1Elements.length === 0) {
      errors.push(
        this._createError(
          ERROR_SEVERITY.ERROR,
          1, // Missing H1 is always a single issue
          "mindfula11y.features.headingStructure.error.missingH1",
          "mindfula11y.features.headingStructure.error.missingH1.description"
        )
      );
    }
  }

  /**
   * Checks for multiple H1 elements and adds warning if needed.
   *
   * @private
   * @param {Array<HTMLElement>} h1Elements - Array of H1 elements (pre-filtered)
   * @param {Array} errors - Error array to modify
   */
  _checkMultipleH1(h1Elements, errors) {
    if (h1Elements.length > 1) {
      const extraH1Count = h1Elements.length - 1; // Count additional H1s beyond the first
      errors.push(
        this._createError(
          ERROR_SEVERITY.WARNING,
          extraH1Count,
          "mindfula11y.features.headingStructure.error.multipleH1",
          "mindfula11y.features.headingStructure.error.multipleH1.description"
        )
      );
    }
  }

  /**
   * Checks for empty headings and adds error if needed.
   *
   * @private
   * @param {Array<HTMLElement>} headings - Array of heading elements
   * @param {Array} errors - Error array to modify
   */
  _checkEmptyHeadings(headings, errors) {
    const emptyHeadings = headings.filter((h) => {
      const text = h.innerText?.trim() || "";
      return text.length === 0;
    });

    if (emptyHeadings.length > 0) {
      errors.push(
        this._createError(
          ERROR_SEVERITY.ERROR,
          emptyHeadings.length, // Count total number of empty headings
          "mindfula11y.features.headingStructure.error.emptyHeadings",
          "mindfula11y.features.headingStructure.error.emptyHeadings.description"
        )
      );
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
    let skippedLocationCount = 0;

    const countSkippedLocations = (nodes) => {
      nodes.forEach((node) => {
        if (node.skippedLevels > 0) {
          skippedLocationCount++; // Count locations where skipping occurs, not total skipped levels
        }
        if (node.children && node.children.length > 0) {
          countSkippedLocations(node.children);
        }
      });
    };

    countSkippedLocations(headingTree);

    if (skippedLocationCount > 0) {
      errors.push(
        this._createError(
          ERROR_SEVERITY.ERROR,
          skippedLocationCount, // Count number of locations where levels are skipped
          "mindfula11y.features.headingStructure.error.skippedLevel",
          "mindfula11y.features.headingStructure.error.skippedLevel.description"
        )
      );
    }
  }

  /**
   * Gets the translation key for a heading error message.
   * Follows the same pattern as landmark-structure for consistency.
   *
   * @private
   * @param {string} errorKey - The error type key
   * @returns {string} The translation key
   */
  _getErrorMessageKey(errorKey) {
    const errorCalloutMap = {
      emptyHeading: "mindfula11y.features.headingStructure.error.emptyHeadings",
      multipleH1: "mindfula11y.features.headingStructure.error.multipleH1",
      skippedLevel: "mindfula11y.features.headingStructure.error.skippedLevel",
    };

    return errorCalloutMap[errorKey] || errorKey;
  }

  /**
   * Gets the severity for a heading error type.
   * Overrides the base class to provide heading-specific severity mappings.
   *
   * @protected
   * @param {string} errorKey - The error type key
   * @returns {string} The error severity
   */
  _getErrorSeverity(errorKey) {
    const severityMap = {
      emptyHeading: ERROR_SEVERITY.ERROR,
      multipleH1: ERROR_SEVERITY.WARNING,
      skippedLevel: ERROR_SEVERITY.ERROR,
      missingH1: ERROR_SEVERITY.ERROR,
    };

    return severityMap[errorKey] || ERROR_SEVERITY.ERROR;
  }
}

customElements.define("mindfula11y-heading-structure", HeadingStructure);

export default HeadingStructure;
