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
import { scanStatusView } from '../../../../Resources/Private/Source/service/scan/status-view.js';
import type { ScanResult } from '../../../../Resources/Private/Source/service/scan/types.js';
import { ScanStatus } from '../../../../Resources/Private/Source/service/scan/types.js';

const makeResult = (status: ScanStatus, over: Partial<ScanResult> = {}): ScanResult => ({
    status,
    violations: [],
    totalIssueCount: 0,
    mode: null,
    targets: [],
    progress: null,
    aiAudit: null,
    agentFindings: [],
    updatedAt: null,
    ...over,
});

describe('scanStatusView', () => {
    it.each([
        [ScanStatus.Pending, 'mindfula11y.scan.status.pending'],
        [ScanStatus.Running, 'mindfula11y.scan.status.running'],
        [ScanStatus.Analyzing, 'mindfula11y.scan.status.analyzing'],
    ])('presents the in-progress status %s as an info spinner notice', (status, labelKey) => {
        expect(scanStatusView(makeResult(status))).toEqual({ state: 'info', labelKey, spinner: true });
    });

    it('presents a failed scan as a danger notice without spinner', () => {
        expect(scanStatusView(makeResult(ScanStatus.Failed))).toEqual({
            state: 'danger',
            labelKey: 'mindfula11y.scan.status.failed',
        });
    });

    it('presents a canceled scan as an info notice without spinner', () => {
        expect(scanStatusView(makeResult(ScanStatus.Canceled))).toEqual({
            state: 'info',
            labelKey: 'mindfula11y.scan.status.canceled',
        });
    });

    it('presents a completed scan with issues as a warning carrying the count argument', () => {
        expect(scanStatusView(makeResult(ScanStatus.Completed, { totalIssueCount: 7 }))).toEqual({
            state: 'warning',
            labelKey: 'mindfula11y.scan.issuesFound',
            labelArgs: [7],
        });
    });

    it('presents a clean completed scan as a success notice', () => {
        expect(scanStatusView(makeResult(ScanStatus.Completed))).toEqual({
            state: 'success',
            labelKey: 'mindfula11y.scan.noIssues',
        });
    });
});
