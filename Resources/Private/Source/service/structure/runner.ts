/*
 * Mindful A11y extension for TYPO3 integrating accessibility tools into the backend.
 * Copyright (C) 2026  Mindful Markup, Felix Spittel
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 */

import { analyzeHeadings } from '../../lib/structure/heading-analysis.js';
import { analyzeLandmarks } from '../../lib/structure/landmark-analysis.js';
import type {
    StructureAnalysisErrorMessage,
    StructureAnalysisInitializeMessage,
    StructureAnalysisReadyMessage,
    StructureAnalysisResultMessage,
} from '../../lib/structure/protocol.js';
import { isStructureAnalysisInitializeMessage, STRUCTURE_ANALYSIS_PROTOCOL } from '../../lib/structure/protocol.js';

const script = document.querySelector<HTMLScriptElement>('#mindfula11y-structure-analysis-runner');
const requestId: string = script?.dataset.requestId ?? '';
const backendOrigin: string = script?.dataset.backendOrigin ?? '';
const httpStatus = Number.parseInt(script?.dataset.status ?? '', 10);

/**
 * Upper bound of the settle wait below. A visible page settles within a few
 * frames; the bound only ever applies to a hidden one, and stays far below
 * the backend's 15 s load timeout (background tabs run timers no more often
 * than once a second anyway).
 */
const SETTLE_LIMIT_MS = 1_000;

/**
 * Lets the page settle before it is analyzed: web fonts loaded, then two
 * frames for the layout they cause. A hidden document — the editor saved and
 * switched to another browser tab — renders no frames, and a font finishing
 * there leaves `fonts.ready` waiting for a layout pass only rendering would
 * run, while the backend's load timeout keeps counting. The wait is therefore
 * capped; the analyzers read markup and computed styles, which do not depend
 * on that pass.
 */
const waitForLayout = async (): Promise<void> => {
    const settled = (async (): Promise<void> => {
        await document.fonts.ready;
        await new Promise<void>((resolve) => {
            requestAnimationFrame(() => requestAnimationFrame(() => resolve()));
        });
    })();
    await Promise.race([settled, new Promise<void>((resolve) => setTimeout(resolve, SETTLE_LIMIT_MS))]);
};

const analyze = async (message: StructureAnalysisInitializeMessage, port: MessagePort): Promise<void> => {
    try {
        // Guaranteed an integer by the top-level guard gating this whole
        // listener registration; only the HTTP range needs re-checking here.
        if (httpStatus < 200 || httpStatus >= 300) {
            port.postMessage({
                protocol: STRUCTURE_ANALYSIS_PROTOCOL,
                type: 'error',
                requestId,
                code: 'http',
                status: httpStatus,
            } satisfies StructureAnalysisErrorMessage);
            return;
        }
        await waitForLayout();
        port.postMessage({
            protocol: STRUCTURE_ANALYSIS_PROTOCOL,
            type: 'result',
            requestId,
            viewport: message.viewport,
            headings: message.headings ? analyzeHeadings(document, { viewport: message.viewport }) : null,
            landmarks: message.landmarks ? analyzeLandmarks(document, { viewport: message.viewport }) : null,
        } satisfies StructureAnalysisResultMessage);
    } catch (error: unknown) {
        port.postMessage({
            protocol: STRUCTURE_ANALYSIS_PROTOCOL,
            type: 'error',
            requestId,
            code: 'analysis',
            message: error instanceof Error ? error.message : 'The frontend structure analysis failed.',
        } satisfies StructureAnalysisErrorMessage);
    } finally {
        port.close();
    }
};

if (requestId !== '' && /^https?:\/\//.test(backendOrigin) && Number.isInteger(httpStatus)) {
    let initialized = false;
    window.addEventListener('message', (event: MessageEvent<unknown>) => {
        if (
            initialized ||
            event.origin !== backendOrigin ||
            event.source !== window.parent ||
            !isStructureAnalysisInitializeMessage(event.data, requestId) ||
            event.ports.length !== 1
        ) {
            return;
        }
        const port = event.ports[0];
        if (port === undefined) {
            return;
        }
        initialized = true;
        void analyze(event.data, port);
    });
    window.parent.postMessage(
        { protocol: STRUCTURE_ANALYSIS_PROTOCOL, type: 'ready', requestId } satisfies StructureAnalysisReadyMessage,
        backendOrigin,
    );
}
