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
 * @file landmark-structure.js
 * @description Web component for visualizing and editing the landmark structure of an HTML document in TYPO3.
 * @typedef {import('./types.js').LandmarkData} LandmarkData
 * @typedef {import('./types.js').LandmarkStructureError} LandmarkStructureError
 */
import { html, css } from "lit";
import LandmarkBox from "./landmark-box.js";
import AccessibilityStructureBase from "./accessibility-structure-base.js";
import {
  LANDMARK_ROLES,
  LANDMARK_ERROR_TYPES,
  LANDMARK_LABEL_KEYS,
  ERROR_SEVERITY,
} from "./types.js";

/**
 * Web component for visualizing and editing the landmark structure of an HTML document in TYPO3.
 *
 * This component analyzes HTML content for ARIA landmarks and semantic HTML elements,
 * displays them in a hierarchical structure, and validates their accessibility compliance.
 * It provides error reporting for missing, duplicate, or improperly labeled landmarks.
 *
 * @class LandmarkStructure
 * @extends AccessibilityStructureBase
 */
export class LandmarkStructure extends AccessibilityStructureBase {
  /**
   * CSS styles for the component.
   *
   * @returns {import('lit').CSSResult} The CSSResult for the component styles.
   */
  static get styles() {
    return css`
      .mindfula11y-landmark-structure__errors + .mindfula11y-landmark-boxes {
        margin-block-start: 1.5rem;
      }
    `;
  }

  /**
   * Component properties.
   *
   * @property {string} previewUrl - The URL to fetch the content to analyze.
   * @returns {Object} The properties definition object for LitElement.
   */
  static get properties() {
    return {
      previewUrl: { type: String },
    };
  }

