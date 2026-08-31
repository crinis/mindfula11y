import { postJson } from "./backend-api.js";
const isKnownAssessment = (value) => value === "likely_clear" || value === "likely_unclear" || value === "uncertain";
class InteractiveLabelAiReviewApi {
  async assess(request, options) {
    const data = await postJson(
      "mindfula11y_interactivelabel_aireview",
      request,
      options
    );
    if (!isKnownAssessment(data.assessment)) {
      throw new Error("The AI review endpoint returned no recognized assessment.");
    }
    return {
      assessment: data.assessment,
      reason: typeof data.reason === "string" ? data.reason : "",
      suggestedLabel: typeof data.suggestedLabel === "string" ? data.suggestedLabel : null
    };
  }
}
export {
  InteractiveLabelAiReviewApi
};
