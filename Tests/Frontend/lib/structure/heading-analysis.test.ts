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
import type { HeadingNode } from '../../../../Resources/Private/Source/lib/structure/types.js';

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

    it('keeps record coordinates on a relation-carrying heading (container child-type editing)', () => {
        document.body.innerHTML = `
            <h1>Page title</h1>
            <h2 data-mindfula11y-relation-id="container-1">Container</h2>
            <h3
                data-mindfula11y-ancestor-id="container-1"
                data-mindfula11y-record-table-name="tt_content"
                data-mindfula11y-record-column-name="tx_mindfula11y_childheadingtype"
                data-mindfula11y-record-uid="800"
                data-mindfula11y-record-value=""
            >Derived child</h3>
        `;

        const analysis = analyzeHeadings(document);
        const childNode = flatten(analysis.nodes).find((node) => node.label === 'Derived child');

        expect(childNode?.relation).toEqual({ kind: 'ancestor', targetRelationId: 'container-1' });
        expect(childNode?.record).toMatchObject({
            tableName: 'tt_content',
            columnName: 'tx_mindfula11y_childheadingtype',
            uid: 800,
            storedValue: '',
        });
    });

    it('omits storedValue when no record-value attribute is emitted', () => {
        document.body.innerHTML = `
            <h1
                data-mindfula11y-record-table-name="tt_content"
                data-mindfula11y-record-column-name="tx_mindfula11y_headingtype"
                data-mindfula11y-record-uid="7"
            >Ordinary heading</h1>
        `;

        const analysis = analyzeHeadings(document);
        const node = flatten(analysis.nodes).find((candidate) => candidate.label === 'Ordinary heading');

        expect(node?.record?.storedValue).toBeUndefined();
    });

    it('assigns distinct node ids to headings sharing identical record coordinates', () => {
        document.body.innerHTML = `
            <h1>Page title</h1>
            <h2
                data-mindfula11y-ancestor-id="container-1"
                data-mindfula11y-record-table-name="tt_content"
                data-mindfula11y-record-column-name="tx_mindfula11y_childheadingtype"
                data-mindfula11y-record-uid="800"
            >First child</h2>
            <h2
                data-mindfula11y-ancestor-id="container-1"
                data-mindfula11y-record-table-name="tt_content"
                data-mindfula11y-record-column-name="tx_mindfula11y_childheadingtype"
                data-mindfula11y-record-uid="800"
            >Second child</h2>
        `;

        const analysis = analyzeHeadings(document);
        const nodes = flatten(analysis.nodes).filter((node) => node.record?.uid === 800);

        expect(nodes).toHaveLength(2);
        expect(nodes[0]?.id).not.toBe(nodes[1]?.id);
    });

    it('maps hidden container markers to container nodes in the current heading context', () => {
        document.body.innerHTML = `
            <h2 data-mindfula11y-relation-id="top">Top</h2>
            <span hidden data-mindfula11y-container="h2" data-mindfula11y-relation-id="acc"
                data-mindfula11y-record-table-name="tt_content"
                data-mindfula11y-record-column-name="tx_mindfula11y_headingtype"
                data-mindfula11y-record-uid="5" data-mindfula11y-record-value="h2"
                data-mindfula11y-childtype-table-name="tt_content"
                data-mindfula11y-childtype-column-name="tx_mindfula11y_childheadingtype"
                data-mindfula11y-childtype-uid="5" data-mindfula11y-childtype-value=""></span>
            <h3 data-mindfula11y-ancestor-id="acc">Derived</h3>
            <h1>Page</h1>`;

        const analysis = analyzeHeadings(document, { isExposed: () => true });

        const container = analysis.nodes[0]?.children.find((node) => node.kind === 'container');
        expect(container).toBeDefined();
        expect(container?.relationId).toBe('acc');
        expect(container?.level).toBe(2);
        expect(container?.label).toBe('');
        expect(container?.record?.storedValue).toBe('h2');
        expect(container?.childTypeRecord?.storedValue).toBe('');
        expect(container?.errors).toEqual([]);
        // Containers are not headings: no empty-heading error, no level participation.
        expect(analysis.errors).toEqual([]);
    });

    it('excludes container markers whose parent is not exposed in the viewport', () => {
        document.body.innerHTML = `
            <h2>Top</h2>
            <div data-hidden-for-test>
                <span hidden data-mindfula11y-container="h2" data-mindfula11y-relation-id="acc"></span>
            </div>`;

        const analysis = analyzeHeadings(document, {
            isExposed: (element: HTMLElement) => !element.hasAttribute('data-hidden-for-test'),
        });

        expect(analysis.nodes[0]?.children).toEqual([]);
    });

    it("reads a heading's own child-type coordinates", () => {
        document.body.innerHTML = `
            <h2 data-mindfula11y-relation-id="acc"
                data-mindfula11y-childtype-table-name="tt_content"
                data-mindfula11y-childtype-column-name="tx_mindfula11y_childheadingtype"
                data-mindfula11y-childtype-uid="7" data-mindfula11y-childtype-value="h4">Visible container</h2>`;

        const analysis = analyzeHeadings(document, { isExposed: () => true });

        expect(analysis.nodes[0]?.kind).toBe('heading');
        expect(analysis.nodes[0]?.childTypeRecord?.uid).toBe(7);
        expect(analysis.nodes[0]?.childTypeRecord?.storedValue).toBe('h4');
    });

    it('treats a container with a non-heading own type as level 0', () => {
        document.body.innerHTML = `
            <span hidden data-mindfula11y-container="p" data-mindfula11y-relation-id="acc"></span>`;

        const analysis = analyzeHeadings(document, { isExposed: () => true });

        expect(analysis.nodes[0]?.kind).toBe('container');
        expect(analysis.nodes[0]?.level).toBe(0);
    });

    it('keeps container markers whose parent only has a presentational role', () => {
        document.body.innerHTML = `
            <h1>Page</h1>
            <div role="presentation">
                <span hidden data-mindfula11y-container="h2" data-mindfula11y-relation-id="acc"></span>
            </div>`;

        const analysis = analyzeHeadings(document, { isExposed: () => true });

        expect(analysis.nodes[0]?.children.some((node) => node.kind === 'container')).toBe(true);
    });

    it('reports a skip caused by a hidden container once on the container row', () => {
        // The container row already shows the unrendered level and hosts the
        // child-type select that fixes the gap — per-heading placeholders next
        // to it would duplicate (and visually contradict) that row.
        document.body.innerHTML = `
            <h1>Page</h1>
            <span hidden data-mindfula11y-container="h2" data-mindfula11y-relation-id="acc"></span>
            <h3 data-mindfula11y-ancestor-id="acc">First child</h3>
            <h3 data-mindfula11y-ancestor-id="acc">Second child</h3>`;

        const analysis = analyzeHeadings(document, { isExposed: () => true });

        const root = analysis.nodes[0];
        const container = root?.children.find((node) => node.kind === 'container');
        const headingChildren = root?.children.filter((node) => node.kind === 'heading') ?? [];
        expect(container?.errors.map((error) => error.key)).toEqual([
            'mindfula11y.structure.headings.error.skippedLevel',
        ]);
        expect(analysis.errors).toHaveLength(1);
        expect(headingChildren.flatMap((node) => node.errors)).toEqual([]);
        expect(headingChildren.every((node) => node.skippedLevels === 0)).toBe(true);
    });

    it('keeps per-heading skip placeholders when the relation target is a rendered heading', () => {
        document.body.innerHTML = `
            <h1>Page</h1>
            <h2 data-mindfula11y-relation-id="visible">Visible container</h2>
            <h4 data-mindfula11y-ancestor-id="visible">Deep child</h4>`;

        const analysis = analyzeHeadings(document, { isExposed: () => true });

        const child = analysis.nodes[0]?.children[0]?.children[0];
        expect(child?.skippedLevels).toBe(1);
        expect(child?.errors.map((error) => error.key)).toEqual(['mindfula11y.structure.headings.error.skippedLevel']);
    });

    it('keeps per-heading skip placeholders when the container row is absent', () => {
        document.body.innerHTML = `
            <h1>Page</h1>
            <h3 data-mindfula11y-ancestor-id="missing">Orphan</h3>`;

        const analysis = analyzeHeadings(document, { isExposed: () => true });

        const child = analysis.nodes[0]?.children[0];
        expect(child?.skippedLevels).toBe(1);
        expect(child?.errors).toHaveLength(1);
    });
});
