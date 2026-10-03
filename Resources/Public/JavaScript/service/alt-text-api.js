import { postJson } from "./backend-api.js";
class AltTextApi {
  /**
   * Generates alternative text for the image described by the signed demand.
   * Throws a RequestError carrying the backend's localized title/description
   * when the endpoint answers with its structured error body, and an Error
   * for a success body that is neither a text nor the decorative verdict.
   */
  async generateAltText(demand, options) {
    const data = await postJson("mindfula11y_alttext_generate", demand, options);
    if (data.decorative === true) {
      return { decorative: true };
    }
    if (typeof data.altText !== "string" || data.altText === "") {
      throw new Error("The alt-text endpoint returned no text.");
    }
    return { decorative: false, altText: data.altText };
  }
}
export {
  AltTextApi
};
