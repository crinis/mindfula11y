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
 * @file landmark-structure-service.js
 * @description Service class for analyzing and building landmark structures with accessibility validation.
 * @typedef {import('./types.js').LandmarkData} LandmarkData
 * @typedef {import('./types.js').StructureError} StructureError
 */
import { ERROR_SEVERITY } from "./types.js";
import { ErrorRegistry } from "./error-registry.js";

/**
 * Service class for analyzing and building landmark structures with accessibility validation.
 *
 * This service provides comprehensive landmark analysis including:
 * - Building hierarchical landmark structures
 * - Detecting accessibility errors (missing main, duplicate main, duplicate labels, etc.)
 * - Calculating landmark relationships and nesting
 * - Providing formatted error messages
 *
 * @class LandmarkStructureService
 */
export class LandmarkStructureService {
  /**
   * Selects landmark elements from HTML content.
   *
   * @param {string} htmlString - The HTML string to parse
   * @returns {Array<HTMLElement>} Array of landmark elements
   */
  selectElements(htmlString) {
    const parser = new DOMParser();
    const doc = parser.parseFromString(htmlString, "text/html");

    // CSS selector for landmark elements - includes explicit ARIA roles and semantic HTML
    const landmarkSelector = [
      // Explicit ARIA roles
      '[role="banner"]',
      '[role="main"]',
      '[role="navigation"]',
      '[role="complementary"]',
      '[role="contentinfo"]',
      '[role="region"]',
      '[role="search"]',
      '[role="form"]',
      // Semantic HTML elements
      "main",
      "nav",
      "aside",
      "form",
      // Header/footer only when NOT nested inside sectioning content
      "header:not(article header, aside header, footer header, header header, main header, nav header, section header)",
      "footer:not(article footer, aside footer, footer footer, header footer, main footer, nav footer, section footer)",
      // Section elements (need to be filtered for valid accessible names)
      "section[aria-label]",
      "section[aria-labelledby]",
    ].join(", ");

    const elements = doc.querySelectorAll(landmarkSelector);

    // Filter out section elements that don't have valid accessible names
    return Array.from(elements).filter((element) => {
      if (element.tagName.toLowerCase() === "section") {
        return this._hasAccessibleName(element);
      }
      return true;
    });
  }

  /**
   * Builds a list of accessibility errors for the landmark structure.
   * Optimized to avoid unnecessary object creation for empty inputs.
   *
   * @param {Array<LandmarkData>} landmarkData - Array of landmark data objects
   * @returns {Array<StructureError>} Array of error objects for the landmark structure
   */
  buildErrorList(landmarkData) {
    if (!landmarkData?.length) {
      return [];
    }

    this.detectAllLandmarkErrors(landmarkData);
    return ErrorRegistry.getAggregatedErrorsByTag("landmarks");
  }
  /**
   * Detects all landmark accessibility errors and stores them in the central registry.
   *
   * @param {Array<LandmarkData>} landmarkData - Hierarchical landmark data
   */
  detectAllLandmarkErrors(landmarkData) {
    // Clear previous landmark errors before starting fresh analysis
    ErrorRegistry.clearByTag("landmarks");

    const allLandmarks = this._flattenLandmarks(landmarkData);

    // Run all validation checks
    this._validateMainLandmarkPresence(allLandmarks);
    this._validateSingleMainLandmark(allLandmarks);
    this._validateLandmarkLabelUniqueness(allLandmarks);
    this._validateUnlabeledLandmarkGroups(allLandmarks);
  }

  /**
   * Validates main landmark presence.
   *
   * @private
   * @param {Array<LandmarkData>} landmarks - Array of landmark data objects
   */
  _validateMainLandmarkPresence(landmarks) {
    const mainLandmarks = landmarks.filter((l) => l.role === "main");

    if (mainLandmarks.length === 0) {
      const missingMainError = ErrorRegistry.createError(
        "mindfula11y.features.landmarkStructure.error.missingMain",
        ERROR_SEVERITY.ERROR,
        "landmarks"
      );
      ErrorRegistry.storeErrors(document.body, [missingMainError]);
    }
  }

