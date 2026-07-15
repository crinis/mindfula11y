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

import type { LitElement, TemplateResult } from 'lit';
import { html, nothing } from 'lit';

/**
 * Shared `role="tablist"`/`role="tabpanel"` chrome and keyboard activation for
 * the two tabbed containers (`<mindfula11y-scan>`, `<mindfula11y-structure>`).
 *
 * Both callers share one id scheme (`tab-<id>` / `panel-<id>`). The ids only
 * need to be unique within a shadow root, so a caller mounting a second tablist
 * into one root would have to prefix them.
 */

/** One tab's static description; the badge is rendered by the caller (result-dependent). */
export interface TabDescriptor {
    id: string;
    label: string;
    badge?: TemplateResult | typeof nothing;
    /** Optional: structure.ts disables tabs while the first analysis is still pending. */
    disabled?: boolean;
}

/** Renders the `role="tablist"` wrapper and its `role="tab"` buttons. */
export const renderTablist = (opts: {
    ariaLabel: string;
    tabs: TabDescriptor[];
    activeTab: string;
    onSelect: (id: string) => void;
    onKeydown: (event: KeyboardEvent) => void;
}): TemplateResult => {
    const { ariaLabel, tabs, activeTab, onSelect, onKeydown } = opts;
    return html`<div class="tabs" role="tablist" aria-label=${ariaLabel}>
        ${tabs.map((tab) => {
            const selected = activeTab === tab.id;
            return html`<button
                type="button"
                role="tab"
                id="tab-${tab.id}"
                data-tab=${tab.id}
                aria-selected=${selected ? 'true' : 'false'}
                aria-controls="panel-${tab.id}"
                tabindex=${selected ? '0' : '-1'}
                ?disabled=${tab.disabled ?? false}
                @click=${(): void => onSelect(tab.id)}
                @keydown=${onKeydown}
            >
                ${tab.label} ${tab.badge ?? nothing}
            </button>`;
        })}
    </div>`;
};

/**
 * Renders one panel's wrapper. `withTablist=false` produces a plain
 * `aria-busy` wrapper with no tabpanel ARIA (the single-view case, where the
 * caller renders its own heading/context instead of a tablist).
 */
export const renderTabPanel = (opts: {
    tab: string;
    active: boolean;
    withTablist: boolean;
    busy: boolean;
    content: TemplateResult;
}): TemplateResult => {
    const { tab, active, withTablist, busy, content } = opts;
    if (!withTablist) {
        return html`<div class="panel" aria-busy=${busy ? 'true' : 'false'}>${content}</div>`;
    }
    return html`<div
        class="panel"
        role="tabpanel"
        id="panel-${tab}"
        aria-labelledby="tab-${tab}"
        tabindex="0"
        aria-busy=${busy ? 'true' : 'false'}
        ?hidden=${!active}
    >
        ${content}
    </div>`;
};

/**
 * Keyboard activation for the tablist above, with roving tabindex: the arrow
 * keys cycle through the tabs, Home/End jump to the ends. A handled key
 * activates the next tab and, once the host re-rendered, moves focus to its
 * button — renderTablist gives each tab button the matching `data-tab`
 * attribute. Every other key is left alone.
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
