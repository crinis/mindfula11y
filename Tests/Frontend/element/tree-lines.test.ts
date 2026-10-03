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
        expect(computeTreeLines([0, 1, 2])).toEqual([['root'], ['last', 'drop'], ['none', 'last']]);
    });

    it('lets an ancestor with further children pass through its descendants', () => {
        // h1 > h2(a) > h3, h2(b): the h1's rail passes the h3 to reach h2(b)
        expect(computeTreeLines([0, 1, 2, 1])).toEqual([['root'], ['branch', 'drop'], ['pass', 'last'], ['last']]);
    });

    it('stops an ancestor rail at its last child even when deeper rows follow', () => {
        // h1 > h2(a), h2(b) > h3 > h4: below h2(b) the h1 rail must not continue
        expect(computeTreeLines([0, 1, 1, 2, 3])).toEqual([
            ['root'],
            ['branch'],
            ['last', 'drop'],
            ['none', 'last', 'drop'],
            ['none', 'none', 'last'],
        ]);
    });

    it('treats a second top-level row as a new tree', () => {
        // h1(a) > h2, h1(b) > h2: the first h1's rail ends at its h2
        expect(computeTreeLines([0, 1, 0, 1])).toEqual([['root'], ['last'], ['root'], ['last']]);
    });

    it('gives a drop lane only to rows that a directly deeper row hangs off', () => {
        expect(computeTreeLines([0, 0, 1, 1])).toEqual([[], ['root'], ['branch'], ['last']]);
    });

    it('draws no lines for a heading that precedes the h1, since no row sits at its parent depth', () => {
        // h2 (pre-h1 region label), h1 > h2
        expect(computeTreeLines([1, 0, 1])).toEqual([['none'], ['root'], ['last']]);
    });

    it('starts the line at the chip of a parent that no elbow reaches', () => {
        // An outline opening at h2 (or an h2 region label before the h1) with
        // children: nothing sits at depth 0 to branch off, so the h2's own
        // line has to start at its chip — a root lane, not a drop.
        expect(computeTreeLines([0, 1])).toEqual([['root'], ['last']]);
        expect(computeTreeLines([1, 2])).toEqual([
            ['none', 'root'],
            ['none', 'last'],
        ]);
        expect(computeTreeLines([1, 2, 0, 1])).toEqual([['none', 'root'], ['none', 'last'], ['root'], ['last']]);
    });

    it('keeps a nested parent on a drop lane, since its incoming elbow already reaches its chip', () => {
        expect(computeTreeLines([0, 1, 2]).map((lanes) => lanes.at(-1))).toEqual(['root', 'drop', 'last']);
        expect(computeTreeLines([0, 1, 2, 1, 2]).map((lanes) => lanes.at(-1))).toEqual([
            'root',
            'drop',
            'last',
            'drop',
            'last',
        ]);
    });

    it('leaves a depth jump unconnected instead of hanging it off a depth nobody occupies', () => {
        // h1 > container whose children derive h4 (depth 3): no h2/h3 row exists to branch off
        expect(computeTreeLines([0, 3])).toEqual([[], ['none', 'none', 'none']]);
        // h1 > row at depth 2 > h4, then h2: the h1 rail passes the jump to reach
        // the h2, and the jumped row's own line starts at its chip
        expect(computeTreeLines([0, 2, 3, 1])).toEqual([
            ['root'],
            ['pass', 'none', 'root'],
            ['pass', 'none', 'last'],
            ['last'],
        ]);
    });

    it('handles an empty list and a lone row', () => {
        expect(computeTreeLines([])).toEqual([]);
        expect(computeTreeLines([0])).toEqual([[]]);
    });
});
