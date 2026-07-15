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

const waitForLayout = async (): Promise<void> => {
    await document.fonts.ready;
    await new Promise<void>((resolve) => {
        requestAnimationFrame(() => requestAnimationFrame(() => resolve()));
    });
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
