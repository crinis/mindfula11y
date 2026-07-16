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

import { mergeViewports } from './analysis.js';
import type { StructureAnalysis, StructureDomain, StructureError, StructureViewport } from './types.js';
import { StructureErrorSeverity } from './types.js';

/** One findings-summary chip: an error type with its occurrence count. */
export interface Finding {
    key: string;
    severity: StructureErrorSeverity;
    count: number;
    domain: StructureDomain;
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

/** Error/warning totals of one domain, for the tab badges and announcements. */
export const severityCounts = (
    analysis: StructureAnalysis | null,
    domain: StructureDomain,
): { errors: number; warnings: number } => {
    const counts = { errors: 0, warnings: 0 };
    for (const error of domainErrors(analysis, domain)) {
        if (error.severity === StructureErrorSeverity.Error) {
            counts.errors += 1;
        } else {
            counts.warnings += 1;
        }
    }
    return counts;
};

/**
 * Groups the enabled domains' errors into findings chips: one chip per
 * domain + error key, counting occurrences and merging viewports, errors
 * sorted before warnings.
 */
export const aggregateFindings = (analysis: StructureAnalysis | null, enabled: EnabledDomains): Finding[] => {
    const findings = new Map<string, Finding>();
    for (const domain of enabledDomains(enabled)) {
        for (const error of domainErrors(analysis, domain)) {
            // Keyed by domain + error key: the same label key can be reused by
            // both analyzers, and their findings must never merge into one chip.
            const findingKey = `${domain} ${error.key}`;
            const existing = findings.get(findingKey);
            if (existing === undefined) {
                findings.set(findingKey, {
                    key: error.key,
                    severity: error.severity,
                    count: 1,
                    domain,
                    viewports: [...error.viewports],
                });
            } else {
                existing.count += 1;
                existing.viewports = mergeViewports(existing.viewports, error.viewports);
            }
        }
    }
    return Array.from(findings.values()).sort((a, b) => {
        if (a.severity === b.severity) {
            return 0;
        }
        return a.severity === StructureErrorSeverity.Error ? -1 : 1;
    });
};
