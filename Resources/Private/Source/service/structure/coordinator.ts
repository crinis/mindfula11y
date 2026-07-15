/*
 * Mindful A11y extension for TYPO3 integrating accessibility tools into the backend.
 * Copyright (C) 2026  Mindful Markup, Felix Spittel
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 */

import type { MergeableAnalysis, MergeableNode } from '../../lib/structure/analysis.js';
import { mergeAnalyses } from '../../lib/structure/analysis.js';
import { applyRecordMetadata, collectRecordRequests, type StructureAnalysis } from '../../lib/structure/enrichment.js';
import { StructureAnalysisError } from '../../lib/structure/error.js';
import type { StructureViewport } from '../../lib/types.js';
import { StructureAnalysisApi } from './api.js';
import { RenderedPageLoader, type RenderedStructureAnalysis, type StructureRenderOptions } from './page-loader.js';

export type { StructureAnalysis };

interface StructureAnalysisBackend {
    fetchRecordMetadata: StructureAnalysisApi['fetchRecordMetadata'];
}

interface StructurePageLoader {
    load(
        viewport: StructureViewport,
        parent: ParentNode,
        signal: AbortSignal,
        options: StructureRenderOptions,
    ): Promise<RenderedStructureAnalysis>;
}

/** Coordinates preview rendering, viewport merging, and backend enrichment. */
export class StructureAnalysisCoordinator {
    constructor(
        private readonly backend: StructureAnalysisBackend,
        private readonly loader: StructurePageLoader,
    ) {}

    /**
     * Wires the coordinator to the real backend: a single `StructureAnalysisApi`
     * instance serves both the loader's ticket issuance and the coordinator's
     * own record-metadata fetch, so it cannot be expressed as a constructor
     * default without widening `backend` back to the full API surface.
     */
    static createDefault(): StructureAnalysisCoordinator {
        const api = new StructureAnalysisApi();
        return new StructureAnalysisCoordinator(api, new RenderedPageLoader(api));
    }

    async analyze(
        options: StructureRenderOptions,
        parent: ParentNode,
        signal: AbortSignal,
    ): Promise<StructureAnalysis> {
        const load = (viewport: StructureViewport): Promise<RenderedStructureAnalysis> =>
            this.loader.load(viewport, parent, signal, options);

        // Each viewport owns its ticket, iframe, and request ID, so the renders
        // are independent and can run concurrently.
        const [mobile, desktop] = await Promise.all([load('mobile'), load('desktop')]);
        const analysis: StructureAnalysis = {
            headings: this.mergeDomain(options.headings, mobile.headings, desktop.headings),
            landmarks: this.mergeDomain(options.landmarks, mobile.landmarks, desktop.landmarks),
        };
        const requests = collectRecordRequests(analysis);
        const metadata = await this.backend.fetchRecordMetadata(requests, signal);
        applyRecordMetadata(analysis, metadata);
        return analysis;
    }

    /** Merges one domain's viewport pair, or yields null when that domain is disabled. */
    private mergeDomain<T extends MergeableNode<T>>(
        include: boolean,
        mobile: MergeableAnalysis<T> | null,
        desktop: MergeableAnalysis<T> | null,
    ): MergeableAnalysis<T> | null {
        if (!include) {
            return null;
        }
        if (mobile === null || desktop === null) {
            throw new StructureAnalysisError(
                'payload',
                'The rendered page did not return the requested analysis results.',
            );
        }
        return mergeAnalyses({ mobile, desktop });
    }
}
