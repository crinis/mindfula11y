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
 * @file heading-structure-service.js
 * @description Service class for analyzing and building heading structures with accessibility validation.
 * @typedef {import('./types.js').HeadingTreeNode} HeadingTreeNode
 * @typedef {import('./types.js').StructureError} StructureError
 * @typedef {import('./types.js').TreeBuildingState} TreeBuildingState
 * @typedef {import('./types.js').HeadingAnalysisData} HeadingAnalysisData
 */
import { ERROR_SEVERITY } from "./types.js";
import { ErrorRegistry } from "./error-registry.js";

/**
 * Service class for analyzing and building heading structures with accessibility validation.
 *
 * This service provides comprehensive heading analysis including:
 * - Building hierarchical heading tree structures
 * - Detecting accessibility errors (missing H1, multiple H1, empty headings, skipped levels)
 * - Calculating heading relationships and nesting
 * - Providing formatted error messages with proper severity levels
 *
 * Key validation rules:
 * - Documents must have exactly one H1 element
 * - Heading levels should not skip (e.g., H1 to H3 without H2)
 * - Headings should not be empty
 * - Proper hierarchical structure should be maintained
 *
 * @class HeadingStructureService
 */
export class HeadingStructureService {
  /**
   * Selects heading elements from HTML content.
   *
   * @param {Document} doc - The parsed HTML document
   * @returns {Array<HTMLElement>} Array of heading elements (h1-h6)
   */
  selectElements(doc) {
    return Array.from(
      doc.querySelectorAll("h1, h2, h3, h4, h5, h6")
    );
  }

  /**
   * Builds a list of accessibility errors for the heading structure.
   * Optimized to avoid unnecessary object creation for empty inputs.
   *
   * @param {Array<HTMLElement>} headings - Array of heading elements
   * @returns {Array<StructureError>} Array of error objects
   */
  buildErrorList(headings) {
    if (!headings?.length) {
      return [];
    }

    this.detectAllHeadingErrors(headings);
    return ErrorRegistry.getAggregatedErrorsByTag("headings");
  }

  /**
   * Analyzes heading elements and detects all errors in a single pass.
   * Stores all errors in the central registry with unique element IDs.
   *
   * @param {Array<HTMLElement>} headings - Array of heading elements
   */
  detectAllHeadingErrors(headings) {
    // Clear previous heading errors before starting fresh analysis
    ErrorRegistry.clearByTag("headings");

    // Run all validation checks
    this._validateH1Presence(headings);
    this._validateSingleH1Requirement(headings);
    this._validateHeadingContent(headings);
    this._validateHeadingHierarchy(headings);
  }

  /**
   * Validates H1 presence in the document.
   *
   * @private
   * @param {Array<HTMLElement>} headings - Array of heading elements
   */
  _validateH1Presence(headings) {
    const h1Elements = headings.filter((h) => h.tagName === "H1");

    if (h1Elements.length === 0) {
      const missingH1Error = ErrorRegistry.createError(
        "mindfula11y.headingStructure.error.missingH1",
        ERROR_SEVERITY.ERROR,
        "headings"
      );
      ErrorRegistry.storeErrors(document.body, [missingH1Error]);
    }
  }

  /**
   * Validates that there's exactly one H1 element (not zero, not multiple).
   *
   * @private
   * @param {Array<HTMLElement>} headings - Array of heading elements
   */
  _validateSingleH1Requirement(headings) {
    const h1Elements = headings.filter((h) => h.tagName === "H1");

    if (h1Elements.length > 1) {
      const multipleH1Error = ErrorRegistry.createError(
        "mindfula11y.headingStructure.error.multipleH1",
        ERROR_SEVERITY.WARNING,
        "headings"
      );

      h1Elements.forEach((h1) => {
        ErrorRegistry.addError(h1, multipleH1Error);
      });
    }
  }

  /**
   * Validates heading content (empty headings).
   *
   * @private
   * @param {Array<HTMLElement>} headings - Array of heading elements
   */
  _validateHeadingContent(headings) {
    headings.forEach((heading) => {
      if (!heading?.textContent?.trim()) {
        const emptyHeadingError = ErrorRegistry.createError(
          "mindfula11y.headingStructure.error.emptyHeadings",
          ERROR_SEVERITY.ERROR,
          "headings"
        );
        ErrorRegistry.addError(heading, emptyHeadingError);
      }
    });
  }

