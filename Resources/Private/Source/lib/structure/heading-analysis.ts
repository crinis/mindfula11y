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
 * Pure analysis of a rendered frontend document's exposed heading structure. Consumes
 * the `data-mindfula11y-*` annotations the ViewHelpers add to structure-analysis
 * requests and returns plain serializable nodes — no element references leak out.
 */

import { createErrorCollector } from './analysis.js';
import { extractChildTypeRecord, extractRecord, indexStructureNodes } from './annotations.js';
import { isElementExposed, resolveExposure } from './element-exposure.js';
import type { HeadingAnalysis, HeadingNode, HeadingRelation, StructureAnalysisOptions } from './types.js';
import { HEADING_ERROR_KEYS, StructureErrorSeverity } from './types.js';

const CONTAINER_SELECTOR = '[data-mindfula11y-container]';

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

/**
 * Analyzes exposed h1–h6 elements: builds the level-nested tree and detects missing H1
 * (page-level error), multiple H1 (warning per instance), empty headings (error)
 * and skipped levels (error per offending heading, `skippedLevels` counts the
 * gap for placeholder rendering).
 * A skip is an increase of more than one against the nearest shallower
 * predecessor; root headings — including those before the first H1 — never
 * skip (axe-core heading-order semantics).
 * Exception: a skip whose heading derives from a hidden container that has its
 * own row (relation target is a `kind: 'container'` node) is reported ONCE on
 * that container node instead — the row already displays the unrendered level
 * and hosts the child-type select that closes the gap, so per-heading
 * placeholders would duplicate and visually contradict it.
 */
export const analyzeHeadings = (doc: Document, options: StructureAnalysisOptions = {}): HeadingAnalysis => {
    const viewport = options.viewport ?? 'desktop';
    const isExposed = resolveExposure(options.isExposed);
    // Container parents are checked against raw exposure only: a wrapper's own
    // role="presentation" does not remove its children from the accessibility
    // tree, so the presentational-role half of resolveExposure must not apply here.
    const rawExposure = options.isExposed ?? isElementExposed;
    const candidates = Array.from(doc.querySelectorAll<HTMLElement>(`h1, h2, h3, h4, h5, h6, ${CONTAINER_SELECTOR}`));
    const index = indexStructureNodes(candidates, (element) => {
        const relationId = element.dataset.mindfula11yRelationId ?? '';
        return relationId === '' ? '' : `rel:${relationId}`;
    });
    // Container markers are deliberately hidden — their presence in a viewport is
    // decided by their PARENT's exposure, not their own.
    const exposed = candidates.filter((element) =>
        element.matches(CONTAINER_SELECTOR)
            ? element.parentElement === null || rawExposure(element.parentElement)
            : isExposed(element),
    );
    const headings = exposed.filter((element) => !element.matches(CONTAINER_SELECTOR));
    const collector = createErrorCollector(viewport);
    const rootNodes: HeadingNode[] = [];
    const parentStack: HeadingNode[] = [];
    // Overwritten as the document-order walk advances, so every lookup sees
    // the NEAREST PRECEDING publisher of a relation id. That mirrors
    // HeadingRelationRegistry, where a duplicate id (e.g. the same container
    // rendered twice via shortcut records) re-registers and descendants
    // resolve whatever was registered when they rendered.
    const nodesByRelationId = new Map<string, HeadingNode>();
    const h1Count = headings.filter((heading) => heading.tagName === 'H1').length;

    if (headings.length > 0 && h1Count === 0) {
        collector.pageError(HEADING_ERROR_KEYS.missingH1, StructureErrorSeverity.Error);
    }

    exposed.forEach((element) => {
        if (element.matches(CONTAINER_SELECTOR)) {
            const ownType = /^h([1-6])$/.exec(element.dataset.mindfula11yContainer ?? '');
            const container: HeadingNode = {
                id: index.get(element)?.id ?? '',
                documentOrder: index.get(element)?.documentOrder ?? 0,
                kind: 'container',
                level: ownType === null ? 0 : Number.parseInt(ownType[1] ?? '0', 10),
                label: '',
                availableTypes: {},
                availableChildTypes: {},
                record: extractRecord(element),
                childTypeRecord: extractChildTypeRecord(element),
                relationId: element.dataset.mindfula11yRelationId ?? '',
                relation: null,
                skippedLevels: 0,
                viewports: [viewport],
                errors: [],
                children: [],
            };
            if (container.relationId !== '') {
                nodesByRelationId.set(container.relationId, container);
            }
            // Containers attach to the current heading context but never open
            // one: they are not headings and join no level or error checks
            // of their own (skips of their derived headings are attributed
            // to them below).
            (parentStack.at(-1)?.children ?? rootNodes).push(container);
            return;
        }

        const level = Number.parseInt(element.tagName.charAt(1), 10);
        const record = extractRecord(element);
        const relationId = element.dataset.mindfula11yRelationId ?? '';
        const nodeId = index.get(element)?.id ?? '';
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
        const skippedLevels = parent === null ? 0 : Math.max(0, level - parent.level - 1);
        const relation = extractRelation(element);

        // A skip deriving from a hidden container with its own row belongs to
        // that row (see the function docblock): suppress the per-heading
        // placeholder and report once on the container instead.
        const relationTarget = relation === null ? undefined : nodesByRelationId.get(relation.targetRelationId);
        const attributedContainer = skippedLevels > 0 && relationTarget?.kind === 'container' ? relationTarget : null;

        const node: HeadingNode = {
            id: nodeId,
            documentOrder: index.get(element)?.documentOrder ?? 0,
            kind: 'heading',
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
            children: [],
        };
        if (relationId !== '') {
            nodesByRelationId.set(relationId, node);
        }

        if (h1Count > 1 && level === 1) {
            collector.nodeError(node, HEADING_ERROR_KEYS.multipleH1, StructureErrorSeverity.Warning);
        }
        if (label === '') {
            collector.nodeError(node, HEADING_ERROR_KEYS.emptyHeading, StructureErrorSeverity.Error);
        }
        if (attributedContainer !== null) {
            if (!attributedContainer.errors.some((error) => error.key === HEADING_ERROR_KEYS.skippedLevel)) {
                collector.nodeError(attributedContainer, HEADING_ERROR_KEYS.skippedLevel, StructureErrorSeverity.Error);
            }
        } else if (skippedLevels > 0) {
            collector.nodeError(node, HEADING_ERROR_KEYS.skippedLevel, StructureErrorSeverity.Error);
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
