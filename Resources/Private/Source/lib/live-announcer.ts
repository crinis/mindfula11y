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

import type { ReactiveControllerHost, TemplateResult } from 'lit';
import { html } from 'lit';

/**
 * Non-visible screen-reader announcements through a pre-rendered live region. The host
 * mounts `render()` in its first render and keeps it mounted (announcements
 * are unreliable when the region itself is inserted); `announce()` clears the
 * region first — with a completed render in between — so an unchanged message
 * is still re-announced.
 */
export class LiveAnnouncer {
    private readonly host: ReactiveControllerHost;
    private message: string = '';
    /** Tail of the announcement chain — never rejects (see announce()). */
    private pending: Promise<void> = Promise.resolve();

    constructor(host: ReactiveControllerHost) {
        this.host = host;
    }

    /**
     * Announcements are serialized: an overlapping call waits for the previous
     * clear/set double-render to complete, otherwise it would wipe the earlier
     * message before assistive technology picks it up.
     */
    announce(message: string, signal?: AbortSignal): Promise<void> {
        const run = this.pending.then(() => this.performAnnounce(message, signal));
        // The chain must survive an aborted predecessor; only the caller's own
        // returned promise rejects.
        this.pending = run.then(
            () => undefined,
            () => undefined,
        );
        return run;
    }

    private async performAnnounce(message: string, signal?: AbortSignal): Promise<void> {
        signal?.throwIfAborted();
        this.message = '';
        this.host.requestUpdate();
        await this.host.updateComplete;
        signal?.throwIfAborted();
        this.message = message;
        this.host.requestUpdate();
        await this.host.updateComplete;
        signal?.throwIfAborted();
    }

    /** The visually hidden live region carrying the current announcement. */
    render(): TemplateResult {
        return html`<div class="sr-only" role="status"><span>${this.message}</span></div>`;
    }
}
