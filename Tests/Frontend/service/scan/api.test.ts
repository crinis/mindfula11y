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
import type { CreateScanDemand } from '../../../../Resources/Private/Source/lib/scan/types.js';
import { ScanStatus } from '../../../../Resources/Private/Source/lib/scan/types.js';
import { ScanApi } from '../../../../Resources/Private/Source/service/scan/api.js';

const getJson = vi.fn();
const postJson = vi.fn();

vi.mock('@typo3/core/lit-helper.js', () => ({
    lll: (key: string): string => key,
}));

vi.mock('../../../../Resources/Private/Source/service/backend-api.js', () => ({
    getJson: (...args: unknown[]): unknown => getJson(...args),
    postJson: (...args: unknown[]): unknown => postJson(...args),
}));

const violation = {
    rule: { id: 'image-alt', description: 'Images must have alternate text', helpUrl: null },
    impact: 'critical',
    issues: [{ id: 1, pageUrl: 'https://example.test/', selector: 'img', context: '<img>' }],
};

const agentFinding = {
    skill: 'alt-text-quality',
    category: 'redundant',
    wcag: '1.1.1',
    severity: 'moderate',
    confidence: 0.9,
    needsHumanReview: false,
    pageUrl: null,
    selector: null,
    message: 'Alt text repeats adjacent caption',
    suggestion: null,
    details: null,
    model: null,
};

const validPayload = (): Record<string, unknown> => ({
    status: ScanStatus.Completed,
    violations: [violation],
    totalIssueCount: 1,
    mode: null,
    targets: [],
    progress: { pagesDiscovered: 1, pagesScanned: 1, pagesFailed: 0 },
    aiAudit: {
        status: 'completed',
        requestedSkills: ['alt-text-quality'],
        tasksTotal: 1,
        tasksCompleted: 1,
        tasksFailed: 0,
    },
    agentFindings: [agentFinding],
    updatedAt: '2026-07-17T10:00:00Z',
});

describe('ScanApi.loadScan wire validation', () => {
    beforeEach(() => {
        getJson.mockReset();
    });

    it('accepts a fully populated valid payload', async () => {
        getJson.mockResolvedValue(validPayload());
        const result = await new ScanApi().loadScan('scan-1');

        expect(result?.status).toBe(ScanStatus.Completed);
        expect(result?.violations).toHaveLength(1);
        expect(result?.agentFindings).toHaveLength(1);
    });

    it('keeps the groups of one rule apart per impact, as MindfulAPI groups them', async () => {
        // MindfulAPI groups violations by rule AND impact; merging them into
        // the worst impact made the severity chips and badges disagree with
        // the API's counts and its report.
        const moderate = { ...violation, impact: 'moderate' };
        const critical = {
            ...violation,
            impact: 'critical',
            issues: [{ id: 2, pageUrl: 'https://example.test/other', selector: 'a', context: '<a>' }],
        };
        getJson.mockResolvedValue({ ...validPayload(), violations: [moderate, critical] });

        const result = await new ScanApi().loadScan('scan-1');

        expect(result?.violations.map((group) => [group.rule.id, group.impact, group.issues.length])).toEqual([
            ['image-alt', 'moderate', 1],
            ['image-alt', 'critical', 1],
        ]);
    });

    it('merges duplicate groups of the same rule and impact, concatenating their issues', async () => {
        // The UI keys violation cards by rule + impact; a payload repeating a
        // group must not produce two cards with the same key.
        const duplicate = {
            ...violation,
            issues: [{ id: 2, pageUrl: 'https://example.test/other', selector: 'a', context: '<a>' }],
        };
        getJson.mockResolvedValue({ ...validPayload(), violations: [violation, duplicate] });

        const result = await new ScanApi().loadScan('scan-1');

        expect(result?.violations).toHaveLength(1);
        expect(result?.violations[0]?.impact).toBe('critical');
        expect(result?.violations[0]?.issues.map((issue) => issue.id)).toEqual([1, 2]);
    });

    it('rejects a payload whose violations member is not an array of violation shapes', async () => {
        getJson.mockResolvedValue({ ...validPayload(), violations: 'not-an-array' });
        await expect(new ScanApi().loadScan('scan-1')).rejects.toThrow(/malformed/);
    });

    it('rejects a violation missing its rule metadata', async () => {
        getJson.mockResolvedValue({ ...validPayload(), violations: [{ impact: 'critical', issues: [] }] });
        await expect(new ScanApi().loadScan('scan-1')).rejects.toThrow(/malformed/);
    });

    it('rejects a violation with an unknown impact', async () => {
        getJson.mockResolvedValue({ ...validPayload(), violations: [{ ...violation, impact: 'apocalyptic' }] });
        await expect(new ScanApi().loadScan('scan-1')).rejects.toThrow(/malformed/);
    });

    it('rejects progress counters that are not numbers', async () => {
        getJson.mockResolvedValue({
            ...validPayload(),
            progress: { pagesDiscovered: 'many', pagesScanned: 0, pagesFailed: 0 },
        });
        await expect(new ScanApi().loadScan('scan-1')).rejects.toThrow(/malformed/);
    });

    it('rejects an agent finding without a string message', async () => {
        getJson.mockResolvedValue({ ...validPayload(), agentFindings: [{ ...agentFinding, message: 42 }] });
        await expect(new ScanApi().loadScan('scan-1')).rejects.toThrow(/malformed/);
    });

    it('rejects a malformed aiAudit summary', async () => {
        getJson.mockResolvedValue({ ...validPayload(), aiAudit: { status: 'completed' } });
        await expect(new ScanApi().loadScan('scan-1')).rejects.toThrow(/malformed/);
    });

    it('still defaults absent optional members', async () => {
        getJson.mockResolvedValue({ status: ScanStatus.Pending });
        const result = await new ScanApi().loadScan('scan-1');

        expect(result).toEqual({
            status: ScanStatus.Pending,
            violations: [],
            totalIssueCount: 0,
            mode: null,
            targets: [],
            progress: null,
            aiAudit: null,
            agentFindings: [],
            updatedAt: null,
        });
    });
});

