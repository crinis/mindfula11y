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
 * @file types.js
 * @description Shared constants, types, and JSDoc typedefs for MindfulA11y web components.
 * This file centralizes all constants used across landmark components to ensure consistency
 * and reduce duplication.
 */

// ============================================================================
// SHARED CONSTANTS
// ============================================================================

/**
 * Error severity levels for accessibility validation.
 * Used to categorize and style errors consistently across components.
 *
 * @constant {Object}
 */
export const ERROR_SEVERITY = {
  ERROR: "error",
  WARNING: "warning",
};

/**
 * CSS classes for different error severity levels.
 * Maps severity levels to Bootstrap alert classes.
 *
 * @constant {Object}
 */
export const SEVERITY_CLASSES = {
  [ERROR_SEVERITY.ERROR]: "alert-danger",
  [ERROR_SEVERITY.WARNING]: "alert-warning",
};

/**
 * Badge styles for different error severity levels.
 * Used in error summaries and individual component messages.
 *
 * @constant {Object}
 */
export const SEVERITY_BADGE_CLASSES = {
  [ERROR_SEVERITY.ERROR]: "bg-danger",
  [ERROR_SEVERITY.WARNING]: "bg-warning",
};

// ============================================================================
// JSDOC TYPEDEFS
// ============================================================================

/**
 * @typedef {Object} AltTextDemand
 * @property {number} pageUid - UID of the page where the element is rendered.
 * @property {number} languageUid - UID of the language for the record.
 * @property {number} fileUid - UID of the sys_file record.
 * @property {string} signature - HMAC signature for request validation.
 */

/**
 * @typedef {Object} TreeBuildingState
 * @property {Array<HeadingTreeNode>} rootNodes - Root nodes of the tree
 * @property {Array<HeadingTreeNode>} parentStack - Stack of parent nodes for hierarchy tracking
 * @property {Map<number, Set<number>>} skippedCombinations - Map tracking parent-child level combinations that skip levels
 */

/**
 * @typedef {Object} HeadingAnalysisData
 * @property {HTMLElement} element - The heading DOM element
 * @property {number} level - The heading level (1-6)
 * @property {number} parentLevel - The hierarchical parent level
 * @property {number} skippedLevels - Number of levels skipped from parent
 * @property {Array<StructureError>} structureErrors - Errors associated with this heading
 */

// ============================================================================
// SHARED UTILITIES
// ============================================================================

/**
 * Gets the appropriate CSS class for error severity.
 *
 * @param {string} severity - Error severity level
 * @returns {string} CSS class name
 */
export function getSeverityClass(severity) {
  return SEVERITY_CLASSES[severity] || SEVERITY_CLASSES[ERROR_SEVERITY.ERROR];
}

/**
 * Gets the appropriate badge CSS class for error severity.
 *
 * @param {string} severity - Error severity level
 * @returns {string} CSS class name
 */
export function getSeverityBadgeClass(severity) {
  return (
    SEVERITY_BADGE_CLASSES[severity] ||
    SEVERITY_BADGE_CLASSES[ERROR_SEVERITY.ERROR]
  );
}

/**
 * Gets the localized severity label.
 *
 * @param {string} severity - Error severity level
 * @returns {string} Localized severity label
 */
export function getSeverityLabel(severity) {
  const key =
    severity === ERROR_SEVERITY.WARNING
      ? "mindfula11y.features.severity.warning"
      : "mindfula11y.features.severity.error";
  return TYPO3.lang[key] || severity;
}

/**
 * @typedef {Object} StructureError
 * @property {string} severity - The error severity level (ERROR_SEVERITY.ERROR or ERROR_SEVERITY.WARNING).
 * @property {string} id - The translation key for the error message.
 * @property {string} [tag] - Optional tag for categorizing errors (e.g., "headings", "landmarks").
 * @property {number} [count] - Only present on aggregated errors (added during aggregation).
 */

/**
 * @typedef {Object} LandmarkData
 * @property {HTMLElement} element - The landmark element
 * @property {string} role - The landmark role (banner, main, navigation, etc.)
 * @property {string} label - The accessible name of the landmark
 * @property {boolean} isEditable - Whether the landmark can be edited (has ViewHelper data attributes)
 * @property {boolean} hasError - Whether the landmark has validation errors
 * @property {Array<StructureError>} structureErrors - Array of StructureError objects for this landmark
 */

/**
 * @typedef {Object} HeadingTreeNode
 * @property {HTMLElement} element - The DOM element representing the heading.
 * @property {number} level - The heading level (e.g., 1 for <h1>, 2 for <h2>, etc.).
 * @property {Array<HeadingTreeNode>} children - An array of child heading nodes.
 * @property {number} skippedLevels - The number of skipped heading levels before this heading.
 * @property {boolean} hasError - Whether the heading has validation errors.
 * @property {Array<StructureError>} structureErrors - Array of StructureError objects for this heading.
 */
