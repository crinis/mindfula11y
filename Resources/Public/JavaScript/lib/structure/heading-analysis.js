import { extractRecord, indexStructureNodes } from "../dom.js";
import { createErrorCollector } from "./analysis.js";
import { resolveExposure } from "./element-exposure.js";
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
const analyzeHeadings = (doc, options = {}) => {
  const viewport = options.viewport ?? "desktop";
  const isExposed = resolveExposure(options.isExposed);
  const candidates = Array.from(doc.querySelectorAll("h1, h2, h3, h4, h5, h6"));
  const index = indexStructureNodes(candidates, (element) => {
    const relationId = element.dataset.mindfula11yRelationId ?? "";
    return relationId === "" ? "" : `rel:${relationId}`;
  });
  const headings = candidates.filter(isExposed);
  const collector = createErrorCollector(viewport);
  const rootNodes = [];
  const parentStack = [];
  const h1Count = headings.filter((heading) => heading.tagName === "H1").length;
  if (headings.length > 0 && h1Count === 0) {
    collector.pageError(ERROR_KEYS.missingH1, StructureErrorSeverity.Error);
  }
  headings.forEach((element) => {
    const level = Number.parseInt(element.tagName.charAt(1), 10);
    const record = extractRecord(element);
    const relationId = element.dataset.mindfula11yRelationId ?? "";
    const nodeId = index.get(element)?.id ?? "";
    const label = element.textContent?.trim() ?? "";
    while ((parentStack.at(-1)?.level ?? 0) >= level) {
      parentStack.pop();
    }
    const parent = parentStack.at(-1) ?? null;
    const skippedLevels = parent === null ? 0 : Math.max(0, level - parent.level - 1);
    const node = {
      id: nodeId,
      documentOrder: index.get(element)?.documentOrder ?? 0,
      level,
      label,
      availableTypes: {},
      record,
      relationId,
      relation: extractRelation(element),
      skippedLevels,
      viewports: [viewport],
      errors: [],
      children: []
    };
    if (h1Count > 1 && level === 1) {
      collector.nodeError(node, ERROR_KEYS.multipleH1, StructureErrorSeverity.Warning);
    }
    if (label === "") {
      collector.nodeError(node, ERROR_KEYS.emptyHeading, StructureErrorSeverity.Error);
    }
    if (skippedLevels > 0) {
      collector.nodeError(node, ERROR_KEYS.skippedLevel, StructureErrorSeverity.Error);
    }
    if (parent === null) {
      rootNodes.push(node);
    } else {
      parent.children.push(node);
    }
    parentStack.push(node);
  });
  return { nodes: rootNodes, errors: collector.errors };
};
export {
  analyzeHeadings
};
