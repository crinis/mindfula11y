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
import {
    recordKey,
    type StructureRecordRequest,
} from '../../../../Resources/Private/Source/lib/structure/enrichment.js';
import { StructureAnalysisError } from '../../../../Resources/Private/Source/lib/structure/error.js';
import { StructureAnalysisApi } from '../../../../Resources/Private/Source/service/structure/api.js';

const ajaxPost = vi.hoisted(() => vi.fn());

vi.mock('@typo3/core/lit-helper.js', () => ({
    lll: (key: string): string => key,
}));

vi.mock('@typo3/core/ajax/ajax-request.js', () => ({
    default: class {
        post(body: { records: Array<{ uid: number }> }): { resolve: () => Promise<unknown> } {
            return ajaxPost(body);
        }
    },
}));

const request = (uid: number): StructureRecordRequest => ({
    tableName: 'tt_content',
    columnName: 'header_layout',
    uid,
});

describe('StructureAnalysisApi', () => {
    beforeEach(() => {
        ajaxPost.mockReset();
        const ajaxUrls = {};
        Reflect.set(ajaxUrls, 'mindfula11y_structure_ticket', '/ticket');
        Reflect.set(ajaxUrls, 'mindfula11y_structure_enrich', '/enrich');
        Reflect.set(globalThis, 'TYPO3', {
            settings: { ajaxUrls },
        });
        ajaxPost.mockImplementation((body: { records: Record<string, unknown>[] }) => ({
            resolve: async () => ({
                records: body.records.map((record) => ({
                    ...record,
                    editLink: `/edit/${record.uid}`,
                    availableValues: { h2: 'H2' },
                })),
            }),
        }));
    });

    describe('issueTicket', () => {
        it('lets the backend derive the preview URL from page and language identifiers', async () => {
            ajaxPost.mockReturnValue({
                resolve: async () => ({
                    url: 'https://frontend.example/page',
                    requestId: '0123456789abcdef0123456789abcdef',
                }),
            });

            await new StructureAnalysisApi().issueTicket(42, 3, new AbortController().signal);

            expect(ajaxPost).toHaveBeenCalledWith({ pageId: 42, languageId: 3 });
            expect(ajaxPost.mock.calls[0]?.[0]).not.toHaveProperty('previewUrl');
        });

        it('rejects with a ticket error when the backend returns an invalid ticket shape', async () => {
            ajaxPost.mockReturnValue({ resolve: async () => ({ url: 'not-a-url', requestId: 'too-short' }) });

            const issuing = new StructureAnalysisApi().issueTicket(42, 3, new AbortController().signal);

            await expect(issuing).rejects.toBeInstanceOf(StructureAnalysisError);
            await expect(issuing).rejects.toMatchObject({ code: 'ticket' });
        });

        it('rejects with a ticket error when the backend AJAX route is not registered', async () => {
            Reflect.set(globalThis, 'TYPO3', { settings: { ajaxUrls: {} } });

            const issuing = new StructureAnalysisApi().issueTicket(42, 3, new AbortController().signal);

            await expect(issuing).rejects.toBeInstanceOf(StructureAnalysisError);
            await expect(issuing).rejects.toMatchObject({ code: 'ticket' });
            expect(ajaxPost).not.toHaveBeenCalled();
        });
    });

    describe('fetchRecordMetadata', () => {
        it('resolves an empty map without a network call when there are no requests', async () => {
            const metadata = await new StructureAnalysisApi().fetchRecordMetadata([], new AbortController().signal);

            expect(metadata.size).toBe(0);
            expect(ajaxPost).not.toHaveBeenCalled();
        });

        it('rejects with an enrich error when the backend returns invalid editing metadata', async () => {
            ajaxPost.mockReturnValue({ resolve: async () => ({ records: 'not-an-array' }) });

            const fetching = new StructureAnalysisApi().fetchRecordMetadata([request(1)], new AbortController().signal);

            await expect(fetching).rejects.toBeInstanceOf(StructureAnalysisError);
            await expect(fetching).rejects.toMatchObject({ code: 'enrich' });
        });

        it('rejects with an enrich error when the backend AJAX route is not registered', async () => {
            Reflect.set(globalThis, 'TYPO3', { settings: { ajaxUrls: {} } });

            const fetching = new StructureAnalysisApi().fetchRecordMetadata([request(1)], new AbortController().signal);

            await expect(fetching).rejects.toBeInstanceOf(StructureAnalysisError);
            await expect(fetching).rejects.toMatchObject({ code: 'enrich' });
            expect(ajaxPost).not.toHaveBeenCalled();
        });

        it('fetches every record when more than one backend batch is required, keyed by table/uid/column', async () => {
            const requests = Array.from({ length: 201 }, (_value, index) => request(index + 1));

            const metadata = await new StructureAnalysisApi().fetchRecordMetadata(
                requests,
                new AbortController().signal,
            );

            expect(ajaxPost).toHaveBeenCalledTimes(2);
            expect(ajaxPost.mock.calls.map(([body]) => body.records.length)).toEqual([200, 1]);
            expect(metadata.get(recordKey(request(201)))).toEqual({
                tableName: 'tt_content',
                columnName: 'header_layout',
                uid: 201,
                editLink: '/edit/201',
                availableValues: { h2: 'H2' },
            });
        });
    });
});
