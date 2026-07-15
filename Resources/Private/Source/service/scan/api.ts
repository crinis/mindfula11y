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

import { getJson, postJson } from '../backend-api.js';
import { RequestError } from '../request-error.js';
import type { AgentFindingDto, AiAuditDto, CreateScanDemand, ScanProgress, ScanResult, ViolationDto } from './types.js';
import { ScanStatus } from './types.js';

const SCAN_STATUSES: ReadonlySet<string> = new Set(Object.values(ScanStatus));

/** Type guard validating a wire value against the ScanStatus enum. */
function isScanStatus(value: unknown): value is ScanStatus {
    return typeof value === 'string' && SCAN_STATUSES.has(value);
}

interface CreateScanResponse {
    scanId?: unknown;
    status?: unknown;
}

interface GetScanResponse {
    status?: unknown;
    violations?: ViolationDto[];
    totalIssueCount?: number;
    mode?: string;
    targets?: string[];
    progress?: ScanProgress | null;
    aiAudit?: AiAuditDto | null;
    agentFindings?: AgentFindingDto[];
    updatedAt?: string;
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
            'mindfula11y_createscan',
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
        let data: GetScanResponse;
        try {
            const params: Record<string, string | string[]> = pageUrls.length > 0 ? { scanId, pageUrls } : { scanId };
            data = await getJson<GetScanResponse>('mindfula11y_getscan', params, { signal });
        } catch (error) {
            if (error instanceof RequestError && error.status === 404) {
                return null;
            }
            throw error;
        }
        if (!isScanStatus(data.status)) {
            throw new Error(`The get-scan endpoint returned an unrecognized scan status: ${String(data.status)}.`);
        }
        return {
            status: data.status,
            violations: data.violations ?? [],
            totalIssueCount: data.totalIssueCount ?? 0,
            mode: data.mode ?? null,
            targets: data.targets ?? [],
            progress: data.progress ?? null,
            aiAudit: data.aiAudit ?? null,
            agentFindings: data.agentFindings ?? [],
            updatedAt: data.updatedAt ?? null,
        };
    }

    /** Requests cancellation of a running scan; resolves to the resulting status. */
    async cancelScan(scanId: string, signal?: AbortSignal): Promise<ScanStatus> {
        const data = await postJson<CancelScanResponse>('mindfula11y_cancelscan', { scanId }, { signal });
        if (!isScanStatus(data.status)) {
            throw new Error(`The cancel-scan endpoint returned an unrecognized scan status: ${String(data.status)}.`);
        }
        return data.status;
    }

    isScanInProgress(status: ScanStatus | ''): boolean {
        return status === ScanStatus.Pending || status === ScanStatus.Running || status === ScanStatus.Analyzing;
    }
}
