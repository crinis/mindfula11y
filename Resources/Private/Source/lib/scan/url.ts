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

/**
 * A URL in the form MindfulAPI stores a scan target: fragment removed,
 * default port dropped, trailing slashes stripped except on the root path —
 * the rules of its `normalizeHttpUrl()` (src/utils/url-normalization.util.ts).
 * `null` for anything that is not an absolute http(s) URL, which MindfulAPI
 * rejects as a target.
 */
export function normalizeScanTargetUrl(url: string): string | null {
    let parsed: URL;
    try {
        parsed = new URL(url);
    } catch {
        return null;
    }
    if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') {
        return null;
    }
    // The URL parser already drops a default port (`:443` on https).
    parsed.hash = '';
    if (parsed.pathname.length > 1 && parsed.pathname.endsWith('/')) {
        parsed.pathname = parsed.pathname.replace(/\/+$/, '');
    }
    return parsed.href;
}

/**
 * Whether a stored scan covers every URL of the selected scope. TYPO3's
 * preview URLs (language roots, slash-terminated slugs) differ from the
 * targets MindfulAPI normalized them to, so both sides are compared in that
 * normalized form; a URL that cannot be normalized is compared verbatim.
 */
export function urlListCoveredByTargets(urlList: readonly string[], targets: readonly string[]): boolean {
    const normalized = (url: string): string => normalizeScanTargetUrl(url) ?? url;
    const targetSet = new Set(targets.map(normalized));
    return urlList.every((url) => targetSet.has(normalized(url)));
}
