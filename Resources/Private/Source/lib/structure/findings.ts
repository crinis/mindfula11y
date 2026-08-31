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
 * Pure aggregation over a merged {@link StructureAnalysis}: per-domain error
 * slices, severity counts and the findings summary the container renders as
 * clickable chips. No component or Lit dependency — the inputs are the
 * analysis plus plain enabled-domain booleans.
 */

import type { ImpactSeverity } from '../types.js';
import { IMPACT_ORDER } from '../types.js';
import { mergeViewports } from './analysis.js';
import type { StructureAnalysis, StructureDomain, StructureError, StructureViewport } from './types.js';

/** One findings-summary chip: an error type with its occurrence count. */
export interface Finding {
    key: string;
    severity: ImpactSeverity;
    count: number;
    viewports: StructureViewport[];
}

/** Which structure domains the current user has access to. */
export interface EnabledDomains {
    headings: boolean;
    landmarks: boolean;
}

/** Canonical domain order; drives tab order and findings aggregation alike. */
const DOMAIN_ORDER: readonly StructureDomain[] = ['headings', 'landmarks'];

/** The enabled domains, in canonical order (headings before landmarks). */
export const enabledDomains = (enabled: EnabledDomains): StructureDomain[] =>
    DOMAIN_ORDER.filter((domain) => enabled[domain]);

/** Every error of one domain's analysis slice (page-level and per-node alike). */
export const domainErrors = (analysis: StructureAnalysis | null, domain: StructureDomain): StructureError[] => {
    if (analysis === null) {
        return [];
    }
    const slice = domain === 'headings' ? analysis.headings : analysis.landmarks;
    return slice?.errors ?? [];
};

/** A domain's page-level errors (missing H1 / missing main — no node to attach to). */
export const pageErrors = (analysis: StructureAnalysis | null, domain: StructureDomain): StructureError[] =>
    domainErrors(analysis, domain).filter((error) => error.nodeId === null);

/**
 * Per-impact finding totals over the given domains — one domain for a tab
 * badge, every enabled one for the widget's status row and announcement.
 */
export const severityCounts = (
    analysis: StructureAnalysis | null,
    domains: StructureTab[],
): Record<ImpactSeverity, number> => {
    const counts: Record<ImpactSeverity, number> = { critical: 0, serious: 0, moderate: 0, minor: 0 };
    for (const domain of domains) {
        for (const error of domainErrors(analysis, domain)) {
            counts[error.severity] += 1;
        }
    }
    return counts;
};

/**
 * Groups one domain's errors into findings chips: one chip per error key,
 * counting occurrences and merging viewports, sorted worst impact first
 * (stable within one impact). Per domain rather than across all of them
 * because a chip is a jump target into the view of its own panel — the same
 * label key reused by both analyzers therefore never merges into one chip.
 */
export const aggregateFindings = (analysis: StructureAnalysis | null, domain: StructureDomain): Finding[] => {
    const findings = new Map<string, Finding>();
    for (const error of domainErrors(analysis, domain)) {
        const existing = findings.get(error.key);
        if (existing === undefined) {
            findings.set(error.key, {
                key: error.key,
                severity: error.severity,
                count: 1,
                viewports: [...error.viewports],
            });
        } else {
            existing.count += 1;
            existing.viewports = mergeViewports(existing.viewports, error.viewports);
        }
    }
    return Array.from(findings.values()).sort(
        (a, b) => IMPACT_ORDER.indexOf(a.severity) - IMPACT_ORDER.indexOf(b.severity),
    );
};
