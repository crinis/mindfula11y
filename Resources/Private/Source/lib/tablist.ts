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

import type { LitElement } from 'lit';

/**
 * Keyboard activation for a `role="tablist"` with roving tabindex: the arrow
 * keys cycle through the tabs, Home/End jump to the ends. A handled key
 * activates the next tab and, once the host re-rendered, moves focus to its
 * button — callers render each tab button with a `data-tab` attribute
 * matching the tab name. Every other key is left alone.
 */
export async function activateTabFromKeydown<T extends string>(
    host: LitElement,
    event: KeyboardEvent,
    tabs: readonly T[],
    activeTab: T,
    activate: (tab: T) => void,
): Promise<void> {
    const index = tabs.indexOf(activeTab);
    let next: T | undefined;
    switch (event.key) {
        case 'ArrowRight':
            next = tabs[(index + 1) % tabs.length];
            break;
        case 'ArrowLeft':
            next = tabs[(index - 1 + tabs.length) % tabs.length];
            break;
        case 'Home':
            next = tabs[0];
            break;
        case 'End':
            next = tabs[tabs.length - 1];
            break;
        default:
            return;
    }
    if (next === undefined) {
        return;
    }
    event.preventDefault();
    activate(next);
    await host.updateComplete;
    host.renderRoot.querySelector<HTMLElement>(`[data-tab="${next}"]`)?.focus();
}
