/*
 * Mindful A11y extension for TYPO3 integrating accessibility tools into the backend.
 * Copyright (C) 2026  Mindful Markup, Felix Spittel
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 */

// @vitest-environment happy-dom

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { StructureAnalysisError } from '../../../../Resources/Private/Source/lib/structure/error.js';
import { STRUCTURE_ANALYSIS_PROTOCOL } from '../../../../Resources/Private/Source/lib/structure/protocol.js';
import type { StructureAnalysisTicket } from '../../../../Resources/Private/Source/service/structure/api.js';
import { RenderedPageLoader } from '../../../../Resources/Private/Source/service/structure/page-loader.js';

const REQUEST_ID = '0123456789abcdef0123456789abcdef';

const createLoader = (): RenderedPageLoader => {
    const service = {
        issueTicket: async (): Promise<StructureAnalysisTicket> => ({
            url: window.location.href,
            requestId: REQUEST_ID,
        }),
        enrich: async (): Promise<undefined> => undefined,
    };
    return new RenderedPageLoader(service);
};

/**
 * Extracts the runner-side port handed to the frame's `initialize` handshake
 * — `window.postMessage`'s DOM typings don't model the transfer list, so the
 * spy's recorded arguments need a manual cast to reach it.
 */
const transferredPort = (spy: { mock: { calls: unknown[][] } }): MessagePort => {
    const [, , transfer] = spy.mock.calls[0] as [unknown, string, MessagePort[]];
    return transfer[0] as MessagePort;
};

