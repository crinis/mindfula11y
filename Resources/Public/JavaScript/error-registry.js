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
 * @file error-registry.js
 * @description Central registry for storing and querying accessibility errors across all structure services.
 */

/**
 * Central registry for accessibility errors.
 * Stores errors by DOM elements for efficient querying during rendering.
 * Uses DOM elements directly as keys, eliminating the need for ID generation.
 *
 * @class ErrorRegistry
 */
export class ErrorRegistry {
  /**
   * Static storage for errors, shared across all usage.
   * Keyed by HTMLElement. Value is Array<StructureError>.
   */
  static _errors = new Map();

  /**
   * Creates a new StructureError object.
   *
   * @param {string} id - The error identifier
   * @param {string} severity - The error severity level
   * @param {string} [tag] - Optional tag for categorizing errors
   * @returns {StructureError} The error object
   */
  static createError(id, severity, tag) {
    return {
      id,
      severity,
      tag,
    };
  }

  /**
   * Stores errors for a specific element.
   *
   * @param {HTMLElement} element - The element to store errors for
   * @param {Array<StructureError>} errors - Array of StructureError objects
   */
  static storeErrors(element, errors) {
    ErrorRegistry._errors.set(element, errors);
  }

  /**
   * Adds a single error to an element's error list.
   *
   * @param {HTMLElement} element - The element to add error to
   * @param {StructureError} error - The StructureError object to add
   */
  static addError(element, error) {
    if (!ErrorRegistry._errors.has(element)) {
      ErrorRegistry._errors.set(element, []);
    }
    const elementErrors = ErrorRegistry._errors.get(element);
    if (!elementErrors.some((e) => e.id === error.id)) {
      elementErrors.push(error);
    }
  }

  /**
   * Gets all errors for a specific element.
   *
   * @param {HTMLElement} element - The element to get errors for
   * @returns {Array<StructureError>} Array of errors for the element
   */
  static getErrors(element) {
    return ErrorRegistry._errors.get(element) || [];
  }

  /**
   * Gets aggregated errors filtered by tag with optimized performance.
   * Consolidates duplicate error types and accumulates counts for the specified tag.
   *
   * @param {string} tag - The tag to filter by (e.g., "headings", "landmarks")
   * @returns {Array<StructureError>} Array of aggregated error objects for the specified tag
   */
  static getAggregatedErrorsByTag(tag) {
    const errorMap = new Map();

    for (const elementErrors of ErrorRegistry._errors.values()) {
      for (const error of elementErrors) {
        if (error.tag === tag) {
          const existingError = errorMap.get(error.id);
          if (existingError) {
            // Count instances for duplicate error types
            existingError.count += 1;
          } else {
            errorMap.set(error.id, { ...error, count: 1 });
          }
        }
      }
    }

    return Array.from(errorMap.values());
  }

  /**
   * Gets all errors for a specific element filtered by tag.
   *
   * @param {HTMLElement} element - The element to get errors for
   * @param {string} tag - The tag to filter by (e.g., "headings", "landmarks")
   * @returns {Array<StructureError>} Array of errors for the element with the specified tag
   */
  static getElementErrorsByTag(element, tag) {
    const elementErrors = ErrorRegistry._errors.get(element) || [];
    return elementErrors.filter((error) => error.tag === tag);
  }

  /**
   * Clears all errors from the registry that have the specified tag.
   *
   * @param {string} tag - The tag to filter by when clearing
   */
  static clearByTag(tag) {
    for (const [element, elementErrors] of ErrorRegistry._errors.entries()) {
      const filteredErrors = elementErrors.filter((error) => error.tag !== tag);
      if (filteredErrors.length === 0) {
        ErrorRegistry._errors.delete(element);
      } else {
        ErrorRegistry._errors.set(element, filteredErrors);
      }
    }
  }
}

export default ErrorRegistry;
