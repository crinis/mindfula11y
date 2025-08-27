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

/**
 * Standard ARIA landmark roles as defined in the WAI-ARIA specification.
 * These roles help assistive technologies understand the structure and purpose of content.
 *
 * @constant {Object}
 */
export const LANDMARK_ROLES = {
  BANNER: "banner",
  MAIN: "main",
  NAVIGATION: "navigation",
  COMPLEMENTARY: "complementary",
  CONTENTINFO: "contentinfo",
  REGION: "region",
  SEARCH: "search",
  FORM: "form",
};

/**
 * CSS classes for styling different callout types based on landmark roles.
 * Maps landmark roles to Bootstrap callout classes for visual distinction.
 *
 * @constant {Object}
 */
export const LANDMARK_CALLOUT_CLASSES = {
  ERROR: "callout-danger",
  DEFAULT: "callout-danger",
  [LANDMARK_ROLES.MAIN]: "callout-info",
  [LANDMARK_ROLES.BANNER]: "callout-primary",
  [LANDMARK_ROLES.CONTENTINFO]: "callout-secondary",
  [LANDMARK_ROLES.NAVIGATION]: "callout-success",
  [LANDMARK_ROLES.COMPLEMENTARY]: "callout-warning",
  [LANDMARK_ROLES.REGION]: "callout-info",
  [LANDMARK_ROLES.SEARCH]: "callout-primary",
  [LANDMARK_ROLES.FORM]: "callout-info",
};

/**
 * Error types for landmark validation.
 * Used to categorize different accessibility violations.
 *
 * @constant {Object}
 */
export const LANDMARK_ERROR_TYPES = {
  DUPLICATE_MAIN: "duplicateMain",
  DUPLICATE_ROLE_SAME_LABEL: "duplicateRoleSameLabel",
  MULTIPLE_UNLABELED_SAME_ROLE: "multipleUnlabeledSameRole",
};

/**
 * Translation label keys organized by component and usage context.
 * Provides centralized mapping for all translatable strings in the landmark functionality.
 *
 * @constant {Object}
 */
export const LANDMARK_LABEL_KEYS = {
  // Global error messages (displayed in error summary)
  GLOBAL_ERROR: {
    MISSING_MAIN: "mindfula11y.features.landmarkStructure.error.missingMain",
    MISSING_MAIN_DESC:
      "mindfula11y.features.landmarkStructure.error.missingMain.description",
    DUPLICATE_LANDMARK:
      "mindfula11y.features.landmarkStructure.error.duplicateLandmark",
    DUPLICATE_LANDMARK_DESC:
      "mindfula11y.features.landmarkStructure.error.duplicateLandmark.description",
    DUPLICATE_SAME_LABEL:
      "mindfula11y.features.landmarkStructure.error.duplicateSameLabel",
    DUPLICATE_SAME_LABEL_DESC:
      "mindfula11y.features.landmarkStructure.error.duplicateSameLabel.description",
    MULTIPLE_UNLABELED:
      "mindfula11y.features.landmarkStructure.error.multipleUnlabeledLandmarks",
    MULTIPLE_UNLABELED_DESC:
      "mindfula11y.features.landmarkStructure.error.multipleUnlabeledLandmarks.description",
  },

  // Individual landmark callout messages
  CALLOUT: {
    DUPLICATE_MAIN:
      "mindfula11y.features.landmarkStructure.callout.duplicateMain",
    DUPLICATE_ROLE_SAME_LABEL:
      "mindfula11y.features.landmarkStructure.callout.duplicateRoleSameLabel",
    MULTIPLE_UNLABELED_SAME_ROLE:
      "mindfula11y.features.landmarkStructure.callout.multipleUnlabeledSameRole",
  },

  // User interface labels
  UI: {
    LOADING_ERROR: "mindfula11y.features.landmarkStructure.error.loading",
    LOADING_ERROR_DESC:
      "mindfula11y.features.landmarkStructure.error.loading.description",
    NO_LANDMARKS_TITLE:
      "mindfula11y.features.landmarkStructure.noLandmarks.title",
    NO_LANDMARKS_DESC:
      "mindfula11y.features.landmarkStructure.noLandmarks.description",
    NESTED_LANDMARKS: "mindfula11y.features.landmarkStructure.nestedLandmarks",
  },

  // Component-specific labels
  COMPONENT: {
    UNLABELED_LANDMARK:
      "mindfula11y.features.landmarkStructure.unlabelledLandmark",
    ROLE_LABEL: "mindfula11y.features.landmarkStructure.role",
    ROLE_NONE: "mindfula11y.features.landmarkStructure.role.none",
    EDIT: "mindfula11y.features.landmarkStructure.edit",
    EDIT_LOCKED: "mindfula11y.features.landmarkStructure.edit.locked",
  },

  // Error handling labels
  ERROR_HANDLING: {
    STORE_FAILED: "mindfula11y.features.landmarkStructure.error.store",
    STORE_FAILED_DESC:
      "mindfula11y.features.landmarkStructure.error.store.description",
    ROLE_SELECT_ERROR:
      "mindfula11y.features.landmarkStructure.error.roleSelect",
    ROLE_CHANGE_ERROR:
      "mindfula11y.features.landmarkStructure.error.roleChange",
  },
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

// ============================================================================
// SHARED UTILITIES
// ============================================================================

/**
 * Creates a standardized error object with severity.
 *
 * @param {string} severity - Error severity (ERROR_SEVERITY.ERROR or ERROR_SEVERITY.WARNING)
 * @param {number} count - Number of occurrences
 * @param {string} title - Error title
 * @param {string} description - Error description
 * @returns {Object} Standardized error object
 */
export function createError(severity, count, title, description) {
  return {
    count,
    severity,
    title,
    description,
  };
}

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
 * @typedef {Object} HeadingStructureError
 * @property {number} count - The number of occurrences of this error type.
 * @property {string} severity - The error severity level (ERROR_SEVERITY.ERROR or ERROR_SEVERITY.WARNING).
 * @property {string} title - The error title or summary.
 * @property {string} description - The detailed error description.
 */

/**
 * @typedef {Object} LandmarkData
 * @property {HTMLElement} element - The landmark element
 * @property {string} role - The landmark role (banner, main, navigation, etc.)
 * @property {string} label - The accessible name of the landmark
 * @property {boolean} isEditable - Whether the landmark can be edited (has ViewHelper data attributes)
 * @property {boolean} hasError - Whether the landmark has validation errors
 * @property {Array<string>} errors - Array of error message keys for this landmark
 */

/**
 * @typedef {Object} LandmarkStructureError
 * @property {number} count - Number of occurrences
 * @property {string} severity - The error severity level (ERROR_SEVERITY.ERROR or ERROR_SEVERITY.WARNING).
 * @property {string} title - Error title
 * @property {string} description - Error description
 */

/**
 * @typedef {Object} HeadingTreeNode
 * @property {HTMLElement} element - The DOM element representing the heading.
 * @property {number} level - The heading level (e.g., 1 for <h1>, 2 for <h2>, etc.).
 * @property {Array<HeadingTreeNode>} children - An array of child heading nodes.
 * @property {number} skippedLevels - The number of skipped heading levels before this heading.
 * @property {boolean} hasError - Whether the heading has validation errors.
 * @property {Array<string>} errors - Array of error message keys for this heading.
 */
