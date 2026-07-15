import { lll } from "@typo3/core/lit-helper.js";
class RequestError extends Error {
  constructor(message, description = "", status = 0) {
    super(message);
    this.name = "RequestError";
    this.description = description;
    this.status = status;
  }
}
const toRequestError = async (error) => {
  const response = error.response;
  if (response === void 0) {
    return error;
  }
  try {
    const data = await response.clone().json();
    if (data.error?.title !== void 0) {
      return new RequestError(data.error.title, data.error.description ?? "", response.status);
    }
  } catch {
  }
  return error;
};
const errorView = (error, fallbackKey) => {
  if (error instanceof RequestError) {
    return { title: error.message, description: error.description !== "" ? error.description : error.message };
  }
  return { title: lll(fallbackKey), description: lll(`${fallbackKey}.description`) };
};
export {
  RequestError,
  errorView,
  toRequestError
};
