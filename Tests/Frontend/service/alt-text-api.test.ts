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

const ajaxPost = vi.hoisted(() => vi.fn());

vi.mock('@typo3/core/lit-helper.js', () => ({
    lll: (key: string): string => key,
}));
vi.mock('@typo3/core/ajax/ajax-request.js', () => ({
    default: class {
        post(): Promise<{ resolve: () => Promise<unknown> }> {
            return ajaxPost();
        }
    },
}));

import { AltTextApi } from '../../../Resources/Private/Source/service/alt-text-api.js';

const answering = (body: unknown): void => {
    ajaxPost.mockResolvedValue({ resolve: async () => body });
};

describe('AltTextApi', () => {
    beforeEach(() => {
        ajaxPost.mockReset();
        const ajaxUrls = {};
        Reflect.set(ajaxUrls, 'mindfula11y_alttext_generate', '/generate');
        Reflect.set(globalThis, 'TYPO3', { settings: { ajaxUrls } });
    });

    it('returns a generated text', async () => {
        answering({ altText: 'A red bicycle' });

        await expect(new AltTextApi().generateAltText({})).resolves.toEqual({
            decorative: false,
            altText: 'A red bicycle',
        });
    });

    it('returns the decorative verdict without a text', async () => {
        answering({ decorative: true });

        await expect(new AltTextApi().generateAltText({})).resolves.toEqual({ decorative: true });
    });

    it.each([
        ['an empty text', { altText: '' }],
        ['a non-string text', { altText: 42 }],
        ['a decorative flag that is not true', { decorative: 'true' }],
        ['an empty body', {}],
    ])('rejects %s', async (_label, body) => {
        answering(body);

        await expect(new AltTextApi().generateAltText({})).rejects.toThrow('The alt-text endpoint returned no text.');
    });
});
