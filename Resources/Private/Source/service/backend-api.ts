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
import { toRequestError } from './request-error.js';

const JSON_CONTENT_TYPE_HEADERS = { 'Content-Type': 'application/json; charset=utf-8' };

/**
 * Resolves a registered AJAX route by its `TYPO3.settings.ajaxUrls` key.
 * Throws (before any network call) when the key isn't registered — a
 * missing route is a configuration bug, not a transient failure.
 */
const resolveAjaxUrl = (ajaxUrlKey: string): string => {
    const url = TYPO3.settings.ajaxUrls[ajaxUrlKey];
    if (url === undefined) {
        throw new Error(`AJAX endpoint not registered: ${ajaxUrlKey}`);
    }
    return url;
};

/**
 * Builds a `RequestInit` that omits `signal` entirely when unset — required
 * under `exactOptionalPropertyTypes`, which rejects an explicit
 * `signal: undefined` against DOM's `signal?: AbortSignal | null` property.
 */
const requestInit = (signal: AbortSignal | undefined, headers?: Record<string, string>): RequestInit => {
    const init: RequestInit = {};
    if (headers !== undefined) {
        init.headers = headers;
    }
    if (signal !== undefined) {
        init.signal = signal;
    }
    return init;
};

/**
 * GETs a registered AJAX route and resolves its JSON body.
 *
 * `params` accepts array values for repeated query keys (e.g.
 * `pageUrls: string[]`) — `AjaxRequest.withQueryArguments` flattens them into
 * the bracketed `key[0]=…&key[1]=…` form TYPO3's own query-param parsing on
 * the PHP side understands.
 *
 * On failure throws `await toRequestError(error)` uniformly, so callers can
 * rely on `RequestError` whenever the backend sent its structured error body.
 */
export const getJson = async <T>(
    ajaxUrlKey: string,
    params?: Record<string, string | string[]>,
    options?: { signal?: AbortSignal | undefined },
): Promise<T> => {
    const url = resolveAjaxUrl(ajaxUrlKey);
    try {
        let request = new AjaxRequest(url);
        if (params !== undefined) {
            request = request.withQueryArguments(params);
        }
        const response = await request.get(requestInit(options?.signal));
        return await response.resolve<T>();
    } catch (error) {
        throw await toRequestError(error);
    }
};

/**
 * POSTs a JSON body to a registered AJAX route and resolves the response's
 * JSON body. Same endpoint-lookup and error-conversion behavior as
 * {@link getJson}.
 */
export const postJson = async <T>(
    ajaxUrlKey: string,
    body: Record<string, unknown> | BodyInit | null,
    options?: { signal?: AbortSignal | undefined },
): Promise<T> => {
    const url = resolveAjaxUrl(ajaxUrlKey);
    try {
        const response = await new AjaxRequest(url).post(body, requestInit(options?.signal, JSON_CONTENT_TYPE_HEADERS));
        return await response.resolve<T>();
    } catch (error) {
        throw await toRequestError(error);
    }
};