  /**
   * Validates that there's exactly one main landmark (not zero, not multiple).
   *
   * @private
   * @param {Array<LandmarkData>} landmarks - Array of landmark data objects
   */
  _validateSingleMainLandmark(landmarks) {
    const mainLandmarks = landmarks.filter((l) => l.role === "main");

    if (mainLandmarks.length > 1) {
      const duplicateMainError = ErrorRegistry.createError(
        "mindfula11y.features.landmarkStructure.error.duplicateMain",
        ERROR_SEVERITY.ERROR,
        "landmarks"
      );

      mainLandmarks.forEach((main) => {
        ErrorRegistry.addError(main.element, duplicateMainError);
      });
    }
  }
  /**
   * Validates landmark label uniqueness.
   *
   * @private
   * @param {Array<LandmarkData>} landmarks - Array of landmark data objects
   */
  _validateLandmarkLabelUniqueness(landmarks) {
    const labelGroups = landmarks.reduce((acc, landmark) => {
      const label = (landmark.label || "").trim();
      if (label.length === 0) return acc;
      (acc[label] = acc[label] || []).push(landmark);
      return acc;
    }, {});

    const duplicateGroups = Object.entries(labelGroups).filter(
      ([label, group]) => group.length >= 2
    );

    if (duplicateGroups.length > 0) {
      // Create an error for each duplicate landmark (count = 1 per duplicate)
      const duplicateLabelsError = ErrorRegistry.createError(
        "mindfula11y.features.landmarkStructure.error.duplicateSameLabel",
        ERROR_SEVERITY.ERROR,
        "landmarks"
      );

      duplicateGroups.forEach(([label, group]) => {
        group.forEach((landmark) => {
          ErrorRegistry.addError(landmark.element, duplicateLabelsError);
        });
      });
    }
  }

  /**
   * Validates unlabeled landmark groups.
   *
   * @private
   * @param {Array<LandmarkData>} landmarks - Array of landmark data objects
   */
  _validateUnlabeledLandmarkGroups(landmarks) {
    const roleGroups = landmarks.reduce((acc, landmark) => {
      if (!landmark.role) return acc;
      (acc[landmark.role] = acc[landmark.role] || []).push(landmark);
      return acc;
    }, {});

    const unlabeledGroups = [];
    Object.entries(roleGroups).forEach(([role, group]) => {
      if (role === "main" || group.length < 2) return;

      const unlabeledLandmarks = group.filter((landmark) => !landmark.label);
      if (unlabeledLandmarks.length > 1) {
        unlabeledGroups.push(unlabeledLandmarks);
      }
    });

    if (unlabeledGroups.length > 0) {
      // Create an error for each unlabeled landmark
      const multipleUnlabeledError = ErrorRegistry.createError(
        "mindfula11y.features.landmarkStructure.error.multipleUnlabeledLandmarks",
        ERROR_SEVERITY.WARNING,
        "landmarks"
      );

      unlabeledGroups.forEach((group) => {
        group.forEach((landmark) => {
          ErrorRegistry.addError(landmark.element, multipleUnlabeledError);
        });
      });
    }
  }

  /**
   * Flattens hierarchical landmark data into a single array.
   *
   * @private
   * @param {Array<LandmarkData>} landmarks - Hierarchical landmark data
   * @returns {Array<LandmarkData>} Flattened array of all landmarks
   */
  _flattenLandmarks(landmarks) {
    const result = [];

    const flatten = (landmarkList) => {
      landmarkList.forEach((landmark) => {
        result.push(landmark);
        if (landmark.children?.length) {
          flatten(landmark.children);
        }
      });
    };

    flatten(landmarks);
    return result;
  }

