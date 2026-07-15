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
 * Shared domain types and the typed catalogue of every custom event this
 * extension dispatches (`mindfula11y:<domain>:<action>`, always with
 * `bubbles: true, composed: true`).
 */

import type { ElementExposurePredicate } from './structure/element-exposure.js';

/** Server-side scan lifecycle status; `analyzing` covers the AI-audit phase. */
export enum ScanStatus {
    Pending = 'pending',
    Running = 'running',
    Analyzing = 'analyzing',
    Completed = 'completed',
    Failed = 'failed',
    Canceled = 'canceled',
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

/** Opaque, HMAC-signed alt-text generation payload serialized into elements by PHP. */
export type GenerateAltTextDemand = Record<string, unknown>;

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
export type ImpactSeverity = 'critical' | 'serious' | 'moderate' | 'minor';

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

/** Severity of a structure finding; Error outranks Warning. */
export enum StructureErrorSeverity {
    Error = 'error',
    Warning = 'warning',
}

/** Responsive frontend render used by the structure analyzers. */
export type StructureViewport = 'mobile' | 'desktop';

/** Canonical viewport order; drives render sequence, merge output and badge order alike. */
export const STRUCTURE_VIEWPORT_ORDER: readonly StructureViewport[] = ['mobile', 'desktop'];

/** Shared options of both structure analyzers. */
export interface StructureAnalysisOptions {
    viewport?: StructureViewport;
    isExposed?: ElementExposurePredicate;
}

/** The two structure domains the container tabs between. */
export type StructureDomain = 'headings' | 'landmarks';

/**
 * One occurrence of a structure problem, identified by its XLF label key
 * (e.g. `mindfula11y.structure.headings.error.missingH1`).
 * `nodeId` is null for page-level findings (missing H1 / missing main).
 */
export interface StructureError {
    key: string;
    severity: StructureErrorSeverity;
    nodeId: string | null;
    viewports: StructureViewport[];
}

/** Database coordinates of the record behind a heading/landmark. */
export interface RecordReference {
    tableName: string;
    columnName: string;
    uid: number;
    editLink: string;
}

/** Relation of a heading whose level is derived from another heading. */
export interface HeadingRelation {
    kind: 'ancestor' | 'sibling';
    targetRelationId: string;
}

/** One heading in the analyzed document, nested by level. */
export interface HeadingNode {
    id: string;
    /** Position in the document, stable across viewports; orders the merged tree. */
    documentOrder: number;
    level: number;
    label: string;
    /** Always `{}` from the analyzer; populated in place by `applyRecordMetadata` after backend enrichment. */
    availableTypes: Record<string, string>;
    record: RecordReference | null;
    relationId: string;
    relation: HeadingRelation | null;
    skippedLevels: number;
    viewports: StructureViewport[];
    errors: StructureError[];
    children: HeadingNode[];
}

/** One landmark in the analyzed document, nested by containment. */
export interface LandmarkNode {
    id: string;
    /** Position in the document, stable across viewports; orders the merged tree. */
    documentOrder: number;
    role: string;
    label: string;
    /** Always `{}` from the analyzer; populated in place by `applyRecordMetadata` after backend enrichment. */
    availableRoles: Record<string, string>;
    record: RecordReference | null;
    viewports: StructureViewport[];
    errors: StructureError[];
    children: LandmarkNode[];
}

/** `errors` lists every occurrence (page-level and per-node); nodes share the same objects. */
export interface HeadingAnalysis {
    nodes: HeadingNode[];
    errors: StructureError[];
}

export interface LandmarkAnalysis {
    nodes: LandmarkNode[];
    errors: StructureError[];
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
