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

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const { generateAltTextMock, notificationErrorMock, notificationInfoMock, notificationSuccessMock } = vi.hoisted(
    () => ({
        generateAltTextMock: vi.fn(),
        notificationErrorMock: vi.fn(),
        notificationInfoMock: vi.fn(),
        notificationSuccessMock: vi.fn(),
    }),
);

vi.mock('@typo3/core/lit-helper.js', () => ({
    lll: (key: string): string => key,
}));
vi.mock('@typo3/backend/element/spinner-element.js', () => ({}));
vi.mock('@typo3/backend/notification.js', () => ({
    default: { error: notificationErrorMock, info: notificationInfoMock, success: notificationSuccessMock },
}));
vi.mock('@typo3/core/document-service.js', () => ({
    default: { ready: (): Promise<Document> => Promise.resolve(document) },
}));
vi.mock('@typo3/core/event/regular-event.js', () => ({
    default: class {
        constructor(
            private readonly eventName: string,
            private readonly callback: (event: Event) => void,
        ) {}

        bindTo(element: EventTarget): void {
            element.addEventListener(this.eventName, this.callback);
        }
    },
}));
vi.mock('../../../Resources/Private/Source/service/alt-text-api.js', () => {
    const module = {};
    Reflect.set(
        module,
        'AltTextApi',
        class {
            generateAltText = generateAltTextMock;
        },
    );
    return module;
});

import type { GenerateAltTextDemand } from '../../../Resources/Private/Source/service/alt-text-api.js';
import { GenerateAltTextControl } from '../../../Resources/Private/Source/service/generate-alt-text-control.js';

const demand: GenerateAltTextDemand = { signature: 'sig' };

/** Renders the FormEngine markup the control augments: the anchor plus the field's input. */
const mountField = (anchorContent: string): { control: HTMLAnchorElement; input: HTMLInputElement } => {
    document.body.innerHTML = `<a id="generate" href="#" data-item-name="data[sys_file_reference][1][alternative]">${anchorContent}</a>
        <input data-formengine-input-name="data[sys_file_reference][1][alternative]" value="">`;
    return {
        control: document.querySelector('#generate') as HTMLAnchorElement,
        input: document.querySelector('input') as HTMLInputElement,
    };
};

const settle = (): Promise<void> => new Promise((resolve) => setTimeout(resolve, 0));

describe('GenerateAltTextControl', () => {
    beforeEach(() => {
        generateAltTextMock.mockReset();
        notificationErrorMock.mockReset();
        notificationInfoMock.mockReset();
        notificationSuccessMock.mockReset();
    });

    afterEach(() => {
        document.body.replaceChildren();
    });

    it('writes the generated text into the input and restores the icon', async () => {
        generateAltTextMock.mockResolvedValue({ decorative: false, altText: 'A red bicycle' });
        const { control, input } = mountField('<span class="icon">icon</span>');
        new GenerateAltTextControl('#generate', demand);
        await settle();

        control.click();
        expect(control.querySelector('typo3-backend-spinner')).not.toBeNull();
        await settle();

        expect(input.value).toBe('A red bicycle');
        expect(control.innerHTML).toBe('<span class="icon">icon</span>');
        expect(control.hasAttribute('aria-busy')).toBe(false);
    });

    it('leaves the field alone and explains a decorative verdict', async () => {
        generateAltTextMock.mockResolvedValue({ decorative: true });
        const { control, input } = mountField('<span class="icon">icon</span>');
        input.value = 'Draft text';
        const change = vi.fn();
        input.addEventListener('change', change);
        new GenerateAltTextControl('#generate', demand);
        await settle();

        control.click();
        await settle();

        expect(input.value).toBe('Draft text');
        expect(change).not.toHaveBeenCalled();
        expect(notificationSuccessMock).not.toHaveBeenCalled();
        // Sticky (duration 0): the editor has to act on it, so it must not vanish unread.
        expect(notificationInfoMock).toHaveBeenCalledWith(
            'mindfula11y.altText.generate.decorative',
            'mindfula11y.altText.generate.decorative.description',
            0,
        );
        expect(control.innerHTML).toBe('<span class="icon">icon</span>');
    });

    it('restores anchor content that has no element child', async () => {
        generateAltTextMock.mockRejectedValue(new Error('boom'));
        const { control } = mountField('Generate');
        new GenerateAltTextControl('#generate', demand);
        await settle();

        control.click();
        await settle();

        expect(notificationErrorMock).toHaveBeenCalledTimes(1);
        expect(control.querySelector('typo3-backend-spinner')).toBeNull();
        expect(control.textContent).toBe('Generate');
    });

    it('restores every child node, not only the first element', async () => {
        generateAltTextMock.mockResolvedValue({ decorative: false, altText: 'Alt' });
        const { control } = mountField('<span class="icon">icon</span> <span class="label">Generate</span>');
        new GenerateAltTextControl('#generate', demand);
        await settle();

        control.click();
        await settle();

        expect(control.innerHTML).toBe('<span class="icon">icon</span> <span class="label">Generate</span>');
    });
});
