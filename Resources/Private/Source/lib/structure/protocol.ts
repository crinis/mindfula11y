/*
 * Mindful A11y extension for TYPO3 integrating accessibility tools into the backend.
 * Copyright (C) 2026  Mindful Markup, Felix Spittel
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 */

import { isBoundedString, isObject, isStringMap } from '../guards.js';
import type { ImpactSeverity } from '../types.js';
import { IMPACT_ORDER } from '../types.js';
import type { HeadingAnalysis, HeadingNode, LandmarkAnalysis, LandmarkNode, StructureViewport } from './types.js';

export const STRUCTURE_ANALYSIS_PROTOCOL = 'mindfula11y.structure.v1' as const;
const MAX_ANALYSIS_ITEMS = 2_000;

/** Runner → backend: the analysis document is live and awaiting its port. */
export interface StructureAnalysisReadyMessage {
    protocol: typeof STRUCTURE_ANALYSIS_PROTOCOL;
    type: 'ready';
    requestId: string;
}

/** Backend → runner: which viewport to report and which analyzers to run. */
export interface StructureAnalysisInitializeMessage {
    protocol: typeof STRUCTURE_ANALYSIS_PROTOCOL;
    type: 'initialize';
    requestId: string;
    viewport: StructureViewport;
    headings: boolean;
    landmarks: boolean;
}

export interface StructureAnalysisResultMessage {
    protocol: typeof STRUCTURE_ANALYSIS_PROTOCOL;
    type: 'result';
    requestId: string;
    viewport: StructureViewport;
    headings: HeadingAnalysis | null;
    landmarks: LandmarkAnalysis | null;
}

export interface StructureAnalysisErrorMessage {
    protocol: typeof STRUCTURE_ANALYSIS_PROTOCOL;
    type: 'error';
    requestId: string;
    code: 'http' | 'analysis';
    status?: number;
    message?: string;
}

interface AnalysisPayload {
    nodes: unknown[];
    errors: unknown[];
}

const isViewport = (value: unknown): value is StructureViewport => value === 'mobile' || value === 'desktop';
const isViewportList = (value: unknown): value is StructureViewport[] =>
    Array.isArray(value) && value.length <= 2 && value.every(isViewport);

const hasMessageEnvelope = (value: unknown, type: string, requestId: string): value is Record<string, unknown> =>
    isObject(value) &&
    value.protocol === STRUCTURE_ANALYSIS_PROTOCOL &&
    value.type === type &&
    value.requestId === requestId;

const isError = (value: unknown): boolean => {
    if (!isObject(value) || !isBoundedString(value.key, 256) || !isViewportList(value.viewports)) {
        return false;
    }
    return (
        IMPACT_ORDER.includes(value.severity as ImpactSeverity) &&
        (value.nodeId === null || isBoundedString(value.nodeId, 512))
    );
};

const isRecord = (value: unknown): boolean => {
    if (value === null) {
        return true;
    }
    return (
        isObject(value) &&
        isBoundedString(value.tableName, 128) &&
        /^[a-zA-Z0-9_]+$/.test(value.tableName) &&
        isBoundedString(value.columnName, 128) &&
        /^[a-zA-Z0-9_]+$/.test(value.columnName) &&
        typeof value.uid === 'number' &&
        Number.isInteger(value.uid) &&
        value.uid > 0 &&
        // storedValue is a column value ('', 'h1'…'h6', 'p', 'div'); bound it
        // like every other wire string so a hostile frame cannot deliver an
        // oversized or non-string payload member.
        (value.storedValue === undefined || isBoundedString(value.storedValue, 128)) &&
        // The runner never resolves edit links; they are supplied only by the
        // authenticated backend enrichment endpoint. Rejecting any non-empty
        // wire value keeps a forged frame from injecting a clickable link.
        value.editLink === ''
    );
};

const hasValidNodeBase = (
    value: Record<string, unknown>,
    availableValues: unknown,
): value is Record<string, unknown> & { children: unknown[] } =>
    isBoundedString(value.id, 512) &&
    typeof value.documentOrder === 'number' &&
    Number.isInteger(value.documentOrder) &&
    isBoundedString(value.label) &&
    isStringMap(availableValues) &&
    isRecord(value.record) &&
    isViewportList(value.viewports) &&
    Array.isArray(value.errors) &&
    value.errors.length <= MAX_ANALYSIS_ITEMS &&
    value.errors.every(isError) &&
    Array.isArray(value.children);

const isHeadingNode = (value: unknown, depth: number, counter: { value: number }): value is HeadingNode => {
    if (!isObject(value) || depth > 20 || ++counter.value > MAX_ANALYSIS_ITEMS) {
        return false;
    }
    // The allowed level range per kind (checked below, after kind is validated):
    // heading 1-6; container 0-6 (level 0 when its own type is not h1-h6);
    // demoted (p/div) always level 0 — see heading-analysis.ts.
    const [minLevel, maxLevel] = value.kind === 'heading' ? [1, 6] : value.kind === 'demoted' ? [0, 0] : [0, 6];
    if (
        !hasValidNodeBase(value, value.availableTypes) ||
        (value.kind !== 'heading' && value.kind !== 'container' && value.kind !== 'demoted') ||
        typeof value.level !== 'number' ||
        !Number.isInteger(value.level) ||
        value.level < minLevel ||
        value.level > maxLevel ||
        (value.nonHeadingType !== undefined && value.nonHeadingType !== 'p' && value.nonHeadingType !== 'div') ||
        !isRecord(value.childTypeRecord) ||
        !isStringMap(value.availableChildTypes) ||
        !isBoundedString(value.relationId, 512) ||
        typeof value.skippedLevels !== 'number' ||
        !Number.isInteger(value.skippedLevels)
    ) {
        return false;
    }
    if (
        value.relation !== null &&
        (!isObject(value.relation) ||
            (value.relation.kind !== 'ancestor' && value.relation.kind !== 'sibling') ||
            !isBoundedString(value.relation.targetRelationId, 512))
    ) {
        return false;
    }
    return value.children.every((child) => isHeadingNode(child, depth + 1, counter));
};