const demand: CreateScanDemand = {
    userId: 1,
    pageId: 2,
    previewUrl: 'https://example.test/',
    languageId: 0,
    workspaceId: 0,
    pageLevels: 0,
    crawl: false,
    expiresAt: 1_900_000_000,
    signature: 'sig',
};

describe('ScanApi.createScan wire validation', () => {
    beforeEach(() => {
        postJson.mockReset();
    });

    it('resolves the scan id and status, posting the AI-audit choice beside the signed demand', async () => {
        postJson.mockResolvedValue({ scanId: 'scan-1', status: ScanStatus.Pending });

        await expect(new ScanApi().createScan(demand, true)).resolves.toEqual({
            scanId: 'scan-1',
            status: ScanStatus.Pending,
        });
        expect(postJson).toHaveBeenCalledWith('mindfula11y_scan_create', { ...demand, aiAudit: true }, undefined);
    });

    it.each([
        ['a missing', undefined],
        ['an empty', ''],
        ['a non-string', 42],
    ])('rejects %s scan id', async (_label, scanId) => {
        postJson.mockResolvedValue({ scanId, status: ScanStatus.Pending });

        await expect(new ScanApi().createScan(demand)).rejects.toThrow('no scan id');
    });

    it('rejects an unrecognized scan status', async () => {
        postJson.mockResolvedValue({ scanId: 'scan-1', status: 'exploded' });

        await expect(new ScanApi().createScan(demand)).rejects.toThrow('unrecognized scan status: exploded');
    });
});

describe('ScanApi.cancelScan wire validation', () => {
    beforeEach(() => {
        postJson.mockReset();
    });

    it('resolves the status the backend reports', async () => {
        postJson.mockResolvedValue({ status: ScanStatus.Canceled });

        await expect(new ScanApi().cancelScan('scan-1')).resolves.toBe(ScanStatus.Canceled);
        expect(postJson).toHaveBeenCalledWith('mindfula11y_scan_cancel', { scanId: 'scan-1' }, undefined);
    });

    it.each([
        ['a missing', undefined],
        ['an unrecognized', 'exploded'],
    ])('rejects %s scan status', async (_label, status) => {
        postJson.mockResolvedValue({ status });

        await expect(new ScanApi().cancelScan('scan-1')).rejects.toThrow('unrecognized scan status');
    });
});
