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

// @vitest-environment happy-dom

import { beforeEach, describe, expect, it } from 'vitest';
import { createErrorCollector, mergeAnalyses } from '../../../../Resources/Private/Source/lib/structure/analysis.js';
import { analyzeHeadings } from '../../../../Resources/Private/Source/lib/structure/heading-analysis.js';
import { analyzeLandmarks } from '../../../../Resources/Private/Source/lib/structure/landmark-analysis.js';
import type {
    HeadingNode,
    LandmarkNode,
    StructureError,
} from '../../../../Resources/Private/Source/lib/structure/types.js';

const flattenHeadings = (nodes: HeadingNode[]): HeadingNode[] =>
    nodes.flatMap((node) => [node, ...flattenHeadings(node.children)]);
const flattenLandmarks = (nodes: LandmarkNode[]): LandmarkNode[] =>
    nodes.flatMap((node) => [node, ...flattenLandmarks(node.children)]);
const exposedAt =
    (viewport: string) =>
    (element: HTMLElement): boolean =>
        element.dataset.viewport === undefined || element.dataset.viewport === viewport;

describe('responsive structure analysis', () => {
    beforeEach(() => {
        document.body.replaceChildren();
    });

    it('evaluates heading order per viewport and merges nodes and findings', () => {
        document.body.innerHTML = `
            <h1>Title</h1>
            <h2 data-viewport="desktop">Desktop section</h2>
            <h3>Shared subsection</h3>
            <h2 data-viewport="mobile">Mobile section</h2>
        `;

        const mobile = analyzeHeadings(document, { viewport: 'mobile', isExposed: exposedAt('mobile') });
        const desktop = analyzeHeadings(document, { viewport: 'desktop', isExposed: exposedAt('desktop') });
        const merged = mergeAnalyses({ mobile, desktop });
        const shared = flattenHeadings(merged.nodes).find((node) => node.label === 'Shared subsection');

        expect(shared?.viewports).toEqual(['mobile', 'desktop']);
        expect(shared?.errors).toHaveLength(1);
        expect(shared?.errors[0]?.viewports).toEqual(['mobile']);
        expect(merged.errors).toHaveLength(1);
    });

    it('does not report responsive navigation copies as duplicates across viewports', () => {
        document.body.innerHTML = `
            <main></main>
            <nav data-viewport="mobile" aria-label="Primary"><a href="/one">One</a></nav>
            <nav data-viewport="desktop" aria-label="Primary"><a href="/one">One</a></nav>
        `;

        const mobile = analyzeLandmarks(document, { viewport: 'mobile', isExposed: exposedAt('mobile') });
        const desktop = analyzeLandmarks(document, { viewport: 'desktop', isExposed: exposedAt('desktop') });
        const merged = mergeAnalyses({ mobile, desktop });
        const navigations = flattenLandmarks(merged.nodes).filter((node) => node.role === 'navigation');

        expect(merged.errors).toEqual([]);
        // The mobile nav precedes the desktop nav in the document, so the merged
        // outline lists it first — merge order follows true document order.
        expect(navigations.map((node) => node.viewports)).toEqual([['mobile'], ['desktop']]);
    });

    it('places a mobile-only heading at its document position, not after its desktop siblings', () => {
        document.body.innerHTML = `
            <h1>Title</h1>
            <h2 data-viewport="mobile">Mobile first</h2>
            <h2>Shared</h2>
        `;

        const mobile = analyzeHeadings(document, { viewport: 'mobile', isExposed: exposedAt('mobile') });
        const desktop = analyzeHeadings(document, { viewport: 'desktop', isExposed: exposedAt('desktop') });
        const merged = mergeAnalyses({ mobile, desktop });
        const titleChildren = merged.nodes[0]?.children ?? [];

        expect(titleChildren.map((node) => node.label)).toEqual(['Mobile first', 'Shared']);
    });

    it('merges the same page-level finding into one viewport-scoped occurrence', () => {
        document.body.innerHTML = '<h2>Section</h2>';
        const mobile = analyzeHeadings(document, { viewport: 'mobile', isExposed: () => true });
        const desktop = analyzeHeadings(document, { viewport: 'desktop', isExposed: () => true });
        const merged = mergeAnalyses({ mobile, desktop });

        expect(merged.errors).toHaveLength(1);
        expect(merged.errors[0]?.viewports).toEqual(['mobile', 'desktop']);
    });
});

describe('createErrorCollector', () => {
    it('pushes the identical error object into both node.errors and collector.errors', () => {
        const collector = createErrorCollector('desktop');
        const node: { id: string; errors: StructureError[] } = { id: 'node-1', errors: [] };

        collector.nodeError(node, 'mindfula11y.structure.some.error', 'moderate');

        expect(node.errors).toHaveLength(1);
        expect(collector.errors).toHaveLength(1);
        expect(collector.errors[0]).toBe(node.errors[0]);
        expect(node.errors[0]).toEqual({
            key: 'mindfula11y.structure.some.error',
            severity: 'moderate',
            nodeId: 'node-1',
            viewports: ['desktop'],
        });
    });

    it('produces a page-level error with a null nodeId and the collector viewport', () => {
        const collector = createErrorCollector('mobile');

        collector.pageError('mindfula11y.structure.some.pageError', 'minor');

        expect(collector.errors).toEqual([
            {
                key: 'mindfula11y.structure.some.pageError',
                severity: 'minor',
                nodeId: null,
                viewports: ['mobile'],
            },
        ]);
    });
});
