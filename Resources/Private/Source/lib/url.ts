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
 * Helpers for safely handling URLs that originate from untrusted sources
 * (e.g. the external accessibility scanner API).
 */

/**
 * Returns the given URL only when it resolves to a safe http(s) scheme,
 * otherwise `#`.
 *
 * Help/page URLs come from the external scanner response and are rendered
 * into href attributes in the backend origin. Lit escapes the attribute value
 * but does not validate the scheme, so a `javascript:`/`data:` URL would
 * otherwise produce a clickable script sink.
 */
export function safeHttpUrl(url: unknown): string {
    if (typeof url !== 'string' || url === '') {
        return '#';
    }
    try {
        const parsed = new URL(url, window.location.origin);
        return parsed.protocol === 'http:' || parsed.protocol === 'https:' ? url : '#';
    } catch {
        return '#';
    }
}
