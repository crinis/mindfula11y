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
import { dispatch } from '../../../Resources/Private/Source/lib/types.js';

describe('dispatch', () => {
    it('dispatches the named custom event with the typed detail payload', () => {
        const target = document.createElement('div');
        const received: CustomEvent<{ scanId: string; totalIssueCount: number }>[] = [];
        target.addEventListener('mindfula11y:scan:completed', (event) => {
            received.push(event);
        });

        dispatch(target, 'mindfula11y:scan:completed', { scanId: 'scan-1', totalIssueCount: 3 });

        expect(received).toHaveLength(1);
        expect(received[0]?.detail).toEqual({ scanId: 'scan-1', totalIssueCount: 3 });
    });

    it('always dispatches bubbling, composed events (the §3.E contract)', () => {
        const target = document.createElement('div');
        const received: CustomEvent<{ scanId: string }>[] = [];
        target.addEventListener('mindfula11y:scan:canceled', (event) => {
            received.push(event);
        });

        dispatch(target, 'mindfula11y:scan:canceled', { scanId: 'scan-1' });

        expect(received[0]?.bubbles).toBe(true);
        expect(received[0]?.composed).toBe(true);
    });

    it('bubbles up the tree so ancestor listeners receive the event', () => {
        const parent = document.createElement('div');
        const child = document.createElement('div');
        parent.append(child);
        const scanIds: string[] = [];
        parent.addEventListener('mindfula11y:scan:completed', (event) => {
            scanIds.push(event.detail.scanId);
        });

        dispatch(child, 'mindfula11y:scan:completed', { scanId: 'bubbled', totalIssueCount: 0 });

        expect(scanIds).toEqual(['bubbled']);
    });
});
