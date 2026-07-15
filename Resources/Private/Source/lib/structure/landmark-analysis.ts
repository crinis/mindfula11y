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
 * Pure analysis of a rendered frontend document's exposed landmark structure. Consumes
 * the `data-mindfula11y-*` annotations the ViewHelpers add to structure-analysis
 * requests and returns plain serializable nodes — no element references leak out.
 */

import { extractRecord, indexStructureNodes } from '../dom.js';
import type { LandmarkAnalysis, LandmarkNode, StructureAnalysisOptions } from '../types.js';
import { StructureErrorSeverity } from '../types.js';
import { createErrorCollector, groupBy } from './analysis.js';
import type { ElementExposurePredicate } from './element-exposure.js';
import { resolveExposure } from './element-exposure.js';

const ERROR_KEYS = {
    missingMain: 'mindfula11y.structure.landmarks.error.missingMain',
    duplicateMain: 'mindfula11y.structure.landmarks.error.duplicateMain',
    duplicateSameLabel: 'mindfula11y.structure.landmarks.error.duplicateSameLabel',
    multipleUnlabeled: 'mindfula11y.structure.landmarks.error.multipleUnlabeledLandmarks',
} as const;

/**
 * Explicit ARIA roles plus semantic elements; header/footer only outside sectioning
 * content. Exported for the test suite's tripwire on the sectioning-scope exclusions
 * (happy-dom cannot evaluate the self-referential `:not()` compounds behaviorally).
 */
export const LANDMARK_SELECTOR = [
    '[role="banner"]',
    '[role="main"]',
    '[role="navigation"]',
    '[role="complementary"]',
    '[role="contentinfo"]',
    '[role="region"]',
    '[role="search"]',
    '[role="form"]',
    'main',
    'nav',
    'aside',
    'form',
    'header:not(article header, aside header, footer header, header header, main header, nav header, section header)',
    'footer:not(article footer, aside footer, footer footer, header footer, main footer, nav footer, section footer)',
    'section[aria-label]',
    'section[aria-labelledby]',
    'section[title]',
].join(', ');

// Keep in sync with AriaLandmark::element() in Classes/Enum/AriaLandmark.php (that method
// is the inverse: role -> element).
const IMPLICIT_ROLES: Record<string, string> = {
    main: 'main',
    nav: 'navigation',
    aside: 'complementary',
    header: 'banner',
    footer: 'contentinfo',
    form: 'form',
};

const resolveLabelledby = (element: HTMLElement, doc: Document): string => {
    const labelledby = element.getAttribute('aria-labelledby')?.trim() ?? '';
    if (labelledby === '') {
        return '';
    }

    return labelledby
        .split(/\s+/)
        .map((id) => doc.getElementById(id)?.textContent?.trim() ?? '')
        .filter((text) => text !== '')
        .join(' ');
};

/**
 * Accessible name per aria-labelledby targets, aria-label, the caller's content
 * fallback, then title. Landmarks name from attributes only; roles whose name
 * comes from contents (links) pass a collector.
 */
const resolveAccessibleName = (element: HTMLElement, doc: Document, contentFallback: () => string = () => ''): string =>
    resolveLabelledby(element, doc) ||
    (element.getAttribute('aria-label')?.trim() ?? '') ||
    contentFallback() ||
    (element.getAttribute('title')?.trim() ?? '');

const resolveRole = (element: HTMLElement, label: string): string => {
    const explicitRole = element.getAttribute('role')?.trim().toLowerCase() ?? '';
    if (explicitRole !== '' && explicitRole !== 'none' && explicitRole !== 'presentation') {
        return explicitRole;
    }
    const tagName = element.tagName.toLowerCase();
    if (tagName === 'section') {
        return label !== '' ? 'region' : '';
    }
    return IMPLICIT_ROLES[tagName] ?? '';
};

const resolveLinkName = (link: HTMLAnchorElement, doc: Document): string =>
    resolveAccessibleName(link, doc, () =>
        [
            link.textContent ?? '',
            ...Array.from(link.querySelectorAll<HTMLElement>('img[alt], input[type="image"][alt]')).map(
                (element) => element.getAttribute('alt') ?? '',
            ),
        ]
            .join(' ')
            .replace(/\s+/g, ' ')
            .trim(),
    );

const navigationSignature = (element: HTMLElement, doc: Document, isExposed: ElementExposurePredicate): string => {
    const links = Array.from(element.querySelectorAll<HTMLAnchorElement>('a[href]'))
        .filter(isExposed)
        .map((link) => `${resolveLinkName(link, doc)}\u0000${link.href}`)
        .sort();
    return links.length === 0 ? '' : JSON.stringify(links);
};