const isLandmarkNode = (value: unknown, depth: number, counter: { value: number }): value is LandmarkNode => {
    if (!isObject(value) || depth > 50 || ++counter.value > MAX_ANALYSIS_ITEMS) {
        return false;
    }
    if (!hasValidNodeBase(value, value.availableRoles) || !isBoundedString(value.role, 128)) {
        return false;
    }
    return value.children.every((child) => isLandmarkNode(child, depth + 1, counter));
};

const hasValidAnalysisShape = (value: unknown): value is Record<string, unknown> & AnalysisPayload =>
    isObject(value) &&
    Array.isArray(value.nodes) &&
    value.nodes.length <= MAX_ANALYSIS_ITEMS &&
    Array.isArray(value.errors) &&
    value.errors.length <= MAX_ANALYSIS_ITEMS &&
    value.errors.every(isError);

const isHeadingAnalysis = (value: unknown): value is HeadingAnalysis => {
    if (!hasValidAnalysisShape(value)) {
        return false;
    }
    const counter = { value: 0 };
    return value.nodes.every((node) => isHeadingNode(node, 0, counter));
};

const isLandmarkAnalysis = (value: unknown): value is LandmarkAnalysis => {
    if (!hasValidAnalysisShape(value)) {
        return false;
    }
    const counter = { value: 0 };
    return value.nodes.every((node) => isLandmarkNode(node, 0, counter));
};

export const isStructureAnalysisReadyMessage = (
    value: unknown,
    requestId: string,
): value is StructureAnalysisReadyMessage => hasMessageEnvelope(value, 'ready', requestId);

export const isStructureAnalysisInitializeMessage = (
    value: unknown,
    requestId: string,
): value is StructureAnalysisInitializeMessage =>
    hasMessageEnvelope(value, 'initialize', requestId) &&
    isViewport(value.viewport) &&
    typeof value.headings === 'boolean' &&
    typeof value.landmarks === 'boolean';

export const isStructureAnalysisResultMessage = (
    value: unknown,
    requestId: string,
    viewport: StructureViewport,
): value is StructureAnalysisResultMessage =>
    hasMessageEnvelope(value, 'result', requestId) && value.viewport === viewport && hasValidResultPayload(value);

export const isStructureAnalysisErrorMessage = (
    value: unknown,
    requestId: string,
): value is StructureAnalysisErrorMessage =>
    hasMessageEnvelope(value, 'error', requestId) &&
    (value.code === 'http' || value.code === 'analysis') &&
    (value.status === undefined || (typeof value.status === 'number' && Number.isInteger(value.status))) &&
    (value.message === undefined || isBoundedString(value.message, 2_000));

/** Outcome of parsing one incoming port message; see `parsePortMessage`. */
export type ParsedPortMessage =
    | { kind: 'result'; headings: HeadingAnalysis | null; landmarks: LandmarkAnalysis | null }
    | { kind: 'error'; code: 'http' | 'analysis'; status?: number; message: string }
    | { kind: 'invalid-result' };

const hasValidResultPayload = (
    value: Record<string, unknown>,
): value is Record<string, unknown> & { headings: HeadingAnalysis | null; landmarks: LandmarkAnalysis | null } =>
    (value.headings === null || isHeadingAnalysis(value.headings)) &&
    (value.landmarks === null || isLandmarkAnalysis(value.landmarks));

const describeError = (value: StructureAnalysisErrorMessage): string =>
    value.code === 'http'
        ? `The frontend preview returned HTTP status ${value.status ?? 'unknown'}.`
        : (value.message ?? 'The frontend structure analysis failed.');

/**
 * Parses one message received on the analysis MessagePort against this
 * protocol. `null` means the message is not addressed to `requestId` (or
 * isn't shaped like this protocol at all) — the caller should ignore it and
 * keep waiting for the real one.
 *
 * A `result`-typed envelope addressed to us is never dropped, even when its
 * payload fails the hardened validation below (oversized, malformed, echoing
 * a viewport other than `expectedViewport`, or otherwise hostile): it comes
 * back as `{ kind: 'invalid-result' }` so the caller can reject immediately
 * instead of silently timing out.
 */
export const parsePortMessage = (
    data: unknown,
    requestId: string,
    expectedViewport: StructureViewport,
): ParsedPortMessage | null => {
    if (hasMessageEnvelope(data, 'result', requestId)) {
        if (isStructureAnalysisResultMessage(data, requestId, expectedViewport)) {
            return { kind: 'result', headings: data.headings, landmarks: data.landmarks };
        }
        return { kind: 'invalid-result' };
    }
    if (isStructureAnalysisErrorMessage(data, requestId)) {
        return {
            kind: 'error',
            code: data.code,
            ...(data.status === undefined ? {} : { status: data.status }),
            message: describeError(data),
        };
    }
    return null;
};
