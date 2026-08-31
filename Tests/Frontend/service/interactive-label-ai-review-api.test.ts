/*
 * Mindful A11y extension for TYPO3 integrating accessibility tools into the backend.
 * Copyright (C) 2026  Mindful Markup, Felix Spittel
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 */

import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { InteractiveLabelAiReviewRequest } from '../../../Resources/Private/Source/service/interactive-label-ai-review-api.js';
import { InteractiveLabelAiReviewApi } from '../../../Resources/Private/Source/service/interactive-label-ai-review-api.js';

const postJson = vi.fn();

vi.mock('../../../Resources/Private/Source/service/backend-api.js', () => ({
    postJson: (...args: unknown[]): unknown => postJson(...args),
}));

const request = (): InteractiveLabelAiReviewRequest => ({
    pageId: 10,
    label: 'weiter',
    elementType: 'button',
    target: '',
    rule: 'potentially_vague_interactive_label',
    pageTitle: 'Test page',
    surroundingContext: '',
    locale: 'de',
});

describe('InteractiveLabelAiReviewApi.assess', () => {
    beforeEach(() => {
        postJson.mockReset();
    });

    it('posts to the registered AJAX route with the given request body', async () => {
        postJson.mockResolvedValue({ assessment: 'likely_clear', reason: 'ok', suggestedLabel: null });

        await new InteractiveLabelAiReviewApi().assess(request());

        expect(postJson).toHaveBeenCalledWith('mindfula11y_interactivelabel_aireview', request(), undefined);
    });

    it('resolves a valid response into the typed result', async () => {
        postJson.mockResolvedValue({
            assessment: 'likely_unclear',
            reason: 'The label does not describe the destination.',
            suggestedLabel: 'Informationen zur Bewerbung',
        });

        const result = await new InteractiveLabelAiReviewApi().assess(request());

        expect(result).toEqual({
            assessment: 'likely_unclear',
            reason: 'The label does not describe the destination.',
            suggestedLabel: 'Informationen zur Bewerbung',
        });
    });

    it('normalizes a missing suggestedLabel to null', async () => {
        postJson.mockResolvedValue({ assessment: 'likely_clear', reason: 'ok' });

        const result = await new InteractiveLabelAiReviewApi().assess(request());

        expect(result.suggestedLabel).toBeNull();
    });

    it('throws when the response carries no recognized assessment value', async () => {
        postJson.mockResolvedValue({ assessment: 'definitely_a_wcag_violation', reason: 'ok', suggestedLabel: null });

        await expect(new InteractiveLabelAiReviewApi().assess(request())).rejects.toThrow();
    });
});
