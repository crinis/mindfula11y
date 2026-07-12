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

import AjaxDataHandler from '@typo3/backend/ajax-data-handler.js';
import type { RecordReference } from '../lib/types.js';

/** A DataHandler write was rejected or reported errors. */
export class RecordUpdateError extends Error {
    constructor(message: string) {
        super(message);
        this.name = 'RecordUpdateError';
    }
}

/** Persists record field updates through TYPO3's DataHandler. */
export class RecordService {
    async updateField(record: RecordReference, value: string): Promise<void> {
        await this.updateFields(record.tableName, record.uid, { [record.columnName]: value });
    }

    async updateFields(tableName: string, uid: number, fields: Record<string, string>): Promise<void> {
        let result: { hasErrors: boolean };
        try {
            result = await AjaxDataHandler.process({
                data: {
                    [tableName]: {
                        [uid]: fields,
                    },
                },
            });
        } catch (error) {
            throw new RecordUpdateError(error instanceof Error ? error.message : String(error));
        }
        if (result.hasErrors) {
            throw new RecordUpdateError(`DataHandler reported errors updating ${tableName}:${uid}`);
        }
    }
}

export default RecordService;
