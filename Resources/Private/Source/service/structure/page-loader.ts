/*
 * Mindful A11y extension for TYPO3 integrating accessibility tools into the backend.
 * Copyright (C) 2026  Mindful Markup, Felix Spittel
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 */

import { StructureAnalysisError } from '../../lib/structure/error.js';
import type { StructureAnalysisInitializeMessage } from '../../lib/structure/protocol.js';
import {
    isStructureAnalysisReadyMessage,
    parsePortMessage,
    STRUCTURE_ANALYSIS_PROTOCOL,
} from '../../lib/structure/protocol.js';
import type { HeadingAnalysis, LandmarkAnalysis, StructureViewport } from '../../lib/structure/types.js';
import type { StructureAnalysisApi, StructureAnalysisTicket } from './api.js';

const LOAD_TIMEOUT = 15_000;
/**
 * Grace period after the iframe finishes loading for the runner to hand-shake.
 * Iframes do not fire `error` on refused framing, redirects that drop the
 * ticket, or non-HTML bodies, so a load with no ready message is our earliest
 * signal that this page cannot be analyzed — reported without the full timeout.
 */
const POST_LOAD_GRACE = 2_000;

const STRUCTURE_VIEWPORTS: Record<StructureViewport, { width: number; height: number }> = {
    mobile: { width: 375, height: 812 },
    desktop: { width: 1280, height: 900 },
};

/** Wire contract with PHP StructureAnalysisTicketService::TICKET_QUERY_PARAMETER. */
const TICKET_QUERY_PARAMETER = 'mindfula11y_structure_ticket';

/** The analyzed page's URL without the single-use ticket — openable/probeable independently. */
const pageUrlOf = (ticketUrl: string): string | null => {
    try {
        const url = new URL(ticketUrl, window.location.href);
        url.searchParams.delete(TICKET_QUERY_PARAMETER);
        return url.toString();
    } catch {
        return null;
    }
};

export interface StructureRenderOptions {
    pageId: number;
    languageId: number;
    headings: boolean;
    landmarks: boolean;
}

export interface RenderedStructureAnalysis {
    headings: HeadingAnalysis | null;
    landmarks: LandmarkAnalysis | null;
}

const abortReason = (signal: AbortSignal): unknown =>
    signal.reason ?? new DOMException('Structure analysis rendering was cancelled.', 'AbortError');

/** Renders the real frontend URL and receives serializable results from its isolated runner. */
export class RenderedPageLoader {
    public constructor(private readonly service: Pick<StructureAnalysisApi, 'issueTicket'>) {}

    async load(
        viewport: StructureViewport,
        parent: ParentNode,
        signal: AbortSignal,
        options: StructureRenderOptions,
    ): Promise<RenderedStructureAnalysis> {
        // Load-bearing on both sides of the ticket request: an abort can race
        // the network response, and `issueTicket()` may still resolve after
        // the caller aborted, so cancellation is rechecked before and after it.
        signal.throwIfAborted();
        const ticket = await this.service.issueTicket(options.pageId, options.languageId, signal);
        signal.throwIfAborted();

        const frame = this.createFrame(viewport);
        parent.append(frame);
        try {
            // No throwIfAborted() after this: waitForResult() always settles —
            // including on abort, via its own signal listener — so a reachable
            // return here already implies the signal was not aborted.
            return await this.waitForResult(frame, ticket, viewport, options, signal);
        } finally {
            // waitForResult() always settles — including on abort, via its own
            // signal listener — so this covers every exit path.
            frame.remove();
        }
    }

    private createFrame(viewport: StructureViewport): HTMLIFrameElement {
        const frame = document.createElement('iframe');
        const dimensions = STRUCTURE_VIEWPORTS[viewport];
        frame.dataset.structureAnalysisFrame = viewport;
        frame.width = `${dimensions.width}`;
        frame.height = `${dimensions.height}`;
        frame.tabIndex = -1;
        frame.setAttribute('aria-hidden', 'true');
        // Keeping the frame out of the layout is a functional requirement of
        // rendering it at a fixed viewport size, not host theming — so it is set
        // here rather than in a host stylesheet this service cannot see.
        frame.style.cssText =
            'position:fixed;inset-block-start:0;inset-inline-start:0;z-index:-1;pointer-events:none;border:0;opacity:0;';
        // No `allow-same-origin`: the framed document runs in an opaque origin,
        // so even if a redirect or expired ticket makes the real (unhardened)
        // frontend page load, its scripts get no DOM, cookie, or storage access
        // toward this backend window. They CAN still postMessage: the handshake
        // below identifies the runner by frame identity (not origin), so a
        // hostile framed page could at most submit forged, schema-validated
        // results about itself — editing metadata is authorized independently
        // by the backend enrichment endpoint.
        frame.setAttribute('sandbox', 'allow-scripts');
        frame.setAttribute('title', `Structure analysis: ${viewport}`);
        frame.referrerPolicy = 'no-referrer';
        return frame;
    }

