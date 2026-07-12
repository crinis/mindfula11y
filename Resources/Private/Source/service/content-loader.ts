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

import AjaxRequest from '@typo3/core/ajax/ajax-request.js';

/**
 * Fetches the frontend preview HTML for structure analysis. The request header
 * makes the ViewHelpers emit their `data-mindfula11y-*` annotations and the
 * extension middlewares disable frontend caching and the admin panel.
 *
 * Responses are cached per URL with in-flight deduplication; call
 * {@link invalidate} after a record was saved so the next load re-fetches.
 */
export class ContentLoader {
    private readonly cache = new Map<string, Promise<string>>();

    async load(url: string): Promise<string> {
        const pending = this.cache.get(url);
        if (pending !== undefined) {
            return pending;
        }
        const request = (async (): Promise<string> => {
            const response = await new AjaxRequest(url).get({
                headers: { 'Mindfula11y-Structure-Analysis': '1' },
            });
            return await response.resolve<string>();
        })();
        // Evict failed fetches so a later call can retry.
        const guarded = request.catch((error: unknown) => {
            this.cache.delete(url);
            throw error;
        });
        this.cache.set(url, guarded);
        return guarded;
    }

    invalidate(url: string): void {
        this.cache.delete(url);
    }
}

export default ContentLoader;
