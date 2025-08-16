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
import { LitElement, html, css } from "lit";
import { Task } from "@lit/task";
import AjaxRequest from "@typo3/core/ajax/ajax-request.js";
import Notification from "@typo3/backend/notification.js";
import LandmarkBox from "./landmark-box.js";
import { 
  LANDMARK_ROLES, 
  LANDMARK_ERROR_TYPES, 
  LANDMARK_LABEL_KEYS 
} from "./types.js";

/**
 * Web component for visualizing and editing the landmark structure of an HTML document in TYPO3.
 * 
 * This component analyzes HTML content for ARIA landmarks and semantic HTML elements,
 * displays them in a hierarchical structure, and validates their accessibility compliance.
 * It provides error reporting for missing, duplicate, or improperly labeled landmarks.
 *
 * @class LandmarkStructure
 * @extends LitElement
 */
export class LandmarkStructure extends LitElement {
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
   * Initializes the component with a task for loading and analyzing landmarks from the preview URL.
   * The task is configured to not auto-run to give control over when analysis happens.
   */
  constructor() {
    super();
    this.previewUrl = "";
    this.firstRun = true; // Prevents alert notifications on initial load

    this.loadLandmarksTask = new Task(
      this,
      this._analyzeLandmarks.bind(this),
      () => [this.previewUrl],
      { autoRun: false }
    );
  }

