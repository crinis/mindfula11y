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

/** Error the backend reports as a structured `{ error: { title, description } }` body. */
export class RequestError extends Error {
    readonly description: string;

    constructor(message: string, description: string = '') {
        super(message);
        this.name = 'RequestError';
        this.description = description;
    }
}

/**
 * Converts a failed AjaxRequest into a RequestError when the backend sent its
 * structured error body; otherwise returns the original error unchanged.
 */
export const toRequestError = async (error: unknown): Promise<unknown> => {
    const response = (error as { response?: Response }).response;
    if (response === undefined) {
        return error;
    }
    try {
        const data = (await response.clone().json()) as { error?: { title?: string; description?: string } };
        if (data.error?.title !== undefined) {
            return new RequestError(data.error.title, data.error.description ?? '');
        }
    } catch {
        // Non-JSON error body — fall through to the original error.
    }
    return error;
};
