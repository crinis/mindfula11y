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
    lll: (key: string): string => key,
}));
vi.mock('@typo3/backend/ajax-data-handler.js', () => ({
    default: { process: vi.fn() },
}));
vi.mock('@typo3/core/ajax/ajax-request.js', () => ({
    default: class AjaxRequest {},
}));
vi.mock('@typo3/backend/element/icon-element.js', () => ({}));
vi.mock('@typo3/backend/element/spinner-element.js', () => ({}));

import type { AltlessFileReference } from '../../../Resources/Private/Source/element/altless-file-reference/altless-file-reference.js';
import '../../../Resources/Private/Source/element/altless-file-reference/altless-file-reference.js';

const mount = async (editable: boolean): Promise<AltlessFileReference> => {
    const view = document.createElement('mindfula11y-altless-file-reference');
    view.decorative = true;
    if (editable) {
        view.recordEditLink = '/edit/1';
        view.recordEditLinkLabel = 'Edit record';
        view.decorativeEditable = true;
    }
    document.body.append(view);
    await view.updateComplete;
    return view;
};

describe('AltlessFileReference decorative state', () => {
    afterEach(() => {
        document.body.replaceChildren();
    });

    it('initializes an editable decorative reference as saved and checked', async () => {
        const view = await mount(true);
        const checkbox = view.renderRoot.querySelector<HTMLInputElement>('input[type="checkbox"]');
        const save = view.renderRoot.querySelector<HTMLButtonElement>('.actions button:last-child');

        expect(checkbox?.checked).toBe(true);
        expect(view.renderRoot.querySelector('textarea')).toBeNull();
        expect(save?.getAttribute('aria-disabled')).toBe('true');
    });

    it('reveals the alt-text editor when the decorative state is cleared', async () => {
        const view = await mount(true);
        const checkbox = view.renderRoot.querySelector<HTMLInputElement>('input[type="checkbox"]');

        expect(checkbox).not.toBeNull();
        if (checkbox === null) {
            return;
        }
        checkbox.checked = false;
        checkbox.dispatchEvent(new Event('change'));
        await view.updateComplete;

        expect(view.decorative).toBe(false);
        expect(view.renderRoot.querySelector('textarea')).not.toBeNull();
        expect(view.renderRoot.querySelector('.actions button:last-child')?.getAttribute('aria-disabled')).toBeNull();
    });

    it('initializes an existing alternative as saved', async () => {
        const view = document.createElement('mindfula11y-altless-file-reference');
        view.recordEditLink = '/edit/1';
        view.alternative = 'A workshop participant using a laptop';
        document.body.append(view);
        await view.updateComplete;

        expect(view.renderRoot.querySelector<HTMLTextAreaElement>('textarea')?.value).toBe(
            'A workshop participant using a laptop',
        );
        expect(view.renderRoot.querySelector('.actions button:last-child')?.getAttribute('aria-disabled')).toBe('true');
    });

    it('identifies a decorative reference when editing is unavailable', async () => {
        const view = await mount(false);

        expect(view.renderRoot.querySelector('.decorative-state')?.textContent).toBe(
            'mindfula11y.altText.decorative.label',
        );
        expect(view.renderRoot.querySelector('input')).toBeNull();
        expect(view.renderRoot.querySelector('button')).toBeNull();
    });

    it('shows an existing alternative when editing is unavailable', async () => {
        const view = document.createElement('mindfula11y-altless-file-reference');
        view.alternative = 'A workshop participant using a laptop';
        document.body.append(view);
        await view.updateComplete;

        expect(view.renderRoot.querySelector('.alternative-readonly dt')?.textContent).toBe(
            'mindfula11y.altText.altLabel',
        );
        expect(view.renderRoot.querySelector('.alternative-readonly dd')?.textContent).toBe(
            'A workshop participant using a laptop',
        );
        expect(view.renderRoot.querySelector('textarea')).toBeNull();
    });
});
