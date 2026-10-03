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
import { STRUCTURE_ANALYSIS_PROTOCOL } from '../../../../Resources/Private/Source/lib/structure/protocol.js';

const BACKEND_ORIGIN = 'https://backend.example';

/** The analysis-port end the runner posts its result to and closes. */
interface FakePort {
    postMessage: ReturnType<typeof vi.fn>;
    close: ReturnType<typeof vi.fn>;
}

let requestSequence = 0;

/**
 * Evaluates the runner afresh for one analysis request. The runner reads its
 * configuration from its script element and registers its message listener
 * at module evaluation, so every test gets a fresh module and its own request
 * id — listeners of earlier tests stay registered but ignore foreign ids.
 */
const startRunner = async (): Promise<string> => {
    requestSequence += 1;
    const requestId = `${'0'.repeat(31)}${requestSequence}`;
    const script = document.createElement('script');
    script.id = 'mindfula11y-structure-analysis-runner';
    script.dataset.requestId = requestId;
    script.dataset.backendOrigin = BACKEND_ORIGIN;
    script.dataset.status = '200';
    document.head.replaceChildren(script);
    vi.resetModules();
    await import('../../../../Resources/Private/Source/service/structure/runner.js');
    return requestId;
};

/** Hands the runner its port the way the backend's page loader does. */
const initialize = (requestId: string): FakePort => {
    const port: FakePort = { postMessage: vi.fn(), close: vi.fn() };
    window.dispatchEvent(
        new MessageEvent('message', {
            data: {
                protocol: STRUCTURE_ANALYSIS_PROTOCOL,
                type: 'initialize',
                requestId,
                viewport: 'desktop',
                headings: true,
                landmarks: false,
            },
            origin: BACKEND_ORIGIN,
            source: window.parent,
            ports: [port as unknown as MessagePort],
        }),
    );
    return port;
};

const postedTypes = (port: FakePort): unknown[] =>
    port.postMessage.mock.calls.map(([message]) => (message as { type: unknown }).type);

describe('structure analysis runner', () => {
    beforeEach(() => {
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
        document.body.innerHTML = '<h1>Page title</h1>';
        // The runner announces itself to its parent (here: the test window
        // itself) for the backend origin, which happy-dom refuses to target.
        vi.spyOn(window, 'postMessage').mockImplementation(() => undefined);
        // happy-dom implements no FontFaceSet.
        Object.defineProperty(document, 'fonts', { configurable: true, value: { ready: Promise.resolve() } });
    });

    afterEach(() => {
        Reflect.deleteProperty(document, 'fonts');
        vi.useRealTimers();
        vi.restoreAllMocks();
        vi.unstubAllGlobals();
    });

    it('analyzes once the page has painted two frames', async () => {
        vi.stubGlobal('requestAnimationFrame', (callback: FrameRequestCallback) =>
            setTimeout(() => callback(performance.now()), 16),
        );
        const port = initialize(await startRunner());

        await vi.advanceTimersByTimeAsync(40);

        expect(postedTypes(port)).toEqual(['result']);
        const [message] = port.postMessage.mock.calls[0] as [{ headings: { nodes: Array<{ label: string }> } }];
        expect(message.headings.nodes.map((node) => node.label)).toEqual(['Page title']);
        expect(port.close).toHaveBeenCalled();
    });

    it('clears the settle limit once the page settled', async () => {
        vi.stubGlobal('requestAnimationFrame', (callback: FrameRequestCallback) =>
            setTimeout(() => callback(performance.now()), 16),
        );
        const port = initialize(await startRunner());

        await vi.advanceTimersByTimeAsync(40);

        expect(postedTypes(port)).toEqual(['result']);
        // No pending timer outlives the analysis in the framed page.
        expect(vi.getTimerCount()).toBe(0);
    });

    it('still analyzes a hidden document, which runs no animation frames', async () => {
        // A background browser tab renders nothing, while the backend's load
        // timeout keeps counting: the frame wait must not hold the result.
        vi.stubGlobal('requestAnimationFrame', vi.fn());
        const port = initialize(await startRunner());

        await vi.advanceTimersByTimeAsync(0);
        expect(port.postMessage).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(1_000);
        expect(postedTypes(port)).toEqual(['result']);
    });

    it('still analyzes when the font set never reports ready', async () => {
        // A web font finishing in a hidden document leaves fonts.ready waiting
        // for a layout pass that only rendering would run.
        Object.defineProperty(document, 'fonts', {
            configurable: true,
            value: { ready: new Promise<never>(() => undefined) },
        });
        vi.stubGlobal('requestAnimationFrame', vi.fn());
        const port = initialize(await startRunner());

        await vi.advanceTimersByTimeAsync(1_000);
        expect(postedTypes(port)).toEqual(['result']);
    });
});
