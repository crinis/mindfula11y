/*
 * Mindful A11y extension for TYPO3 integrating accessibility tools into the backend.
 * Copyright (C) 2025  Mindful Markup, Felix Spittel
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
 * What a row's lane at one crossed depth shows, named after the characters a
 * directory listing draws in the same place:
 * - `pass`   │  the ancestor at this depth has further children below this row
 * - `branch` ├  this row hangs off the ancestor at this depth, which continues
 * - `last`   └  this row is that ancestor's last child
 * - `none`      the ancestor at this depth ended above this row
 */
export type LaneState = 'pass' | 'branch' | 'last' | 'none';

export interface TreeLines {
    /** One entry per crossed depth, index 0 being the outermost; the final entry is the lane the row branches off. */
    lanes: LaneState[];
    /** Whether the next row is deeper — this row has children and draws the drop into their rail. */
    parent: boolean;
}

/**
 * Derives the tree lines of a pre-order flat list of rows from their depths
 * alone. Depth d of a row is 0-based: a row at depth 3 crosses depths 0–2 and
 * hangs off depth 2.
 *
 * An ancestor's rail continues past a row while another row at the ancestor's
 * child depth still follows before the list returns to the ancestor's own depth
 * or above. The lane of the row's own parent depth is therefore `branch` while
 * a later sibling follows and `last` once none does; the lanes of outer depths
 * are `pass` or `none` on the same rule.
 */
export function computeTreeLines(depths: readonly number[]): TreeLines[] {
    return depths.map((depth, index) => ({
        lanes: Array.from({ length: depth }, (_, laneDepth) => laneState(depths, index, laneDepth, depth)),
        parent: (depths[index + 1] ?? 0) > depth,
    }));
}

function laneState(depths: readonly number[], index: number, laneDepth: number, rowDepth: number): LaneState {
    const ancestorContinues = hasLaterChildAtDepth(depths, index, laneDepth + 1);
    if (laneDepth === rowDepth - 1) {
        return ancestorContinues ? 'branch' : 'last';
    }
    return ancestorContinues ? 'pass' : 'none';
}

/** Whether a row at `childDepth` follows `index` before the list climbs back to `childDepth - 1` or above. */
function hasLaterChildAtDepth(depths: readonly number[], index: number, childDepth: number): boolean {
    for (let later = index + 1; later < depths.length; later++) {
        const laterDepth = depths[later] ?? 0;
        if (laterDepth < childDepth) {
            return false;
        }
        if (laterDepth === childDepth) {
            return true;
        }
    }
    return false;
}
