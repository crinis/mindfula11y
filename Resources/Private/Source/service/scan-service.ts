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

import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import type {
    AgentFindingDto,
    AiAuditDto,
    CreateScanDemand,
    ScanProgress,
    ScanResult,
    ViolationDto,
} from '../lib/types.js';
import { ScanStatus } from '../lib/types.js';
import { toRequestError } from './request-error.js';

interface CreateScanResponse {
    scanId: string;
    status?: ScanStatus;
}

interface GetScanResponse {
    status?: ScanStatus;
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
    status?: ScanStatus;
}

/** AJAX operations of the accessibility-scan endpoints. */
export class ScanService {
    /**
     * Creates a scan from a signed demand. `aiAudit` rides alongside the
     * signed fields — it is an editor choice the backend authorizes via page
     * TSConfig, not a server-derived parameter covered by the HMAC.
     */
    async createScan(
        createScanDemand: CreateScanDemand,
        aiAudit: boolean = false,
    ): Promise<{ scanId: string; status: ScanStatus }> {
        try {
            const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.mindfula11y_createscan ?? '').post(
                { ...createScanDemand, aiAudit },
                { headers: { 'Content-Type': 'application/json; charset=utf-8' } },
            );
            const data = await response.resolve<CreateScanResponse>();
            return { scanId: data.scanId, status: data.status ?? ScanStatus.Pending };
        } catch (error) {
            throw await toRequestError(error);
        }
    }

    /** Loads scan results; resolves to null when the scan no longer exists. */
    async loadScan(scanId: string, pageUrls: string[] = []): Promise<ScanResult | null> {
        try {
            const params = new URLSearchParams({ scanId });
            for (const url of pageUrls) {
                params.append('pageUrls', url);
            }
            const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.mindfula11y_getscan ?? '')
                .withQueryArguments(params)
                .get();
            const data = await response.resolve<GetScanResponse>();
            return {
                status: data.status ?? ScanStatus.Completed,
                violations: data.violations ?? [],
                totalIssueCount: data.totalIssueCount ?? 0,
                mode: data.mode ?? null,
                targets: data.targets ?? [],
                progress: data.progress ?? null,
                aiAudit: data.aiAudit ?? null,
                agentFindings: data.agentFindings ?? [],
                updatedAt: data.updatedAt ?? null,
            };
        } catch (error) {
            if ((error as { response?: Response }).response?.status === 404) {
                return null;
            }
            throw await toRequestError(error);
        }
    }

    /** Requests cancellation of a running scan; resolves to the resulting status. */
    async cancelScan(scanId: string): Promise<ScanStatus> {
        try {
            const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.mindfula11y_cancelscan ?? '').post(
                { scanId },
                { headers: { 'Content-Type': 'application/json; charset=utf-8' } },
            );
            const data = await response.resolve<CancelScanResponse>();
            return data.status ?? ScanStatus.Canceled;
        } catch (error) {
            throw await toRequestError(error);
        }
    }

    isScanInProgress(status: ScanStatus | ''): boolean {
        return status === ScanStatus.Pending || status === ScanStatus.Running || status === ScanStatus.Analyzing;
    }
}

export default ScanService;
