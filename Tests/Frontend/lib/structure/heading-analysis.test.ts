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
        expect(analysis.errors[0]?.severity).toBe('moderate');
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
        expect(multipleH1Errors.every((error) => error.severity === 'minor')).toBe(true);
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
        expect(emptyHeadingErrors[0]?.severity).toBe('minor');

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
        expect(skippedLevelErrors[0]?.severity).toBe('moderate');
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

    it('accepts an h2 region label preceding the h1 without any finding', () => {
        // W3C WAI page-structure tutorial's canonical outline: pre-h1 region
        // labels (nav, sidebar) are h2 — never a finding.
        document.body.innerHTML = `
            <h2>Navigation</h2>
            <h1>Title</h1>
        `;

        const analysis = analyzeHeadings(document);
        expect(analysis.errors).toEqual([]);
    });

    it('accepts sub-headings nested under a pre-h1 region label (h2, h3, h1)', () => {
        document.body.innerHTML = `
            <h2>Navigation</h2>
            <h3>Products</h3>
            <h1>Title</h1>
        `;

        const analysis = analyzeHeadings(document);
        expect(analysis.errors).toEqual([]);
    });

    it('still flags a genuine skip inside the pre-h1 region (h2, h4, h1)', () => {
        document.body.innerHTML = `
            <h2>Navigation</h2>
            <h4>Products</h4>
            <h1>Title</h1>
        `;

        const analysis = analyzeHeadings(document);
        const skippedLevelErrors = analysis.errors.filter((error) => error.key.endsWith('skippedLevel'));
        expect(skippedLevelErrors).toHaveLength(1);
        expect(skippedLevelErrors[0]?.severity).toBe('moderate');
        const h4Node = flatten(analysis.nodes).find((node) => node.label === 'Products');
        expect(skippedLevelErrors[0]?.nodeId).toBe(h4Node?.id);
        expect(analysis.errors.some((error) => error.key.endsWith('deepRootHeading'))).toBe(false);
    });

    it('advises on a root heading deeper than h2 opening the outline (h3 before the h1)', () => {
        document.body.innerHTML = `
            <h3>Quick links</h3>
            <h1>Title</h1>
        `;

        const analysis = analyzeHeadings(document);
        const advisories = analysis.errors.filter((error) => error.key.endsWith('deepRootHeading'));
        expect(advisories).toHaveLength(1);
        expect(advisories[0]?.severity).toBe('minor');
        const h3Node = flatten(analysis.nodes).find((node) => node.label === 'Quick links');
        expect(advisories[0]?.nodeId).toBe(h3Node?.id);
        expect(h3Node?.skippedLevels).toBe(0);
        expect(analysis.errors.some((error) => error.key.endsWith('skippedLevel'))).toBe(false);
    });

    it('advises once per deep root heading (h3, h3, h1)', () => {
        document.body.innerHTML = `
            <h3>Quick links</h3>
            <h3>Search</h3>
            <h1>Title</h1>
        `;

        const analysis = analyzeHeadings(document);
        const advisories = analysis.errors.filter((error) => error.key.endsWith('deepRootHeading'));
        expect(advisories).toHaveLength(2);
        expect(new Set(advisories.map((error) => error.nodeId)).size).toBe(2);
    });

    it('combines missingH1 with the deep-root-heading advisory on a page opening at h3', () => {
        document.body.innerHTML = `
            <h3>Quick links</h3>
        `;

        const analysis = analyzeHeadings(document);
        expect(analysis.errors.some((error) => error.key.endsWith('missingH1'))).toBe(true);
        expect(analysis.errors.some((error) => error.key.endsWith('deepRootHeading'))).toBe(true);
    });

    describe('announced level and role', () => {
        // The analysis follows what assistive technology announces (axe-core's
        // heading-order semantics), not the tag name alone.

        it('takes the level from a valid aria-level instead of the tag', () => {
            document.body.innerHTML = `
                <h1>Title</h1>
                <h4 aria-level="2">Announced as level 2</h4>
            `;

            const analysis = analyzeHeadings(document);
            const section = analysis.nodes[0]?.children[0];
            expect(section?.label).toBe('Announced as level 2');
            expect(section?.level).toBe(2);
            expect(section?.skippedLevels).toBe(0);
            expect(analysis.errors).toEqual([]);
        });

        it('includes elements with role="heading", at level 2 unless aria-level says otherwise', () => {
            document.body.innerHTML = `
                <h1>Title</h1>
                <div role="heading">Default level</div>
                <p role="HEADING" aria-level="3">Explicit level</p>
            `;

            const analysis = analyzeHeadings(document);
            const section = analysis.nodes[0]?.children[0];
            expect(section).toMatchObject({ kind: 'heading', label: 'Default level', level: 2 });
            expect(section?.children[0]).toMatchObject({ kind: 'heading', label: 'Explicit level', level: 3 });
            expect(analysis.errors).toEqual([]);
        });

        it('does not count an h1–h6 whose explicit role replaces the heading role', () => {
            document.body.innerHTML = `
                <h1>Title</h1>
                <h2 role="tab">Tab label</h2>
                <h3>Subsection</h3>
            `;

            const analysis = analyzeHeadings(document);
            const labels = flatten(analysis.nodes).map((node) => node.label);
            expect(labels).toEqual(['Title', 'Subsection']);
            // Without the tab, the h3 follows the h1 directly.
            expect(flatten(analysis.nodes).find((node) => node.label === 'Subsection')?.skippedLevels).toBe(1);
        });

        it('keeps an h1–h6 whose role names no concrete ARIA role', () => {
            // Browsers skip an unknown or abstract role token and fall back to
            // the native role: dropping these would report a false missing H1
            // and a false skipped level for the heading after them.
            document.body.innerHTML = `
                <h1 role="text">Title</h1>
                <h2 role="foo">Unknown role</h2>
                <h3 role="section">Abstract role</h3>
            `;

            const analysis = analyzeHeadings(document);
            expect(flatten(analysis.nodes).map((node) => [node.label, node.level])).toEqual([
                ['Title', 1],
                ['Unknown role', 2],
                ['Abstract role', 3],
            ]);
            expect(analysis.errors).toEqual([]);
        });

        it('takes the first concrete ARIA role of a role token list', () => {
            document.body.innerHTML = `
                <h1>Title</h1>
                <h2 role="tab heading">Tab first</h2>
                <div role="heading tab">Heading first</div>
                <h2 role="foo tab">Unknown token skipped</h2>
                <h2 role="doc-subtitle">Subtitle</h2>
            `;

            const labels = flatten(analyzeHeadings(document).nodes).map((node) => node.label);
            expect(labels).toEqual(['Title', 'Heading first']);
        });

        it('keeps an h1–h6 whose explicit role is heading, or none/presentation overridden by focusability', () => {
            document.body.innerHTML = `
                <h1 role="heading">Title</h1>
                <h2 role="presentation" tabindex="0">Focusable section</h2>
                <h2 role="presentation">Presentational</h2>
            `;

            const analysis = analyzeHeadings(document);
            expect(flatten(analysis.nodes).map((node) => node.label)).toEqual(['Title', 'Focusable section']);
        });

        it('counts the H1 by announced level', () => {
            document.body.innerHTML = `
                <h2 aria-level="1">Announced H1</h2>
                <h2>Section</h2>
            `;
            expect(analyzeHeadings(document).errors).toEqual([]);

            document.body.innerHTML = `
                <h1 aria-level="2">Announced as level 2</h1>
            `;
            expect(analyzeHeadings(document).errors.map((error) => error.key)).toContain(
                'mindfula11y.structure.headings.error.missingH1',
            );

            document.body.innerHTML = `
                <h1>First</h1>
                <div role="heading" aria-level="1">Second</div>
            `;
            const multiple = analyzeHeadings(document).errors.filter((error) => error.key.endsWith('multipleH1'));
            expect(multiple).toHaveLength(2);
        });

        it('falls back to the tag level for an invalid aria-level and caps deep levels at 6', () => {
            document.body.innerHTML = `
                <h1>title</h1>
                <h2 aria-level="0">zero</h2>
                <h2 aria-level="two">word</h2>
                <h2 aria-level="">empty</h2>
                <div role="heading" aria-level="-3">negative</div>
                <h5 aria-level="9">nine</h5>
            `;

            const levels = Object.fromEntries(
                flatten(analyzeHeadings(document).nodes).map((node) => [node.label, node.level]),
            );
            expect(levels).toEqual({ title: 1, zero: 2, word: 2, empty: 2, negative: 2, nine: 6 });
        });

        it('still resolves the record of an annotated heading whose aria-level overrides its tag', () => {
            // The level select shows the record's stored type, which a plain
            // h1–h6 implies by its tag. Once the announced level differs, the
            // tag name is stated explicitly so the select does not show (and
            // save against) the announced level instead.
            document.body.innerHTML = `
                <h1>Title</h1>
                <h4 aria-level="2"
                    data-mindfula11y-record-table-name="tt_content"
                    data-mindfula11y-record-column-name="tx_mindfula11y_headingtype"
                    data-mindfula11y-record-uid="12"
                >Overridden</h4>
                <h2 aria-level="2"
                    data-mindfula11y-record-table-name="tt_content"
                    data-mindfula11y-record-column-name="tx_mindfula11y_headingtype"
                    data-mindfula11y-record-uid="13"
                >Matching</h2>
            `;

            const nodes = flatten(analyzeHeadings(document).nodes);
            const overridden = nodes.find((node) => node.label === 'Overridden');
            expect(overridden?.id).toBe('tt_content:12:tx_mindfula11y_headingtype');
            expect(overridden?.level).toBe(2);
            expect(overridden?.record).toEqual({
                tableName: 'tt_content',
                columnName: 'tx_mindfula11y_headingtype',
                uid: 12,
                editLink: '',
                storedValue: 'h4',
            });
            // An aria-level agreeing with the tag changes nothing.
            expect(nodes.find((node) => node.label === 'Matching')?.record?.storedValue).toBeUndefined();
        });
    });

    it('attributes a deep root heading derived from a hidden container once to the container row', () => {
        // Same attribution rule as skips: the container row already shows the
        // unrendered level and hosts the child-type select that closes the gap.
        document.body.innerHTML = `
            <span hidden data-mindfula11y-container="h2" data-mindfula11y-relation-id="acc"></span>
            <h3 data-mindfula11y-ancestor-id="acc">First child</h3>
            <h3 data-mindfula11y-ancestor-id="acc">Second child</h3>
            <h1>Title</h1>`;

        const analysis = analyzeHeadings(document, { isExposed: () => true });

        const all = flatten(analysis.nodes);
        const container = all.find((node) => node.kind === 'container');
        expect(container?.errors.map((error) => error.key)).toEqual([
            'mindfula11y.structure.headings.error.deepRootHeading',
        ]);
        const advisories = analysis.errors.filter((error) => error.key.endsWith('deepRootHeading'));
        expect(advisories).toHaveLength(1);
        const headingChildren = all.filter((node) => node.kind === 'heading' && node.level === 3);
        expect(headingChildren.flatMap((node) => node.errors)).toEqual([]);
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
        // The marker names a real heading level: no non-heading type to carry.
        expect(container?.nonHeadingType).toBeUndefined();
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
        expect(analysis.nodes[0]?.nonHeadingType).toBe('p');
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

    it('attributes a skip to the nearest preceding publisher of a duplicated relation id', () => {
        // The same container rendered twice (shortcut records) publishes its
        // relation id once per occurrence; the registry resolves descendants
        // against the most recent registration, so the analyzer must attribute
        // to the SECOND occurrence, not the first.
        document.body.innerHTML = `
            <h1>Page</h1>
            <span hidden data-mindfula11y-container="h2" data-mindfula11y-relation-id="dup" data-mindfula11y-record-uid="1"></span>
            <h3 data-mindfula11y-ancestor-id="dup">First occurrence child</h3>
            <span hidden data-mindfula11y-container="h2" data-mindfula11y-relation-id="dup" data-mindfula11y-record-uid="2"></span>
            <h3 data-mindfula11y-ancestor-id="dup">Second occurrence child</h3>`;

        const analysis = analyzeHeadings(document, { isExposed: () => true });

        const all = flatten(analysis.nodes);
        const containers = all.filter((node) => node.kind === 'container');
        expect(containers).toHaveLength(2);
        // Each occurrence's skipping child attributes to ITS preceding marker;
        // first-wins would pile both skips onto the first container.
        expect(containers[0]?.errors.map((error) => error.key)).toEqual([
            'mindfula11y.structure.headings.error.skippedLevel',
        ]);
        expect(containers[1]?.errors.map((error) => error.key)).toEqual([
            'mindfula11y.structure.headings.error.skippedLevel',
        ]);
        const headings = all.filter((node) => node.kind === 'heading');
        expect(headings.every((node) => node.skippedLevels === 0)).toBe(true);
    });

    it('maps rendered demoted tags to level-0 nodes without joining any heading check', () => {
        document.body.innerHTML = `
            <h1>Page</h1>
            <p data-mindfula11y-demoted="p"
                data-mindfula11y-record-table-name="tt_content"
                data-mindfula11y-record-column-name="tx_mindfula11y_headingtype"
                data-mindfula11y-record-uid="9" data-mindfula11y-record-value="p">Former heading</p>
            <h2>Section</h2>`;

        const analysis = analyzeHeadings(document, { isExposed: () => true });

        const demoted = analysis.nodes[0]?.children.find((node) => node.kind === 'demoted');
        expect(demoted).toBeDefined();
        expect(demoted?.level).toBe(0);
        expect(demoted?.label).toBe('Former heading');
        expect(demoted?.record?.storedValue).toBe('p');
        expect(demoted?.nonHeadingType).toBe('p');
        // Not a heading: empty-heading/H1/skip checks ignore it entirely.
        expect(analysis.errors).toEqual([]);
        expect(analysis.nodes[0]?.children.some((node) => node.label === 'Section')).toBe(true);
    });

    it('ignores rendered p/div tags without the demoted discriminator', () => {
        document.body.innerHTML = `
            <h1>Page</h1>
            <p>Ordinary copy</p>`;

        const analysis = analyzeHeadings(document, { isExposed: () => true });

        expect(flatten(analysis.nodes)).toHaveLength(1);
    });

    it('excludes demoted tags hidden in the analyzed viewport', () => {
        document.body.innerHTML = `
            <h1>Page</h1>
            <p data-mindfula11y-demoted="p" data-hidden-for-test>Hidden demoted</p>`;

        const analysis = analyzeHeadings(document, {
            isExposed: (element: HTMLElement) => !element.hasAttribute('data-hidden-for-test'),
        });

        expect(flatten(analysis.nodes)).toHaveLength(1);
    });

    it('registers a demoted publisher so relations targeting it resolve to its node', () => {
        // A container whose own header renders as a paragraph must remain the
        // relation target: its derived children reference the demoted node, not
        // a missing one.
        document.body.innerHTML = `
            <h1>Page</h1>
            <p data-mindfula11y-demoted="p" data-mindfula11y-relation-id="acc"
                data-mindfula11y-childtype-table-name="tt_content"
                data-mindfula11y-childtype-column-name="tx_mindfula11y_childheadingtype"
                data-mindfula11y-childtype-uid="9" data-mindfula11y-childtype-value="h3">Container title</p>
            <h3 data-mindfula11y-ancestor-id="acc">Derived</h3>`;

        const analysis = analyzeHeadings(document, { isExposed: () => true });

        const all = flatten(analysis.nodes);
        const demoted = all.find((node) => node.kind === 'demoted');
        expect(demoted?.relationId).toBe('acc');
        expect(demoted?.childTypeRecord?.storedValue).toBe('h3');
        const derived = all.find((node) => node.label === 'Derived');
        expect(derived?.relation).toEqual({ kind: 'ancestor', targetRelationId: 'acc' });
        // The h1->h3 skip stays on the heading: only hidden container rows
        // absorb their children's skips (the demoted row shows no level).
        expect(derived?.skippedLevels).toBe(1);
    });
});
