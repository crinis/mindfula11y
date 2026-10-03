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

import type { RecordReference } from '../types.js';
import { createErrorCollector } from './analysis.js';
import { extractChildTypeRecord, extractRecord, indexStructureNodes } from './annotations.js';
import { explicitRole, isElementExposed, resolveExposure } from './element-exposure.js';
import type {
    HeadingAnalysis,
    HeadingNode,
    HeadingRelation,
    StructureAnalysisOptions,
    StructureError,
} from './types.js';
import { HEADING_ERROR_KEYS } from './types.js';

const CONTAINER_SELECTOR = '[data-mindfula11y-container]';
const DEMOTED_SELECTOR = '[data-mindfula11y-demoted]';
const NON_HEADING_SELECTOR = `${CONTAINER_SELECTOR}, ${DEMOTED_SELECTOR}`;
/** Elements that may be headings; resolveHeadingLevel() decides. Any role is matched, so its case does not matter. */
const HEADING_CANDIDATE_SELECTOR = 'h1, h2, h3, h4, h5, h6, [role]';

/** ARIA's implicit aria-level of an element with role="heading". */
const DEFAULT_ARIA_HEADING_LEVEL = 2;
/** The deepest level of the structure model: h6, the deepest type the level select offers. */
const MAX_HEADING_LEVEL = 6;

/**
 * The level assistive technology announces for an element, or `null` when the
 * element is no heading. An h1–h6 keeps its native heading role unless an
 * explicit role replaces it (`role="tab"` …; explicitRole() resolves the
 * first concrete role of a token list and skips unknown or abstract tokens,
 * as browsers do) — none/presentation is left to the exposure check, whose
 * conflict resolution may keep the native role — and any element with
 * `role="heading"` is one. A valid `aria-level` (a positive integer)
 * overrides the tag's level; `role="heading"` without one is level 2. Deeper
 * ARIA levels are reported as level 6.
 */
const resolveHeadingLevel = (element: HTMLElement): number | null => {
    const role = explicitRole(element);
    const tagLevel = /^H([1-6])$/.exec(element.tagName)?.[1];
    const isHeading =
        role === 'heading' || (tagLevel !== undefined && (role === '' || role === 'none' || role === 'presentation'));
    if (!isHeading) {
        return null;
    }
    const ariaLevel = element.getAttribute('aria-level')?.trim() ?? '';
    const level = /^[1-9]\d*$/.test(ariaLevel)
        ? Number(ariaLevel)
        : tagLevel !== undefined
          ? Number(tagLevel)
          : DEFAULT_ARIA_HEADING_LEVEL;
    return Math.min(level, MAX_HEADING_LEVEL);
};

/**
 * A heading's record coordinates. The level select shows the record's stored
 * type, which a rendered heading implies by its tag name — the annotation
 * omits the value then, and the select falls back to the node's level. When
 * the announced level differs from the tag (an `aria-level` override, or a
 * `role="heading"` element), that fallback would show the announced level
 * instead, so the tag name is stated explicitly.
 */
const extractHeadingRecord = (element: HTMLElement, level: number): RecordReference | null => {
    const record = extractRecord(element);
    const tagType = element.tagName.toLowerCase();
    if (record === null || record.storedValue !== undefined || tagType === `h${level}`) {
        return record;
    }
    return { ...record, storedValue: tagType };
};

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
 * Analyzes exposed headings — h1–h6 and `role="heading"` elements, each at the
 * level assistive technology announces, which every check below uses (see
 * resolveHeadingLevel(): `aria-level` overrides the tag, another explicit
 * role removes the heading). Builds the level-nested tree and detects missing H1
 * (page-level, moderate — axe `page-has-heading-one`), multiple H1 (minor per
 * instance — not an axe rule, pure best-practice advice), empty headings
 * (minor — axe `empty-heading`), skipped levels (moderate per offending
 * heading — axe `heading-order`; `skippedLevels` counts the gap for
 * placeholder rendering) and root headings deeper than H2 (minor per
 * heading — no axe equivalent).
 * Besides headings, the tree carries hidden container markers and rendered
 * demoted tags (`data-mindfula11y-demoted`, p/div): both attach to the current
 * heading context without opening a level or joining any heading check, so
 * every element wired to a heading ViewHelper keeps exactly one row.
 * A skip is an increase of more than one against the nearest shallower
 * predecessor; root headings — including those before the first H1 — never
 * skip (axe-core heading-order semantics). A root heading deeper than H2
 * instead gets a minor deep-root advisory (stricter than axe, which passes
 * any first heading): the W3C-sanctioned level for pre-H1 region labels
 * (nav, sidebar) is H2, so the outline must not open mid-hierarchy. An H2
 * before the H1 therefore stays finding-free.
 * Exception: a hierarchy finding (skip or deep root) whose heading derives from
 * a hidden container that has its own row (relation target is a
 * `kind: 'container'` node) is reported ONCE on that container node instead —
 * the row already displays the unrendered level and hosts the child-type
 * select that closes the gap, so per-heading placeholders would duplicate and
 * visually contradict it.
 */
