import { buildStructureNodeId, extractRecord, parseJsonMap } from "./dom.js";
import { StructureErrorSeverity } from "./types.js";
const ERROR_KEYS = {
  missingH1: "mindfula11y.structure.headings.error.missingH1",
  multipleH1: "mindfula11y.structure.headings.error.multipleH1",
  emptyHeading: "mindfula11y.structure.headings.error.emptyHeadings",
  skippedLevel: "mindfula11y.structure.headings.error.skippedLevel"
};
const extractRelation = (element) => {
  const ancestorId = element.dataset.mindfula11yAncestorId ?? "";
  if (ancestorId !== "") {
    return { kind: "ancestor", targetRelationId: ancestorId };
  }
  const siblingId = element.dataset.mindfula11ySiblingId ?? "";
  if (siblingId !== "") {
    return { kind: "sibling", targetRelationId: siblingId };
  }
  return null;
};
const analyzeHeadings = (doc) => {
  const headings = Array.from(doc.querySelectorAll("h1, h2, h3, h4, h5, h6"));
  const errors = [];
  const rootNodes = [];
  const parentStack = [];
  const skippedCombinations = /* @__PURE__ */ new Map();
  const seenIds = /* @__PURE__ */ new Map();
  const h1Count = headings.filter((heading) => heading.tagName === "H1").length;
  if (headings.length > 0 && h1Count === 0) {
    errors.push({ key: ERROR_KEYS.missingH1, severity: StructureErrorSeverity.Error, nodeId: null });
  }
  headings.forEach((element, index) => {
    const level = Number.parseInt(element.tagName.charAt(1), 10);
    const record = extractRecord(element);
    const relationId = element.dataset.mindfula11yRelationId ?? "";
    const nodeId = buildStructureNodeId(record, index, seenIds, relationId === "" ? "" : `rel:${relationId}`);
    const label = element.textContent?.trim() ?? "";
    while ((parentStack.at(-1)?.level ?? 0) >= level) {
      parentStack.pop();
    }
    const parent = parentStack.at(-1) ?? null;
    const directSkips = parent === null ? 0 : Math.max(0, level - parent.level - 1);
    const parentLevel = parent === null ? 0 : parent.level;
    let skippedLevels = directSkips;
    if (directSkips > 0) {
      const children = skippedCombinations.get(parentLevel) ?? /* @__PURE__ */ new Set();
      children.add(level);
      skippedCombinations.set(parentLevel, children);
    } else if (skippedCombinations.get(parentLevel)?.has(level) === true) {
      skippedLevels = Math.max(0, level - parentLevel - 1);
    }
    const nodeErrors = [];
    if (h1Count > 1 && level === 1) {
      nodeErrors.push({ key: ERROR_KEYS.multipleH1, severity: StructureErrorSeverity.Warning, nodeId });
    }
    if (label === "") {
      nodeErrors.push({ key: ERROR_KEYS.emptyHeading, severity: StructureErrorSeverity.Error, nodeId });
    }
    if (skippedLevels > 0) {
      nodeErrors.push({ key: ERROR_KEYS.skippedLevel, severity: StructureErrorSeverity.Error, nodeId });
    }
    errors.push(...nodeErrors);
    const node = {
      id: nodeId,
      level,
      label,
      availableTypes: parseJsonMap(element.dataset.mindfula11yAvailableTypes),
      record,
      relationId,
      relation: extractRelation(element),
      skippedLevels,
      errors: nodeErrors,
      children: []
    };
    if (parent === null) {
      rootNodes.push(node);
    } else {
      parent.children.push(node);
    }
    parentStack.push(node);
  });
  return { nodes: rootNodes, errors };
};
export {
  analyzeHeadings
};
