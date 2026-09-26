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

import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('@typo3/core/lit-helper.js', () => ({
    lll: (key: string, ...args: unknown[]): string => (args.length > 0 ? `${key}: ${args.join(', ')}` : key),
}));
vi.mock('@typo3/backend/notification.js', () => ({
    default: { error: vi.fn(), success: vi.fn() },
}));
vi.mock('@typo3/backend/element/icon-element.js', () => ({}));
vi.mock('@typo3/backend/element/spinner-element.js', () => ({}));

import type { LandmarkStructure } from '../../../Resources/Private/Source/element/landmark-structure/landmark-structure.js';
import '../../../Resources/Private/Source/element/landmark-structure/landmark-structure.js';
import type { LandmarkNode } from '../../../Resources/Private/Source/lib/structure/types.js';
import type { RecordReference } from '../../../Resources/Private/Source/lib/types.js';

const makeRecord = (over: Partial<RecordReference> = {}): RecordReference => ({
    tableName: 'tt_content',
    columnName: 'tx_mindfula11y_landmark',
    uid: 1,
    editLink: '/edit/1',
    storedValue: 'navigation',
    ...over,
});

const makeNode = (id: string, over: Partial<LandmarkNode> = {}): LandmarkNode => ({
    id,
    documentOrder: 0,
    role: 'navigation',
    label: 'Main menu',
    availableRoles: {},
    record: null,
    viewports: ['desktop'],
    errors: [],
    children: [],
    ...over,
});

const mount = async (nodes: LandmarkNode[]): Promise<LandmarkStructure> => {
    const view = document.createElement('mindfula11y-landmark-structure');
    view.nodes = nodes;
    document.body.append(view);
    await view.updateComplete;
    return view;
};

/** The head row of one region, addressed by its node id. */
const head = (view: LandmarkStructure, nodeId: string): Element | null =>
    view.renderRoot.querySelector(`[data-node-id="${nodeId}"]`);

describe('LandmarkStructure', () => {
    afterEach(() => {
        document.body.replaceChildren();
    });

    it('offers an editable role select with the available roles and an edit link', async () => {
        const view = await mount([
            makeNode('nav-1', {
                record: makeRecord(),
                availableRoles: { navigation: 'Navigation', complementary: 'Complementary' },
            }),
        ]);

        const select = head(view, 'nav-1')?.querySelector<HTMLSelectElement>('select[data-control="role"]');

        expect(select).not.toBeNull();
        expect(select?.getAttribute('aria-label')).toBe('mindfula11y.structure.landmarks.role: Main menu');
        expect([...(select?.options ?? [])].map((option) => option.value)).toEqual(['navigation', 'complementary']);
        expect(select?.value).toBe('navigation');
        expect(head(view, 'nav-1')?.querySelector('[data-locked]')).toBeNull();
        expect(head(view, 'nav-1')?.querySelector('a[data-control="edit"]')?.getAttribute('href')).toBe('/edit/1');
    });

    it.each([
        ['has no record', null],
        ['has no edit link', makeRecord({ editLink: '' })],
    ])('shows a locked role chip when the landmark %s', async (_label, record) => {
        const view = await mount([makeNode('nav-1', { record, availableRoles: { navigation: 'Navigation' } })]);

        const row = head(view, 'nav-1');
        const locked = row?.querySelector('[data-locked]');

        expect(row?.querySelector('select')).toBeNull();
        expect(row?.querySelector('a[data-control="edit"]')).toBeNull();
        expect(locked?.textContent).toContain('mindfula11y.structure.landmarks.role.navigation');
        // The lock icon is decorative; the locked state reaches assistive
        // technology as text.
        expect(locked?.textContent).toContain('mindfula11y.structure.landmarks.edit.locked');
    });

    it('names a landmark without a role as such', async () => {
        const view = await mount([makeNode('generic-1', { role: '' })]);

        expect(head(view, 'generic-1')?.querySelector('[data-locked]')?.textContent).toContain(
            'mindfula11y.structure.landmarks.role.none',
        );
    });

    it('marks unlabelled landmarks and falls back to the generic label', async () => {
        const view = await mount([makeNode('nav-1', { label: '' })]);

        const name = head(view, 'nav-1')?.querySelector('[data-unlabelled]');

        expect(name?.textContent).toBe('mindfula11y.structure.landmarks.unlabelledLandmark');
    });

    it('nests child landmarks as a list inside their parent region, framing only the root list', async () => {
        const view = await mount([
            makeNode('main-1', {
                role: 'main',
                label: '',
                children: [makeNode('nav-1'), makeNode('aside-1', { role: 'complementary', label: 'Related' })],
            }),
            makeNode('footer-1', { role: 'contentinfo', label: '' }),
        ]);

        const root = view.renderRoot.querySelector('ol[data-root]');
        const topLevel = [...(root?.children ?? [])].map((item) => item.getAttribute('data-role'));
        const main = root?.querySelector(':scope > li[data-role="main"]');
        const nested = main?.querySelector(':scope > ol');

        expect(view.renderRoot.querySelectorAll('ol[data-root]')).toHaveLength(1);
        expect(topLevel).toEqual(['main', 'contentinfo']);
        expect(nested?.hasAttribute('data-root')).toBe(false);
        expect([...(nested?.children ?? [])].map((item) => item.getAttribute('data-role'))).toEqual([
            'navigation',
            'complementary',
        ]);
    });

    it('keeps node findings inside their region', async () => {
        const view = await mount([
            makeNode('nav-1', {
                errors: [
                    {
                        key: 'mindfula11y.structure.landmarks.error.duplicateSameLabel',
                        severity: 'moderate',
                        nodeId: 'nav-1',
                        viewports: ['desktop'],
                    },
                ],
            }),
        ]);

        const issue = view.renderRoot.querySelector('#issue-nav-1 [data-scope="node"]');

        expect(issue?.closest('li[data-role="navigation"]')).not.toBeNull();
        expect(issue?.textContent).toContain('mindfula11y.structure.landmarks.error.duplicateSameLabel');
    });
});
