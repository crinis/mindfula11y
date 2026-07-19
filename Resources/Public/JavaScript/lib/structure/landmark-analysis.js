import { createErrorCollector, groupBy } from "./analysis.js";
import { extractRecord, indexStructureNodes } from "./annotations.js";
import { resolveExposure } from "./element-exposure.js";
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
  "section[aria-labelledby]",
  "section[title]"
].join(", ");
const IMPLICIT_ROLES = {
  main: "main",
  nav: "navigation",
  aside: "complementary",
  header: "banner",
  footer: "contentinfo",
  form: "form"
};
const resolveLabelledby = (element, doc) => {
  const labelledby = element.getAttribute("aria-labelledby")?.trim() ?? "";
  if (labelledby === "") {
    return "";
  }
  return labelledby.split(/\s+/).map((id) => doc.getElementById(id)?.textContent?.trim() ?? "").filter((text) => text !== "").join(" ");
};
const resolveAccessibleName = (element, doc, contentFallback = () => "") => resolveLabelledby(element, doc) || (element.getAttribute("aria-label")?.trim() ?? "") || contentFallback() || (element.getAttribute("title")?.trim() ?? "");
const resolveRole = (element, label) => {
  const explicitRole = element.getAttribute("role")?.trim().toLowerCase() ?? "";
  if (explicitRole !== "" && explicitRole !== "none" && explicitRole !== "presentation") {
    return explicitRole;
  }
  const tagName = element.tagName.toLowerCase();
  if (tagName === "section") {
    return label !== "" ? "region" : "";
  }
  return IMPLICIT_ROLES[tagName] ?? "";
};
const resolveLinkName = (link, doc) => resolveAccessibleName(
  link,
  doc,
  () => [
    link.textContent ?? "",
    ...Array.from(link.querySelectorAll('img[alt], input[type="image"][alt]')).map(
      (element) => element.getAttribute("alt") ?? ""
    )
  ].join(" ").replace(/\s+/g, " ").trim()
);
const navigationSignature = (element, doc, isExposed) => {
  const links = Array.from(element.querySelectorAll("a[href]")).filter(isExposed).map((link) => `${resolveLinkName(link, doc)}\0${link.href}`).sort();
  return links.length === 0 ? "" : JSON.stringify(links);
};
const analyzeLandmarks = (doc, options = {}) => {
  const viewport = options.viewport ?? "desktop";
  const isExposed = resolveExposure(options.isExposed);
  const labels = /* @__PURE__ */ new Map();
  const labelOf = (element) => {
    let label = labels.get(element);
    if (label === void 0) {
      label = resolveAccessibleName(element, doc);
      labels.set(element, label);
    }
    return label;
  };
  const candidates = Array.from(doc.querySelectorAll(LANDMARK_SELECTOR));
  const index = indexStructureNodes(candidates);
  const elements = candidates.filter((element) => {
    if (!isExposed(element)) {
      return false;
    }
    const label = labelOf(element);
    const tagName = element.tagName.toLowerCase();
    if (tagName === "form" && !element.hasAttribute("role")) {
      return label !== "";
    }
    return tagName !== "section" || element.hasAttribute("role") || label !== "";
  });
  const nodesByElement = /* @__PURE__ */ new Map();
  const elementByNode = /* @__PURE__ */ new Map();
  const flat = [];
  elements.forEach((element) => {
    const label = labelOf(element);
    const record = extractRecord(element);
    const node = {
      id: index.get(element)?.id ?? "",
      documentOrder: index.get(element)?.documentOrder ?? 0,
      role: resolveRole(element, label),
      label,
      availableRoles: {},
      record,
      viewports: [viewport],
      errors: [],
      children: []
    };
    nodesByElement.set(element, node);
    elementByNode.set(node, element);
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
  const collector = createErrorCollector(viewport);
  if (flat.length > 0) {
    const mains = flat.filter((node) => node.role === "main");
    if (mains.length === 0) {
      collector.pageError(ERROR_KEYS.missingMain, "moderate");
    } else if (mains.length > 1) {
      for (const node of mains) {
        collector.nodeError(node, ERROR_KEYS.duplicateMain, "moderate");
      }
    }
    const byRoleAndLabel = groupBy(
      flat,
      (node) => node.label === "" || node.role === "main" ? null : `${node.role}\0${node.label}`
    );
    for (const group of byRoleAndLabel.values()) {
      if (group.length < 2) {
        continue;
      }
      const signatures = group.every((node) => node.role === "navigation") ? group.map((node) => {
        const element = elementByNode.get(node);
        return element === void 0 ? "" : navigationSignature(element, doc, isExposed);
      }) : [];
      const identicalNavigation = signatures[0] !== void 0 && signatures[0] !== "" && new Set(signatures).size === 1;
      if (!identicalNavigation) {
        for (const node of group) {
          collector.nodeError(node, ERROR_KEYS.duplicateSameLabel, "moderate");
        }
      }
    }
    const unlabeledByRole = groupBy(
      flat,
      (node) => node.label !== "" || node.role === "" || node.role === "main" ? null : node.role
    );
    for (const group of unlabeledByRole.values()) {
      if (group.length < 2) {
        continue;
      }
      for (const node of group) {
        collector.nodeError(node, ERROR_KEYS.multipleUnlabeled, "moderate");
      }
    }
  }
  return { nodes: rootNodes, errors: collector.errors };
};
export {
  LANDMARK_SELECTOR,
  analyzeLandmarks
};
