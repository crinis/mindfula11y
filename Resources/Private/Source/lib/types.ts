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
 * Cross-feature types and the typed catalogue of every custom event this
 * extension dispatches (`mindfula11y:<domain>:<action>`, always with
 * `bubbles: true, composed: true`). Feature-specific types live with their
 * feature: `lib/structure/types.ts`, `service/scan/types.ts`.
 */

/** Opaque, HMAC-signed alt-text generation payload serialized into elements by PHP. */
export type GenerateAltTextDemand = Record<string, unknown>;

/** Database coordinates of the record behind a heading/landmark. */
export interface RecordReference {
    tableName: string;
    columnName: string;
    uid: number;
    editLink: string;
    /**
     * The column's stored value when it can differ from what the rendered element
     * implies — emitted for headings whose level derives from a container's
     * child-type column (empty string = "automatic"). Absent for ordinary records,
     * where the rendered state is the stored state.
     */
    storedValue?: string;
}

/** Detail payloads of the extension's custom events, keyed by event name. */
export interface Mindfula11yEventMap {
    'mindfula11y:scan:completed': CustomEvent<{ scanId: string; totalIssueCount: number }>;
    'mindfula11y:scan:canceled': CustomEvent<{ scanId: string }>;
    'mindfula11y:structure:changed': CustomEvent<{
        nodeId: string;
        tableName: string;
        uid: number;
        columnName: string;
        value: string;
    }>;
}

declare global {
    interface HTMLElementEventMap extends Mindfula11yEventMap {}
}

/**
 * Dispatches one of the extension's custom events with its typed detail and
 * the mandatory `bubbles: true, composed: true` (§3.E) — the single place the
 * event-dispatch contract is spelled out.
 */
export function dispatch<K extends keyof Mindfula11yEventMap>(
    target: EventTarget,
    name: K,
    detail: Mindfula11yEventMap[K]['detail'],
): void {
    target.dispatchEvent(new CustomEvent(name, { bubbles: true, composed: true, detail }));
}
