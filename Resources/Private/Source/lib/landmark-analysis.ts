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
 * Pure analysis of a parsed frontend document's landmark structure. Consumes
 * the `data-mindfula11y-*` annotations the ViewHelpers add to structure-analysis
 * requests and returns plain serializable nodes — no element references leak out.
 */

import { buildStructureNodeId, extractRecord, parseJsonMap } from './dom.js';
import type { LandmarkAnalysis, LandmarkNode, StructureError } from './types.js';
import { StructureErrorSeverity } from './types.js';

const ERROR_KEYS = {
    missingMain: 'mindfula11y.structure.landmarks.error.missingMain',
    duplicateMain: 'mindfula11y.structure.landmarks.error.duplicateMain',
    duplicateSameLabel: 'mindfula11y.structure.landmarks.error.duplicateSameLabel',
    multipleUnlabeled: 'mindfula11y.structure.landmarks.error.multipleUnlabeledLandmarks',
} as const;

/** Explicit ARIA roles plus semantic elements; header/footer only outside sectioning content. */
const LANDMARK_SELECTOR = [
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

const IMPLICIT_ROLES: Record<string, string> = {
    main: 'main',
    nav: 'navigation',
    aside: 'complementary',
    header: 'banner',
    footer: 'contentinfo',
    form: 'form',
};

/** Accessible name per aria-label, aria-labelledby targets, then title. */
const resolveLabel = (element: HTMLElement, doc: Document): string => {
    const ariaLabel = element.getAttribute('aria-label')?.trim() ?? '';
    if (ariaLabel !== '') {
        return ariaLabel;
    }
    const labelledby = element.getAttribute('aria-labelledby')?.trim() ?? '';
    if (labelledby !== '') {
        const referencedLabel = labelledby
            .split(/\s+/)
            .map((id) => doc.getElementById(id)?.textContent?.trim() ?? '')
            .filter((text) => text !== '')
            .join(' ');
        if (referencedLabel !== '') {
            return referencedLabel;
        }
    }
    return element.getAttribute('title')?.trim() ?? '';
};

const resolveRole = (element: HTMLElement, label: string): string => {
    const explicitRole = element.getAttribute('role');
    if (explicitRole !== null && explicitRole !== '') {
        return explicitRole;
    }
    const tagName = element.tagName.toLowerCase();
    if (tagName === 'section') {
        return label !== '' ? 'region' : '';
    }
    return IMPLICIT_ROLES[tagName] ?? '';
};

/**
 * Analyzes the document's landmarks: nests them by containment (nearest landmark
 * ancestor) and detects missing main (page-level error), multiple main (error per
 * instance), duplicate identical labels (error per instance) and multiple unlabeled
 * landmarks sharing a role (warning per instance, `main` exempt).
 */
export const analyzeLandmarks = (doc: Document): LandmarkAnalysis => {
    // Labels are resolved once per element and reused by both the section
    // filter and node building below (resolving aria-labelledby walks the DOM).
    const labels = new Map<HTMLElement, string>();
    const labelOf = (element: HTMLElement): string => {
        let label = labels.get(element);
        if (label === undefined) {
            label = resolveLabel(element, doc);
            labels.set(element, label);
        }
        return label;
    };

    const elements = Array.from(doc.querySelectorAll<HTMLElement>(LANDMARK_SELECTOR)).filter((element) => {
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

    const seenIds = new Map<string, number>();
    const nodesByElement = new Map<HTMLElement, LandmarkNode>();
    const flat: LandmarkNode[] = [];

    elements.forEach((element, index) => {
        const label = labelOf(element);
        const record = extractRecord(element);
        const node: LandmarkNode = {
            id: buildStructureNodeId(record, index, seenIds),
            role: resolveRole(element, label),
            label,
            availableRoles: parseJsonMap(element.dataset.mindfula11yAvailableRoles),
            record,
            errors: [],
            children: [],
        };
        nodesByElement.set(element, node);
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

    const errors: StructureError[] = [];
    const addNodeError = (node: LandmarkNode, key: string, severity: StructureErrorSeverity): void => {
        const error: StructureError = { key, severity, nodeId: node.id };
        node.errors.push(error);
        errors.push(error);
    };

    if (flat.length > 0) {
        const mains = flat.filter((node) => node.role === 'main');
        if (mains.length === 0) {
            errors.push({ key: ERROR_KEYS.missingMain, severity: StructureErrorSeverity.Error, nodeId: null });
        } else if (mains.length > 1) {
            for (const node of mains) {
                addNodeError(node, ERROR_KEYS.duplicateMain, StructureErrorSeverity.Error);
            }
        }

        const byRoleAndLabel = new Map<string, LandmarkNode[]>();
        for (const node of flat) {
            if (node.label === '') {
                continue;
            }
            const key = `${node.role}\u0000${node.label}`;
            const group = byRoleAndLabel.get(key);
            if (group === undefined) {
                byRoleAndLabel.set(key, [node]);
            } else {
                group.push(node);
            }
        }
        for (const group of byRoleAndLabel.values()) {
            if (group.length < 2) {
                continue;
            }
            for (const node of group) {
                addNodeError(node, ERROR_KEYS.duplicateSameLabel, StructureErrorSeverity.Error);
            }
        }

        const byRole = new Map<string, LandmarkNode[]>();
        for (const node of flat) {
            if (node.role === '' || node.role === 'main') {
                continue;
            }
            const group = byRole.get(node.role);
            if (group === undefined) {
                byRole.set(node.role, [node]);
            } else {
                group.push(node);
            }
        }
        for (const group of byRole.values()) {
            if (group.length < 2) {
                continue;
            }
            const unlabeled = group.filter((node) => node.label === '');
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
