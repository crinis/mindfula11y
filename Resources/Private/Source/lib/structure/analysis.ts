/*
 * Mindful A11y extension for TYPO3 integrating accessibility tools into the backend.
 * Copyright (C) 2026  Mindful Markup, Felix Spittel
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 */

import type { StructureError, StructureErrorSeverity, StructureViewport } from './types.js';
import { STRUCTURE_VIEWPORT_ORDER } from './types.js';

/** Union of two viewport sets in canonical order — the merge contract's single rule. */
export const mergeViewports = (a: readonly StructureViewport[], b: readonly StructureViewport[]): StructureViewport[] =>
    STRUCTURE_VIEWPORT_ORDER.filter((viewport) => a.includes(viewport) || b.includes(viewport));

/**
 * Records structure findings for a single-viewport analysis pass. `nodeError` pushes the
 * SAME error object into both the owning node's `errors` array and the collector's flat
 * `errors` list — never a copy. Sharing one instance keeps the per-node view (`node.errors`)
 * and the flat list (`errors`) consistent by construction: a single source of truth so the
 * analyzer can't emit divergent copies of the same finding. `pageError` findings have no
 * owning node (missing H1 / missing main) and go into the flat list only, with `nodeId: null`.
 */
export interface StructureErrorCollector {
    readonly errors: StructureError[];
    pageError(key: string, severity: StructureErrorSeverity): void;
    nodeError(node: { id: string; errors: StructureError[] }, key: string, severity: StructureErrorSeverity): void;
}

export const createErrorCollector = (viewport: StructureViewport): StructureErrorCollector => {
    const errors: StructureError[] = [];
    return {
        errors,
        pageError(key: string, severity: StructureErrorSeverity): void {
            errors.push({ key, severity, nodeId: null, viewports: [viewport] });
        },
        nodeError(node: { id: string; errors: StructureError[] }, key: string, severity: StructureErrorSeverity): void {
            const error: StructureError = { key, severity, nodeId: node.id, viewports: [viewport] };
            node.errors.push(error);
            errors.push(error);
        },
    };
};

/** Groups items by a derived key; items whose key is `null` are dropped. */
export const groupBy = <T>(items: readonly T[], keyOf: (item: T) => string | null): Map<string, T[]> => {
    const groups = new Map<string, T[]>();
    for (const item of items) {
        const key = keyOf(item);
        if (key === null) {
            continue;
        }
        const group = groups.get(key);
        if (group === undefined) {
            groups.set(key, [item]);
        } else {
            group.push(item);
        }
    }
    return groups;
};

export interface MergeableNode<T> {
    id: string;
    documentOrder: number;
    viewports: StructureViewport[];
    errors: StructureError[];
    children: T[];
}

/** The shape both domain analyses share — all the merge contract needs to know. */
export interface MergeableAnalysis<T extends MergeableNode<T>> {
    nodes: T[];
    errors: StructureError[];
}

interface TreeIndex<T> {
    nodes: Map<string, T>;
    parents: Map<string, string | null>;
    order: string[];
}

const indexTree = <T extends MergeableNode<T>>(roots: T[]): TreeIndex<T> => {
    const index: TreeIndex<T> = { nodes: new Map(), parents: new Map(), order: [] };
    const visit = (nodes: T[], parentId: string | null): void => {
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

const mergeErrors = (analyses: Array<{ errors: StructureError[] }>): StructureError[] => {
    const merged = new Map<string, StructureError>();
    for (const analysis of analyses) {
        for (const error of analysis.errors) {
            const identity = `${error.key}\u0000${error.nodeId ?? ''}`;
            const existing = merged.get(identity);
            if (existing === undefined) {
                merged.set(identity, { ...error, viewports: [...error.viewports] });
                continue;
            }
            existing.viewports = mergeViewports(existing.viewports, error.viewports);
        }
    }
    return Array.from(merged.values());
};

const mergeTrees = <T extends MergeableNode<T>>(mobileRoots: T[], desktopRoots: T[], errors: StructureError[]): T[] => {
    const mobile = indexTree(mobileRoots);
    const desktop = indexTree(desktopRoots);
    // Both viewports analyze the same document, so documentOrder is comparable
    // across them; ordering the union by it interleaves viewport-only nodes at
    // their true document position instead of appending them after their peers.
    const order = [...new Set([...desktop.order, ...mobile.order])].sort((a, b) => {
        const nodeA = desktop.nodes.get(a) ?? mobile.nodes.get(a);
        const nodeB = desktop.nodes.get(b) ?? mobile.nodes.get(b);
        return (nodeA?.documentOrder ?? 0) - (nodeB?.documentOrder ?? 0);
    });
    const errorsByNode = groupBy(errors, (error) => error.nodeId);
    const mergedNodes = new Map<string, T>();

    for (const id of order) {
        const source = desktop.nodes.get(id) ?? mobile.nodes.get(id);
        if (source === undefined) {
            continue;
        }
        const viewports = STRUCTURE_VIEWPORT_ORDER.filter((viewport) =>
            (viewport === 'mobile' ? mobile.nodes : desktop.nodes).has(id),
        );
        mergedNodes.set(id, {
            ...source,
            viewports,
            errors: errorsByNode.get(id) ?? [],
            children: [],
        });
    }

    const roots: T[] = [];
    for (const id of order) {
        const node = mergedNodes.get(id);
        if (node === undefined) {
            continue;
        }
        const parentId = desktop.nodes.has(id) ? desktop.parents.get(id) : mobile.parents.get(id);
        const parent = parentId === null || parentId === undefined ? undefined : mergedNodes.get(parentId);
        if (parent === undefined) {
            roots.push(node);
        } else {
            parent.children.push(node);
        }
    }
    return roots;
};

/** Folds a domain's two viewport analyses into one tree with union viewport membership. */
export const mergeAnalyses = <T extends MergeableNode<T>>(
    analyses: Record<StructureViewport, MergeableAnalysis<T>>,
): MergeableAnalysis<T> => {
    const errors = mergeErrors([analyses.mobile, analyses.desktop]);
    return {
        nodes: mergeTrees(analyses.mobile.nodes, analyses.desktop.nodes, errors),
        errors,
    };
};
