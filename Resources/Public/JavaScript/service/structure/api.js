import { isObject, isStringMap } from "../../lib/guards.js";
import {
  recordKey
} from "../../lib/structure/enrichment.js";
import { StructureAnalysisError } from "../../lib/structure/error.js";
import { postJson } from "../backend-api.js";
const MAX_RECORDS_PER_REQUEST = 200;
class StructureAnalysisApi {
  async issueTicket(pageId, languageId, signal) {
    const value = await this.post(
      "mindfula11y_structure_ticket",
      "ticket",
      { pageId, languageId },
      signal
    );
    if (!this.isTicket(value)) {
      throw new StructureAnalysisError("ticket", "The backend returned an invalid structure analysis ticket.");
    }
    return value;
  }
  async fetchRecordMetadata(requests, signal) {
    const metadata = /* @__PURE__ */ new Map();
    if (requests.length === 0) {
      return metadata;
    }
    for (let offset = 0; offset < requests.length; offset += MAX_RECORDS_PER_REQUEST) {
      signal.throwIfAborted();
      const value = await this.post(
        "mindfula11y_structure_enrich",
        "enrich",
        { records: requests.slice(offset, offset + MAX_RECORDS_PER_REQUEST) },
        signal
      );
      if (!this.isMetadataResponse(value)) {
        throw new StructureAnalysisError("enrich", "The backend returned invalid structure editing metadata.");
      }
      for (const record of value.records) {
        metadata.set(recordKey(record), record);
      }
    }
    signal.throwIfAborted();
    return metadata;
  }
  /**
   * Posts to a registered AJAX route via the shared transport, restating an
   * unregistered route as this flow's typed failure code — `postJson` reports it
   * as a plain Error, which the structure views cannot localize.
   */
  async post(endpointKey, code, body, signal) {
    if (TYPO3.settings.ajaxUrls[endpointKey] === void 0) {
      throw new StructureAnalysisError(code, `The backend AJAX route "${endpointKey}" is not registered.`);
    }
    return postJson(endpointKey, body, { signal });
  }
  isTicket(value) {
    if (!isObject(value)) {
      return false;
    }
    return typeof value.url === "string" && /^https?:\/\//.test(value.url) && typeof value.requestId === "string" && /^[a-f0-9]{32}$/.test(value.requestId);
  }
  isMetadataResponse(value) {
    if (!isObject(value) || !Array.isArray(value.records)) {
      return false;
    }
    return value.records.length <= MAX_RECORDS_PER_REQUEST && value.records.every((record) => this.isMetadata(record));
  }
  isMetadata(value) {
    if (!isObject(value)) {
      return false;
    }
    return typeof value.tableName === "string" && typeof value.columnName === "string" && typeof value.uid === "number" && Number.isInteger(value.uid) && typeof value.editLink === "string" && isStringMap(value.availableValues);
  }
}
export {
  StructureAnalysisApi
};
