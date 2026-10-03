import { lll } from "@typo3/core/lit-helper.js";
import { isObject } from "../lib/guards.js";
class RequestError extends Error {
  constructor(message, description = "", status = 0, retryAfter = null) {
    super(message);
    this.name = "RequestError";
    this.description = description;
    this.status = status;
    this.retryAfter = retryAfter;
  }
}
const responseOf = (error) => (
  // The rejection value may be anything (including null) — never let this
  // property access throw in place of the original error.
  isObject(error) && error.response instanceof Response ? error.response : void 0
);
const parseRetryAfter = (value) => {
  const trimmed = value?.trim() ?? "";
  if (/^\d+$/.test(trimmed)) {
    return Number(trimmed);
  }
  const date = trimmed === "" ? Number.NaN : Date.parse(trimmed);
  return Number.isNaN(date) ? null : Math.max(0, Math.round((date - Date.now()) / 1e3));
};
const toRequestError = async (error) => {
  const response = responseOf(error);
  if (response === void 0) {
    return error;
  }
  try {
    const data = await response.clone().json();
    const body = isObject(data) && isObject(data.error) ? data.error : void 0;
    if (body !== void 0 && typeof body.title === "string") {
      const description = typeof body.description === "string" ? body.description : "";
      return new RequestError(
        body.title,
        description,
        response.status,
        parseRetryAfter(response.headers.get("Retry-After"))
      );
    }
  } catch {
  }
  return error;
};
const httpStatusOf = (error) => {
  if (error instanceof RequestError) {
    return error.status;
  }
  return responseOf(error)?.status ?? 0;
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
  httpStatusOf,
  toRequestError
};
