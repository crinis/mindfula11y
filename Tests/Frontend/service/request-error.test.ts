/*
 * Mindful A11y extension for TYPO3 integrating accessibility tools into the backend.
 * Copyright (C) 2026  Mindful Markup, Felix Spittel
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 */

import { describe, expect, it, vi } from 'vitest';
import { errorView, RequestError, toRequestError } from '../../../Resources/Private/Source/service/request-error.js';

vi.mock('@typo3/core/lit-helper.js', () => ({
    lll: (key: string, ...args: Array<string | number>): string =>
        args.length > 0 ? `${key}(${args.join(',')})` : key,
}));

describe('toRequestError', () => {
    it('passes null and undefined rejection values through unchanged', async () => {
        // AjaxRequest can reject with a bare value; the converter must not
        // replace the original error with its own TypeError.
        await expect(toRequestError(null)).resolves.toBeNull();
        await expect(toRequestError(undefined)).resolves.toBeUndefined();
    });

    it('passes a primitive rejection value through unchanged', async () => {
        await expect(toRequestError('boom')).resolves.toBe('boom');
    });

    /** An AjaxRequest-style rejection carrying the given JSON error body. */
    const rejectionWith = (body: unknown, status: number = 400): { response: Response } => ({
        response: new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }),
    });

    it('converts a structured error body into a RequestError', async () => {
        const converted = await toRequestError(
            rejectionWith({ error: { title: 'Denied', description: 'No access.' } }, 403),
        );

        expect(converted).toBeInstanceOf(RequestError);
        expect(converted).toMatchObject({ message: 'Denied', description: 'No access.', status: 403 });
    });

    it('drops a non-string description instead of rendering it', async () => {
        const converted = await toRequestError(rejectionWith({ error: { title: 'Denied', description: { html: 1 } } }));

        expect(converted).toMatchObject({ message: 'Denied', description: '' });
    });

    it.each([
        ['a non-string title', { error: { title: { text: 'Denied' } } }],
        ['a non-object error', { error: 'Denied' }],
        ['a null body', null],
        ['an array body', [{ title: 'Denied' }]],
    ])('passes the original error through for %s', async (_case, body) => {
        const rejection = rejectionWith(body);

        await expect(toRequestError(rejection)).resolves.toBe(rejection);
    });
});

describe('errorView', () => {
    it('reads title and description straight off a RequestError', () => {
        const view = errorView(new RequestError('Scan failed', 'The scanner timed out.', 500), 'mindfula11y.fallback');

        expect(view).toEqual({ title: 'Scan failed', description: 'The scanner timed out.' });
    });

    it('falls back to the RequestError message when its description is empty', () => {
        const view = errorView(new RequestError('Scan failed', '', 500), 'mindfula11y.fallback');

        expect(view).toEqual({ title: 'Scan failed', description: 'Scan failed' });
    });

    it('falls back to the localized fallback key for a plain Error', () => {
        const view = errorView(new Error('boom'), 'mindfula11y.fallback');

        expect(view).toEqual({ title: 'mindfula11y.fallback', description: 'mindfula11y.fallback.description' });
    });

    it('falls back to the localized fallback key for a non-Error rejection value', () => {
        const view = errorView('not an error', 'mindfula11y.fallback');

        expect(view).toEqual({ title: 'mindfula11y.fallback', description: 'mindfula11y.fallback.description' });
    });
});