  /**
   * Builds a hierarchical list of landmark data objects from elements.
   *
   * @param {Array<HTMLElement>} landmarkElements - Array of landmark elements
   * @returns {Array<LandmarkData>} Array of landmark data objects with hierarchical structure
   */
  buildLandmarkList(landmarkElements) {
    const landmarkData = Array.from(landmarkElements).map((element) => ({
      element: element,
      role: this.getLandmarkRole(element),
      label: this.getLandmarkLabel(element),
      isEditable: element.hasAttribute("data-mindfula11y-record-uid"),
      children: [],
    }));

    // Build hierarchical structure
    const rootLandmarks = [];

    landmarkData.forEach((landmark) => {
      let parent = null;

      // Find the closest parent landmark
      let parentElement = landmark.element.parentElement;
      while (parentElement && !parent) {
        parent = landmarkData.find((l) => l.element === parentElement);
        if (!parent) {
          parentElement = parentElement.parentElement;
        }
      }

      if (parent) {
        parent.children.push(landmark);
      } else {
        rootLandmarks.push(landmark);
      }
    });

    return rootLandmarks;
  }

  /**
   * Gets the landmark role for a given element.
   *
   * Determines the role by checking explicit ARIA role attribute first,
   * then falling back to implicit roles based on HTML element type.
   *
   * @param {HTMLElement} element - The element to analyze
   * @returns {string} The landmark role, or empty string if not a landmark
   */
  getLandmarkRole(element) {
    // Explicit ARIA role takes precedence
    const explicitRole = element.getAttribute("role");
    if (explicitRole) {
      return explicitRole;
    }

    // Implicit roles based on HTML element type
    const tagName = element.tagName.toLowerCase();
    return this._getImplicitLandmarkRole(tagName, element);
  }

  /**
   * Gets the implicit landmark role for an HTML element.
   *
   * @private
   * @param {string} tagName - The lowercase tag name
   * @param {HTMLElement} element - The element to check for additional attributes
   * @returns {string} The implicit landmark role, or empty string if not a landmark
   */
  _getImplicitLandmarkRole(tagName, element) {
    const implicitRoleMap = {
      main: "main",
      nav: "navigation",
      aside: "complementary",
      header: "banner",
      footer: "contentinfo",
      form: "form",
    };

    // Special case: section only becomes a landmark if it has an accessible name
    if (tagName === "section") {
      return this._hasAccessibleName(element) ? "region" : "";
    }

    return implicitRoleMap[tagName] || "";
  }

  /**
   * Checks if an element has an accessible name via aria-label or aria-labelledby.
   *
   * @private
   * @param {HTMLElement} element - The element to check
   * @returns {boolean} True if the element has an accessible name
   */
  _hasAccessibleName(element) {
    if (!element) return false;

    // Check for aria-label
    if (element.getAttribute("aria-label")?.trim()) return true;

    // Check for aria-labelledby
    const labelledBy = element.getAttribute("aria-labelledby");
    if (labelledBy) {
      const labelElement = document.getElementById(labelledBy);
      if (labelElement?.textContent?.trim()) return true;
    }

    return false;
  }

  /**
   * Gets the accessible name for a landmark element.
   *
   * Follows the accessibility name computation algorithm by checking:
   * 1. aria-label attribute
   * 2. aria-labelledby attribute (resolving referenced elements)
   *
   * @param {HTMLElement} element - The element to analyze
   * @returns {string} The accessible name, or empty string if none found
   */
  getLandmarkLabel(element) {
    // Check aria-label first
    const ariaLabel = element.getAttribute("aria-label");
    if (ariaLabel?.trim()) {
      return ariaLabel.trim();
    }

    // Check aria-labelledby and resolve referenced elements
    const ariaLabelledby = element.getAttribute("aria-labelledby");
    if (ariaLabelledby?.trim()) {
      return this._resolveAriaLabelledBy(ariaLabelledby, element.ownerDocument);
    }

    return "";
  }

  /**
   * Resolves aria-labelledby attribute by getting text content from referenced elements.
   *
   * @private
   * @param {string} ariaLabelledby - Space-separated list of element IDs
   * @param {Document} document - The document to search for referenced elements
   * @returns {string} Combined text content from referenced elements
   */
  _resolveAriaLabelledBy(ariaLabelledby, document) {
    const ids = ariaLabelledby.trim().split(/\s+/);
    const labelTexts = ids
      .map((id) => {
        const referencedElement = document.getElementById(id);
        return referencedElement?.textContent?.trim() || "";
      })
      .filter((text) => text.length > 0);

    return labelTexts.join(" ");
  }
}

export default LandmarkStructureService;
