import AjaxRequest from "@typo3/core/ajax/ajax-request.js";
import Notification from "@typo3/backend/notification.js";

/** @typedef {import('./types.js').AltTextDemand} AltTextDemand */

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
   * @param {AltTextDemand} options - The request payload. Must include:
   *   - pageUid: UID of the page where the element is rendered
   *   - languageUid: UID of the language for the record
   *   - fileUid: UID of the file
   *   - signature: HMAC signature for request validation
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
          TYPO3.lang["mindfula11y.modules.missingAltText.generate.error.unknown"],
          TYPO3.lang["mindfula11y.modules.missingAltText.generate.error.unknown.description"]
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
