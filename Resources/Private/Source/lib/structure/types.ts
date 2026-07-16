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

/** Domain types of the structure analysis (headings and landmarks). */

import type { RecordReference } from '../types.js';
import type { ElementExposurePredicate } from './element-exposure.js';

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

/** Merged (mobile+desktop) result of both structure analyzers, before backend enrichment. */
export interface StructureAnalysis {
    headings: HeadingAnalysis | null;
    landmarks: LandmarkAnalysis | null;
}