export const analyzeHeadings = (doc: Document, options: StructureAnalysisOptions = {}): HeadingAnalysis => {
    const viewport = options.viewport ?? 'desktop';
    const isExposed = resolveExposure(options.isExposed);
    // Container parents are checked against raw exposure only: a wrapper's own
    // role="presentation" does not remove its children from the accessibility
    // tree, so the presentational-role half of resolveExposure must not apply here.
    const rawExposure = options.isExposed ?? isElementExposed;
    // Container markers and demoted tags take precedence over any heading
    // semantics of the same element (their annotation is what the row edits).
    const headingLevels = new Map<HTMLElement, number>();
    const candidates = Array.from(
        doc.querySelectorAll<HTMLElement>(`${HEADING_CANDIDATE_SELECTOR}, ${NON_HEADING_SELECTOR}`),
    ).filter((element) => {
        if (element.matches(NON_HEADING_SELECTOR)) {
            return true;
        }
        const level = resolveHeadingLevel(element);
        if (level !== null) {
            headingLevels.set(element, level);
        }
        return level !== null;
    });
    // Every heading candidate has a level; the fallback only satisfies the type check.
    const levelOf = (element: HTMLElement): number => headingLevels.get(element) ?? DEFAULT_ARIA_HEADING_LEVEL;
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
    const headings = exposed.filter((element) => !element.matches(NON_HEADING_SELECTOR));
    const collector = createErrorCollector(viewport);
    const rootNodes: HeadingNode[] = [];
    const parentStack: HeadingNode[] = [];
    // Overwritten as the document-order walk advances, so every lookup sees
    // the NEAREST PRECEDING publisher of a relation id. That mirrors
    // HeadingRelationRegistry, where a duplicate id (e.g. the same container
    // rendered twice via shortcut records) re-registers and descendants
    // resolve whatever was registered when they rendered.
    const nodesByRelationId = new Map<string, HeadingNode>();
    const h1Count = headings.filter((heading) => levelOf(heading) === 1).length;

    if (headings.length > 0 && h1Count === 0) {
        collector.pageError(HEADING_ERROR_KEYS.missingH1, 'moderate');
    }

    // Hidden container markers and rendered demoted tags (p/div) share one row
    // shape: a non-heading node that attaches to the current heading context
    // but opens no level and joins no heading check. Only kind, level, label
    // (containers render no text of their own) and relation vary. Registering
    // it by relation id keeps a suppressed container — or one whose own header
    // rendered as a paragraph — resolvable as its descendants' jump target and
    // child-type host.
    const attachNonHeadingNode = (
        element: HTMLElement,
        kind: 'container' | 'demoted',
        level: number,
        relation: HeadingRelation | null,
    ): void => {
        // The marker/discriminator attribute names the element's non-heading
        // type; preserved so read-only chips can label rows that carry no
        // record value (e.g. relation-derived demoted descendants).
        const rawType = kind === 'demoted' ? element.dataset.mindfula11yDemoted : element.dataset.mindfula11yContainer;
        const node: HeadingNode = {
            id: index.get(element)?.id ?? '',
            documentOrder: index.get(element)?.documentOrder ?? 0,
            kind,
            level,
            ...(rawType === 'p' || rawType === 'div' ? { nonHeadingType: rawType } : {}),
            label: kind === 'demoted' ? (element.textContent?.trim() ?? '') : '',
            availableTypes: {},
            availableChildTypes: {},
            record: extractRecord(element),
            childTypeRecord: extractChildTypeRecord(element),
            relationId: element.dataset.mindfula11yRelationId ?? '',
            relation,
            skippedLevels: 0,
            viewports: [viewport],
            errors: [],
            children: [],
        };
        if (node.relationId !== '') {
            nodesByRelationId.set(node.relationId, node);
        }
        (parentStack.at(-1)?.children ?? rootNodes).push(node);
    };

    exposed.forEach((element) => {
        // Containers never open a level or join an error check of their own;
        // hierarchy findings of their derived headings are attributed to them
        // below.
        if (element.matches(CONTAINER_SELECTOR)) {
            const ownType = /^h([1-6])$/.exec(element.dataset.mindfula11yContainer ?? '');
            attachNonHeadingNode(
                element,
                'container',
                ownType === null ? 0 : Number.parseInt(ownType[1] ?? '0', 10),
                null,
            );
            return;
        }

        // A rendered non-heading tag (p/div) from a heading ViewHelper: kept as
        // a row so its type stays editable from the module, but — like
        // containers — it opens no level and joins no heading check.
        if (element.matches(DEMOTED_SELECTOR)) {
            attachNonHeadingNode(element, 'demoted', 0, extractRelation(element));
            return;
        }

        const level = levelOf(element);
        const record = extractHeadingRecord(element, level);
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

        // At most one hierarchy finding applies per heading — a root heading
        // (parent === null) forces skippedLevels to 0, so the two conditions
        // are mutually exclusive (which is also what keeps the skippedLevels
        // zeroing below a no-op for the deep-root branch). Severities and
        // rationale: see the function docblock.
        let hierarchyFinding: Pick<StructureError, 'key' | 'severity'> | null = null;
        if (skippedLevels > 0) {
            hierarchyFinding = { key: HEADING_ERROR_KEYS.skippedLevel, severity: 'moderate' };
        } else if (parent === null && level > 2) {
            hierarchyFinding = { key: HEADING_ERROR_KEYS.deepRootHeading, severity: 'minor' };
        }

        // A hierarchy finding deriving from a hidden container with its own
        // row belongs to that row (see the function docblock): suppress the
        // per-heading cue and report once on the container instead.
        const relationTarget = relation === null ? undefined : nodesByRelationId.get(relation.targetRelationId);
        const attributedContainer =
            hierarchyFinding !== null && relationTarget?.kind === 'container' ? relationTarget : null;

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
            collector.nodeError(node, HEADING_ERROR_KEYS.multipleH1, 'minor');
        }
        if (label === '') {
            collector.nodeError(node, HEADING_ERROR_KEYS.emptyHeading, 'minor');
        }
        if (hierarchyFinding !== null) {
            // The heading's own node is freshly built, so the once-per-row
            // guard only ever bites on a container collecting several
            // derived headings.
            const { key, severity } = hierarchyFinding;
            const target = attributedContainer ?? node;
            if (!target.errors.some((error) => error.key === key)) {
                collector.nodeError(target, key, severity);
            }
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
