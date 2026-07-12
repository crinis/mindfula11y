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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 */

/**
 * Pure analysis of a parsed frontend document's heading structure. Consumes
 * the `data-mindfula11y-*` annotations the ViewHelpers add to structure-analysis
 * requests and returns plain serializable nodes — no element references leak out.
 */

import { extractRecord, parseJsonMap } from './dom.js';
import type { HeadingAnalysis, HeadingNode, HeadingRelation, RecordReference, StructureError } from './types.js';
import { StructureErrorSeverity } from './types.js';

const ERROR_KEYS = {
    missingH1: 'mindfula11y.structure.headings.error.missingH1',
    multipleH1: 'mindfula11y.structure.headings.error.multipleH1',
    emptyHeading: 'mindfula11y.structure.headings.error.emptyHeadings',
    skippedLevel: 'mindfula11y.structure.headings.error.skippedLevel',
} as const;

const extractRelation = (element: HTMLElement): HeadingRelation | null => {
    const ancestorId = element.dataset.mindfula11yAncestorId ?? '';
    if (ancestorId !== '') {
        return { kind: 'ancestor', targetRelationId: ancestorId };
    }
    const siblingId = element.dataset.mindfula11ySiblingId ?? '';
    if (siblingId !== '') {
        return { kind: 'sibling', targetRelationId: siblingId };
    }
    return null;
};

/** Builds a repeat()-stable node id; an occurrence counter disambiguates repeated records. */
const buildNodeId = (
    record: RecordReference | null,
    relationId: string,
    index: number,
    seen: Map<string, number>,
): string => {
    let base: string;
    if (record !== null) {
        base = `${record.tableName}:${record.uid}:${record.columnName}`;
    } else if (relationId !== '') {
        base = `rel:${relationId}`;
    } else {
        return `pos:${index}`;
    }
    const occurrence = seen.get(base) ?? 0;
    seen.set(base, occurrence + 1);
    return occurrence === 0 ? base : `${base}#${occurrence}`;
};

/**
 * Analyzes h1–h6 elements: builds the level-nested tree and detects missing H1
 * (page-level error), multiple H1 (warning per instance), empty headings (error)
 * and skipped levels (error per offending heading, `skippedLevels` counts the
 * gap for placeholder rendering — repeated parent/child combinations included).
 * A skip is an increase of more than one against the nearest shallower
 * predecessor; root headings — including those before the first H1 — never
 * skip (axe-core heading-order semantics).
 */
export const analyzeHeadings = (doc: Document): HeadingAnalysis => {
    const headings = Array.from(doc.querySelectorAll<HTMLElement>('h1, h2, h3, h4, h5, h6'));
    const errors: StructureError[] = [];
    const rootNodes: HeadingNode[] = [];
    const parentStack: HeadingNode[] = [];
    const skippedCombinations = new Map<number, Set<number>>();
    const seenIds = new Map<string, number>();
    const h1Count = headings.filter((heading) => heading.tagName === 'H1').length;

    if (headings.length > 0 && h1Count === 0) {
        errors.push({ key: ERROR_KEYS.missingH1, severity: StructureErrorSeverity.Error, nodeId: null });
    }

    headings.forEach((element, index) => {
        const level = Number.parseInt(element.tagName.charAt(1), 10);
        const record = extractRecord(element);
        const relationId = element.dataset.mindfula11yRelationId ?? '';
        const nodeId = buildNodeId(record, relationId, index, seenIds);
        const label = element.textContent?.trim() ?? '';

        // Pop parents at the same level or deeper, then measure the gap to the
        // remaining parent. Root nodes (no shallower heading before them) are
        // never a skip: headings may legitimately precede the first <h1> as
        // region labels (nav, sidebar — a W3C WAI-recommended pattern), and
        // decreases in rank never skip anything. This matches axe-core's
        // heading-order rule, which only fails increases of more than one
        // against the nearest shallower predecessor.
        while ((parentStack.at(-1)?.level ?? 0) >= level) {
            parentStack.pop();
        }
        const parent = parentStack.at(-1) ?? null;
        const directSkips = parent === null ? 0 : Math.max(0, level - parent.level - 1);
        const parentLevel = parent === null ? 0 : parent.level;

        // A parent/child level combination that skipped once is flagged on every
        // repetition so identical siblings render consistently (legacy behavior).
        let skippedLevels = directSkips;
        if (directSkips > 0) {
            const children = skippedCombinations.get(parentLevel) ?? new Set<number>();
            children.add(level);
            skippedCombinations.set(parentLevel, children);
        } else if (skippedCombinations.get(parentLevel)?.has(level) === true) {
            skippedLevels = Math.max(0, level - parentLevel - 1);
        }

        const nodeErrors: StructureError[] = [];
        if (h1Count > 1 && level === 1) {
            nodeErrors.push({ key: ERROR_KEYS.multipleH1, severity: StructureErrorSeverity.Warning, nodeId });
        }
        if (label === '') {
            nodeErrors.push({ key: ERROR_KEYS.emptyHeading, severity: StructureErrorSeverity.Error, nodeId });
        }
        if (skippedLevels > 0) {
            nodeErrors.push({ key: ERROR_KEYS.skippedLevel, severity: StructureErrorSeverity.Error, nodeId });
        }
        errors.push(...nodeErrors);

        const node: HeadingNode = {
            id: nodeId,
            level,
            label,
            availableTypes: parseJsonMap(element.dataset.mindfula11yAvailableTypes),
            record,
            relationId,
            relation: extractRelation(element),
            skippedLevels,
            errors: nodeErrors,
            children: [],
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
