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
            this.tabs.renderPanel({ tab, withTablist: true, busy: false, content: html`<p>${tab}</p>` }),
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

    it('renders a plain aria-busy wrapper without tabpanel semantics when there is no tablist', async () => {
        const host = await mount();
        const container = document.createElement('div');
        render(host.tabs.renderPanel({ tab: 'one', withTablist: false, busy: true, content: html`x` }), container);

        const panel = container.querySelector('.panel');
        expect(panel?.getAttribute('aria-busy')).toBe('true');
        expect(panel?.getAttribute('role')).toBeNull();
        expect(panel?.hasAttribute('hidden')).toBe(false);
    });
});

declare global {
    interface HTMLElementTagNameMap {
        'mindfula11y-test-tabs-host': TabsHost;
    }
}
