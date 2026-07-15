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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 */

// @vitest-environment happy-dom

import { beforeEach, describe, expect, it } from 'vitest';
import {
    hasPresentationalRole,
    isElementExposed,
} from '../../../../Resources/Private/Source/lib/structure/element-exposure.js';

describe('isElementExposed', () => {
    beforeEach(() => {
        document.body.replaceChildren();
    });

    it.each([
        '<div hidden><h2>Hidden</h2></div>',
        '<div inert><h2>Hidden</h2></div>',
        '<div aria-hidden="true"><h2>Hidden</h2></div>',
        '<div style="display: none"><h2>Hidden</h2></div>',
        '<div style="visibility: hidden"><h2>Hidden</h2></div>',
    ])('excludes headings hidden by markup or rendered styles', (markup) => {
        document.body.innerHTML = markup;
        expect(isElementExposed(document.querySelector('h2') as HTMLElement)).toBe(false);
    });

    it('does not confuse opacity or off-screen positioning with accessibility hiding', () => {
        document.body.innerHTML = '<h2 style="opacity: 0; position: fixed; left: -9999px">Exposed</h2>';
        expect(isElementExposed(document.querySelector('h2') as HTMLElement)).toBe(true);
    });

    it('honors a descendant overriding inherited visibility', () => {
        document.body.innerHTML = '<div style="visibility: hidden"><h2 style="visibility: visible">Exposed</h2></div>';
        expect(isElementExposed(document.querySelector('h2') as HTMLElement)).toBe(true);
    });

    it('excludes closed disclosure content but keeps its summary exposed', () => {
        document.body.innerHTML = '<details><summary><h2>Summary</h2></summary><h3>Details</h3></details>';
        expect(isElementExposed(document.querySelector('h2') as HTMLElement)).toBe(true);
        expect(isElementExposed(document.querySelector('h3') as HTMLElement)).toBe(false);
    });

    it('recognizes roles which suppress native semantics', () => {
        document.body.innerHTML = '<main role="presentation"></main>';
        expect(hasPresentationalRole(document.querySelector('main') as HTMLElement)).toBe(true);
    });

    it.each([
        '<h2 role="none" tabindex="-1">Focusable heading</h2>',
        '<h2 role="presentation" aria-describedby="description">Described heading</h2>',
    ])('retains native semantics when a presentational-role conflict applies', (markup) => {
        document.body.innerHTML = `${markup}<p id="description">Description</p>`;
        expect(hasPresentationalRole(document.querySelector('h2') as HTMLElement)).toBe(false);
    });

    it('still suppresses semantics for a non-global role-specific ARIA property', () => {
        document.body.innerHTML = '<h2 role="none" aria-level="2">Presentational heading</h2>';
        expect(hasPresentationalRole(document.querySelector('h2') as HTMLElement)).toBe(true);
    });
});
