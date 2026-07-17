import AjaxRequest from "@typo3/core/ajax/ajax-request.js";
import { toRequestError } from "./request-error.js";
const JSON_CONTENT_TYPE_HEADERS = { "Content-Type": "application/json; charset=utf-8" };
const resolveAjaxUrl = (ajaxUrlKey) => {
  const url = TYPO3.settings.ajaxUrls[ajaxUrlKey];
  if (url === void 0) {
    throw new Error(`AJAX endpoint not registered: ${ajaxUrlKey}`);
  }
  return url;
};
const requestInit = (signal, headers) => {
  const init = {};
  if (headers !== void 0) {
    init.headers = headers;
  }
  if (signal !== void 0) {
    init.signal = signal;
  }
  return init;
};
const getJson = async (ajaxUrlKey, params, options) => {
  const url = resolveAjaxUrl(ajaxUrlKey);
  try {
    let request = new AjaxRequest(url);
    if (params !== void 0) {
      request = request.withQueryArguments(params);
    }
    const response = await request.get(requestInit(options?.signal));
    return await response.resolve();
  } catch (error) {
    throw await toRequestError(error);
  }
};
const postJson = async (ajaxUrlKey, body, options) => {
  const url = resolveAjaxUrl(ajaxUrlKey);
  try {
    const response = await new AjaxRequest(url).post(body, requestInit(options?.signal, JSON_CONTENT_TYPE_HEADERS));
    return await response.resolve();
  } catch (error) {
    throw await toRequestError(error);
  }
};
export {
  getJson,
  postJson
};
