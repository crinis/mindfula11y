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

import type { TemplateResult } from 'lit';
import { render } from 'lit';
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('@typo3/core/lit-helper.js', () => ({
    lll: (key: string): string => key,
}));
vi.mock('@typo3/backend/element/icon-element.js', () => ({}));
vi.mock('@typo3/backend/element/spinner-element.js', () => ({}));

import { renderSaveButton, renderSaveStatusRegion } from '../../../Resources/Private/Source/lib/status-render.js';

const mount = (template: TemplateResult): HTMLDivElement => {
    const container = document.createElement('div');
    render(template, container);
    document.body.append(container);
    return container;
};

describe('renderSaveButton', () => {
    afterEach(() => {
        document.body.replaceChildren();
    });

    it('renders the label and the save icon while idle', () => {
        const onClick = vi.fn();
        const container = mount(
            renderSaveButton({ saving: false, disabled: false, labelKey: 'mindfula11y.save', onClick }),
        );

        const button = container.querySelector('button');
        expect(button?.textContent?.trim()).toBe('mindfula11y.save');
        expect(button?.querySelector('typo3-backend-icon')?.getAttribute('identifier')).toBe('actions-save');
        expect(button?.querySelector('typo3-backend-spinner')).toBeNull();
        expect(button?.getAttribute('aria-disabled')).toBeNull();
    });

    it('swaps the icon for a spinner while saving', () => {
        const container = mount(
            renderSaveButton({ saving: true, disabled: true, labelKey: 'mindfula11y.save', onClick: vi.fn() }),
        );

        expect(container.querySelector('typo3-backend-spinner')).not.toBeNull();
        expect(container.querySelector('typo3-backend-icon')).toBeNull();
    });

    it('marks the button aria-disabled without using a real disabled attribute', () => {
        // A real `disabled` would strand keyboard focus on <body> for the
        // whole async save window — the button must stay focusable.
        const container = mount(
            renderSaveButton({ saving: false, disabled: true, labelKey: 'mindfula11y.save', onClick: vi.fn() }),
        );

        const button = container.querySelector('button');
        expect(button?.getAttribute('aria-disabled')).toBe('true');
        expect(button?.disabled).toBe(false);
    });

    it('invokes onClick when clicked', () => {
        const onClick = vi.fn();
        const container = mount(
            renderSaveButton({ saving: false, disabled: false, labelKey: 'mindfula11y.save', onClick }),
        );

        container.querySelector('button')?.dispatchEvent(new Event('click'));

        expect(onClick).toHaveBeenCalledTimes(1);
    });
});

describe('renderSaveStatusRegion', () => {
    afterEach(() => {
        document.body.replaceChildren();
    });

    it('renders nothing when there is no error and nothing was saved', () => {
        const container = mount(
            renderSaveStatusRegion({ error: null, saved: false, successLabelKey: 'mindfula11y.saved' }),
        );

        expect(container.querySelector('mindfula11y-notice')).toBeNull();
    });

    it('renders a success notice once saved', () => {
        const container = mount(
            renderSaveStatusRegion({ error: null, saved: true, successLabelKey: 'mindfula11y.saved' }),
        );

        const notice = container.querySelector('mindfula11y-notice');
        expect(notice?.getAttribute('state')).toBe('success');
        expect(notice?.textContent?.trim()).toBe('mindfula11y.saved');
    });

    it('renders a danger notice with the error title and description', () => {
        const container = mount(
            renderSaveStatusRegion({
                error: { title: 'Could not save changes', description: 'The change was not stored.' },
                saved: false,
                successLabelKey: 'mindfula11y.saved',
            }),
        );

        const notice = container.querySelector('mindfula11y-notice');
        expect(notice?.getAttribute('state')).toBe('danger');
        expect(notice?.textContent).toContain('Could not save changes');
        expect(notice?.textContent).toContain('The change was not stored.');
    });

    it('prioritizes the error notice over the success notice when both are set', () => {
        const container = mount(
            renderSaveStatusRegion({
                error: { title: 'Could not save changes', description: '' },
                saved: true,
                successLabelKey: 'mindfula11y.saved',
            }),
        );

        const notices = container.querySelectorAll('mindfula11y-notice');
        expect(notices).toHaveLength(1);
        expect(notices[0]?.getAttribute('state')).toBe('danger');
    });
});
