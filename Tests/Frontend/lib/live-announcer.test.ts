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
import { LitElement } from 'lit';
import { afterEach, describe, expect, it } from 'vitest';
import { LiveAnnouncer } from '../../../Resources/Private/Source/lib/live-announcer.js';

class LiveAnnouncerHost extends LitElement {
    readonly announcer: LiveAnnouncer = new LiveAnnouncer(this);
    updateCount: number = 0;

    protected override updated(): void {
        this.updateCount += 1;
    }

    override render(): TemplateResult {
        return this.announcer.render();
    }
}

if (customElements.get('mindfula11y-test-live-announcer') === undefined) {
    customElements.define('mindfula11y-test-live-announcer', LiveAnnouncerHost);
}

describe('LiveAnnouncer', () => {
    afterEach(() => {
        document.body.replaceChildren();
    });

    it('mounts an empty status region before announcing a message', async () => {
        const host = document.createElement('mindfula11y-test-live-announcer') as LiveAnnouncerHost;
        document.body.append(host);
        await host.updateComplete;

        const status = host.shadowRoot?.querySelector('[role="status"]');
        expect(status?.textContent).toBe('');

        await host.announcer.announce('Analysis completed');

        expect(status?.textContent).toBe('Analysis completed');
    });

    it('clears and repopulates the region so identical messages produce two updates', async () => {
        const host = document.createElement('mindfula11y-test-live-announcer') as LiveAnnouncerHost;
        document.body.append(host);
        await host.updateComplete;
        await host.announcer.announce('Scan started');
        const previousUpdateCount = host.updateCount;

        await host.announcer.announce('Scan started');

        expect(host.updateCount).toBe(previousUpdateCount + 2);
        expect(host.shadowRoot?.querySelector('[role="status"]')?.textContent).toBe('Scan started');
    });
});

declare global {
    interface HTMLElementTagNameMap {
        'mindfula11y-test-live-announcer': LiveAnnouncerHost;
    }
}
