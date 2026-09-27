/*
 * Mindful A11y extension for TYPO3 integrating accessibility tools into the backend.
 * Copyright (C) 2025  Mindful Markup, Felix Spittel
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 */
import { describe, expect, it } from 'vitest';

import { computeTreeLines } from '../../../Resources/Private/Source/element/heading-structure/tree-lines.js';

describe('computeTreeLines', () => {
    it('gives a row one lane per crossed depth, ending in the lane it branches off', () => {
        // h1 > h2 > h3 (a single chain: every row is its parent's only, and so last, child)
        expect(computeTreeLines([0, 1, 2])).toEqual([
            { lanes: [], parent: true },
            { lanes: ['last'], parent: true },
            { lanes: ['none', 'last'], parent: false },
        ]);
    });

    it('lets an ancestor with further children pass through its descendants', () => {
        // h1 > h2(a) > h3, h2(b): the h1's rail passes the h3 to reach h2(b)
        expect(computeTreeLines([0, 1, 2, 1])).toEqual([
            { lanes: [], parent: true },
            { lanes: ['branch'], parent: true },
            { lanes: ['pass', 'last'], parent: false },
            { lanes: ['last'], parent: false },
        ]);
    });

    it('stops an ancestor rail at its last child even when deeper rows follow', () => {
        // h1 > h2(a), h2(b) > h3 > h4: below h2(b) the h1 rail must not continue
        expect(computeTreeLines([0, 1, 1, 2, 3])).toEqual([
            { lanes: [], parent: true },
            { lanes: ['branch'], parent: false },
            { lanes: ['last'], parent: true },
            { lanes: ['none', 'last'], parent: true },
            { lanes: ['none', 'none', 'last'], parent: false },
        ]);
    });

    it('treats a second top-level row as a new tree', () => {
        // h1(a) > h2, h1(b) > h2: the first h1's rail ends at its h2
        expect(computeTreeLines([0, 1, 0, 1])).toEqual([
            { lanes: [], parent: true },
            { lanes: ['last'], parent: false },
            { lanes: [], parent: true },
            { lanes: ['last'], parent: false },
        ]);
    });

    it('marks only rows whose next row is deeper as parents', () => {
        expect(computeTreeLines([0, 0, 1, 1]).map((lines) => lines.parent)).toEqual([false, true, false, false]);
    });

    it('handles an empty list and a lone row', () => {
        expect(computeTreeLines([])).toEqual([]);
        expect(computeTreeLines([0])).toEqual([{ lanes: [], parent: false }]);
    });
});
