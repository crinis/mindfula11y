/*
 * Mindful A11y extension for TYPO3 integrating accessibility tools into the backend.
 * Copyright (C) 2026  Mindful Markup, Felix Spittel
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 */

// @vitest-environment happy-dom

import { beforeEach, describe, expect, it } from 'vitest';
import { analyzeHeadings } from '../../../../Resources/Private/Source/lib/structure/heading-analysis.js';
import type { HeadingNode } from '../../../../Resources/Private/Source/lib/types.js';

const flatten = (nodes: HeadingNode[]): HeadingNode[] => nodes.flatMap((node) => [node, ...flatten(node.children)]);

describe('analyzeHeadings', () => {
    beforeEach(() => {
        document.body.replaceChildren();
    });

    it('flags missingH1 when no h1 is present', () => {
        document.body.innerHTML = `
            <h2>Section</h2>
        `;

        const analysis = analyzeHeadings(document);
        expect(analysis.errors).toHaveLength(1);
        expect(analysis.errors[0]?.key.endsWith('missingH1')).toBe(true);
        expect(analysis.errors[0]?.severity).toBe('error');
        expect(analysis.errors[0]?.nodeId).toBeNull();
    });

    it('does not flag missingH1 when an h1 is present', () => {
        document.body.innerHTML = `
            <h1>Title</h1>
        `;

        const analysis = analyzeHeadings(document);
        expect(analysis.errors.some((error) => error.key.endsWith('missingH1'))).toBe(false);
    });

    it('flags multipleH1 once per extra h1', () => {
        document.body.innerHTML = `
            <h1>First</h1>
            <h1>Second</h1>
        `;

        const analysis = analyzeHeadings(document);
        const multipleH1Errors = analysis.errors.filter((error) => error.key.endsWith('multipleH1'));
        expect(multipleH1Errors).toHaveLength(2);
        expect(multipleH1Errors.every((error) => error.severity === 'warning')).toBe(true);
        expect(multipleH1Errors.every((error) => error.nodeId !== null)).toBe(true);
    });

    it('flags emptyHeading for a visually-exposed heading with no accessible text', () => {
        document.body.innerHTML = `
            <h1>Title</h1>
            <h2></h2>
        `;

        const analysis = analyzeHeadings(document);
        const emptyHeadingErrors = analysis.errors.filter((error) => error.key.endsWith('emptyHeadings'));
        expect(emptyHeadingErrors).toHaveLength(1);
        expect(emptyHeadingErrors[0]?.severity).toBe('error');

        const nodes = flatten(analysis.nodes);
        expect(nodes.find((node) => node.label === 'Title')?.errors ?? []).toHaveLength(0);
        const emptyNode = nodes.find((node) => node.label === '');
        expect(emptyNode?.id).toBe(emptyHeadingErrors[0]?.nodeId);
    });

    it('reports skippedLevels for a skipped level (h1 -> h3)', () => {
        document.body.innerHTML = `
            <h1>Title</h1>
            <h3>Subsection</h3>
        `;

        const analysis = analyzeHeadings(document);
        const nodes = flatten(analysis.nodes);
        const h3Node = nodes.find((node) => node.label === 'Subsection');
        expect(h3Node?.skippedLevels).toBe(1);

        const skippedLevelErrors = analysis.errors.filter((error) => error.key.endsWith('skippedLevel'));
        expect(skippedLevelErrors).toHaveLength(1);
        expect(skippedLevelErrors[0]?.severity).toBe('error');
        expect(skippedLevelErrors[0]?.nodeId).toBe(h3Node?.id);
    });

    it('flags skippedLevels on every repeated identical sibling under the same parent', () => {
        document.body.innerHTML = `
            <h2>Section</h2>
            <h4>First</h4>
            <h4>Second</h4>
        `;

        const analysis = analyzeHeadings(document);
        const nodes = flatten(analysis.nodes);
        const firstH4 = nodes.find((node) => node.label === 'First');
        const secondH4 = nodes.find((node) => node.label === 'Second');
        expect(firstH4?.skippedLevels).toBe(1);
        expect(secondH4?.skippedLevels).toBe(1);

        const skippedLevelErrors = analysis.errors.filter((error) => error.key.endsWith('skippedLevel'));
        expect(skippedLevelErrors).toHaveLength(2);
    });

    it('does not flag a landmark-label heading preceding the first h1 as skipped', () => {
        document.body.innerHTML = `
            <h3>Quick links</h3>
            <nav><a href="/">Home</a></nav>
            <h1>Title</h1>
        `;

        const analysis = analyzeHeadings(document);
        const nodes = flatten(analysis.nodes);
        const quickLinksNode = nodes.find((node) => node.label === 'Quick links');
        const titleNode = nodes.find((node) => node.label === 'Title');
        expect(quickLinksNode?.skippedLevels).toBe(0);
        expect(titleNode?.skippedLevels).toBe(0);
        expect(analysis.errors.some((error) => error.key.endsWith('skippedLevel'))).toBe(false);
    });

    it('extracts ancestor/sibling relations and falls back to a rel:-prefixed id without record data', () => {
        document.body.innerHTML = `
            <h1 data-mindfula11y-relation-id="hero">Hero</h1>
            <h2 data-mindfula11y-ancestor-id="hero">Ancestor child</h2>
            <h2 data-mindfula11y-sibling-id="hero">Sibling child</h2>
        `;

        const analysis = analyzeHeadings(document);
        const nodes = flatten(analysis.nodes);

        const heroNode = nodes.find((node) => node.label === 'Hero');
        expect(heroNode?.id).toBe('rel:hero');
        expect(heroNode?.relationId).toBe('hero');
        expect(heroNode?.relation).toBeNull();

        const ancestorNode = nodes.find((node) => node.label === 'Ancestor child');
        expect(ancestorNode?.relation).toEqual({ kind: 'ancestor', targetRelationId: 'hero' });

        const siblingNode = nodes.find((node) => node.label === 'Sibling child');
        expect(siblingNode?.relation).toEqual({ kind: 'sibling', targetRelationId: 'hero' });
    });
});
