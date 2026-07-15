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

import { describe, expect, it } from 'vitest';
import { withQueryParams } from '../../../Resources/Private/Source/lib/url.js';

describe('withQueryParams', () => {
    it('appends params to a base URL without an existing query', () => {
        const result = withQueryParams('https://example.com/path', { a: '1' });

        expect(result).toBe('https://example.com/path?a=1');
    });

    it('preserves existing query params and adds the new ones', () => {
        const result = withQueryParams('https://example.com/path?a=1', { b: '2' });

        expect(result).toBe('https://example.com/path?a=1&b=2');
    });

    it('overwrites a param that already exists on the base URL', () => {
        const result = withQueryParams('https://example.com/path?a=1', { a: '2' });

        expect(result).toBe('https://example.com/path?a=2');
    });

    it('encodes values that need escaping', () => {
        const result = withQueryParams('https://example.com/path', { q: 'a b&c' });

        expect(result).toBe('https://example.com/path?q=a+b%26c');
    });

    it('resolves a relative base against the current origin', () => {
        const result = withQueryParams('/module/scan', { scanId: 'abc' });

        expect(result).toBe(`${window.location.origin}/module/scan?scanId=abc`);
    });
});
