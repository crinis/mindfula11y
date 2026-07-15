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
import { errorView, RequestError } from '../../../Resources/Private/Source/service/request-error.js';

vi.mock('@typo3/core/lit-helper.js', () => ({
    lll: (key: string, ...args: Array<string | number>): string =>
        args.length > 0 ? `${key}(${args.join(',')})` : key,
}));

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
