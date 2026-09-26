/*
 * Mindful A11y extension for TYPO3 integrating accessibility tools into the backend.
 * Copyright (C) 2026  Mindful Markup, Felix Spittel
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 */

// @vitest-environment happy-dom

import type { TemplateResult } from 'lit';
import { html, LitElement, render } from 'lit';
import { afterEach, describe, expect, it } from 'vitest';
import { TabsController } from '../../../Resources/Private/Source/lib/tabs.js';

// happy-dom knows no beforematch/until-found; the panel renderer feature-
// detects support through this property, so declare it to exercise the
// until-found path (individual tests delete it to cover the fallback).
Object.defineProperty(HTMLElement.prototype, 'onbeforematch', {
    value: null,
    writable: true,
    configurable: true,
});

type TestTab = 'one' | 'two' | 'three';

class TabsHost extends LitElement {
    availableTabs: TestTab[] = ['one', 'two', 'three'];

    readonly tabs: TabsController<TestTab> = new TabsController(this, () => this.availableTabs, 'one');

    override render(): TemplateResult {
        return html`${this.tabs.renderTablist({
            ariaLabel: 'Test tabs',
            tabs: this.availableTabs.map((id) => ({ id, label: id })),
        })}
        ${this.availableTabs.map((tab) =>
            this.tabs.renderPanel({ tab, busy: false, content: html`<p>${tab}</p>`, label: tab }),
        )}`;
    }
}

if (customElements.get('mindfula11y-test-tabs-host') === undefined) {
    customElements.define('mindfula11y-test-tabs-host', TabsHost);
}

const mount = async (): Promise<TabsHost> => {
    const host = document.createElement('mindfula11y-test-tabs-host') as TabsHost;
    document.body.append(host);
    await host.updateComplete;
    return host;
};

const tabButton = (host: TabsHost, tab: TestTab): HTMLButtonElement => {
    const button = host.shadowRoot?.querySelector<HTMLButtonElement>(`[data-tab="${tab}"]`);
    if (button === null || button === undefined) {
        throw new Error(`Tab button ${tab} not rendered.`);
    }
    return button;
};

const pressKey = async (host: TabsHost, tab: TestTab, key: string): Promise<void> => {
    tabButton(host, tab).dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true, composed: true }));
    // activateTabFromKeydown awaits host.updateComplete before focusing.
    await host.updateComplete;
    await new Promise((resolve) => setTimeout(resolve, 0));
};

