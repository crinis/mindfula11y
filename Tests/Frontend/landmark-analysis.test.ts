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
import { analyzeLandmarks } from '../../Resources/Private/Source/lib/landmark-analysis.js';
import type { LandmarkNode } from '../../Resources/Private/Source/lib/types.js';

const flatten = (nodes: LandmarkNode[]): LandmarkNode[] => nodes.flatMap((node) => [node, ...flatten(node.children)]);

describe('analyzeLandmarks', () => {
    beforeEach(() => {
        document.body.replaceChildren();
    });

    it('omits unnamed native forms but keeps explicit form roles', () => {
        document.body.innerHTML = `
            <main></main>
            <form><input name="search"></form>
            <form><input name="login"></form>
            <div role="form"><input name="contact"></div>
        `;

        const analysis = analyzeLandmarks(document);
        expect(flatten(analysis.nodes).map((node) => node.role)).toEqual(['main', 'form']);
        expect(analysis.errors).toEqual([]);
    });

    it('includes forms named by aria-label, aria-labelledby, or title', () => {
        document.body.innerHTML = `
            <main></main>
            <form aria-label="Search"></form>
            <h2 id="login-title">Login</h2><form aria-labelledby="login-title"></form>
            <form title="Contact"></form>
        `;

        const forms = flatten(analyzeLandmarks(document).nodes).filter((node) => node.role === 'form');
        expect(forms.map((node) => node.label)).toEqual(['Search', 'Login', 'Contact']);
    });

    it('allows the same label on different landmark roles', () => {
        document.body.innerHTML = `
            <main></main>
            <nav aria-label="Products"></nav>
            <section aria-label="Products"></section>
        `;

        const analysis = analyzeLandmarks(document);
        expect(analysis.errors).toEqual([]);
    });

    it('reports the same label on repeated instances of one role', () => {
        document.body.innerHTML = `
            <main></main>
            <nav aria-label="Products"></nav>
            <nav aria-label="Products"></nav>
        `;

        const analysis = analyzeLandmarks(document);
        expect(analysis.errors).toHaveLength(2);
        expect(analysis.errors.every((error) => error.key.endsWith('duplicateSameLabel'))).toBe(true);
    });
});