  /**
   * Creates an instance of LandmarkStructure.
   *
   * Inherits the task system from AccessibilityStructureBase for loading and analyzing landmarks.
   */
  constructor() {
    super(); // This initializes the base class task system
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
   * Renders the landmark structure component, including errors and the landmark boxes.
   *
   * @returns {import('lit').TemplateResult} The rendered template for the component.
   */
  render() {
    return html`
      <style>
        ${this.constructor.styles}
      </style>
      ${this.loadContentTask.render({
        complete: (landmarkElements) => {
          if (this.firstRun) {
            this.firstRun = false;
          }
          const landmarkData = this.buildLandmarkList(landmarkElements || []);

          // Run error checking on the landmark data to attach error reasons
          const errors = this._buildErrorList(Array.from(landmarkElements || []));

          return html`
            ${this._renderErrors(errors)}
            ${this._renderLandmarkContent(landmarkData)}
          `;
        },
      })}
    `;
  }

  /**
   * Renders the main landmark content or no-landmarks message.
   *
   * @private
   * @param {Array<LandmarkData>} landmarkData - The landmark data to render
   * @returns {import('lit').TemplateResult} The rendered landmark content
   */
  _renderLandmarkContent(landmarkData) {
    if (landmarkData.length === 0) {
      return html`
        <div class="alert alert-info">
          <strong
            >${TYPO3.lang[LANDMARK_LABEL_KEYS.UI.NO_LANDMARKS_TITLE]}</strong
          >
          <p>${TYPO3.lang[LANDMARK_LABEL_KEYS.UI.NO_LANDMARKS_DESC]}</p>
        </div>
      `;
    }

    return html`
      <div class="d-flex flex-column gap-3 mt-3">
        ${landmarkData.map((landmark) => this.renderLandmarkBox(landmark))}
      </div>
    `;
  }

  /**
   * Selects landmark elements from the HTML content.
   * Implementation of abstract method from AccessibilityStructureBase.
   *
   * @private
   * @param {string} htmlString - The HTML string to parse for landmarks.
   * @returns {Array<HTMLElement>} Array of landmark elements.
   */
  _selectElements(htmlString) {
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
    return Array.from(elements).filter(element => {
      if (element.tagName.toLowerCase() === 'section') {
        return this._hasAccessibleName(element);
      }
      return true;
    });
  }

  /**
   * Build a list of landmark data objects from elements.
   *
   * @param {Array<HTMLElement>} landmarkElements - Array of landmark elements.
   * @returns {Array<LandmarkData>} Array of landmark data objects with hierarchical structure.
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
   * @param {HTMLElement} element - The element to analyze.
   * @returns {string} The landmark role, or empty string if not a landmark.
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
      main: LANDMARK_ROLES.MAIN,
      nav: LANDMARK_ROLES.NAVIGATION,
      aside: LANDMARK_ROLES.COMPLEMENTARY,
      header: LANDMARK_ROLES.BANNER,
      footer: LANDMARK_ROLES.CONTENTINFO,
      form: LANDMARK_ROLES.FORM,
    };

    // Special case: section only becomes a landmark if it has an accessible name
    if (tagName === "section") {
      return this._hasAccessibleName(element) ? LANDMARK_ROLES.REGION : "";
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
    const ariaLabel = (element.getAttribute("aria-label") || "").trim();
    const ariaLabelledby = (
      element.getAttribute("aria-labelledby") || ""
    ).trim();
    return ariaLabel.length > 0 || ariaLabelledby.length > 0;
  }

  /**
   * Gets the accessible name for a landmark element.
   *
   * Follows the accessibility name computation algorithm by checking:
   * 1. aria-label attribute
   * 2. aria-labelledby attribute (resolving referenced elements)
   *
   * @param {HTMLElement} element - The element to analyze.
   * @returns {string} The accessible name, or empty string if none found.
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

  /**
   * Creates a unique ID for a landmark element.
   *
   * Uses the landmark role as a prefix and the record UID if available,
   * falling back to a random string for non-editable landmarks.
   *
   * @param {LandmarkData} landmarkData - The landmark data to create an ID for.
   * @returns {string} A unique ID for the landmark.
   */
  createLandmarkId(landmarkData) {
    const rolePrefix = landmarkData.role || "landmark";
    const uid =
      landmarkData.element.dataset.mindfula11yRecordUid ||
      Math.random().toString(36).substr(2, 9);
    return `mindfula11y-landmark-${rolePrefix}-${uid}`;
  }

  /**
   * Fetches the preview content from the server.
   *
   * Sends an AJAX request to the server to fetch the preview content.
   * The response is expected to be HTML content for landmark analysis.
  /**
   * Renders a single landmark box component with its nested children.
   *
   * @param {LandmarkData} landmarkData - The landmark data to render.
   * @returns {import('lit').TemplateResult} The rendered landmark box with nested children.
   */
  renderLandmarkBox(landmarkData) {
    const hasChildren = landmarkData.children?.length > 0;
    const errorMessages = this._getErrorMessages(landmarkData);
    const landmarkBoxProps = this._buildLandmarkBoxProps(
      landmarkData,
      errorMessages
    );

    if (!hasChildren) {
      return this._renderSimpleLandmark(landmarkBoxProps);
    }

    return this._renderLandmarkWithChildren(landmarkData, landmarkBoxProps);
  }

  /**
   * Gets error messages with severity for a landmark.
   *
   * @private
   * @param {LandmarkData} landmarkData - The landmark data
   * @returns {Array<Object>} Array of error message objects with severity
   */
  _getErrorMessages(landmarkData) {
    if (!landmarkData.errorReasons?.length) {
      return [];
    }

    return landmarkData.errorReasons.map((reason) => {
      const messageKey = this._getErrorMessageKey(reason);
      const message = TYPO3.lang[messageKey] || reason;
      const severity = this._getErrorSeverity(reason);

      return {
        message,
        severity,
      };
    });
  }

  /**
   * Builds properties object for landmark box component.
   *
   * @private
   * @param {LandmarkData} landmarkData - The landmark data
   * @param {Array<string>} errorMessages - Array of error messages
   * @returns {Object} Properties object for landmark box
   */
  _buildLandmarkBoxProps(landmarkData, errorMessages) {
    const { element, role, label, isEditable } = landmarkData;

    return {
      role,
      label,
      errorMessages,
      children: [],
      availableRoles: isEditable
        ? JSON.parse(element.dataset.mindfula11yAvailableRoles || "{}")
        : {},
      recordTableName: element.dataset.mindfula11yRecordTableName || "",
      recordColumnName: element.dataset.mindfula11yRecordColumnName || "",
      recordUid: element.dataset.mindfula11yRecordUid || "",
      recordEditLink: element.dataset.mindfula11yRecordEditLink || "",
      landmarkId: this.createLandmarkId(landmarkData),
    };
  }

  /**
   * Renders a simple landmark without children.
   *
   * @private
   * @param {Object} props - Landmark box properties
   * @returns {import('lit').TemplateResult} The rendered simple landmark
   */
  _renderSimpleLandmark(props) {
    return html`
      <mindfula11y-landmark-box
        .role="${props.role}"
        .label="${props.label}"
        .errorMessages=${props.errorMessages}
        .children="${props.children}"
        .availableRoles="${props.availableRoles}"
        recordTableName="${props.recordTableName}"
        recordColumnName="${props.recordColumnName}"
        recordUid="${props.recordUid}"
        recordEditLink="${props.recordEditLink}"
        landmarkId="${props.landmarkId}"
        @mindfula11y-landmark-changed="${() => this.loadContentTask.run()}"
      ></mindfula11y-landmark-box>
    `;
  }

  /**
   * Renders a landmark with nested children.
   *
   * @private
   * @param {LandmarkData} landmarkData - The landmark data
   * @param {Object} props - Landmark box properties
   * @returns {import('lit').TemplateResult} The rendered landmark with children
   */
  _renderLandmarkWithChildren(landmarkData, props) {
    const nestedChildren = landmarkData.children.map((child) =>
      this.renderLandmarkBox(child)
    );

    return html`
      <section class="mb-4">
        ${this._renderSimpleLandmark(props)}
        <div class="ms-4 mt-3">
          <div class="fw-bold text-muted text-uppercase fs-7 mb-2">
            ${TYPO3.lang[LANDMARK_LABEL_KEYS.UI.NESTED_LANDMARKS]}
          </div>
          <div class="d-flex flex-column gap-3">${nestedChildren}</div>
        </div>
      </section>
    `;
  }

  /**
   * Builds a list of errors for the landmark structure.
   * Implementation of abstract method from AccessibilityStructureBase.
   *
   * @private
   * @param {Array<HTMLElement>} landmarkElements - Array of landmark elements to check.
   * @returns {Array<LandmarkStructureError>} Array of error objects for the landmark structure.
   */
  _buildErrorList(landmarkElements) {
    // Build landmark data from elements
    const landmarkData = this.buildLandmarkList(landmarkElements || []);
    const errors = [];
    const allLandmarks = this._flattenLandmarks(landmarkData);

    // Check for various landmark validation errors
    this._checkMissingMainLandmark(allLandmarks, errors);
    this._checkDuplicateMainLandmarks(allLandmarks, errors);
    this._checkDuplicateLabels(allLandmarks, errors);
    this._checkMultipleUnlabeledSameRole(allLandmarks, errors);

    return errors;
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
   * Adds an error reason to a landmark's error list.
   *
   * @private
   * @param {LandmarkData} landmark - The landmark to add error to
   * @param {string} errorType - The error type constant
   */
  _addLandmarkError(landmark, errorType) {
    landmark.errorReasons = landmark.errorReasons || [];
    if (!landmark.errorReasons.includes(errorType)) {
      landmark.errorReasons.push(errorType);
    }
  }

  /**
   * Checks for missing main landmark and adds error if none found.
   *
   * @private
   * @param {Array<LandmarkData>} allLandmarks - All landmarks in the page
   * @param {Array} errors - Errors array to modify
   */
  _checkMissingMainLandmark(allLandmarks, errors) {
    const mainLandmarks = allLandmarks.filter(
      (l) => l.role === LANDMARK_ROLES.MAIN
    );

    if (mainLandmarks.length === 0) {
      errors.push(
        this._createError(
          ERROR_SEVERITY.ERROR,
          1, // Missing main is always a single issue
          LANDMARK_LABEL_KEYS.GLOBAL_ERROR.MISSING_MAIN,
          LANDMARK_LABEL_KEYS.GLOBAL_ERROR.MISSING_MAIN_DESC
        )
      );
    }
  }

  /**
   * Checks for duplicate main landmarks and adds errors.
   *
   * @private
   * @param {Array<LandmarkData>} allLandmarks - All landmarks in the page
   * @param {Array} errors - Errors array to modify
   */
  _checkDuplicateMainLandmarks(allLandmarks, errors) {
    const mainLandmarks = allLandmarks.filter(
      (l) => l.role === LANDMARK_ROLES.MAIN
    );

    if (mainLandmarks.length > 1) {
      mainLandmarks.forEach((landmark) => {
        this._addLandmarkError(landmark, LANDMARK_ERROR_TYPES.DUPLICATE_MAIN);
      });

      const extraMainCount = mainLandmarks.length - 1; // Count additional main landmarks beyond the first
      errors.push(
        this._createError(
          ERROR_SEVERITY.ERROR,
          extraMainCount,
          "mindfula11y.features.landmarkStructure.error.duplicateLandmark",
          LANDMARK_LABEL_KEYS.GLOBAL_ERROR.DUPLICATE_LANDMARK_DESC
        )
      );
    }
  }

  /**
   * Checks for landmarks with duplicate labels and adds errors.
   *
   * @private
   * @param {Array<LandmarkData>} allLandmarks - All landmarks in the page
   * @param {Array} errors - Errors array to modify
   */
  _checkDuplicateLabels(allLandmarks, errors) {
    // Group landmarks by their non-empty labels (regardless of role)
    const labelGroups = allLandmarks.reduce((acc, landmark) => {
      const label = (landmark.label || "").trim();
      if (label.length === 0) return acc;

      (acc[label] = acc[label] || []).push(landmark);
      return acc;
    }, {});

    // Find groups with multiple landmarks sharing the same label
    const duplicateGroups = Object.entries(labelGroups).filter(
      ([label, group]) => group.length >= 2
    );

    duplicateGroups.forEach(([label, group]) => {
      group.forEach((landmark) => {
        this._addLandmarkError(
          landmark,
          LANDMARK_ERROR_TYPES.DUPLICATE_ROLE_SAME_LABEL
        );
      });
    });

    if (duplicateGroups.length > 0) {
      errors.push(
        this._createError(
          ERROR_SEVERITY.ERROR,
          duplicateGroups.length, // Count number of label groups that have duplicates
          "mindfula11y.features.landmarkStructure.error.duplicateSameLabel",
          LANDMARK_LABEL_KEYS.GLOBAL_ERROR.DUPLICATE_SAME_LABEL_DESC
        )
      );
    }
  }

  /**
   * Checks for multiple unlabeled landmarks of the same role and adds errors.
   *
   * @private
   * @param {Array<LandmarkData>} allLandmarks - All landmarks in the page
   * @param {Array} errors - Errors array to modify
   */
  _checkMultipleUnlabeledSameRole(allLandmarks, errors) {
    // Group landmarks by role
    const roleGroups = allLandmarks.reduce((acc, landmark) => {
      if (!landmark.role) return acc;

      (acc[landmark.role] = acc[landmark.role] || []).push(landmark);
      return acc;
    }, {});

    let unlabeledGroupCount = 0;

    // Check each role group for multiple unlabeled landmarks
    Object.entries(roleGroups).forEach(([role, group]) => {
      // Skip main role (handled by duplicate main check) and single-landmark groups
      if (role === LANDMARK_ROLES.MAIN || group.length < 2) return;

      const unlabeledLandmarks = group.filter((landmark) => !landmark.label);

      if (unlabeledLandmarks.length > 1) {
        unlabeledLandmarks.forEach((landmark) => {
          this._addLandmarkError(
            landmark,
            LANDMARK_ERROR_TYPES.MULTIPLE_UNLABELED_SAME_ROLE
          );
        });

        unlabeledGroupCount++; // Count number of role groups that have multiple unlabeled landmarks
      }
    });

    if (unlabeledGroupCount > 0) {
      errors.push(
        this._createError(
          ERROR_SEVERITY.WARNING,
          unlabeledGroupCount, // Count number of role groups with this issue
          "mindfula11y.features.landmarkStructure.error.multipleUnlabeledLandmarks",
          LANDMARK_LABEL_KEYS.GLOBAL_ERROR.MULTIPLE_UNLABELED_DESC
        )
      );
    }
  }

  /**
   * Maps an error reason key to a user-friendly callout message for individual landmarks.
  /**
   * Gets the translation key for a landmark error message.
   * 
   * @private
   * @param {string} errorKey - The error type key
   * @returns {string} The translation key
   */
  _getErrorMessageKey(errorKey) {
    const errorCalloutMap = {
      [LANDMARK_ERROR_TYPES.DUPLICATE_MAIN]:
        LANDMARK_LABEL_KEYS.CALLOUT.DUPLICATE_MAIN,
      [LANDMARK_ERROR_TYPES.DUPLICATE_ROLE_SAME_LABEL]:
        LANDMARK_LABEL_KEYS.CALLOUT.DUPLICATE_ROLE_SAME_LABEL,
      [LANDMARK_ERROR_TYPES.MULTIPLE_UNLABELED_SAME_ROLE]:
        LANDMARK_LABEL_KEYS.CALLOUT.MULTIPLE_UNLABELED_SAME_ROLE,
    };

    return errorCalloutMap[errorKey] || errorKey;
  }

  /**
   * Gets the severity for a landmark error type.
   * Overrides the base class to provide landmark-specific severity mappings.
   *
   * @protected
   * @param {string} errorKey - The error type key
   * @returns {string} The error severity
   */
  _getErrorSeverity(errorKey) {
    const severityMap = {
      [LANDMARK_ERROR_TYPES.DUPLICATE_MAIN]: ERROR_SEVERITY.ERROR,
      [LANDMARK_ERROR_TYPES.DUPLICATE_ROLE_SAME_LABEL]: ERROR_SEVERITY.ERROR, // Updated to ERROR as requested
      [LANDMARK_ERROR_TYPES.MULTIPLE_UNLABELED_SAME_ROLE]:
        ERROR_SEVERITY.WARNING,
    };

    return severityMap[errorKey] || ERROR_SEVERITY.ERROR;
  }
}

// Register the custom element
customElements.define("mindfula11y-landmark-structure", LandmarkStructure);

export default LandmarkStructure;
