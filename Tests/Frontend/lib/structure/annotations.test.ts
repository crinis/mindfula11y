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

import { describe, expect, it } from 'vitest';
import {
    extractChildTypeRecord,
    extractRecord,
    indexStructureNodes,
} from '../../../../Resources/Private/Source/lib/structure/annotations.js';

const makeElement = (record?: { tableName: string; columnName: string; uid: number | string }): HTMLElement => {
    const element = document.createElement('div');
    if (record !== undefined) {
        element.dataset.mindfula11yRecordTableName = record.tableName;
        element.dataset.mindfula11yRecordColumnName = record.columnName;
        element.dataset.mindfula11yRecordUid = String(record.uid);
    }
    return element;
};

describe('extractRecord', () => {
    it('reads the record coordinates off the annotation dataset with an empty edit link', () => {
        const element = makeElement({ tableName: 'tt_content', columnName: 'header_type', uid: 42 });

        expect(extractRecord(element)).toEqual({
            tableName: 'tt_content',
            columnName: 'header_type',
            uid: 42,
            editLink: '',
        });
    });

    it.each([
        ['missing table name', { tableName: '', columnName: 'header_type', uid: 42 }],
        ['missing column name', { tableName: 'tt_content', columnName: '', uid: 42 }],
        ['non-numeric uid', { tableName: 'tt_content', columnName: 'header_type', uid: 'abc' }],
    ])('yields null for an incomplete dataset (%s)', (_case, record) => {
        expect(extractRecord(makeElement(record))).toBeNull();
    });

    it('yields null for an element without any annotation', () => {
        expect(extractRecord(makeElement())).toBeNull();
    });
});

describe('indexStructureNodes', () => {
    it('bases the id on the record coordinates for annotated elements', () => {
        const element = makeElement({ tableName: 'tt_content', columnName: 'header_type', uid: 7 });

        const index = indexStructureNodes([element]);

        expect(index.get(element)).toEqual({ id: 'tt_content:7:header_type', documentOrder: 0 });
    });

    it('falls back to the caller-provided base for unannotated elements', () => {
        const element = makeElement();

        const index = indexStructureNodes([element], () => 'relation:intro');

        expect(index.get(element)?.id).toBe('relation:intro');
    });

    it('falls back to the document position when there is neither record nor fallback base', () => {
        const first = makeElement();
        const second = makeElement();

        const index = indexStructureNodes([first, second]);

        expect(index.get(first)?.id).toBe('pos:0');
        expect(index.get(second)?.id).toBe('pos:1');
    });

    it('prefers the record base over a fallback base', () => {
        const element = makeElement({ tableName: 'pages', columnName: 'title', uid: 3 });

        const index = indexStructureNodes([element], () => 'relation:ignored');

        expect(index.get(element)?.id).toBe('pages:3:title');
    });

    it('disambiguates repeated bases with an #occurrence suffix', () => {
        const record = { tableName: 'tt_content', columnName: 'header_type', uid: 7 };
        const elements = [makeElement(record), makeElement(record), makeElement(record)];

        const index = indexStructureNodes(elements);

        expect(elements.map((element) => index.get(element)?.id)).toEqual([
            'tt_content:7:header_type',
            'tt_content:7:header_type#1',
            'tt_content:7:header_type#2',
        ]);
    });

    it('counts occurrences per base across record and fallback ids alike', () => {
        const withFallback = (): HTMLElement => makeElement();
        const elements = [withFallback(), withFallback()];

        const index = indexStructureNodes(elements, () => 'relation:shared');

        expect(elements.map((element) => index.get(element)?.id)).toEqual(['relation:shared', 'relation:shared#1']);
    });

    it('assigns ascending document order matching the candidate order', () => {
        const elements = [makeElement(), makeElement(), makeElement()];

        const index = indexStructureNodes(elements);

        expect(elements.map((element) => index.get(element)?.documentOrder)).toEqual([0, 1, 2]);
    });
});

describe('extractChildTypeRecord', () => {
    it('extracts child-type coordinates with the stored value', () => {
        const element = document.createElement('h2');
        element.setAttribute('data-mindfula11y-childtype-table-name', 'tt_content');
        element.setAttribute('data-mindfula11y-childtype-column-name', 'tx_mindfula11y_childheadingtype');
        element.setAttribute('data-mindfula11y-childtype-uid', '5');
        element.setAttribute('data-mindfula11y-childtype-value', '');

        expect(extractChildTypeRecord(element)).toEqual({
            tableName: 'tt_content',
            columnName: 'tx_mindfula11y_childheadingtype',
            uid: 5,
            editLink: '',
            storedValue: '',
        });
    });

    it('returns null when coordinates are incomplete', () => {
        const element = document.createElement('h2');
        element.setAttribute('data-mindfula11y-childtype-table-name', 'tt_content');

        expect(extractChildTypeRecord(element)).toBeNull();
    });
});
