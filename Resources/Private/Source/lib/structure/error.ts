/*
 * Mindful A11y extension for TYPO3 integrating accessibility tools into the backend.
 * Copyright (C) 2026  Mindful Markup, Felix Spittel
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 */

/** Discriminates why the structure-analysis pipeline failed, for UI-facing messaging. */
export type StructureAnalysisFailureCode =
    | 'ticket' // ticket issuance failed
    | 'timeout' // preview render timeout
    | 'framing' // frame refused/expired ticket (load-event race)
    | 'http' // page rendered with error status
    | 'analysis' // runner reported an analysis error
    | 'payload' // result rejected (too large / malformed)
    | 'enrich'; // record-metadata enrichment failed

/** A typed failure from the structure-analysis pipeline (ticket → iframe → runner → enrichment). */
export class StructureAnalysisError extends Error {
    constructor(
        readonly code: StructureAnalysisFailureCode,
        message: string, // developer-facing; UI uses code, not message
        readonly status?: number, // for 'http'
    ) {
        super(message);
        this.name = 'StructureAnalysisError';
    }
}
