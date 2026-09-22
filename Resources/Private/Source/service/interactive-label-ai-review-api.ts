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

import type { RequestOptions } from './backend-api.js';
import { postJson } from './backend-api.js';

/** The three (and only) verdicts InteractiveLabelAssessment (PHP enum) may return. */
export type InteractiveLabelAiAssessment = 'likely_clear' | 'likely_unclear' | 'uncertain';

export interface InteractiveLabelAiReviewRequest {
    pageId: number;
    label: string;
    elementType: string;
    target: string;
    rule: string;
    pageTitle: string;
    surroundingContext: string;
    locale: string;
}

export interface InteractiveLabelAiReviewResult {
    assessment: InteractiveLabelAiAssessment;
    reason: string;
    suggestedLabel: string | null;
}

const isKnownAssessment = (value: unknown): value is InteractiveLabelAiAssessment =>
    value === 'likely_clear' || value === 'likely_unclear' || value === 'uncertain';

/**
 * AJAX client of the interactive-label AI context review endpoint.
 *
 * Unlike AltTextApi's signed demand, the request body here is plain JSON —
 * see InteractiveLabelContextReviewAjaxController for why no HMAC signature
 * is needed (module access plus a per-page TSconfig re-check are enough,
 * this call never touches the database).
 */
export class InteractiveLabelAiReviewApi {
    async assess(
        request: InteractiveLabelAiReviewRequest,
        options?: RequestOptions,
    ): Promise<InteractiveLabelAiReviewResult> {
        const data = await postJson<Record<string, unknown>>(
            'mindfula11y_interactivelabel_aireview',
            request as unknown as Record<string, unknown>,
            options,
        );

        if (!isKnownAssessment(data.assessment)) {
            throw new Error('The AI review endpoint returned no recognized assessment.');
        }

        return {
            assessment: data.assessment,
            reason: typeof data.reason === 'string' ? data.reason : '',
            suggestedLabel: typeof data.suggestedLabel === 'string' ? data.suggestedLabel : null,
        };
    }
}
