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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 */

import type { CSSResult } from 'lit';
import { css } from 'lit';
import baseCss from './base.css.js';
import resetCss from './reset.css.js';
import tokensCss from './tokens.css.js';
import utilitiesCss from './utilities.css.js';

/**
 * The fixed cascade-layer order of every shadow root. Declared first so all
 * following stylesheets slot into it; component styles live in `component`.
 */
const layerOrder: CSSResult = css`
    @layer reset, base, component, utilities;
`;

/**
 * Shared foundation adopted by every component's shadow root:
 * `static override styles = [...baseStyles, componentStyles];`
 * (constructable stylesheets — shared by reference, not duplicated per root).
 */
export const baseStyles: CSSResult[] = [layerOrder, resetCss, tokensCss, baseCss, utilitiesCss];
