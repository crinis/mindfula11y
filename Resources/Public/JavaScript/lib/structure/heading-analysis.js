import { createErrorCollector } from "./analysis.js";
import { extractChildTypeRecord, extractRecord, indexStructureNodes } from "./annotations.js";
import { isElementExposed, resolveExposure } from "./element-exposure.js";
import { HEADING_ERROR_KEYS } from "./types.js";
const CONTAINER_SELECTOR = "[data-mindfula11y-container]";
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
  const rawExposure = options.isExposed ?? isElementExposed;
  const candidates = Array.from(doc.querySelectorAll(`h1, h2, h3, h4, h5, h6, ${CONTAINER_SELECTOR}`));
  const index = indexStructureNodes(candidates, (element) => {
    const relationId = element.dataset.mindfula11yRelationId ?? "";
    return relationId === "" ? "" : `rel:${relationId}`;
  });
  const exposed = candidates.filter(
    (element) => element.matches(CONTAINER_SELECTOR) ? element.parentElement === null || rawExposure(element.parentElement) : isExposed(element)
  );
  const headings = exposed.filter((element) => !element.matches(CONTAINER_SELECTOR));
  const collector = createErrorCollector(viewport);
  const rootNodes = [];
  const parentStack = [];
  const nodesByRelationId = /* @__PURE__ */ new Map();
  const h1Count = headings.filter((heading) => heading.tagName === "H1").length;
  if (headings.length > 0 && h1Count === 0) {
    collector.pageError(HEADING_ERROR_KEYS.missingH1, "moderate");
  }
  exposed.forEach((element) => {
    if (element.matches(CONTAINER_SELECTOR)) {
      const ownType = /^h([1-6])$/.exec(element.dataset.mindfula11yContainer ?? "");
      const container = {
        id: index.get(element)?.id ?? "",
        documentOrder: index.get(element)?.documentOrder ?? 0,
        kind: "container",
        level: ownType === null ? 0 : Number.parseInt(ownType[1] ?? "0", 10),
        label: "",
        availableTypes: {},
        availableChildTypes: {},
        record: extractRecord(element),
        childTypeRecord: extractChildTypeRecord(element),
        relationId: element.dataset.mindfula11yRelationId ?? "",
        relation: null,
        skippedLevels: 0,
        viewports: [viewport],
        errors: [],
        children: []
      };
      if (container.relationId !== "") {
        nodesByRelationId.set(container.relationId, container);
      }
      (parentStack.at(-1)?.children ?? rootNodes).push(container);
      return;
    }
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
    const relation = extractRelation(element);
    const relationTarget = relation === null ? void 0 : nodesByRelationId.get(relation.targetRelationId);
    const attributedContainer = skippedLevels > 0 && relationTarget?.kind === "container" ? relationTarget : null;
    const node = {
      id: nodeId,
      documentOrder: index.get(element)?.documentOrder ?? 0,
      kind: "heading",
      level,
      label,
      availableTypes: {},
      availableChildTypes: {},
      record,
      childTypeRecord: extractChildTypeRecord(element),
      relationId,
      relation,
      skippedLevels: attributedContainer === null ? skippedLevels : 0,
      viewports: [viewport],
      errors: [],
      children: []
    };
    if (relationId !== "") {
      nodesByRelationId.set(relationId, node);
    }
    if (h1Count > 1 && level === 1) {
      collector.nodeError(node, HEADING_ERROR_KEYS.multipleH1, "minor");
    }
    if (label === "") {
      collector.nodeError(node, HEADING_ERROR_KEYS.emptyHeading, "minor");
    }
    if (attributedContainer !== null) {
      if (!attributedContainer.errors.some((error) => error.key === HEADING_ERROR_KEYS.skippedLevel)) {
        collector.nodeError(attributedContainer, HEADING_ERROR_KEYS.skippedLevel, "moderate");
      }
    } else if (skippedLevels > 0) {
      collector.nodeError(node, HEADING_ERROR_KEYS.skippedLevel, "moderate");
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
