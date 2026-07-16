/*
 * Mindful A11y extension for TYPO3 integrating accessibility tools into the backend.
 * Copyright (C) 2026  Mindful Markup, Felix Spittel
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 */

import { isObject, isStringMap } from '../../lib/guards.js';
import {
    recordKey,
    type StructureRecordMetadata,
    type StructureRecordRequest,
} from '../../lib/structure/enrichment.js';
import type { StructureAnalysisFailureCode } from '../../lib/structure/error.js';
import { StructureAnalysisError } from '../../lib/structure/error.js';
import { postJson } from '../backend-api.js';

/** Must match MAX_RECORDS_PER_REQUEST in Classes/Controller/StructureAnalysisEnrichmentAjaxController.php. */
const MAX_RECORDS_PER_REQUEST = 200;

export interface StructureAnalysisTicket {
    url: string;
    requestId: string;
}

/** Authenticated backend API client for the structure analysis flow. */
export class StructureAnalysisApi {
    async issueTicket(pageId: number, languageId: number, signal: AbortSignal): Promise<StructureAnalysisTicket> {
        const value = await this.post<unknown>(
            'mindfula11y_structure_ticket_issue',
            'ticket',
            { pageId, languageId },
            signal,
        );
        if (!this.isTicket(value)) {
            throw new StructureAnalysisError('ticket', 'The backend returned an invalid structure analysis ticket.');
        }
        return value;
    }

    async fetchRecordMetadata(
        requests: StructureRecordRequest[],
        signal: AbortSignal,
    ): Promise<Map<string, StructureRecordMetadata>> {
        const metadata = new Map<string, StructureRecordMetadata>();
        if (requests.length === 0) {
            return metadata;
        }
        for (let offset = 0; offset < requests.length; offset += MAX_RECORDS_PER_REQUEST) {
            signal.throwIfAborted();
            const value = await this.post<unknown>(
                'mindfula11y_structure_enrich',
                'enrich',
                { records: requests.slice(offset, offset + MAX_RECORDS_PER_REQUEST) },
                signal,
            );
            if (!this.isMetadataResponse(value)) {
                throw new StructureAnalysisError('enrich', 'The backend returned invalid structure editing metadata.');
            }
            for (const record of value.records) {
                metadata.set(recordKey(record), record);
            }
        }
        signal.throwIfAborted();
        return metadata;
    }

    /**
     * Posts to a registered AJAX route via the shared transport, restating an
     * unregistered route as this flow's typed failure code — `postJson` reports it
     * as a plain Error, which the structure views cannot localize.
     */
    private async post<T>(
        endpointKey: string,
        code: StructureAnalysisFailureCode,
        body: Record<string, unknown>,
        signal: AbortSignal,
    ): Promise<T> {
        if (TYPO3.settings.ajaxUrls[endpointKey] === undefined) {
            throw new StructureAnalysisError(code, `The backend AJAX route "${endpointKey}" is not registered.`);
        }
        return postJson<T>(endpointKey, body, { signal });
    }

    private isTicket(value: unknown): value is StructureAnalysisTicket {
        if (!isObject(value)) {
            return false;
        }
        return (
            typeof value.url === 'string' &&
            /^https?:\/\//.test(value.url) &&
            typeof value.requestId === 'string' &&
            /^[a-f0-9]{32}$/.test(value.requestId)
        );
    }

    private isMetadataResponse(value: unknown): value is { records: StructureRecordMetadata[] } {
        if (!isObject(value) || !Array.isArray(value.records)) {
            return false;
        }
        return (
            value.records.length <= MAX_RECORDS_PER_REQUEST && value.records.every((record) => this.isMetadata(record))
        );
    }

    private isMetadata(value: unknown): value is StructureRecordMetadata {
        if (!isObject(value)) {
            return false;
        }
        return (
            typeof value.tableName === 'string' &&
            typeof value.columnName === 'string' &&
            typeof value.uid === 'number' &&
            Number.isInteger(value.uid) &&
            typeof value.editLink === 'string' &&
            isStringMap(value.availableValues)
        );
    }
}
