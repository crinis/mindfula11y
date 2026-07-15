import { postJson } from "./backend-api.js";
class AltTextService {
  /**
   * Generates alternative text for the image described by the signed demand.
   * Throws a RequestError carrying the backend's localized title/description
   * when the endpoint answers with its structured error body.
   */
  async generateAltText(demand, signal) {
    const data = await postJson("mindfula11y_alttext_generate", demand, { signal });
    if (typeof data.altText !== "string" || data.altText === "") {
      throw new Error("The alt-text endpoint returned no text.");
    }
    return data.altText;
  }
}
export {
  AltTextService
};
