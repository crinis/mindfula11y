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

vi.mock('@typo3/core/lit-helper.js', () => ({
    lll: (key: string): string => key,
}));

const { processMock } = vi.hoisted(() => ({ processMock: vi.fn() }));
vi.mock('@typo3/backend/ajax-data-handler.js', () => ({
    default: { process: processMock },
}));
vi.mock('@typo3/core/ajax/ajax-request.js', () => ({
    default: class AjaxRequest {},
}));
vi.mock('@typo3/backend/element/icon-element.js', () => ({}));
vi.mock('@typo3/backend/element/spinner-element.js', () => ({}));

const { assessMock } = vi.hoisted(() => ({ assessMock: vi.fn() }));
vi.mock('../../../Resources/Private/Source/service/interactive-label-ai-review-api.js', () => ({
    // biome-ignore lint/style/useNamingConvention: matches the real InteractiveLabelAiReviewApi export name the component imports
    InteractiveLabelAiReviewApi: class {
        assess = assessMock;
    },
}));

import type { InteractiveLabelFinding } from '../../../Resources/Private/Source/element/interactive-label-finding/interactive-label-finding.js';
import '../../../Resources/Private/Source/element/interactive-label-finding/interactive-label-finding.js';

interface FindingFixture {
    table?: string;
    uid?: number;
    field?: string;
    value?: string;
    editable?: boolean;
    type?: string;
    target?: string;
    rule?: string;
    isRepeated?: boolean;
    occurrenceCount?: number;
    hasDifferentTargets?: boolean;
    distinctTargetCount?: number;
    aiReviewAvailable?: boolean;
    pageId?: number;
    pageTitle?: string;
    surroundingContext?: string;
    locale?: string;
}

const editableFinding = (overrides: FindingFixture = {}): FindingFixture => ({
    table: 'tt_content',
    uid: 100,
    field: 'header',
    value: 'weiter',
    editable: true,
    ...overrides,
});

const mount = async (finding: FindingFixture | null): Promise<InteractiveLabelFinding> => {
    const view = document.createElement('mindfula11y-interactive-label-finding') as InteractiveLabelFinding;
    view.finding = finding;
    document.body.append(view);
    await view.updateComplete;
    return view;
};

const saveButton = (view: InteractiveLabelFinding): HTMLButtonElement | null =>
    view.renderRoot.querySelector<HTMLButtonElement>('.actions button');

const aiReviewButton = (view: InteractiveLabelFinding): HTMLButtonElement | null =>
    view.renderRoot.querySelector<HTMLButtonElement>('.ai-review > button');

const aiReviewFinding = (overrides: FindingFixture = {}): FindingFixture =>
    editableFinding({
        type: 'button',
        target: '',
        rule: 'potentially_vague_interactive_label',
        aiReviewAvailable: true,
        pageId: 10,
        pageTitle: 'Test page',
        surroundingContext: '',
        locale: 'de',
        ...overrides,
    });

