import { STRUCTURE_VIEWPORT_ORDER } from "../types.js";
const mergeViewports = (a, b) => STRUCTURE_VIEWPORT_ORDER.filter((viewport) => a.includes(viewport) || b.includes(viewport));
const createErrorCollector = (viewport) => {
  const errors = [];
  return {
    errors,
    pageError(key, severity) {
      errors.push({ key, severity, nodeId: null, viewports: [viewport] });
    },
    nodeError(node, key, severity) {
      const error = { key, severity, nodeId: node.id, viewports: [viewport] };
      node.errors.push(error);
      errors.push(error);
    }
  };
};
const groupBy = (items, keyOf) => {
  const groups = /* @__PURE__ */ new Map();
  for (const item of items) {
    const key = keyOf(item);
    if (key === null) {
      continue;
    }
    const group = groups.get(key);
    if (group === void 0) {
      groups.set(key, [item]);
    } else {
      group.push(item);
    }
  }
  return groups;
};
const indexTree = (roots) => {
  const index = { nodes: /* @__PURE__ */ new Map(), parents: /* @__PURE__ */ new Map(), order: [] };
  const visit = (nodes, parentId) => {
    for (const node of nodes) {
      index.nodes.set(node.id, node);
      index.parents.set(node.id, parentId);
      index.order.push(node.id);
      visit(node.children, node.id);
    }
  };
  visit(roots, null);
  return index;
};
const mergeErrors = (analyses) => {
  const merged = /* @__PURE__ */ new Map();
  for (const analysis of analyses) {
    for (const error of analysis.errors) {
      const identity = `${error.key}\0${error.nodeId ?? ""}`;
      const existing = merged.get(identity);
      if (existing === void 0) {
        merged.set(identity, { ...error, viewports: [...error.viewports] });
        continue;
      }
      existing.viewports = mergeViewports(existing.viewports, error.viewports);
    }
  }
  return Array.from(merged.values());
};
const mergeTrees = (mobileRoots, desktopRoots, errors) => {
  const mobile = indexTree(mobileRoots);
  const desktop = indexTree(desktopRoots);
  const order = [.../* @__PURE__ */ new Set([...desktop.order, ...mobile.order])].sort((a, b) => {
    const nodeA = desktop.nodes.get(a) ?? mobile.nodes.get(a);
    const nodeB = desktop.nodes.get(b) ?? mobile.nodes.get(b);
    return (nodeA?.documentOrder ?? 0) - (nodeB?.documentOrder ?? 0);
  });
  const errorsByNode = groupBy(errors, (error) => error.nodeId);
  const mergedNodes = /* @__PURE__ */ new Map();
  for (const id of order) {
    const source = desktop.nodes.get(id) ?? mobile.nodes.get(id);
    if (source === void 0) {
      continue;
    }
    const viewports = STRUCTURE_VIEWPORT_ORDER.filter(
      (viewport) => (viewport === "mobile" ? mobile.nodes : desktop.nodes).has(id)
    );
    mergedNodes.set(id, {
      ...source,
      viewports,
      errors: errorsByNode.get(id) ?? [],
      children: []
    });
  }
  const roots = [];
  for (const id of order) {
    const node = mergedNodes.get(id);
    if (node === void 0) {
      continue;
    }
    const parentId = desktop.nodes.has(id) ? desktop.parents.get(id) : mobile.parents.get(id);
    const parent = parentId === null || parentId === void 0 ? void 0 : mergedNodes.get(parentId);
    if (parent === void 0) {
      roots.push(node);
    } else {
      parent.children.push(node);
    }
  }
  return roots;
};
const mergeAnalyses = (analyses) => {
  const errors = mergeErrors([analyses.mobile, analyses.desktop]);
  return {
    nodes: mergeTrees(analyses.mobile.nodes, analyses.desktop.nodes, errors),
    errors
  };
};
export {
  createErrorCollector,
  groupBy,
  mergeAnalyses,
  mergeViewports
};
