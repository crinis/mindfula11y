import { isBoundedString, isObject, isStringMap } from "../guards.js";
const STRUCTURE_ANALYSIS_PROTOCOL = "mindfula11y.structure.v1";
const MAX_ANALYSIS_ITEMS = 2e3;
const isViewport = (value) => value === "mobile" || value === "desktop";
const isViewportList = (value) => Array.isArray(value) && value.length <= 2 && value.every(isViewport);
const hasMessageEnvelope = (value, type, requestId) => isObject(value) && value.protocol === STRUCTURE_ANALYSIS_PROTOCOL && value.type === type && value.requestId === requestId;
const isError = (value) => {
  if (!isObject(value) || !isBoundedString(value.key, 256) || !isViewportList(value.viewports)) {
    return false;
  }
  return (value.severity === "error" || value.severity === "warning") && (value.nodeId === null || isBoundedString(value.nodeId, 512));
};
const isRecord = (value) => {
  if (value === null) {
    return true;
  }
  return isObject(value) && isBoundedString(value.tableName, 128) && /^[a-zA-Z0-9_]+$/.test(value.tableName) && isBoundedString(value.columnName, 128) && /^[a-zA-Z0-9_]+$/.test(value.columnName) && typeof value.uid === "number" && Number.isInteger(value.uid) && value.uid > 0 && // storedValue is a column value ('', 'h1'…'h6', 'p', 'div'); bound it
  // like every other wire string so a hostile frame cannot deliver an
  // oversized or non-string payload member.
  (value.storedValue === void 0 || isBoundedString(value.storedValue, 128)) && // The runner never resolves edit links; they are supplied only by the
  // authenticated backend enrichment endpoint. Rejecting any non-empty
  // wire value keeps a forged frame from injecting a clickable link.
  value.editLink === "";
};
const hasValidNodeBase = (value, availableValues) => isBoundedString(value.id, 512) && typeof value.documentOrder === "number" && Number.isInteger(value.documentOrder) && isBoundedString(value.label) && isStringMap(availableValues) && isRecord(value.record) && isViewportList(value.viewports) && Array.isArray(value.errors) && value.errors.length <= MAX_ANALYSIS_ITEMS && value.errors.every(isError) && Array.isArray(value.children);
const isHeadingNode = (value, depth, counter) => {
  if (!isObject(value) || depth > 20 || ++counter.value > MAX_ANALYSIS_ITEMS) {
    return false;
  }
  if (!hasValidNodeBase(value, value.availableTypes) || value.kind !== "heading" && value.kind !== "container" || typeof value.level !== "number" || !Number.isInteger(value.level) || // Containers report level 0 when their own type is not h1-h6
  // (see heading-analysis.ts); real headings are always 1-6.
  value.level < (value.kind === "container" ? 0 : 1) || value.level > 6 || !isRecord(value.childTypeRecord) || !isStringMap(value.availableChildTypes) || !isBoundedString(value.relationId, 512) || typeof value.skippedLevels !== "number" || !Number.isInteger(value.skippedLevels)) {
    return false;
  }
  if (value.relation !== null && (!isObject(value.relation) || value.relation.kind !== "ancestor" && value.relation.kind !== "sibling" || !isBoundedString(value.relation.targetRelationId, 512))) {
    return false;
  }
  return value.children.every((child) => isHeadingNode(child, depth + 1, counter));
};
const isLandmarkNode = (value, depth, counter) => {
  if (!isObject(value) || depth > 50 || ++counter.value > MAX_ANALYSIS_ITEMS) {
    return false;
  }
  if (!hasValidNodeBase(value, value.availableRoles) || !isBoundedString(value.role, 128)) {
    return false;
  }
  return value.children.every((child) => isLandmarkNode(child, depth + 1, counter));
};
const hasValidAnalysisShape = (value) => isObject(value) && Array.isArray(value.nodes) && value.nodes.length <= MAX_ANALYSIS_ITEMS && Array.isArray(value.errors) && value.errors.length <= MAX_ANALYSIS_ITEMS && value.errors.every(isError);
const isHeadingAnalysis = (value) => {
  if (!hasValidAnalysisShape(value)) {
    return false;
  }
  const counter = { value: 0 };
  return value.nodes.every((node) => isHeadingNode(node, 0, counter));
};
const isLandmarkAnalysis = (value) => {
  if (!hasValidAnalysisShape(value)) {
    return false;
  }
  const counter = { value: 0 };
  return value.nodes.every((node) => isLandmarkNode(node, 0, counter));
};
const isStructureAnalysisReadyMessage = (value, requestId) => hasMessageEnvelope(value, "ready", requestId);
const isStructureAnalysisInitializeMessage = (value, requestId) => hasMessageEnvelope(value, "initialize", requestId) && isViewport(value.viewport) && typeof value.headings === "boolean" && typeof value.landmarks === "boolean";
const isStructureAnalysisResultMessage = (value, requestId, viewport) => hasMessageEnvelope(value, "result", requestId) && value.viewport === viewport && hasValidResultPayload(value);
const isStructureAnalysisErrorMessage = (value, requestId) => hasMessageEnvelope(value, "error", requestId) && (value.code === "http" || value.code === "analysis") && (value.status === void 0 || typeof value.status === "number" && Number.isInteger(value.status)) && (value.message === void 0 || isBoundedString(value.message, 2e3));
const hasValidResultPayload = (value) => (value.headings === null || isHeadingAnalysis(value.headings)) && (value.landmarks === null || isLandmarkAnalysis(value.landmarks));
const describeError = (value) => value.code === "http" ? `The frontend preview returned HTTP status ${value.status ?? "unknown"}.` : value.message ?? "The frontend structure analysis failed.";
const parsePortMessage = (data, requestId, expectedViewport) => {
  if (hasMessageEnvelope(data, "result", requestId)) {
    if (isStructureAnalysisResultMessage(data, requestId, expectedViewport)) {
      return { kind: "result", headings: data.headings, landmarks: data.landmarks };
    }
    return { kind: "invalid-result" };
  }
  if (isStructureAnalysisErrorMessage(data, requestId)) {
    return {
      kind: "error",
      code: data.code,
      ...data.status === void 0 ? {} : { status: data.status },
      message: describeError(data)
    };
  }
  return null;
};
export {
  STRUCTURE_ANALYSIS_PROTOCOL,
  isStructureAnalysisErrorMessage,
  isStructureAnalysisInitializeMessage,
  isStructureAnalysisReadyMessage,
  isStructureAnalysisResultMessage,
  parsePortMessage
};
