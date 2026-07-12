import AjaxRequest from "@typo3/core/ajax/ajax-request.js";
import { toRequestError } from "./request-error.js";
class AltTextService {
  /**
   * Generates alternative text for the image described by the signed demand.
   * Throws a RequestError carrying the backend's localized title/description
   * when the endpoint answers with its structured error body.
   */
  async generateAltText(demand) {
    try {
      const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.mindfula11y_generatealttext ?? "").post(
        demand,
        { headers: { "Content-Type": "application/json; charset=utf-8" } }
      );
      const data = await response.resolve();
      if (typeof data.altText !== "string" || data.altText === "") {
        throw new Error("The alt-text endpoint returned no text.");
      }
      return data.altText;
    } catch (error) {
      throw await toRequestError(error);
    }
  }
}
var alt_text_service_default = AltTextService;
export {
  AltTextService,
  alt_text_service_default as default
};