    private async waitForResult(
        frame: HTMLIFrameElement,
        ticket: StructureAnalysisTicket,
        viewport: StructureViewport,
        options: StructureRenderOptions,
        signal: AbortSignal,
    ): Promise<RenderedStructureAnalysis> {
        return new Promise<RenderedStructureAnalysis>((resolve, reject) => {
            // Every listener below is registered with this signal instead of a
            // manual removeEventListener call; settle() aborts it once, which
            // tears down all of them together.
            const cleanup = new AbortController();
            let done = false;
            let port: MessagePort | null = null;
            let graceTimeout: number | null = null;
            const settle = (callback: () => void): void => {
                if (done) {
                    return;
                }
                done = true;
                window.clearTimeout(timeout);
                if (graceTimeout !== null) {
                    window.clearTimeout(graceTimeout);
                }
                port?.close();
                cleanup.abort();
                callback();
            };
            const timeout = window.setTimeout(
                () =>
                    settle(() =>
                        reject(
                            new StructureAnalysisError('timeout', 'Timed out while rendering the frontend preview.'),
                        ),
                    ),
                LOAD_TIMEOUT,
            );
            const handleAbort = (): void => settle(() => reject(abortReason(signal)));
            // The frame finished loading but the runner never handshook: framing
            // was refused, the ticketed URL redirected away, or the body was not
            // an analysis document. Fail fast rather than wait out LOAD_TIMEOUT.
            const handleFrameLoad = (): void => {
                if (port !== null || graceTimeout !== null) {
                    return;
                }
                graceTimeout = window.setTimeout((): void => {
                    void this.framingFailure(ticket, signal).then((error) => settle(() => reject(error)));
                }, POST_LOAD_GRACE);
            };
            const handleReady = (event: MessageEvent<unknown>): void => {
                // The runner's frame is sandboxed to an opaque origin, so its
                // messages carry no matchable origin. Identity is established by
                // event.source (the browser sets it to the real sender window)
                // plus the unguessable-per-load requestId.
                if (
                    port !== null ||
                    event.source !== frame.contentWindow ||
                    !isStructureAnalysisReadyMessage(event.data, ticket.requestId)
                ) {
                    return;
                }
                if (graceTimeout !== null) {
                    window.clearTimeout(graceTimeout);
                    graceTimeout = null;
                }

                const channel = new MessageChannel();
                port = channel.port1;
                port.onmessage = (message: MessageEvent<unknown>): void => {
                    const parsed = parsePortMessage(message.data, ticket.requestId, viewport);
                    if (parsed === null) {
                        return;
                    }
                    switch (parsed.kind) {
                        case 'result':
                            settle(() => resolve({ headings: parsed.headings, landmarks: parsed.landmarks }));
                            return;
                        case 'error':
                            settle(() =>
                                reject(new StructureAnalysisError(parsed.code, parsed.message, parsed.status)),
                            );
                            return;
                        case 'invalid-result':
                            // A result addressed to us that fails validation
                            // (e.g. the page exceeds the protocol's
                            // node/label limits) would otherwise be dropped
                            // and surface only as a timeout.
                            settle(() =>
                                reject(
                                    new StructureAnalysisError(
                                        'payload',
                                        'The frontend structure analysis result was too large or malformed.',
                                    ),
                                ),
                            );
                            return;
                    }
                };
                port.start();
                const initialize: StructureAnalysisInitializeMessage = {
                    protocol: STRUCTURE_ANALYSIS_PROTOCOL,
                    type: 'initialize',
                    requestId: ticket.requestId,
                    viewport,
                    headings: options.headings,
                    landmarks: options.landmarks,
                };
                // Target origin '*' because the frame is opaque-origin; delivery
                // is still restricted to this specific frame's window, and the
                // transferred port — not the message — is the capability.
                frame.contentWindow?.postMessage(initialize, '*', [channel.port2]);
            };

            window.addEventListener('message', handleReady, { signal: cleanup.signal });
            frame.addEventListener('load', handleFrameLoad, { signal: cleanup.signal });
            signal.addEventListener('abort', handleAbort, { signal: cleanup.signal });
            if (signal.aborted) {
                handleAbort();
                return;
            }
            frame.src = ticket.url;
        });
    }

    /**
     * Classifies a frame that loaded but never handshook. The sandboxed frame
     * is opaque-origin: browsers suppress its HTTP-auth prompt and hide the
     * response status, so a page behind basic auth is indistinguishable from
     * refused framing in here. A same-origin fetch of the ticket-free page
     * URL can read that status and upgrade the diagnosis to 'auth';
     * cross-origin pages expose no status without CORS, so the generic
     * 'framing' message (which names authentication as a possible cause)
     * remains. Runs only on an already-failed load — the working credential
     * paths never reach it.
     */
    private async framingFailure(
        ticket: StructureAnalysisTicket,
        signal: AbortSignal,
    ): Promise<StructureAnalysisError> {
        const pageUrl = pageUrlOf(ticket.url);
        const framing = new StructureAnalysisError(
            'framing',
            'The frontend preview could not be analyzed. It may refuse framing, require authentication, or the analysis session expired.',
            undefined,
            pageUrl ?? undefined,
        );
        if (pageUrl === null || new URL(pageUrl).origin !== window.location.origin) {
            return framing;
        }
        try {
            const response = await fetch(pageUrl, {
                credentials: 'include',
                redirect: 'follow',
                cache: 'no-store',
                signal,
            });
            if (response.status === 401 || response.status === 407) {
                return new StructureAnalysisError(
                    'auth',
                    'The frontend page requires HTTP authentication the sandboxed preview cannot supply.',
                    response.status,
                    pageUrl,
                );
            }
        } catch {
            // The probe failing adds no information; keep the framing diagnosis.
        }
        return framing;
    }
}