describe('TabsController', () => {
    afterEach(() => {
        document.body.replaceChildren();
    });

    it('starts on the initial tab and reflects it in the rendered tablist and panels', async () => {
        const host = await mount();

        expect(host.tabs.activeTab).toBe('one');
        expect(tabButton(host, 'one').getAttribute('aria-selected')).toBe('true');
        expect(tabButton(host, 'two').getAttribute('aria-selected')).toBe('false');
        expect(host.shadowRoot?.querySelector('#panel-one')?.hasAttribute('hidden')).toBe(false);
        expect(host.shadowRoot?.querySelector('#panel-two')?.hasAttribute('hidden')).toBe(true);
        expect(host.shadowRoot?.querySelector('#panel-one')?.hasAttribute('aria-busy')).toBe(false);
        expect(host.shadowRoot?.querySelector('#panel-two')?.hasAttribute('aria-busy')).toBe(false);
    });

    it('activates a tab on click selection and re-renders the host', async () => {
        const host = await mount();

        tabButton(host, 'two').click();
        await host.updateComplete;

        expect(host.tabs.activeTab).toBe('two');
        expect(tabButton(host, 'two').getAttribute('aria-selected')).toBe('true');
        expect(tabButton(host, 'two').getAttribute('tabindex')).toBe('0');
        expect(tabButton(host, 'one').getAttribute('tabindex')).toBe('-1');
        expect(host.shadowRoot?.querySelector('#panel-two')?.hasAttribute('hidden')).toBe(false);
    });

    it('cycles forward with ArrowRight, wrapping past the last tab', async () => {
        const host = await mount();

        await pressKey(host, 'one', 'ArrowRight');
        expect(host.tabs.activeTab).toBe('two');

        await pressKey(host, 'two', 'ArrowRight');
        await pressKey(host, 'three', 'ArrowRight');
        expect(host.tabs.activeTab).toBe('one');
    });

    it('cycles backward with ArrowLeft, wrapping before the first tab', async () => {
        const host = await mount();

        await pressKey(host, 'one', 'ArrowLeft');
        expect(host.tabs.activeTab).toBe('three');
    });

    it('jumps to the ends with Home and End', async () => {
        const host = await mount();

        await pressKey(host, 'one', 'End');
        expect(host.tabs.activeTab).toBe('three');

        await pressKey(host, 'three', 'Home');
        expect(host.tabs.activeTab).toBe('one');
    });

    it('leaves other keys alone', async () => {
        const host = await mount();

        await pressKey(host, 'one', 'Enter');
        expect(host.tabs.activeTab).toBe('one');
    });

    it('re-anchors onto the first available tab when the active one disappears', async () => {
        const host = await mount();
        host.tabs.select('three');
        await host.updateComplete;

        host.availableTabs = ['two'];
        host.tabs.ensureActive('one');

        expect(host.tabs.activeTab).toBe('two');
    });

    it('falls back to the given default when no tab is available', async () => {
        const host = await mount();
        host.tabs.select('two');

        host.availableTabs = [];
        host.tabs.ensureActive('one');

        expect(host.tabs.activeTab).toBe('one');
    });

    it('keeps the active tab when it is still available', async () => {
        const host = await mount();
        host.tabs.select('two');

        host.tabs.ensureActive('one');

        expect(host.tabs.activeTab).toBe('two');
    });

    it('hides inactive panels with hidden="until-found" so find-in-page can reach them', async () => {
        const host = await mount();

        expect(host.shadowRoot?.querySelector('#panel-one')?.hasAttribute('hidden')).toBe(false);
        expect(host.shadowRoot?.querySelector('#panel-two')?.getAttribute('hidden')).toBe('until-found');
        expect(host.shadowRoot?.querySelector('#panel-three')?.getAttribute('hidden')).toBe('until-found');
        // The until-found box still renders (only contents are skipped), so an
        // inactive panel must not be a sequential tab stop nor surface as an
        // empty tabpanel node in the accessibility tree.
        expect(host.shadowRoot?.querySelector('#panel-one')?.getAttribute('tabindex')).toBe('0');
        expect(host.shadowRoot?.querySelector('#panel-two')?.hasAttribute('tabindex')).toBe(false);
        expect(host.shadowRoot?.querySelector('#panel-one')?.hasAttribute('aria-hidden')).toBe(false);
        expect(host.shadowRoot?.querySelector('#panel-two')?.getAttribute('aria-hidden')).toBe('true');
    });

    it('falls back to the plain hidden attribute where until-found is unsupported', async () => {
        // Without the fallback, panels that set their own `display` would beat
        // the UA [hidden] rule in non-supporting browsers and stay visible.
        const descriptor = Object.getOwnPropertyDescriptor(HTMLElement.prototype, 'onbeforematch');
        Reflect.deleteProperty(HTMLElement.prototype, 'onbeforematch');
        try {
            const host = await mount();
            expect(host.shadowRoot?.querySelector('#panel-two')?.getAttribute('hidden')).toBe('');
        } finally {
            if (descriptor !== undefined) {
                Object.defineProperty(HTMLElement.prototype, 'onbeforematch', descriptor);
            }
        }
    });

    it('selects the tab of a panel that find-in-page reveals, keeping tablist state in sync', async () => {
        const host = await mount();

        // The browser fires beforematch on the hidden panel right before it
        // removes the until-found attribute to reveal a find-in-page match.
        host.shadowRoot?.querySelector('#panel-two')?.dispatchEvent(new Event('beforematch'));
        await host.updateComplete;

        expect(host.tabs.activeTab).toBe('two');
        expect(tabButton(host, 'two').getAttribute('aria-selected')).toBe('true');
        expect(tabButton(host, 'two').getAttribute('tabindex')).toBe('0');
        expect(host.shadowRoot?.querySelector('#panel-two')?.hasAttribute('hidden')).toBe(false);
        expect(host.shadowRoot?.querySelector('#panel-one')?.getAttribute('hidden')).toBe('until-found');
    });

    it('names the single view as a region when there is no tablist to name it', async () => {
        const host = await mount();
        // A lone tab is the whole trigger: the controller derives the absence
        // of tab chrome from the tab set, callers never pass it in.
        host.availableTabs = ['one'];
        const container = document.createElement('div');
        render(
            host.tabs.renderPanel({
                tab: 'one',
                busy: true,
                content: html`x`,
                label: 'Headings',
            }),
            container,
        );

        // No tab exists to label this panel, so it carries its own name
        // instead of being an anonymous container — and it is never hidden or
        // a tabpanel, because there is nothing to switch between.
        const panel = container.querySelector('.panel');
        expect(panel?.getAttribute('role')).toBe('region');
        expect(panel?.getAttribute('aria-label')).toBe('Headings');
        expect(panel?.getAttribute('aria-busy')).toBe('true');
        expect(panel?.hasAttribute('hidden')).toBe(false);
    });
});

declare global {
    interface HTMLElementTagNameMap {
        'mindfula11y-test-tabs-host': TabsHost;
    }
}