describe('InteractiveLabelFinding', () => {
    beforeEach(() => {
        processMock.mockReset();
        assessMock.mockReset();
    });

    afterEach(() => {
        document.body.replaceChildren();
    });

    it('renders nothing without a finding', async () => {
        const view = await mount(null);

        expect(view.renderRoot.textContent?.trim()).toBe('');
    });

    it('shows a read-only value and no controls when the finding is not editable', async () => {
        const view = await mount(editableFinding({ editable: false }));

        expect(view.renderRoot.querySelector('input')).toBeNull();
        expect(view.renderRoot.querySelector('button')).toBeNull();
        expect(view.renderRoot.querySelector('dl.editor dt')?.textContent).toBe('mindfula11y.findings.label');
        expect(view.renderRoot.querySelector('dl.editor dd')?.textContent).toBe('weiter');
    });

    it('shows nothing in place of the value when it is empty and not editable', async () => {
        const view = await mount(editableFinding({ editable: false, value: '' }));

        expect(view.renderRoot.querySelector('dl.editor')).toBeNull();
    });

    it('renders an editable input and a disabled save button for an unchanged value', async () => {
        const view = await mount(editableFinding());

        const input = view.renderRoot.querySelector<HTMLInputElement>('input');
        const button = saveButton(view);

        expect(input?.value).toBe('weiter');
        expect(view.renderRoot.querySelector('dl.editor')).toBeNull();
        expect(button?.getAttribute('aria-disabled')).toBe('true');
    });

    it('enables the save button once the value is edited', async () => {
        const view = await mount(editableFinding());
        const input = view.renderRoot.querySelector<HTMLInputElement>('input');
        expect(input).not.toBeNull();
        if (input === null) {
            return;
        }

        input.value = 'Add to basket';
        input.dispatchEvent(new Event('input'));
        await view.updateComplete;

        expect(saveButton(view)?.getAttribute('aria-disabled')).toBeNull();
    });

    it('saves the edited value through the DataHandler with the finding’s table, uid and field', async () => {
        processMock.mockResolvedValue({ hasErrors: false });
        const view = await mount(editableFinding({ table: 'tt_content', uid: 100, field: 'header' }));
        const input = view.renderRoot.querySelector<HTMLInputElement>('input');
        expect(input).not.toBeNull();
        if (input === null) {
            return;
        }

        input.value = 'Add to basket';
        input.dispatchEvent(new Event('input'));
        await view.updateComplete;
        saveButton(view)?.dispatchEvent(new Event('click'));
        await view.updateComplete;

        expect(processMock).toHaveBeenCalledWith({
            // biome-ignore lint/style/useNamingConvention: tt_content is the real DB table name, not an identifier to rename
            data: { tt_content: { 100: { header: 'Add to basket' } } },
        });
    });

    it('shows a success notice and re-disables the save button after a successful save', async () => {
        processMock.mockResolvedValue({ hasErrors: false });
        const view = await mount(editableFinding());
        const input = view.renderRoot.querySelector<HTMLInputElement>('input');
        expect(input).not.toBeNull();
        if (input === null) {
            return;
        }

        input.value = 'Add to basket';
        input.dispatchEvent(new Event('input'));
        await view.updateComplete;
        saveButton(view)?.dispatchEvent(new Event('click'));
        await view.updateComplete;
        // handleSave() awaits the mocked promise before flipping state.
        await new Promise((resolve) => setTimeout(resolve, 0));
        await view.updateComplete;

        expect(view.renderRoot.querySelector('.status-region [state="success"]')).not.toBeNull();
        expect(saveButton(view)?.getAttribute('aria-disabled')).toBe('true');
    });

    it('shows a danger notice when the DataHandler reports an error and leaves the button enabled', async () => {
        processMock.mockResolvedValue({ hasErrors: true });
        const view = await mount(editableFinding());
        const input = view.renderRoot.querySelector<HTMLInputElement>('input');
        expect(input).not.toBeNull();
        if (input === null) {
            return;
        }

        input.value = 'Add to basket';
        input.dispatchEvent(new Event('input'));
        await view.updateComplete;
        saveButton(view)?.dispatchEvent(new Event('click'));
        await view.updateComplete;
        await new Promise((resolve) => setTimeout(resolve, 0));
        await view.updateComplete;

        expect(view.renderRoot.querySelector('.status-region [state="danger"]')).not.toBeNull();
        expect(saveButton(view)?.getAttribute('aria-disabled')).toBeNull();
    });

    it('does not call the DataHandler when clicking save without having changed the value', async () => {
        processMock.mockResolvedValue({ hasErrors: false });
        const view = await mount(editableFinding());

        saveButton(view)?.dispatchEvent(new Event('click'));
        await view.updateComplete;

        expect(processMock).not.toHaveBeenCalled();
    });

    it('renders the primary rule notice independently of the editable state', async () => {
        const view = await mount(editableFinding({ editable: false, rule: 'symbol_only_label' }));

        expect(view.renderRoot.querySelector('mindfula11y-notice')).not.toBeNull();
    });

    it('renders the repeated-label notice as secondary when it is not the primary rule', async () => {
        const view = await mount(
            editableFinding({
                rule: 'potentially_vague_interactive_label',
                isRepeated: true,
                occurrenceCount: 3,
            }),
        );

        const notices = view.renderRoot.querySelectorAll('mindfula11y-notice');
        expect(notices).toHaveLength(2);
        expect(notices[1]?.getAttribute('count')).toBe('3');
    });

    describe('AI context review', () => {
        it('does not render the AI review button when the finding does not offer it', async () => {
            const view = await mount(editableFinding());

            expect(aiReviewButton(view)).toBeNull();
        });

        it('renders the AI review button when the finding offers it', async () => {
            const view = await mount(aiReviewFinding());

            expect(aiReviewButton(view)).not.toBeNull();
        });

        it('requests an assessment with the finding’s context and the current input value on click', async () => {
            assessMock.mockResolvedValue({ assessment: 'likely_clear', reason: 'Clear enough.', suggestedLabel: null });
            const view = await mount(aiReviewFinding({ value: 'weiter' }));

            aiReviewButton(view)?.dispatchEvent(new Event('click'));
            await view.updateComplete;
            await new Promise((resolve) => setTimeout(resolve, 0));

            expect(assessMock).toHaveBeenCalledWith({
                pageId: 10,
                label: 'weiter',
                elementType: 'button',
                target: '',
                rule: 'potentially_vague_interactive_label',
                pageTitle: 'Test page',
                surroundingContext: '',
                locale: 'de',
            });
        });

        it('shows the AI opinion, its disclaimer, and an apply-suggestion action', async () => {
            assessMock.mockResolvedValue({
                assessment: 'likely_unclear',
                reason: 'The label does not describe the destination.',
                suggestedLabel: 'Informationen zur Bewerbung',
            });
            const view = await mount(aiReviewFinding());

            aiReviewButton(view)?.dispatchEvent(new Event('click'));
            await view.updateComplete;
            await new Promise((resolve) => setTimeout(resolve, 0));
            await view.updateComplete;

            const assessmentNotice = view.renderRoot.querySelector('.ai-assessment');
            expect(assessmentNotice?.textContent).toContain('The label does not describe the destination.');
            expect(assessmentNotice?.textContent).toContain('mindfula11y.findings.aiReview.disclaimer');
            expect(assessmentNotice?.querySelector('button')?.textContent).toContain('Informationen zur Bewerbung');
        });

        it('applies the suggested label into the (unsaved) input without saving it', async () => {
            processMock.mockResolvedValue({ hasErrors: false });
            assessMock.mockResolvedValue({
                assessment: 'likely_unclear',
                reason: 'irrelevant',
                suggestedLabel: 'Informationen zur Bewerbung',
            });
            const view = await mount(aiReviewFinding());

            aiReviewButton(view)?.dispatchEvent(new Event('click'));
            await view.updateComplete;
            await new Promise((resolve) => setTimeout(resolve, 0));
            await view.updateComplete;
            view.renderRoot
                .querySelector<HTMLButtonElement>('.ai-assessment button')
                ?.dispatchEvent(new Event('click'));
            await view.updateComplete;

            expect(view.renderRoot.querySelector<HTMLInputElement>('input')?.value).toBe('Informationen zur Bewerbung');
            expect(saveButton(view)?.getAttribute('aria-disabled')).toBeNull();
            expect(processMock).not.toHaveBeenCalled();
        });

        it('shows a danger notice when the AI review request fails', async () => {
            assessMock.mockRejectedValue(new Error('network error'));
            const view = await mount(aiReviewFinding());

            aiReviewButton(view)?.dispatchEvent(new Event('click'));
            await view.updateComplete;
            await new Promise((resolve) => setTimeout(resolve, 0));
            await view.updateComplete;

            expect(view.renderRoot.querySelector('.ai-review [state="danger"]')).not.toBeNull();
        });

        it('clears a previous AI opinion once the label is edited again', async () => {
            assessMock.mockResolvedValue({ assessment: 'likely_clear', reason: 'ok', suggestedLabel: null });
            const view = await mount(aiReviewFinding());

            aiReviewButton(view)?.dispatchEvent(new Event('click'));
            await view.updateComplete;
            await new Promise((resolve) => setTimeout(resolve, 0));
            await view.updateComplete;
            expect(view.renderRoot.querySelector('.ai-assessment')).not.toBeNull();

            const input = view.renderRoot.querySelector<HTMLInputElement>('input');
            expect(input).not.toBeNull();
            if (input === null) {
                return;
            }

            input.value = 'a different label';
            input.dispatchEvent(new Event('input'));
            await view.updateComplete;

            expect(view.renderRoot.querySelector('.ai-assessment')).toBeNull();
        });
    });
});
