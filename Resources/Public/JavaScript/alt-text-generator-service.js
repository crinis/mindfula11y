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

import AjaxRequest from "@typo3/core/ajax/ajax-request.js";
import Notification from "@typo3/backend/notification.js";

/** @typedef {import('./types.js').GenerateAltTextDemand} GenerateAltTextDemand */

/**
 * @file alt-text-generator-service.js
 * @description Service for generating alternative text for images via AJAX in TYPO3.
 */

/**
 * Service for requesting alternative text generation for images.
 *
 * Handles the AJAX request to generate alt text for images
 * and returns the generated text.
 *
 * @class AltTextGeneratorService
 */
export default class AltTextGeneratorService {
  /**
   * Create a new AltTextGeneratorService instance.
   *
   * @param {string} ajaxUrl - The URL to send the AJAX request to.
   */
  constructor(ajaxUrl) {
    this.ajaxUrl = ajaxUrl;
  }

  /**
   * Sends an AJAX request to generate alt text for an image.
   *
   * @param {GenerateAltTextDemand} options - The request payload containing all necessary parameters for alt text generation.
   * @returns {Promise<string|null>} Resolves to the generated alt text or null on error.
   */
  async generateAltText(options) {
    let responseData = null;
    try {
      const response = await new AjaxRequest(this.ajaxUrl).post(options);
      responseData = await response.resolve();
    } catch (error) {
      if (!error.response) {
        return null;
      }
      try {
        responseData = await error.response.json();
      } catch (e) {
        Notification.error(
          TYPO3.lang["mindfula11y.missingAltText.generate.error.unknown"],
          TYPO3.lang["mindfula11y.missingAltText.generate.error.unknown.description"]
        );
        return null;
      }
      if (responseData && responseData.error) {
        Notification.error(
          responseData.error.title,
          responseData.error.description
        );
        return null;
      }
    }
    return responseData && responseData.altText ? responseData.altText : null;
  }
}
