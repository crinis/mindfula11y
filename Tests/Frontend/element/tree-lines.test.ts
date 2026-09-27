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
    it('gives a row one lane per crossed depth, ending in the lane it branches off, plus a drop lane when it has children', () => {
        // h1 > h2 > h3: a single chain, every row its parent's only (and so last) child
        expect(computeTreeLines([0, 1, 2])).toEqual([['drop'], ['last', 'drop'], ['none', 'last']]);
    });

    it('lets an ancestor with further children pass through its descendants', () => {
        // h1 > h2(a) > h3, h2(b): the h1's rail passes the h3 to reach h2(b)
        expect(computeTreeLines([0, 1, 2, 1])).toEqual([['drop'], ['branch', 'drop'], ['pass', 'last'], ['last']]);
    });

    it('stops an ancestor rail at its last child even when deeper rows follow', () => {
        // h1 > h2(a), h2(b) > h3 > h4: below h2(b) the h1 rail must not continue
        expect(computeTreeLines([0, 1, 1, 2, 3])).toEqual([
            ['drop'],
            ['branch'],
            ['last', 'drop'],
            ['none', 'last', 'drop'],
            ['none', 'none', 'last'],
        ]);
    });

    it('treats a second top-level row as a new tree', () => {
        // h1(a) > h2, h1(b) > h2: the first h1's rail ends at its h2
        expect(computeTreeLines([0, 1, 0, 1])).toEqual([['drop'], ['last'], ['drop'], ['last']]);
    });

    it('gives a drop lane only to rows that a directly deeper row hangs off', () => {
        expect(computeTreeLines([0, 0, 1, 1])).toEqual([[], ['drop'], ['branch'], ['last']]);
    });

    it('draws no lines for a heading that precedes the h1, since no row sits at its parent depth', () => {
        // h2 (pre-h1 region label), h1 > h2
        expect(computeTreeLines([1, 0, 1])).toEqual([['none'], ['drop'], ['last']]);
    });

    it('leaves a depth jump unconnected instead of hanging it off a depth nobody occupies', () => {
        // h1 > container whose children derive h4 (depth 3): no h2/h3 row exists to branch off
        expect(computeTreeLines([0, 3])).toEqual([[], ['none', 'none', 'none']]);
        // h1 > row at depth 2 > h4, then h2: the h1 rail passes the jump to reach the h2
        expect(computeTreeLines([0, 2, 3, 1])).toEqual([
            ['drop'],
            ['pass', 'none', 'drop'],
            ['pass', 'none', 'last'],
            ['last'],
        ]);
    });

    it('handles an empty list and a lone row', () => {
        expect(computeTreeLines([])).toEqual([]);
        expect(computeTreeLines([0])).toEqual([[]]);
    });
});