describe('RenderedPageLoader', () => {
    beforeEach(() => {
        document.body.replaceChildren();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('removes its iframe and rejects when rendering is aborted', async () => {
        const controller = new AbortController();
        const loader = createLoader();
        const loading = loader.load('mobile', document.body, controller.signal, {
            pageId: 1,
            languageId: 0,
            headings: true,
            landmarks: true,
        });
        await Promise.resolve();
        controller.abort();

        await expect(loading).rejects.toMatchObject({ name: 'AbortError' });
        expect(document.querySelector('[data-structure-analysis-frame]')).toBeNull();
    });

    it('rejects with a timeout error when no ready message arrives within LOAD_TIMEOUT', async () => {
        vi.useFakeTimers();
        const controller = new AbortController();
        const loader = createLoader();
        const loading = loader.load('mobile', document.body, controller.signal, {
            pageId: 1,
            languageId: 0,
            headings: true,
            landmarks: true,
        });
        // Attach the rejection assertion before advancing the fake clock so the
        // promise never has an instant without a handler once the timer fires.
        const assertion = expect(loading).rejects.toMatchObject({
            code: 'timeout',
            message: expect.stringContaining('Timed out'),
        });

        await vi.advanceTimersByTimeAsync(15_000);

        await assertion;
        expect(document.querySelector('[data-structure-analysis-frame]')).toBeNull();
    });

    it('rejects with a framing error when the frame loads without ever handshaking', async () => {
        vi.useFakeTimers();
        const controller = new AbortController();
        const loader = createLoader();
        const loading = loader.load('mobile', document.body, controller.signal, {
            pageId: 1,
            languageId: 0,
            headings: true,
            landmarks: true,
        });
        await vi.advanceTimersByTimeAsync(0);
        const frame = document.querySelector('[data-structure-analysis-frame]') as HTMLIFrameElement;
        const assertion = expect(loading).rejects.toMatchObject({ code: 'framing' });

        frame.dispatchEvent(new Event('load'));
        await vi.advanceTimersByTimeAsync(2_000);

        await assertion;
    });

    it('rejects with an analysis error when the runner reports a non-HTTP failure', async () => {
        const controller = new AbortController();
        const loader = createLoader();
        const loading = loader.load('mobile', document.body, controller.signal, {
            pageId: 1,
            languageId: 0,
            headings: true,
            landmarks: true,
        });
        await Promise.resolve();
        const frame = document.querySelector('[data-structure-analysis-frame]') as HTMLIFrameElement;
        const contentWindow = frame.contentWindow as Window;
        const postMessageSpy = vi.spyOn(contentWindow, 'postMessage');

        window.dispatchEvent(
            new MessageEvent('message', {
                source: contentWindow,
                data: { protocol: STRUCTURE_ANALYSIS_PROTOCOL, type: 'ready', requestId: REQUEST_ID },
            }),
        );
        const port2 = transferredPort(postMessageSpy);

        port2.postMessage({
            protocol: STRUCTURE_ANALYSIS_PROTOCOL,
            type: 'error',
            requestId: REQUEST_ID,
            code: 'analysis',
            message: 'The isolated runner crashed while walking the DOM.',
        });

        await expect(loading).rejects.toMatchObject({
            code: 'analysis',
            message: 'The isolated runner crashed while walking the DOM.',
        });
    });

    it('rejects with an HTTP error carrying the reported status when the rendered page returned an error status', async () => {
        const controller = new AbortController();
        const loader = createLoader();
        const loading = loader.load('mobile', document.body, controller.signal, {
            pageId: 1,
            languageId: 0,
            headings: true,
            landmarks: true,
        });
        await Promise.resolve();
        const frame = document.querySelector('[data-structure-analysis-frame]') as HTMLIFrameElement;
        const contentWindow = frame.contentWindow as Window;
        const postMessageSpy = vi.spyOn(contentWindow, 'postMessage');

        window.dispatchEvent(
            new MessageEvent('message', {
                source: contentWindow,
                data: { protocol: STRUCTURE_ANALYSIS_PROTOCOL, type: 'ready', requestId: REQUEST_ID },
            }),
        );
        const port2 = transferredPort(postMessageSpy);

        port2.postMessage({
            protocol: STRUCTURE_ANALYSIS_PROTOCOL,
            type: 'error',
            requestId: REQUEST_ID,
            code: 'http',
            status: 404,
        });

        await expect(loading).rejects.toBeInstanceOf(StructureAnalysisError);
        await expect(loading).rejects.toMatchObject({ code: 'http', status: 404 });
    });

    it('rejects with a payload error when an addressed result fails validation', async () => {
        const controller = new AbortController();
        const loader = createLoader();
        const loading = loader.load('mobile', document.body, controller.signal, {
            pageId: 1,
            languageId: 0,
            headings: true,
            landmarks: true,
        });
        await Promise.resolve();
        const frame = document.querySelector('[data-structure-analysis-frame]') as HTMLIFrameElement;
        const contentWindow = frame.contentWindow as Window;
        const postMessageSpy = vi.spyOn(contentWindow, 'postMessage');

        window.dispatchEvent(
            new MessageEvent('message', {
                source: contentWindow,
                data: { protocol: STRUCTURE_ANALYSIS_PROTOCOL, type: 'ready', requestId: REQUEST_ID },
            }),
        );
        const port2 = transferredPort(postMessageSpy);

        port2.postMessage({
            protocol: STRUCTURE_ANALYSIS_PROTOCOL,
            type: 'result',
            requestId: REQUEST_ID,
            viewport: 'mobile',
            headings: 'not-an-analysis',
            landmarks: null,
        });

        await expect(loading).rejects.toMatchObject({ code: 'payload' });
    });

    it('stops reacting to ready messages once the caller aborts', async () => {
        // A spy on window.removeEventListener cannot observe this: under
        // vitest-environment-happy-dom, AbortSignal-driven `{ signal }`
        // removal runs against happy-dom's internal window instance, not the
        // globalThis-proxied object `vi.spyOn` patches, so the spy never
        // fires even though the removal itself is real (verified by a
        // standalone repro against happy-dom directly). Assert the
        // observable behavior instead: a ready message dispatched right
        // after abort — while the frame (and its identity check) are still
        // intact — must have no effect.
        const controller = new AbortController();
        const loader = createLoader();
        const loading = loader.load('mobile', document.body, controller.signal, {
            pageId: 1,
            languageId: 0,
            headings: true,
            landmarks: true,
        });
        await Promise.resolve();
        const frame = document.querySelector('[data-structure-analysis-frame]') as HTMLIFrameElement;
        const contentWindow = frame.contentWindow as Window;
        const postMessageSpy = vi.spyOn(contentWindow, 'postMessage');

        controller.abort();
        window.dispatchEvent(
            new MessageEvent('message', {
                source: contentWindow,
                data: { protocol: STRUCTURE_ANALYSIS_PROTOCOL, type: 'ready', requestId: REQUEST_ID },
            }),
        );
        expect(postMessageSpy).not.toHaveBeenCalled();

        await expect(loading).rejects.toMatchObject({ name: 'AbortError' });
    });

    it('ignores a ready message addressed to a different requestId', async () => {
        const controller = new AbortController();
        const loader = createLoader();
        const loading = loader.load('mobile', document.body, controller.signal, {
            pageId: 1,
            languageId: 0,
            headings: true,
            landmarks: true,
        });
        await Promise.resolve();
        const frame = document.querySelector('[data-structure-analysis-frame]') as HTMLIFrameElement;
        const contentWindow = frame.contentWindow as Window;
        const postMessageSpy = vi.spyOn(contentWindow, 'postMessage');

        window.dispatchEvent(
            new MessageEvent('message', {
                source: contentWindow,
                data: { protocol: STRUCTURE_ANALYSIS_PROTOCOL, type: 'ready', requestId: 'not-the-real-request-id' },
            }),
        );

        expect(postMessageSpy).not.toHaveBeenCalled();

        controller.abort();
        await expect(loading).rejects.toMatchObject({ name: 'AbortError' });
    });
});
