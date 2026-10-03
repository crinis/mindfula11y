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
import { scanStatusView } from '../../../../Resources/Private/Source/lib/scan/status-view.js';
import type { ScanResult } from '../../../../Resources/Private/Source/lib/scan/types.js';
import { ScanStatus } from '../../../../Resources/Private/Source/lib/scan/types.js';

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
            descriptionKey: 'mindfula11y.scan.status.failed.description',
        });
    });

    it('presents a canceled scan as an info notice without spinner', () => {
        expect(scanStatusView(makeResult(ScanStatus.Canceled))).toEqual({
            state: 'info',
            labelKey: 'mindfula11y.scan.status.canceled',
            descriptionKey: 'mindfula11y.scan.status.canceled.description',
        });
    });

    it('presents a completed scan whose every page failed to load as danger, not as "no issues"', () => {
        // MindfulAPI completes a scan whose pages could not be loaded (site
        // down, certificate rejected, HTTP error) with zero issues — which is
        // no evidence of accessibility at all.
        const result = makeResult(ScanStatus.Completed, {
            totalIssueCount: 0,
            progress: { pagesDiscovered: 1, pagesScanned: 0, pagesFailed: 1 },
        });

        expect(scanStatusView(result)).toEqual({
            state: 'danger',
            labelKey: 'mindfula11y.scan.noPagesScanned',
            descriptionKey: 'mindfula11y.scan.noPagesScanned.description',
        });
    });

    it('never presents a partially failed clean scan as plain success', () => {
        const result = makeResult(ScanStatus.Completed, {
            progress: { pagesDiscovered: 3, pagesScanned: 2, pagesFailed: 1 },
        });

        expect(scanStatusView(result)).toEqual({
            state: 'warning',
            labelKey: 'mindfula11y.scan.noIssuesOnScannedPages',
            pagesFailed: { failed: 1, total: 3 },
        });
    });

    it('reports the failed pages of a partially failed scan with issues alongside the issue count', () => {
        const result = makeResult(ScanStatus.Completed, {
            totalIssueCount: 4,
            progress: { pagesDiscovered: 5, pagesScanned: 3, pagesFailed: 2 },
        });

        expect(scanStatusView(result)).toEqual({
            state: 'warning',
            labelKey: 'mindfula11y.scan.issuesFound',
            announceLabelKey: 'mindfula11y.scan.announce.issuesFound',
            count: 4,
            pagesFailed: { failed: 2, total: 5 },
        });
    });

    it('presents a completed scan with issues as a warning carrying the badge count', () => {
        // The visible label omits the count (the badge carries it), so the
        // mapping also names the spoken variant that keeps the number.
        expect(scanStatusView(makeResult(ScanStatus.Completed, { totalIssueCount: 7 }))).toEqual({
            state: 'warning',
            labelKey: 'mindfula11y.scan.issuesFound',
            announceLabelKey: 'mindfula11y.scan.announce.issuesFound',
            count: 7,
        });
    });

    it('presents a clean completed scan as a success notice', () => {
        expect(scanStatusView(makeResult(ScanStatus.Completed))).toEqual({
            state: 'success',
            labelKey: 'mindfula11y.scan.noIssues',
        });
        expect(
            scanStatusView(
                makeResult(ScanStatus.Completed, {
                    progress: { pagesDiscovered: 2, pagesScanned: 2, pagesFailed: 0 },
                }),
            ),
        ).toEqual({ state: 'success', labelKey: 'mindfula11y.scan.noIssues' });
    });
});
