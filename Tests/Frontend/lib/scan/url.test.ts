/*
 * Mindful A11y extension for TYPO3 integrating accessibility tools into the backend.
 * Copyright (C) 2026  Mindful Markup, Felix Spittel
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 */

import { describe, expect, it } from 'vitest';
import { normalizeScanTargetUrl, urlListCoveredByTargets } from '../../../../Resources/Private/Source/lib/scan/url.js';

describe('normalizeScanTargetUrl', () => {
    it.each([
        ['strips a trailing slash from a language root', 'https://example.com/de/', 'https://example.com/de'],
        ['strips repeated trailing slashes', 'https://example.com/page//', 'https://example.com/page'],
        ['keeps the slash of the site root', 'https://example.com/', 'https://example.com/'],
        ['adds the slash a bare origin resolves to', 'https://example.com', 'https://example.com/'],
        ['drops the fragment', 'https://example.com/page#section', 'https://example.com/page'],
        ['drops the default https port', 'https://example.com:443/page/', 'https://example.com/page'],
        ['drops the default http port', 'http://example.com:80/page', 'http://example.com/page'],
        ['keeps a non-default port', 'https://example.com:8443/page/', 'https://example.com:8443/page'],
        ['keeps the query', 'https://example.com/page/?id=1', 'https://example.com/page?id=1'],
    ])('%s', (_label, url, expected) => {
        expect(normalizeScanTargetUrl(url)).toBe(expected);
    });

    it.each([
        ['a relative URL', '/page'],
        ['a non-http scheme', 'mailto:someone@example.com'],
        ['garbage', 'not a url'],
    ])('rejects %s', (_label, url) => {
        expect(normalizeScanTargetUrl(url)).toBeNull();
    });
});

describe('urlListCoveredByTargets', () => {
    it('matches preview URLs with trailing slashes against the targets MindfulAPI normalized', () => {
        // TYPO3 builds language roots and slash-terminated slugs; MindfulAPI
        // stores them without the trailing slash.
        const urlList = ['https://example.com/de/', 'https://example.com/de/about/'];
        const targets = ['https://example.com/de', 'https://example.com/de/about'];

        expect(urlListCoveredByTargets(urlList, targets)).toBe(true);
    });

    it('reports a URL the stored targets do not include', () => {
        expect(
            urlListCoveredByTargets(
                ['https://example.com/de/', 'https://example.com/de/new/'],
                ['https://example.com/de'],
            ),
        ).toBe(false);
    });

    it('compares a URL that cannot be normalized verbatim', () => {
        expect(urlListCoveredByTargets(['not a url'], ['not a url'])).toBe(true);
        expect(urlListCoveredByTargets(['not a url'], ['https://example.com/'])).toBe(false);
    });
});