  /**
   * Validates heading hierarchy (skipped levels).
   *
   * @private
   * @param {Array<HTMLElement>} headings - Array of heading elements
   */
  _validateHeadingHierarchy(headings) {
    const headingTree = this._buildHeadingTree(headings);
    const skippedCount = this._countSkippedLocations(headingTree);

    if (skippedCount > 0) {
      this._associateSkippedLevelErrors(headingTree);
    }
  }

  /**
   * Associates skipped level errors with individual headings in the tree.
   * Creates a separate error instance for each heading that has skipped levels.
   *
   * @private
   * @param {Array} nodes - Heading tree nodes
   */
  _associateSkippedLevelErrors(nodes) {
    const associateInNodes = (nodeList) => {
      nodeList.forEach((node) => {
        if (node.skippedLevels > 0) {
          // Create a separate error instance for each heading with skipped levels
          const headingError = ErrorRegistry.createError(
            "mindfula11y.headingStructure.error.skippedLevel",
            ERROR_SEVERITY.ERROR,
            "headings"
          );
          ErrorRegistry.addError(node.element, headingError);
        }
        if (node.children && node.children.length > 0) {
          associateInNodes(node.children);
        }
      });
    };

    associateInNodes(nodes);
  }

  /**
   * Counts skipped level locations in the heading tree.
   *
   * @private
   * @param {Array} nodes - Array of heading tree nodes
   * @returns {number} Number of headings with skipped levels
   */
  _countSkippedLocations(nodes) {
    let count = 0;

    const countInNodes = (nodeList) => {
      nodeList.forEach((node) => {
        if (node.skippedLevels > 0) {
          count++;
        }
        if (node.children && node.children.length > 0) {
          countInNodes(node.children);
        }
      });
    };

    countInNodes(nodes);
    return count;
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
   * @returns {TreeBuildingState} Initial state for tree building
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
   * @returns {HeadingAnalysisData} Comprehensive heading analysis data
   */
  _analyzeHeadingElement(element, parentStack) {
    const level = this._extractHeadingLevel(element);
    const parentLevel = this._findHierarchicalParentLevel(level, parentStack);
    const skippedLevels = this._calculateSkippedLevels(level, parentStack);
    const structureErrors = ErrorRegistry.getErrors(element);

    return {
      element,
      level,
      parentLevel,
      skippedLevels,
      structureErrors,
    };
  }

  /**
   * Processes heading data to determine error status and create the tree node.
   *
   * @private
   * @param {HeadingAnalysisData} headingData - Data from _analyzeHeadingElement
   * @param {TreeBuildingState} state - Tree building state
   * @returns {HeadingTreeNode} The processed heading tree node
   */
  _processHeadingForTree(headingData, state) {
    const { element, level, parentLevel, skippedLevels, structureErrors } =
      headingData;

    const skippedLevelStatus = this._determineSkippedLevelStatus(
      level,
      parentLevel,
      skippedLevels,
      state.skippedCombinations
    );

    // Note: StructureError objects for skipped levels are now handled in detectAllHeadingErrors
    // and associated with elements via _associateSkippedLevelErrors

    return this._createHeadingNode(
      element,
      level,
      skippedLevelStatus.visualSkips,
      structureErrors
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
    return 0;
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
   * @param {Array<StructureError>} structureErrors - Array of StructureError objects
   * @returns {Object} The heading tree node
   */
  _createHeadingNode(element, level, skippedLevels, structureErrors) {
    return {
      element,
      level,
      children: [],
      skippedLevels,
      hasError: structureErrors.length > 0,
      structureErrors,
    };
  }

  /**
   * Adds a node to the tree structure and updates the parent stack.
   *
   * @private
   * @param {HeadingTreeNode} node - The heading node to add
   * @param {Array<HeadingTreeNode>} parentStack - Stack of parent headings to modify
   * @param {Array<HeadingTreeNode>} rootNodes - Root nodes array to modify if needed
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
      return 1; // First heading should be H1
    }

    const lastParentLevel = parentStack[parentStack.length - 1].level;
    return lastParentLevel + 1; // Next level should be parent + 1
  }
}

export default HeadingStructureService;