/**
 * Analyzes the document's exposed landmarks: nests them by containment (nearest landmark
 * ancestor) and detects missing main (page-level error), multiple main (error per
 * instance), ambiguous identical labels (warning per instance) and multiple unlabeled
 * landmarks sharing a role (warning per instance, `main` exempt).
 */
export const analyzeLandmarks = (doc: Document, options: StructureAnalysisOptions = {}): LandmarkAnalysis => {
    const viewport = options.viewport ?? 'desktop';
    const isExposed = resolveExposure(options.isExposed);
    // Labels are resolved once per element and reused by both the section
    // filter and node building below (resolving aria-labelledby walks the DOM).
    const labels = new Map<HTMLElement, string>();
    const labelOf = (element: HTMLElement): string => {
        let label = labels.get(element);
        if (label === undefined) {
            label = resolveAccessibleName(element, doc);
            labels.set(element, label);
        }
        return label;
    };

    const candidates = Array.from(doc.querySelectorAll<HTMLElement>(LANDMARK_SELECTOR));
    const index = indexStructureNodes(candidates);
    const elements = candidates.filter((element) => {
        if (!isExposed(element)) {
            return false;
        }
        const label = labelOf(element);
        const tagName = element.tagName.toLowerCase();

        // A native form only gains the implicit form landmark role when it has
        // an accessible name. Explicit ARIA roles are kept: even an invalidly
        // unnamed role="form" / role="region" is still exposed as that role
        // and should remain visible to the analyzer.
        if (tagName === 'form' && !element.hasAttribute('role')) {
            return label !== '';
        }

        // Native sections are regions only when they have an accessible name.
        return tagName !== 'section' || element.hasAttribute('role') || label !== '';
    });

    const nodesByElement = new Map<HTMLElement, LandmarkNode>();
    const elementByNode = new Map<LandmarkNode, HTMLElement>();
    const flat: LandmarkNode[] = [];

    elements.forEach((element) => {
        const label = labelOf(element);
        const record = extractRecord(element);
        const node: LandmarkNode = {
            id: index.get(element)?.id ?? '',
            documentOrder: index.get(element)?.documentOrder ?? 0,
            role: resolveRole(element, label),
            label,
            availableRoles: {},
            record,
            viewports: [viewport],
            errors: [],
            children: [],
        };
        nodesByElement.set(element, node);
        elementByNode.set(node, element);
        flat.push(node);
    });

    // Containment: attach each landmark to its nearest landmark ancestor.
    const rootNodes: LandmarkNode[] = [];
    elements.forEach((element) => {
        const node = nodesByElement.get(element);
        if (node === undefined) {
            return;
        }
        let ancestor = element.parentElement;
        while (ancestor !== null && !nodesByElement.has(ancestor)) {
            ancestor = ancestor.parentElement;
        }
        const parent = ancestor === null ? undefined : nodesByElement.get(ancestor);
        if (parent === undefined) {
            rootNodes.push(node);
        } else {
            parent.children.push(node);
        }
    });

    const collector = createErrorCollector(viewport);

    if (flat.length > 0) {
        const mains = flat.filter((node) => node.role === 'main');
        if (mains.length === 0) {
            collector.pageError(ERROR_KEYS.missingMain, StructureErrorSeverity.Error);
        } else if (mains.length > 1) {
            for (const node of mains) {
                collector.nodeError(node, ERROR_KEYS.duplicateMain, StructureErrorSeverity.Error);
            }
        }

        const byRoleAndLabel = groupBy(flat, (node) =>
            node.label === '' || node.role === 'main' ? null : `${node.role}\u0000${node.label}`,
        );
        for (const group of byRoleAndLabel.values()) {
            if (group.length < 2) {
                continue;
            }
            // Responsive copies of one navigation legitimately share a label:
            // an identical, non-empty link set means identical content, not ambiguity.
            const signatures = group.every((node) => node.role === 'navigation')
                ? group.map((node) => {
                      const element = elementByNode.get(node);
                      return element === undefined ? '' : navigationSignature(element, doc, isExposed);
                  })
                : [];
            const identicalNavigation =
                signatures[0] !== undefined && signatures[0] !== '' && new Set(signatures).size === 1;
            if (!identicalNavigation) {
                for (const node of group) {
                    collector.nodeError(node, ERROR_KEYS.duplicateSameLabel, StructureErrorSeverity.Warning);
                }
            }
        }

        const unlabeledByRole = groupBy(flat, (node) =>
            node.label !== '' || node.role === '' || node.role === 'main' ? null : node.role,
        );
        for (const group of unlabeledByRole.values()) {
            if (group.length < 2) {
                continue;
            }
            for (const node of group) {
                collector.nodeError(node, ERROR_KEYS.multipleUnlabeled, StructureErrorSeverity.Warning);
            }
        }
    }

    return { nodes: rootNodes, errors: collector.errors };
};
