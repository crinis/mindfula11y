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

import { lll } from '@typo3/core/lit-helper.js';
import { isObject } from '../lib/guards.js';

/** Error the backend reports as a structured `{ error: { title, description } }` body. */
export class RequestError extends Error {
    readonly description: string;
    /** HTTP status of the backend response; 0 when unknown. */
    readonly status: number;
    /** Seconds the response's Retry-After header asks to wait (e.g. on a 429); null when it names none. */
    readonly retryAfter: number | null;

    constructor(message: string, description: string = '', status: number = 0, retryAfter: number | null = null) {
        super(message);
        this.name = 'RequestError';
        this.description = description;
        this.status = status;
        this.retryAfter = retryAfter;
    }
}

/** The Response an AjaxRequest rejection carries, if any. */
const responseOf = (error: unknown): Response | undefined =>
    // The rejection value may be anything (including null) — never let this
    // property access throw in place of the original error.
    isObject(error) && error.response instanceof Response ? error.response : undefined;

/**
 * A Retry-After header in seconds: either form RFC 9110 allows
 * (delay-seconds or an HTTP date); null when absent or unreadable.
 */
const parseRetryAfter = (value: string | null): number | null => {
    const trimmed = value?.trim() ?? '';
    if (/^\d+$/.test(trimmed)) {
        return Number(trimmed);
    }
    const date = trimmed === '' ? Number.NaN : Date.parse(trimmed);
    return Number.isNaN(date) ? null : Math.max(0, Math.round((date - Date.now()) / 1000));
};

/**
 * Converts a failed AjaxRequest into a RequestError when the backend sent its
 * structured error body; otherwise returns the original error unchanged.
 */
export const toRequestError = async (error: unknown): Promise<unknown> => {
    const response = responseOf(error);
    if (response === undefined) {
        return error;
    }
    try {
        // Validated, not cast (a wire payload): a non-string title would
        // otherwise render as "[object Object]".
        const data: unknown = await response.clone().json();
        const body = isObject(data) && isObject(data.error) ? data.error : undefined;
        if (body !== undefined && typeof body.title === 'string') {
            const description = typeof body.description === 'string' ? body.description : '';
            return new RequestError(
                body.title,
                description,
                response.status,
                parseRetryAfter(response.headers.get('Retry-After')),
            );
        }
    } catch {
        // Non-JSON error body — fall through to the original error.
    }
    return error;
};

/**
 * The HTTP status any caught request error carries: a RequestError's, or
 * that of an AjaxRequest rejection the backend answered without its
 * structured body (TYPO3's own 401 for an expired session, for instance).
 * 0 when there is none — a network failure or a non-request error.
 */
export const httpStatusOf = (error: unknown): number => {
    if (error instanceof RequestError) {
        return error.status;
    }
    return responseOf(error)?.status ?? 0;
};

/** Display pair every caught error is rendered as. */
export interface ErrorView {
    title: string;
    description: string;
}

/**
 * Renders any caught error as a `{ title, description }` pair for display:
 * a RequestError's own localized title/description (the title doubles as the
 * description when the backend sent none — some endpoints report a title
 * only), or the given fallback label (`${fallbackKey}.description` for the
 * description) for anything else — an unexpected exception must never leak
 * its raw message to editors.
 */
export const errorView = (error: unknown, fallbackKey: string): ErrorView => {
    if (error instanceof RequestError) {
        return { title: error.message, description: error.description !== '' ? error.description : error.message };
    }
    return { title: lll(fallbackKey), description: lll(`${fallbackKey}.description`) };
};
