import { extractRecord, parseJsonMap } from "./dom.js";
import { StructureErrorSeverity } from "./types.js";
const ERROR_KEYS = {
  missingMain: "mindfula11y.structure.landmarks.error.missingMain",
  duplicateMain: "mindfula11y.structure.landmarks.error.duplicateMain",
  duplicateSameLabel: "mindfula11y.structure.landmarks.error.duplicateSameLabel",
  multipleUnlabeled: "mindfula11y.structure.landmarks.error.multipleUnlabeledLandmarks"
};
const LANDMARK_SELECTOR = [
  '[role="banner"]',
  '[role="main"]',
  '[role="navigation"]',
  '[role="complementary"]',
  '[role="contentinfo"]',
  '[role="region"]',
  '[role="search"]',
  '[role="form"]',
  "main",
  "nav",
  "aside",
  "form",
  "header:not(article header, aside header, footer header, header header, main header, nav header, section header)",
  "footer:not(article footer, aside footer, footer footer, header footer, main footer, nav footer, section footer)",
  "section[aria-label]",
  "section[aria-labelledby]"
].join(", ");
const IMPLICIT_ROLES = {
  main: "main",
  nav: "navigation",
  aside: "complementary",
  header: "banner",
  footer: "contentinfo",
  form: "form"
};
const resolveLabel = (element, doc) => {
  const ariaLabel = element.getAttribute("aria-label")?.trim() ?? "";
  if (ariaLabel !== "") {
    return ariaLabel;
  }
  const labelledby = element.getAttribute("aria-labelledby")?.trim() ?? "";
  if (labelledby === "") {
    return "";
  }
  return labelledby.split(/\s+/).map((id) => doc.getElementById(id)?.textContent?.trim() ?? "").filter((text) => text !== "").join(" ");
};
const resolveRole = (element, label) => {
  const explicitRole = element.getAttribute("role");
  if (explicitRole !== null && explicitRole !== "") {
    return explicitRole;
  }
  const tagName = element.tagName.toLowerCase();
  if (tagName === "section") {
    return label !== "" ? "region" : "";
  }
  return IMPLICIT_ROLES[tagName] ?? "";
};
const buildNodeId = (record, index, seen) => {
  if (record === null) {
    return `pos:${index}`;
  }
  const base = `${record.tableName}:${record.uid}:${record.columnName}`;
  const occurrence = seen.get(base) ?? 0;
  seen.set(base, occurrence + 1);
  return occurrence === 0 ? base : `${base}#${occurrence}`;
};
const analyzeLandmarks = (doc) => {
  const labels = /* @__PURE__ */ new Map();
  const labelOf = (element) => {
    let label = labels.get(element);
    if (label === void 0) {
      label = resolveLabel(element, doc);
      labels.set(element, label);
    }
    return label;
  };
  const elements = Array.from(doc.querySelectorAll(LANDMARK_SELECTOR)).filter((element) => {
    if (element.tagName.toLowerCase() !== "section") {
      return true;
    }
    return labelOf(element) !== "";
  });
  const seenIds = /* @__PURE__ */ new Map();
  const nodesByElement = /* @__PURE__ */ new Map();
  const flat = [];
  elements.forEach((element, index) => {
    const label = labelOf(element);
    const record = extractRecord(element);
    const node = {
      id: buildNodeId(record, index, seenIds),
      role: resolveRole(element, label),
      label,
      availableRoles: parseJsonMap(element.dataset.mindfula11yAvailableRoles),
      record,
      errors: [],
      children: []
    };
    nodesByElement.set(element, node);
    flat.push(node);
  });
  const rootNodes = [];
  elements.forEach((element) => {
    const node = nodesByElement.get(element);
    if (node === void 0) {
      return;
    }
    let ancestor = element.parentElement;
    while (ancestor !== null && !nodesByElement.has(ancestor)) {
      ancestor = ancestor.parentElement;
    }
    const parent = ancestor === null ? void 0 : nodesByElement.get(ancestor);
    if (parent === void 0) {
      rootNodes.push(node);
    } else {
      parent.children.push(node);
    }
  });
  const errors = [];
  const addNodeError = (node, key, severity) => {
    const error = { key, severity, nodeId: node.id };
    node.errors.push(error);
    errors.push(error);
  };
  if (flat.length > 0) {
    const mains = flat.filter((node) => node.role === "main");
    if (mains.length === 0) {
      errors.push({ key: ERROR_KEYS.missingMain, severity: StructureErrorSeverity.Error, nodeId: null });
    } else if (mains.length > 1) {
      for (const node of mains) {
        addNodeError(node, ERROR_KEYS.duplicateMain, StructureErrorSeverity.Error);
      }
    }
    const byLabel = /* @__PURE__ */ new Map();
    for (const node of flat) {
      if (node.label === "") {
        continue;
      }
      const group = byLabel.get(node.label);
      if (group === void 0) {
        byLabel.set(node.label, [node]);
      } else {
        group.push(node);
      }
    }
    for (const group of byLabel.values()) {
      if (group.length < 2) {
        continue;
      }
      for (const node of group) {
        addNodeError(node, ERROR_KEYS.duplicateSameLabel, StructureErrorSeverity.Error);
      }
    }
    const byRole = /* @__PURE__ */ new Map();
    for (const node of flat) {
      if (node.role === "" || node.role === "main") {
        continue;
      }
      const group = byRole.get(node.role);
      if (group === void 0) {
        byRole.set(node.role, [node]);
      } else {
        group.push(node);
      }
    }
    for (const group of byRole.values()) {
      if (group.length < 2) {
        continue;
      }
      const unlabeled = group.filter((node) => node.label === "");
      if (unlabeled.length < 2) {
        continue;
      }
      for (const node of unlabeled) {
        addNodeError(node, ERROR_KEYS.multipleUnlabeled, StructureErrorSeverity.Warning);
      }
    }
  }
  return { nodes: rootNodes, errors };
};
export {
  analyzeLandmarks
};
