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

import type { LitElement, ReactiveController, TemplateResult } from 'lit';
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
export interface TabDescriptor<T extends string = string> {
    id: T;
    label: string;
    badge?: TemplateResult | typeof nothing;
}

/** Renders the `role="tablist"` wrapper and its `role="tab"` buttons. */
export const renderTablist = <T extends string>(opts: {
    ariaLabel: string;
    tabs: TabDescriptor<T>[];
    activeTab: string;
    onSelect: (id: T) => void;
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
                @click=${(): void => onSelect(tab.id)}
                @keydown=${onKeydown}
            >
                ${tab.label} ${tab.badge ?? nothing}
            </button>`;
        })}
    </div>`;
};

/**
 * What a panel needs; the controller derives `active` and `withTablist` from
 * the tab set itself. `label` is always required: without a tablist there is
 * no tab to name the panel and it becomes a labelled region instead, and an
 * unnamed region is inert — so the compiler asks for the name unconditionally
 * rather than a doc comment asking for it in one of two cases.
 */
export type TabPanelContent<T extends string = string> = {
    tab: T;
    busy: boolean;
    content: TemplateResult;
    label: string;
};

export type TabPanelOptions<T extends string = string> = TabPanelContent<T> & {
    active: boolean;
    withTablist: boolean;
    /** Selects this panel's tab when find-in-page reveals the hidden panel. */
    onReveal: () => void;
};

/**
 * Whether the browser reveals `hidden="until-found"` content for find-in-page.
 * Feature-detected per render (cheap), NOT assumed: in a non-supporting
 * browser the value degrades to plain `hidden` semantics only through the
 * UA's `[hidden] { display: none }` rule — which any author `display` on the
 * panel (scan sets `display: flex`) silently beats, showing both panels at
 * once. Unsupporting browsers therefore get the plain `hidden` attribute,
 * which the component stylesheets re-assert to `display: none`.
 */
const untilFoundSupported = (): boolean => 'onbeforematch' in HTMLElement.prototype;

/**
 * Renders one panel's wrapper: a `role="tabpanel"` named by its tab when a
 * tablist exists, else a `role="region"` carrying its own name.
 *
 * Inactive panels use `hidden="until-found"` (where supported) so their
 * findings stay reachable through the browser's find-in-page, which fires
 * `beforematch` and removes the attribute; `onReveal` then selects the tab so
 * `aria-selected` and the roving tabindex follow the reveal instead of
 * silently desyncing. Screen-reader exposure of the CONTENTS is unchanged:
 * the UA's `content-visibility: hidden` skips them exactly like
 * `display: none` did. The panel element itself still generates a box in
 * that state (per spec: margins/background render, only contents are
 * skipped), so it carries `tabindex` only while active — otherwise Tab would
 * stop on an invisible zero-height box — and `aria-hidden` while inactive:
 * the box would otherwise surface as an EMPTY named tabpanel node in the
 * accessibility tree (verified in Chromium), which browse-mode users would
 * stumble over. `aria-hidden` only affects the accessibility tree — the
 * find-in-page index that until-found hooks is untouched.
 */
export const renderTabPanel = (opts: TabPanelOptions): TemplateResult => {
    const { tab, active, busy, content } = opts;
    if (!opts.withTablist) {
        return html`<div class="panel" role="region" aria-label=${opts.label} aria-busy=${busy ? 'true' : nothing}>
            ${content}
        </div>`;
    }
    return html`<div
        class="panel"
        role="tabpanel"
        id="panel-${tab}"
        aria-labelledby="tab-${tab}"
        tabindex=${active ? '0' : nothing}
        aria-busy=${busy ? 'true' : nothing}
        hidden=${active ? nothing : untilFoundSupported() ? 'until-found' : ''}
        aria-hidden=${active ? nothing : 'true'}
        @beforematch=${opts.onReveal}
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
    if (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft' && event.key !== 'Home' && event.key !== 'End') {
        return;
    }
    if (tabs.length === 0) {
        return;
    }
    // Cyclic walk from the active tab's position; Home/End jump to the ends.
    const from = tabs.indexOf(activeTab);
    let index: number;
    switch (event.key) {
        case 'ArrowRight':
            index = (from + 1) % tabs.length;
            break;
        case 'ArrowLeft':
            index = (from - 1 + tabs.length) % tabs.length;
            break;
        case 'Home':
            index = 0;
            break;
        default:
            index = tabs.length - 1;
    }
    const next = tabs[index];
    if (next === undefined) {
        return;
    }
    event.preventDefault();
    activate(next);
    await host.updateComplete;
    host.renderRoot.querySelector<HTMLElement>(`[data-tab="${next}"]`)?.focus();
}

/**
 * Reactive controller owning a tabbed container's glue: the active-tab state,
 * tab selection and the tablist keyboard activation. The host supplies only
 * its {@link TabDescriptor}s and panel contents; the rendered markup comes
 * unchanged from {@link renderTablist}/{@link renderTabPanel}.
 */
export class TabsController<T extends string> implements ReactiveController {
    private active: T;

    constructor(
        private readonly host: LitElement,
        /** Currently available tabs, in display order (drives arrow-key cycling). */
        private readonly tabs: () => readonly T[],
        initial: T,
    ) {
        this.active = initial;
        host.addController(this);
    }

    hostConnected(): void {
        // All state is component-lifetime; registering as a controller only
        // ties `select()`'s requestUpdate to the host. (ReactiveController is
        // a weak type — one member must be declared.)
    }

    get activeTab(): T {
        return this.active;
    }

    /** Activates a tab and re-renders the host (click selection, findings jump). */
    select(tab: T): void {
        this.active = tab;
        this.host.requestUpdate();
    }

    /**
     * Re-anchors the active tab when it is no longer available. Call from
     * `willUpdate` — the host is already updating, so no update is requested.
     */
    ensureActive(fallback: T): void {
        const available = this.tabs();
        if (!available.includes(this.active)) {
            this.active = available[0] ?? fallback;
        }
    }

    /**
     * Whether the container shows tab chrome at all. Derived from the tab set
     * the controller already owns, so the rule lives here rather than at every
     * call site: a lone tab has nothing to switch between, and its panel names
     * itself as a region instead of being named by an absent tab.
     */
    private get withTablist(): boolean {
        return this.tabs().length > 1;
    }

    /**
     * Renders the tablist for the host-built descriptors of the current tab
     * set, or nothing when a single tab makes the chrome pointless.
     */
    renderTablist(opts: { ariaLabel: string; tabs: TabDescriptor<T>[] }): TemplateResult | typeof nothing {
        if (!this.withTablist) {
            return nothing;
        }
        return renderTablist<T>({
            ...opts,
            activeTab: this.active,
            onSelect: (id: T): void => this.select(id),
            onKeydown: this.handleKeydown,
        });
    }

    /** Renders one panel wrapper around the host-supplied content. */
    renderPanel(opts: TabPanelContent<T>): TemplateResult {
        return renderTabPanel({
            ...opts,
            withTablist: this.withTablist,
            active: this.active === opts.tab,
            onReveal: (): void => this.select(opts.tab),
        });
    }

    private readonly handleKeydown = (event: KeyboardEvent): void => {
        void activateTabFromKeydown(this.host, event, this.tabs(), this.active, (tab) => this.select(tab));
    };
}