  /**
   * Analyzes landmarks from the preview URL.
   * 
   * @private
   * @param {Array} args - Task arguments containing [previewUrl]
   * @returns {Promise<NodeListOf<HTMLElement>|null>} The landmarks found or null on error
   */
  async _analyzeLandmarks([previewUrl]) {
    try {
      const previewHtml = await this.fetchPreview(previewUrl);
      return this.selectLandmarks(previewHtml);
    } catch (error) {
      Notification.notice(
        TYPO3.lang[LANDMARK_LABEL_KEYS.UI.LOADING_ERROR],
        TYPO3.lang[LANDMARK_LABEL_KEYS.UI.LOADING_ERROR_DESC]
      );
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
   * Renders the landmark structure component, including errors and the landmark boxes.
   *
   * @returns {import('lit').TemplateResult} The rendered template for the component.
   */
  render() {
    return html`
      <style>
        ${this.constructor.styles}
      </style>
      ${this.loadLandmarksTask.render({
        complete: (landmarks) => {
          if (this.firstRun) {
            this.firstRun = false;
          }
          const landmarkData = this.buildLandmarkList(Array.from(landmarks || []));
          const errors = this.buildErrorList(landmarkData);
          
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
          <strong>${TYPO3.lang[LANDMARK_LABEL_KEYS.UI.NO_LANDMARKS_TITLE]}</strong>
          <p>${TYPO3.lang[LANDMARK_LABEL_KEYS.UI.NO_LANDMARKS_DESC]}</p>
        </div>
      `;
    }

    return html`
      <div class="d-flex flex-column gap-3 mt-3">
        ${landmarkData.map(landmark => this.renderLandmarkBox(landmark))}
      </div>
    `;
  }

  /**
   * Selects landmarks from the given HTML string.
   *
   * Parses HTML content and identifies all landmark elements using both explicit ARIA roles
   * and implicit semantic HTML elements. A <section> only becomes a landmark (implicit role=region) 
   * if it has an accessible name.
   *
   * @param {string} htmlString - The HTML string to parse for landmarks.
   * @returns {NodeListOf<HTMLElement>} A NodeList of landmark elements.
   */
  selectLandmarks(htmlString) {
    const parser = new DOMParser();
    const doc = parser.parseFromString(htmlString, "text/html");
    
    // CSS selector for landmark elements - includes explicit ARIA roles and semantic HTML
    const landmarkSelector = [
      // Explicit ARIA roles
      '[role="banner"]', '[role="main"]', '[role="navigation"]', '[role="complementary"]',
      '[role="contentinfo"]', '[role="region"]', '[role="search"]', '[role="form"]',
      // Semantic HTML elements
      'main', 'nav', 'aside', 'form', 'header', 'footer',
      // Section only when labeled (becomes implicit role=region)
      'section[aria-label]', 'section[aria-labelledby]'
    ].join(', ');
    
    return doc.querySelectorAll(landmarkSelector);
  }

  /**
   * Build a list of landmark data objects from DOM elements.
   *
   * @param {Array<HTMLElement>} landmarks - Array of landmark elements.
   * @returns {Array<LandmarkData>} Array of landmark data objects with hierarchical structure.
   */
  buildLandmarkList(landmarks) {
    const landmarkData = landmarks.map(element => ({
      element,
      role: this.getLandmarkRole(element),
      label: this.getLandmarkLabel(element),
      isEditable: element.hasAttribute('data-mindfula11y-record-uid'),
      children: []
    }));

    // Build hierarchical structure
    const rootLandmarks = [];
    
    landmarkData.forEach(landmark => {
      let parent = null;
      
      // Find the closest parent landmark
      let parentElement = landmark.element.parentElement;
      while (parentElement && !parent) {
        parent = landmarkData.find(l => l.element === parentElement);
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
    const explicitRole = element.getAttribute('role');
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
      'main': LANDMARK_ROLES.MAIN,
      'nav': LANDMARK_ROLES.NAVIGATION,
      'aside': LANDMARK_ROLES.COMPLEMENTARY,
      'header': LANDMARK_ROLES.BANNER,
      'footer': LANDMARK_ROLES.CONTENTINFO,
      'form': LANDMARK_ROLES.FORM
    };

    // Special case: section only becomes a landmark if it has an accessible name
    if (tagName === 'section') {
      return this._hasAccessibleName(element) ? LANDMARK_ROLES.REGION : '';
    }

    return implicitRoleMap[tagName] || '';
  }

  /**
   * Checks if an element has an accessible name via aria-label or aria-labelledby.
   * 
   * @private
   * @param {HTMLElement} element - The element to check
   * @returns {boolean} True if the element has an accessible name
   */
  _hasAccessibleName(element) {
    const ariaLabel = (element.getAttribute('aria-label') || '').trim();
    const ariaLabelledby = (element.getAttribute('aria-labelledby') || '').trim();
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
    const ariaLabel = element.getAttribute('aria-label');
    if (ariaLabel?.trim()) {
      return ariaLabel.trim();
    }
    
    // Check aria-labelledby and resolve referenced elements
    const ariaLabelledby = element.getAttribute('aria-labelledby');
    if (ariaLabelledby?.trim()) {
      return this._resolveAriaLabelledBy(ariaLabelledby, element.ownerDocument);
    }
    
    return '';
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
      .map(id => {
        const referencedElement = document.getElementById(id);
        return referencedElement?.textContent?.trim() || '';
      })
      .filter(text => text.length > 0);
    
    return labelTexts.join(' ');
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
    const rolePrefix = landmarkData.role || 'landmark';
    const uid = landmarkData.element.dataset.mindfula11yRecordUid || 
                Math.random().toString(36).substr(2, 9);
    return `mindfula11y-landmark-${rolePrefix}-${uid}`;
  }

  /**
   * Fetches the preview content from the server.
   *
   * Sends an AJAX request to the server to fetch the preview content.
   * The response is expected to be HTML content for landmark analysis.
   *
   * @returns {Promise<string>} The HTML content of the preview.
   * @throws {Error} Throws an error if the request fails.
   */
  async fetchPreview() {
    const response = await new AjaxRequest(this.previewUrl).get({
      headers: {
        "Mindfula11y-Structure-Analysis": "1",
      },
    });

    return await response.resolve();
  }  /**
   * Renders a single landmark box component with its nested children.
   *
   * @param {LandmarkData} landmarkData - The landmark data to render.
   * @returns {import('lit').TemplateResult} The rendered landmark box with nested children.
   */
  renderLandmarkBox(landmarkData) {
    const hasChildren = landmarkData.children?.length > 0;
    const errorMessages = this._getErrorMessages(landmarkData);
    const landmarkBoxProps = this._buildLandmarkBoxProps(landmarkData, errorMessages);

    if (!hasChildren) {
      return this._renderSimpleLandmark(landmarkBoxProps);
    }

    return this._renderLandmarkWithChildren(landmarkData, landmarkBoxProps);
  }

  /**
   * Gets error messages for a landmark.
   * 
   * @private
   * @param {LandmarkData} landmarkData - The landmark data
   * @returns {Array<string>} Array of error messages
   */
  _getErrorMessages(landmarkData) {
    if (!landmarkData.errorReasons?.length) {
      return [];
    }
    
    return landmarkData.errorReasons
      .map(reason => this.mapErrorReasonToMessage(reason))
      .filter(message => message);
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
      availableRoles: isEditable ? 
        JSON.parse(element.dataset.mindfula11yAvailableRoles || '{}') : {},
      recordTableName: element.dataset.mindfula11yRecordTableName || '',
      recordColumnName: element.dataset.mindfula11yRecordColumnName || '',
      recordUid: element.dataset.mindfula11yRecordUid || '',
      recordEditLink: element.dataset.mindfula11yRecordEditLink || '',
      landmarkId: this.createLandmarkId(landmarkData)
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
        @mindfula11y-landmark-changed="${() => this.loadLandmarksTask.run()}"
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
    const nestedChildren = landmarkData.children.map(child => this.renderLandmarkBox(child));
    
    return html`
      <section class="mb-4">
        ${this._renderSimpleLandmark(props)}
        <div class="ms-4 mt-3">
          <div class="fw-bold text-muted text-uppercase fs-7 mb-2">
            ${TYPO3.lang[LANDMARK_LABEL_KEYS.UI.NESTED_LANDMARKS]}
          </div>
          <div class="d-flex flex-column gap-3">
            ${nestedChildren}
          </div>
        </div>
      </section>
    `;
  }

  /**
   * Builds a list of errors for the landmark structure.
   *
   * @param {Array<LandmarkData>} landmarkData - The list of landmark data (hierarchical).
   * @returns {Array<LandmarkStructureError>} List of error messages for the landmark structure.
   */
  buildErrorList(landmarkData) {
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
      landmarkList.forEach(landmark => {
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
   * Adds a global error to the errors array, or increments count if it already exists.
   * 
   * @private
   * @param {Array} errors - The errors array to modify
   * @param {string} titleKey - Translation key for the error title
   * @param {string} descKey - Translation key for the error description
   * @param {number} count - Number of landmarks with this error
   */
  _addGlobalError(errors, titleKey, descKey, count) {
    const title = TYPO3.lang[titleKey];
    const description = TYPO3.lang[descKey];
    const existingError = errors.find(e => e.title === title && e.description === description);
    
    if (existingError) {
      existingError.count += count;
    } else {
      errors.push({ count, title, description });
    }
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
    const mainLandmarks = allLandmarks.filter(l => l.role === LANDMARK_ROLES.MAIN);
    
    if (mainLandmarks.length === 0) {
      this._addGlobalError(
        errors,
        LANDMARK_LABEL_KEYS.GLOBAL_ERROR.MISSING_MAIN,
        LANDMARK_LABEL_KEYS.GLOBAL_ERROR.MISSING_MAIN_DESC,
        1
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
    const mainLandmarks = allLandmarks.filter(l => l.role === LANDMARK_ROLES.MAIN);
    
    if (mainLandmarks.length > 1) {
      mainLandmarks.forEach(landmark => {
        this._addLandmarkError(landmark, LANDMARK_ERROR_TYPES.DUPLICATE_MAIN);
      });
      
      this._addGlobalError(
        errors,
        LANDMARK_LABEL_KEYS.GLOBAL_ERROR.DUPLICATE_LANDMARK,
        LANDMARK_LABEL_KEYS.GLOBAL_ERROR.DUPLICATE_LANDMARK_DESC,
        mainLandmarks.length
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
    // Group landmarks by their non-empty labels
    const labelGroups = allLandmarks.reduce((acc, landmark) => {
      const label = (landmark.label || '').trim();
      if (label.length === 0) return acc;
      
      (acc[label] = acc[label] || []).push(landmark);
      return acc;
    }, {});

    // Find groups with multiple landmarks sharing the same label
    Object.entries(labelGroups).forEach(([label, group]) => {
      if (group.length < 2) return;
      
      group.forEach(landmark => {
        this._addLandmarkError(landmark, LANDMARK_ERROR_TYPES.DUPLICATE_ROLE_SAME_LABEL);
      });
      
      this._addGlobalError(
        errors,
        LANDMARK_LABEL_KEYS.GLOBAL_ERROR.DUPLICATE_SAME_LABEL,
        LANDMARK_LABEL_KEYS.GLOBAL_ERROR.DUPLICATE_SAME_LABEL_DESC,
        group.length
      );
    });
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

    let totalUnlabeledCount = 0;

    // Check each role group for multiple unlabeled landmarks
    Object.entries(roleGroups).forEach(([role, group]) => {
      // Skip main role (handled by duplicate main check) and single-landmark groups
      if (role === LANDMARK_ROLES.MAIN || group.length < 2) return;
      
      const unlabeledLandmarks = group.filter(landmark => !landmark.label);
      
      if (unlabeledLandmarks.length > 1) {
        unlabeledLandmarks.forEach(landmark => {
          this._addLandmarkError(landmark, LANDMARK_ERROR_TYPES.MULTIPLE_UNLABELED_SAME_ROLE);
        });
        
        totalUnlabeledCount += unlabeledLandmarks.length;
      }
    });

    if (totalUnlabeledCount > 0) {
      this._addGlobalError(
        errors,
        LANDMARK_LABEL_KEYS.GLOBAL_ERROR.MULTIPLE_UNLABELED,
        LANDMARK_LABEL_KEYS.GLOBAL_ERROR.MULTIPLE_UNLABELED_DESC,
        totalUnlabeledCount
      );
    }
  }

  /**
   * Renders the error list for the landmark structure.
   *
   * @private
   * @param {Array<LandmarkStructureError>} errors - The error list to render.
   * @returns {import('lit').TemplateResult} The rendered error list.
   */
  _renderErrors(errors) {
    if (errors.length === 0) {
      return html``;
    }

    return html`
      <section
        class="mindfula11y-landmark-structure__errors"
        role="${this.firstRun ? "" : "alert"}"
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
   * @param {LandmarkStructureError} error - The error to render
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

  /**
   * Fetch the preview content from the server.
   *
   * Sends an AJAX request to the server to fetch the preview content.
   * The response is expected to be HTML content.
   *
   * @returns {Promise<string>} The HTML content of the preview.
   * @throws {Error} Throws an error if the request fails.
   */
  async fetchPreview() {
    const response = await new AjaxRequest(this.previewUrl).get({
      headers: {
        "Mindfula11y-Structure-Analysis": "1",
      },
    });

    return await response.resolve();
  }

  /**
   * Maps an error reason key to a user-friendly callout message for individual landmarks.
   *
   * @param {string} reason - The error reason key.
   * @returns {string} The mapped callout message for the specific landmark.
   */
  mapErrorReasonToMessage(reason) {
    const errorCalloutMap = {
      [LANDMARK_ERROR_TYPES.DUPLICATE_MAIN]: LANDMARK_LABEL_KEYS.CALLOUT.DUPLICATE_MAIN,
      [LANDMARK_ERROR_TYPES.DUPLICATE_ROLE_SAME_LABEL]: LANDMARK_LABEL_KEYS.CALLOUT.DUPLICATE_ROLE_SAME_LABEL,
      [LANDMARK_ERROR_TYPES.MULTIPLE_UNLABELED_SAME_ROLE]: LANDMARK_LABEL_KEYS.CALLOUT.MULTIPLE_UNLABELED_SAME_ROLE
    };
    
    const labelKey = errorCalloutMap[reason];
    return labelKey ? (TYPO3.lang[labelKey] || '') : '';
  }
}

// Register the custom element
customElements.define("mindfula11y-landmark-structure", LandmarkStructure);

export default LandmarkStructure;
