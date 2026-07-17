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

/** Wire types of the accessibility-scan endpoints. */

import type { ImpactSeverity } from '../../lib/types.js';

/** Server-side scan lifecycle status; `analyzing` covers the AI-audit phase. */
export enum ScanStatus {
    Pending = 'pending',
    Running = 'running',
    Analyzing = 'analyzing',
    Completed = 'completed',
    Failed = 'failed',
    Canceled = 'canceled',
}

/** `true` while the scan still runs (pending, running, or the AI-audit analyzing phase). */
export function isScanInProgress(status: ScanStatus | ''): boolean {
    return status === ScanStatus.Pending || status === ScanStatus.Running || status === ScanStatus.Analyzing;
}

/** Immutable scan-creation scope signed and serialized by PHP. */
export interface CreateScanDemand {
    readonly userId: number;
    readonly pageId: number;
    readonly previewUrl: string;
    readonly languageId: number;
    readonly workspaceId: number;
    readonly pageLevels: number;
    readonly crawl: boolean;
    readonly expiresAt: number;
    readonly signature: string;
}

/** Lifecycle of the optional AI audit inside a scan (distinct from ScanStatus). */
export enum AiAuditStatus {
    Skipped = 'skipped',
    Pending = 'pending',
    Running = 'running',
    Completed = 'completed',
}

/** Server-owned AI-audit skill identifier. MindfulAPI may add skills independently. */
export type AiAuditSkill = string;

/** axe-core impact scale; agent findings reuse it as their severity. */
export type { ImpactSeverity };

/** Rule metadata of a violation group. */
export interface RuleDto {
    id: string;
    description: string;
    helpUrl: string | null;
    tags?: string[];
}

/** One occurrence of a violated rule on a scanned page. */
export interface IssueDto {
    id: number;
    pageUrl: string | null;
    selector: string | null;
    context: string | null;
}

/** One axe-core rule violation group returned by the getScan endpoint. */
export interface ViolationDto {
    rule: RuleDto;
    impact: ImpactSeverity;
    issues: IssueDto[];
}

/** Crawl progress counters of a scan. */
export interface ScanProgress {
    pagesDiscovered: number;
    pagesScanned: number;
    pagesFailed: number;
}

/** Summary of the AI audit that ran (or is running) as part of a scan. */
export interface AiAuditDto {
    status: AiAuditStatus;
    requestedSkills: AiAuditSkill[];
    tasksTotal: number;
    tasksCompleted: number;
    tasksFailed: number;
}

/**
 * One AI-audit finding. `category` is an open string per skill; `appropriate`
 * marks a pass and `insufficient_evidence` an unresolved judgement call.
 */
export interface AgentFindingDto {
    skill: AiAuditSkill;
    category: string;
    wcag: string | null;
    severity: ImpactSeverity;
    confidence: number;
    needsHumanReview: boolean;
    pageUrl: string | null;
    selector: string | null;
    message: string;
    suggestion: string | null;
    details: Record<string, unknown> | null;
    model: string | null;
}

/** Result of the getScan endpoint. */
export interface ScanResult {
    status: ScanStatus;
    violations: ViolationDto[];
    totalIssueCount: number;
    mode: string | null;
    targets: string[];
    progress: ScanProgress | null;
    aiAudit: AiAuditDto | null;
    agentFindings: AgentFindingDto[];
    updatedAt: string | null;
}
