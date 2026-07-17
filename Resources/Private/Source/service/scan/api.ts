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

import { isObject } from '../../lib/guards.js';
import { getJson, postJson } from '../backend-api.js';
import { RequestError } from '../request-error.js';
import type { AgentFindingDto, AiAuditDto, CreateScanDemand, ScanProgress, ScanResult, ViolationDto } from './types.js';
import { AiAuditStatus, ScanStatus } from './types.js';

const SCAN_STATUSES: ReadonlySet<string> = new Set(Object.values(ScanStatus));

/** Type guard validating a wire value against the ScanStatus enum. */
function isScanStatus(value: unknown): value is ScanStatus {
    return typeof value === 'string' && SCAN_STATUSES.has(value);
}

interface CreateScanResponse {
    scanId?: unknown;
    status?: unknown;
}

/** Mirrors the ImpactSeverity union in lib/types.ts as a runtime set. */
const IMPACT_SEVERITIES: ReadonlySet<string> = new Set(['critical', 'serious', 'moderate', 'minor']);
const AI_AUDIT_STATUSES: ReadonlySet<string> = new Set(Object.values(AiAuditStatus));

const isNullableString = (value: unknown): value is string | null => value === null || typeof value === 'string';

const isIssue = (value: unknown): boolean =>
    isObject(value) &&
    typeof value.id === 'number' &&
    isNullableString(value.pageUrl) &&
    isNullableString(value.selector) &&
    isNullableString(value.context);

const isRule = (value: unknown): boolean =>
    isObject(value) &&
    typeof value.id === 'string' &&
    typeof value.description === 'string' &&
    isNullableString(value.helpUrl) &&
    (value.tags === undefined || (Array.isArray(value.tags) && value.tags.every((tag) => typeof tag === 'string')));

const isViolation = (value: unknown): value is ViolationDto =>
    isObject(value) &&
    isRule(value.rule) &&
    typeof value.impact === 'string' &&
    IMPACT_SEVERITIES.has(value.impact) &&
    Array.isArray(value.issues) &&
    value.issues.every(isIssue);

const isProgress = (value: unknown): value is ScanProgress =>
    isObject(value) &&
    typeof value.pagesDiscovered === 'number' &&
    typeof value.pagesScanned === 'number' &&
    typeof value.pagesFailed === 'number';

const isAiAudit = (value: unknown): value is AiAuditDto =>
    isObject(value) &&
    typeof value.status === 'string' &&
    AI_AUDIT_STATUSES.has(value.status) &&
    Array.isArray(value.requestedSkills) &&
    value.requestedSkills.every((skill) => typeof skill === 'string') &&
    typeof value.tasksTotal === 'number' &&
    typeof value.tasksCompleted === 'number' &&
    typeof value.tasksFailed === 'number';

const isAgentFinding = (value: unknown): value is AgentFindingDto =>
    isObject(value) &&
    typeof value.skill === 'string' &&
    typeof value.category === 'string' &&
    isNullableString(value.wcag) &&
    typeof value.severity === 'string' &&
    IMPACT_SEVERITIES.has(value.severity) &&
    typeof value.confidence === 'number' &&
    typeof value.needsHumanReview === 'boolean' &&
    isNullableString(value.pageUrl) &&
    isNullableString(value.selector) &&
    typeof value.message === 'string' &&
    isNullableString(value.suggestion) &&
    (value.details === null || isObject(value.details)) &&
    isNullableString(value.model);

const malformed = (member: string): Error => new Error(`The get-scan endpoint returned a malformed ${member} payload.`);

/**
 * Validates the getScan wire shape member by member — the payload partly
 * originates from the external scanner, so a malformed field must surface as
 * the error branch, not as a deep render TypeError.
 */
function parseScanResult(data: unknown): ScanResult {
    if (!isObject(data)) {
        throw malformed('response');
    }
    if (!isScanStatus(data.status)) {
        throw new Error(`The get-scan endpoint returned an unrecognized scan status: ${String(data.status)}.`);
    }
    const violations = data.violations ?? [];
    if (!(Array.isArray(violations) && violations.every(isViolation))) {
        throw malformed('violations');
    }
    const progress = data.progress ?? null;
    if (progress !== null && !isProgress(progress)) {
        throw malformed('progress');
    }
    const aiAudit = data.aiAudit ?? null;
    if (aiAudit !== null && !isAiAudit(aiAudit)) {
        throw malformed('aiAudit');
    }
    const agentFindings = data.agentFindings ?? [];
    if (!(Array.isArray(agentFindings) && agentFindings.every(isAgentFinding))) {
        throw malformed('agentFindings');
    }
    const targets = data.targets ?? [];
    if (!(Array.isArray(targets) && targets.every((target) => typeof target === 'string'))) {
        throw malformed('targets');
    }
    const totalIssueCount = data.totalIssueCount ?? 0;
    const mode = data.mode ?? null;
    const updatedAt = data.updatedAt ?? null;
    if (typeof totalIssueCount !== 'number' || !isNullableString(mode) || !isNullableString(updatedAt)) {
        throw malformed('summary');
    }

    return {
        status: data.status,
        violations,
        totalIssueCount,
        mode,
        targets,
        progress,
        aiAudit,
        agentFindings,
        updatedAt,
    };
}

interface CancelScanResponse {
    status?: unknown;
}

/** AJAX operations of the accessibility-scan endpoints. */
export class ScanApi {
    /**
     * Creates a scan from a signed demand. `aiAudit` rides alongside the
     * signed fields — it is an editor choice the backend authorizes via page
     * TSConfig, not a server-derived parameter covered by the HMAC.
     */
    async createScan(
        createScanDemand: CreateScanDemand,
        aiAudit: boolean = false,
        signal?: AbortSignal,
    ): Promise<{ scanId: string; status: ScanStatus }> {
        const data = await postJson<CreateScanResponse>(
            'mindfula11y_scan_create',
            { ...createScanDemand, aiAudit },
            { signal },
        );
        if (typeof data.scanId !== 'string' || data.scanId === '') {
            throw new Error('The create-scan endpoint returned no scan id.');
        }
        if (!isScanStatus(data.status)) {
            throw new Error(`The create-scan endpoint returned an unrecognized scan status: ${String(data.status)}.`);
        }
        return { scanId: data.scanId, status: data.status };
    }

    /** Loads scan results; resolves to null when the scan no longer exists. */
    async loadScan(scanId: string, pageUrls: string[] = [], signal?: AbortSignal): Promise<ScanResult | null> {
        let data: unknown;
        try {
            const params: Record<string, string | string[]> = pageUrls.length > 0 ? { scanId, pageUrls } : { scanId };
            data = await getJson<unknown>('mindfula11y_scan_get', params, { signal });
        } catch (error) {
            if (error instanceof RequestError && error.status === 404) {
                return null;
            }
            throw error;
        }
        return parseScanResult(data);
    }

    /** Requests cancellation of a running scan; resolves to the resulting status. */
    async cancelScan(scanId: string, signal?: AbortSignal): Promise<ScanStatus> {
        const data = await postJson<CancelScanResponse>('mindfula11y_scan_cancel', { scanId }, { signal });
        if (!isScanStatus(data.status)) {
            throw new Error(`The cancel-scan endpoint returned an unrecognized scan status: ${String(data.status)}.`);
        }
        return data.status;
    }
}
