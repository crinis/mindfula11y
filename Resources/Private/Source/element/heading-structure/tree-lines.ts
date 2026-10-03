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
 * What one lane of a row's tree lines shows. The lanes of the depths a row
 * crosses are named after the characters a directory listing draws there:
 * - `pass`   │  the ancestor at this depth has further children below this row
 * - `branch` ├  this row hangs off the ancestor at this depth, which continues
 * - `last`   └  this row is that ancestor's last child
 * - `none`      no ancestor of this row sits at this depth
 * A row with children carries one more lane, at its own depth, holding the
 * line from its level chip down into its children's rail:
 * - `drop`   ╷  the elbow from this row's own ancestor already reaches the
 *               chip, so the lane draws only the line going down
 * - `root`   ┌  no elbow arrives (a top-level row, or one whose parent depth
 *               nobody occupies), so the line starts at this row's chip and
 *               the lane draws the corner from the chip down as well
 */
export type LaneState = 'pass' | 'branch' | 'last' | 'none' | 'drop' | 'root';

/**
 * Derives the tree lines of a pre-order flat list of rows from their depths
 * alone: for each row, the lane states from the outermost crossed depth
 * inwards. A depth is 0-based; a row at depth 3 crosses depths 0–2 and, if a
 * row at depth 2 precedes it, hangs off that one.
 *
 * Only real ancestors get lines. The ancestor at depth d is the nearest
 * earlier row at depth d or above; if that row sits above d, nobody occupies
 * d and the lane stays empty. Likewise a row is a parent only when a row one
 * level deeper follows it — a deeper jump (a container whose children derive
 * a far lower level) hangs off nothing and gets no drop.
 *
 * An ancestor's rail continues past a row while another row at the
 * ancestor's child depth still follows before the list returns to the
 * ancestor's own depth or above.
 */
export function computeTreeLines(depths: readonly number[]): LaneState[][] {
    return depths.map((depth, index) => {
        const lanes = Array.from({ length: depth }, (_, laneDepth): LaneState => {
            if (!hasAncestorAtDepth(depths, index, laneDepth)) {
                return 'none';
            }
            const ancestorContinues = hasLaterChildAtDepth(depths, index, laneDepth + 1);
            if (laneDepth === depth - 1) {
                return ancestorContinues ? 'branch' : 'last';
            }
            return ancestorContinues ? 'pass' : 'none';
        });
        if (hasLaterChildAtDepth(depths, index, depth + 1)) {
            const incoming = lanes.at(-1);
            lanes.push(incoming === 'branch' || incoming === 'last' ? 'drop' : 'root');
        }
        return lanes;
    });
}

/** Whether the nearest row before `index` at `ancestorDepth` or above sits exactly at `ancestorDepth`. */
function hasAncestorAtDepth(depths: readonly number[], index: number, ancestorDepth: number): boolean {
    for (let earlier = index - 1; earlier >= 0; earlier--) {
        const earlierDepth = depths[earlier] ?? 0;
        if (earlierDepth <= ancestorDepth) {
            return earlierDepth === ancestorDepth;
        }
    }
    return false;
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
