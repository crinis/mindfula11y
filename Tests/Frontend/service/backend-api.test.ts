/*
 * Mindful A11y extension for TYPO3 integrating accessibility tools into the backend.
 * Copyright (C) 2026  Mindful Markup, Felix Spittel
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 */

import { beforeEach, describe, expect, it, vi } from 'vitest';
import { getJson, postJson } from '../../../Resources/Private/Source/service/backend-api.js';
import { RequestError } from '../../../Resources/Private/Source/service/request-error.js';

const ajaxGet = vi.hoisted(() => vi.fn());
const ajaxPost = vi.hoisted(() => vi.fn());
const withQueryArguments = vi.hoisted(() => vi.fn());

vi.mock('@typo3/core/lit-helper.js', () => ({
    lll: (key: string): string => key,
}));

vi.mock('@typo3/core/ajax/ajax-request.js', () => ({
    default: class {
        readonly url: string;

        constructor(url: string) {
            this.url = url;
        }

        withQueryArguments(params: unknown): unknown {
            withQueryArguments(params);
            return this;
        }

        get(init?: unknown): unknown {
            return ajaxGet(this.url, init);
        }

        post(body: unknown, init?: unknown): unknown {
            return ajaxPost(this.url, body, init);
        }
    },
}));

describe('backend-api', () => {
    beforeEach(() => {
        ajaxGet.mockReset();
        ajaxPost.mockReset();
        withQueryArguments.mockReset();
        const ajaxUrls = {};
        Reflect.set(ajaxUrls, 'mindfula11y_test', '/test');
        Reflect.set(globalThis, 'TYPO3', { settings: { ajaxUrls } });
    });

    describe('getJson', () => {
        it('throws with the ajaxUrlKey in the message when the endpoint is not registered', async () => {
            await expect(getJson('mindfula11y_missing')).rejects.toThrow(
                'AJAX endpoint not registered: mindfula11y_missing',
            );
            expect(ajaxGet).not.toHaveBeenCalled();
        });

        it('resolves the typed payload on the happy path', async () => {
            ajaxGet.mockReturnValue({ resolve: async () => ({ value: 42 }) });

            const result = await getJson<{ value: number }>('mindfula11y_test');

            expect(result).toEqual({ value: 42 });
            expect(ajaxGet).toHaveBeenCalledWith('/test', {});
        });

        it('threads the abort signal through to the request', async () => {
            ajaxGet.mockReturnValue({ resolve: async () => ({}) });
            const controller = new AbortController();

            await getJson('mindfula11y_test', undefined, { signal: controller.signal });

            expect(ajaxGet).toHaveBeenCalledWith('/test', expect.objectContaining({ signal: controller.signal }));
        });

        it('forwards query params onto the request', async () => {
            ajaxGet.mockReturnValue({ resolve: async () => ({}) });

            await getJson('mindfula11y_test', { scanId: 'abc' });

            expect(withQueryArguments).toHaveBeenCalledWith({ scanId: 'abc' });
        });

        it('converts a structured backend error body into a RequestError', async () => {
            const response = new Response(JSON.stringify({ error: { title: 'Oops', description: 'Bad request' } }), {
                status: 500,
            });
            ajaxGet.mockReturnValue(Promise.reject(Object.assign(new Error('failed'), { response })));

            await expect(getJson('mindfula11y_test')).rejects.toBeInstanceOf(RequestError);
        });
    });

    describe('postJson', () => {
        it('throws with the ajaxUrlKey in the message when the endpoint is not registered', async () => {
            await expect(postJson('mindfula11y_missing', {})).rejects.toThrow(
                'AJAX endpoint not registered: mindfula11y_missing',
            );
            expect(ajaxPost).not.toHaveBeenCalled();
        });

        it('sets the JSON content-type header, posts the body and resolves the typed payload', async () => {
            ajaxPost.mockReturnValue({ resolve: async () => ({ ok: true }) });

            const result = await postJson<{ ok: boolean }>('mindfula11y_test', { a: 1 });

            expect(result).toEqual({ ok: true });
            expect(ajaxPost).toHaveBeenCalledWith(
                '/test',
                { a: 1 },
                { headers: { 'Content-Type': 'application/json; charset=utf-8' } },
            );
        });

        it('threads the abort signal through to the request', async () => {
            ajaxPost.mockReturnValue({ resolve: async () => ({}) });
            const controller = new AbortController();

            await postJson('mindfula11y_test', {}, { signal: controller.signal });

            expect(ajaxPost).toHaveBeenCalledWith('/test', {}, expect.objectContaining({ signal: controller.signal }));
        });

        it('converts a structured backend error body into a RequestError', async () => {
            const response = new Response(JSON.stringify({ error: { title: 'Oops', description: 'Bad request' } }), {
                status: 422,
            });
            ajaxPost.mockReturnValue(Promise.reject(Object.assign(new Error('failed'), { response })));

            await expect(postJson('mindfula11y_test', {})).rejects.toBeInstanceOf(RequestError);
        });
    });
});
