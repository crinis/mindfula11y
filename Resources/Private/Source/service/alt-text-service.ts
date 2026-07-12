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

import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import type { GenerateAltTextDemand } from '../lib/types.js';
import { toRequestError } from './request-error.js';

interface GenerateAltTextResponse {
    altText?: string;
}

/** AJAX client of the signed alt-text generation endpoint. */
export class AltTextService {
    /**
     * Generates alternative text for the image described by the signed demand.
     * Throws a RequestError carrying the backend's localized title/description
     * when the endpoint answers with its structured error body.
     */
    async generateAltText(demand: GenerateAltTextDemand): Promise<string> {
        try {
            const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.mindfula11y_generatealttext ?? '').post(
                demand,
                { headers: { 'Content-Type': 'application/json; charset=utf-8' } },
            );
            const data = await response.resolve<GenerateAltTextResponse>();
            if (typeof data.altText !== 'string' || data.altText === '') {
                throw new Error('The alt-text endpoint returned no text.');
            }
            return data.altText;
        } catch (error) {
            throw await toRequestError(error);
        }
    }
}

export default AltTextService;
