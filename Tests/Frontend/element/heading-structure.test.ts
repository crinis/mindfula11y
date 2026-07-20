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

import type { HeadingStructure } from '../../../Resources/Private/Source/element/heading-structure/heading-structure.js';
import '../../../Resources/Private/Source/element/heading-structure/heading-structure.js';
import type { HeadingNode, StructureError } from '../../../Resources/Private/Source/lib/structure/types.js';
import type { RecordReference } from '../../../Resources/Private/Source/lib/types.js';

const makeError = (key: string, nodeId: string | null): StructureError => ({
    key,
    severity: 'moderate',
    nodeId,
    viewports: ['desktop'],
});

const makeNode = (id: string, over: Partial<HeadingNode> = {}): HeadingNode => ({
    id,
    documentOrder: 0,
    kind: 'heading',
    level: 2,
    label: 'Section',
    availableTypes: {},
    availableChildTypes: {},
    record: null,
    childTypeRecord: null,
    relationId: '',
    relation: null,
    skippedLevels: 0,
    viewports: ['desktop'],
    errors: [],
    children: [],
    ...over,
});

const makeRecord = (columnName: string): RecordReference => ({
    tableName: 'tt_content',
    columnName,
    uid: 1,
    editLink: '/edit/1',
    storedValue: 'h2',
});

const mount = async (nodes: HeadingNode[], pageErrors: StructureError[] = []): Promise<HeadingStructure> => {
    const view = document.createElement('mindfula11y-heading-structure');
    view.nodes = nodes;
    view.pageErrors = pageErrors;
    document.body.append(view);
    await view.updateComplete;
    return view;
};

describe('HeadingStructure', () => {
    afterEach(() => {
        document.body.replaceChildren();
    });

    it('renders page-level findings as issue rows inside the heading list', async () => {
        const pageError = makeError('mindfula11y.structure.headings.error.missingH1', null);
        const view = await mount([makeNode('heading-1')], [pageError]);

        const issue = view.renderRoot.querySelector<HTMLElement>('[data-scope="page"]');

        expect(issue).not.toBeNull();
        expect(issue?.closest('li[data-issue-kind="page"]')?.parentElement?.matches('ol.tree')).toBe(true);
        expect(view.renderRoot.querySelector('.view > [data-scope="page"]')).toBeNull();
    });

    it('keeps node findings inside their affected list item', async () => {
        const nodeError = makeError('mindfula11y.structure.headings.error.emptyHeadings', 'heading-1');
        const view = await mount([makeNode('heading-1', { errors: [nodeError] })]);

        const issue = view.renderRoot.querySelector<HTMLElement>('[data-scope="node"]');

        expect(issue?.closest('.row-issues')?.parentElement?.matches('.row')).toBe(true);
        expect(issue?.closest('li.node')).not.toBeNull();
    });

    it('notes a container that anchors no headings, and drops the note once one derives from it', async () => {
        const container = (): HeadingNode =>
            makeNode('container-1', { kind: 'container', label: '', relationId: 'rel-container' });
        const noteTexts = (view: HeadingStructure): string[] =>
            Array.from(view.renderRoot.querySelectorAll('[data-node-id="container-1"] .text .note')).map(
                (note) => note.textContent ?? '',
            );

        const alone = await mount([container()]);
        const aloneText = alone.renderRoot.querySelector('[data-node-id="container-1"] .text');
        expect(aloneText?.hasAttribute('data-empty')).toBe(true);
        expect(noteTexts(alone)).toEqual([
            'mindfula11y.structure.headings.notInStructure',
            'mindfula11y.structure.headings.container.empty',
        ]);
        document.body.replaceChildren();

        const anchored = await mount([
            container(),
            makeNode('heading-1', {
                level: 3,
                relation: { kind: 'ancestor', targetRelationId: 'rel-container' },
            }),
        ]);
        // The shared non-heading state note stays; only the anchors-nothing
        // hint disappears once a heading derives from the container.
        expect(noteTexts(anchored)).toEqual(['mindfula11y.structure.headings.notInStructure']);
    });

    it('renders a demoted element as an editable row whose state note is announced with the select', async () => {
        const view = await mount([
            makeNode('demoted-1', {
                kind: 'demoted',
                level: 0,
                label: 'Former heading',
                record: {
                    tableName: 'tt_content',
                    columnName: 'tx_mindfula11y_headingtype',
                    uid: 1,
                    editLink: '/edit/1',
                    storedValue: 'p',
                },
                availableTypes: { h2: 'Level 2 (H2)', p: 'Paragraph — not a heading' },
            }),
        ]);

        const row = view.renderRoot.querySelector<HTMLElement>('[data-node-id="demoted-1"]');
        expect(row?.hasAttribute('data-demoted')).toBe(true);
        const text = row?.querySelector('.text');
        // The demoted state rides on text: the label keeps its normal styling
        // and the note reads in the row's flow for screen-reader users.
        expect(text?.hasAttribute('data-empty')).toBe(false);
        expect(text?.textContent).toContain('Former heading');
        const note = text?.querySelector('.note');
        expect(note?.id).toBe('structure-note-demoted-1');
        expect(note?.textContent).toContain('mindfula11y.structure.headings.notInStructure');
        // The select announces the note too, and its value ("Paragraph — not a
        // heading") names the state; changing it promotes right from the module.
        const select = row?.querySelector<HTMLSelectElement>('select.level');
        expect(select?.getAttribute('aria-describedby')).toBe('structure-note-demoted-1');
        expect(select?.value).toBe('p');
    });

    it('composes the container note with finding cues in the level select description', async () => {
        const containerError = makeError('mindfula11y.structure.headings.error.skippedLevel', 'container-1');
        const view = await mount([
            makeNode('container-1', {
                kind: 'container',
                label: '',
                level: 2,
                relationId: 'rel-container',
                record: {
                    tableName: 'tt_content',
                    columnName: 'tx_mindfula11y_headingtype',
                    uid: 1,
                    editLink: '/edit/1',
                    storedValue: 'h2',
                },
                availableTypes: { h2: 'Level 2 (H2)' },
                errors: [containerError],
            }),
        ]);

        const select = view.renderRoot.querySelector('[data-node-id="container-1"] select.level');
        expect(select?.getAttribute('aria-describedby')).toBe('structure-note-container-1 issue-container-1');
    });

    it('shows the stored type label on a read-only demoted chip instead of a bare dash', async () => {
        const view = await mount([
            makeNode('demoted-1', {
                kind: 'demoted',
                level: 0,
                label: 'Former heading',
                record: {
                    tableName: 'tt_content',
                    columnName: 'tx_mindfula11y_headingtype',
                    uid: 1,
                    editLink: '',
                    storedValue: 'p',
                },
            }),
        ]);

        const chip = view.renderRoot.querySelector('[data-node-id="demoted-1"] .level[data-locked]');
        expect(chip?.textContent).toContain('mindfula11y.structure.headings.level.p');
    });

    it('names the rendered type on a record-less relation-derived demoted row', async () => {
        // Derived descendants/siblings carry no record coordinates; the type
        // preserved from the demoted discriminator must label the chip.
        const view = await mount([
            makeNode('demoted-1', {
                kind: 'demoted',
                level: 0,
                label: 'Derived paragraph',
                nonHeadingType: 'p',
                relation: { kind: 'ancestor', targetRelationId: 'absent' },
            }),
        ]);

        const chip = view.renderRoot.querySelector('[data-node-id="demoted-1"] .level[data-relation]');
        expect(chip?.textContent).toContain('mindfula11y.structure.headings.level.p');
        expect(chip?.textContent).not.toContain('—');
    });

    it('keeps the empty note on an earlier duplicate publisher that nothing resolves to', async () => {
        // Relations bind to the nearest preceding publisher of a duplicated id
        // (shortcut records): the deriving heading after the second container
        // anchors that occurrence only, so the first still reports itself empty.
        const container = (id: string, documentOrder: number): HeadingNode =>
            makeNode(id, { kind: 'container', label: '', relationId: 'dup', documentOrder });
        const view = await mount([
            container('container-1', 0),
            container('container-2', 1),
            makeNode('heading-1', {
                documentOrder: 2,
                level: 3,
                relation: { kind: 'ancestor', targetRelationId: 'dup' },
            }),
        ]);

        const notes = (nodeId: string): string[] =>
            Array.from(view.renderRoot.querySelectorAll(`[data-node-id="${nodeId}"] .text .note`)).map(
                (note) => note.textContent ?? '',
            );
        expect(notes('container-1')).toContain('mindfula11y.structure.headings.container.empty');
        expect(notes('container-2')).not.toContain('mindfula11y.structure.headings.container.empty');
    });

    it('uses the shared issue component for missing levels and hidden-container errors', async () => {
        const skippedError = makeError('mindfula11y.structure.headings.error.skippedLevel', 'heading-1');
        const containerError = makeError('mindfula11y.structure.headings.error.skippedLevel', 'container-1');
        const view = await mount([
            makeNode('heading-1', { level: 3, skippedLevels: 1, errors: [skippedError] }),
            makeNode('container-1', {
                kind: 'container',
                level: 2,
                label: '',
                errors: [containerError],
            }),
        ]);

        const missingLevelIssue = view.renderRoot.querySelector(
            '[data-issue-kind="missing-level"] .row-issues > .notice.issue',
        );
        const containerIssue = view.renderRoot.querySelector(
            '[data-node-id="container-1"] .row-issues > .notice.issue',
        );

        expect(missingLevelIssue).not.toBeNull();
        expect(containerIssue).not.toBeNull();
        expect(missingLevelIssue?.getAttribute('data-state')).toBe(containerIssue?.getAttribute('data-state'));
        expect(missingLevelIssue?.getAttribute('data-variant')).toBe(containerIssue?.getAttribute('data-variant'));
        expect(missingLevelIssue?.textContent).toContain('mindfula11y.structure.headings.error.skippedLevel.inline: 2');
        expect(missingLevelIssue?.querySelector('.viewports')).not.toBeNull();
        expect(view.renderRoot.querySelector('[data-issue-kind="missing-level"] [data-missing]')).toBeNull();
    });

    it('renders ancestor and sibling levels as explicitly inherited instead of locally editable', async () => {
        const source = makeNode('source', {
            relationId: 'source',
            record: makeRecord('header_layout'),
            availableTypes: { h2: 'H2' },
        });
        const descendant = makeNode('descendant', {
            level: 3,
            relation: { kind: 'ancestor', targetRelationId: 'source' },
            // Even unexpected local metadata must not turn a derived level into a select.
            record: makeRecord('header_layout'),
            availableTypes: { h3: 'H3' },
        });
        const sibling = makeNode('sibling', {
            relation: { kind: 'sibling', targetRelationId: 'source' },
        });
        const view = await mount([source, descendant, sibling]);

        for (const [kind, labelKey] of [
            ['ancestor', 'mindfula11y.structure.headings.relation.descendant'],
            ['sibling', 'mindfula11y.structure.headings.relation.sibling'],
        ] as const) {
            const control = view.renderRoot.querySelector<HTMLElement>(`[data-relation-kind="${kind}"]`);

            expect(control?.tagName).toBe('BUTTON');
            expect(control?.querySelector('select')).toBeNull();
            expect(control?.textContent).toContain(labelKey);
            expect(control?.textContent).toContain('mindfula11y.structure.headings.relation.readonly');
            expect(control?.textContent).toContain('mindfula11y.structure.headings.relation.jump');
            expect(control?.getAttribute('aria-label')).toBeNull();
            expect(control?.querySelector('typo3-backend-icon')?.getAttribute('aria-hidden')).toBe('true');
        }
    });

    it('jumps descendants to the headings-inside control and siblings to the source level', async () => {
        const source = makeNode('source', {
            relationId: 'source',
            record: makeRecord('header_layout'),
            availableTypes: { h2: 'H2' },
            childTypeRecord: makeRecord('tx_mindfula11y_childheadingtype'),
            availableChildTypes: { h3: 'H3' },
        });
        const descendant = makeNode('descendant', {
            level: 3,
            relation: { kind: 'ancestor', targetRelationId: 'source' },
        });
        const sibling = makeNode('sibling', {
            relation: { kind: 'sibling', targetRelationId: 'source' },
        });
        const view = await mount([source, descendant, sibling]);
        const levelControl = view.renderRoot.querySelector<HTMLElement>(
            '[data-node-id="source"] [data-control="level"]',
        );
        const childLevelControl = view.renderRoot.querySelector<HTMLElement>(
            '[data-node-id="source"] [data-control="child-level"]',
        );
        const levelFocus = vi.spyOn(levelControl as HTMLElement, 'focus');
        const childLevelFocus = vi.spyOn(childLevelControl as HTMLElement, 'focus');

        view.renderRoot.querySelector<HTMLElement>('[data-relation-kind="ancestor"]')?.click();
        expect(childLevelFocus).toHaveBeenCalledOnce();
        expect(levelFocus).not.toHaveBeenCalled();

        view.renderRoot.querySelector<HTMLElement>('[data-relation-kind="sibling"]')?.click();
        expect(levelFocus).toHaveBeenCalledOnce();
    });

    it('focuses a labelled native list item when the relation source has no available control', async () => {
        const source = makeNode('source', {
            label: 'Source heading',
            relationId: 'source',
        });
        const descendant = makeNode('descendant', {
            level: 3,
            relation: { kind: 'ancestor', targetRelationId: 'source' },
        });
        const view = await mount([source, descendant]);
        const sourceRow = view.renderRoot.querySelector<HTMLElement>('[data-node-id="source"]');
        const sourceItem = sourceRow?.closest<HTMLElement>('li') ?? null;
        const itemFocus = vi.spyOn(sourceItem as HTMLElement, 'focus');
        const rowFocus = vi.spyOn(sourceRow as HTMLElement, 'focus');

        view.renderRoot.querySelector<HTMLElement>('[data-relation-kind="ancestor"]')?.click();

        const labelId = sourceItem?.getAttribute('aria-labelledby') ?? '';
        const label = view.renderRoot.querySelector<HTMLElement>(`#${CSS.escape(labelId)}`);
        expect(sourceItem?.tagName).toBe('LI');
        expect(itemFocus).toHaveBeenCalledOnce();
        expect(rowFocus).not.toHaveBeenCalled();
        expect(sourceItem?.getAttribute('tabindex')).toBe('-1');
        expect(labelId).toBe('heading-row-label-source');
        expect(label?.textContent).toContain('mindfula11y.structure.headings.level.h2');
        expect(label?.textContent).toContain('Source heading');
        expect(label?.textContent).toContain('mindfula11y.structure.headings.edit.locked');

        sourceItem?.dispatchEvent(new Event('blur'));
        expect(sourceItem?.getAttribute('tabindex')).toBeNull();
        expect(sourceItem?.getAttribute('aria-labelledby')).toBeNull();
    });

    it('does not focus an unrelated source control when the relation-owning control is unavailable', async () => {
        const source = makeNode('source', {
            label: 'Source heading',
            relationId: 'source',
            record: makeRecord('header_layout'),
            availableTypes: { h2: 'H2' },
        });
        const descendant = makeNode('descendant', {
            level: 3,
            relation: { kind: 'ancestor', targetRelationId: 'source' },
        });
        const view = await mount([source, descendant]);
        const sourceRow = view.renderRoot.querySelector<HTMLElement>('[data-node-id="source"]');
        const sourceItem = sourceRow?.closest<HTMLElement>('li') ?? null;
        const ownLevel = sourceRow?.querySelector<HTMLElement>('[data-control="level"]') ?? null;
        const itemFocus = vi.spyOn(sourceItem as HTMLElement, 'focus');
        const ownLevelFocus = vi.spyOn(ownLevel as HTMLElement, 'focus');

        view.renderRoot.querySelector<HTMLElement>('[data-relation-kind="ancestor"]')?.click();

        expect(itemFocus).toHaveBeenCalledOnce();
        expect(ownLevelFocus).not.toHaveBeenCalled();
        expect(sourceItem?.getAttribute('aria-labelledby')).toBe('heading-row-label-source');
    });

    it('explains an inherited level without rendering a dead-end button when its source is absent', async () => {
        const view = await mount([
            makeNode('orphan', {
                relation: { kind: 'ancestor', targetRelationId: 'absent' },
            }),
        ]);
        const control = view.renderRoot.querySelector<HTMLElement>('[data-relation-kind="ancestor"]');

        expect(control?.tagName).toBe('SPAN');
        expect(control?.textContent).toContain('mindfula11y.structure.headings.relation.descendant');
        expect(control?.textContent).toContain('mindfula11y.structure.headings.relation.readonly');
        expect(control?.textContent).not.toContain('mindfula11y.structure.headings.relation.jump');
        expect(control?.querySelector('typo3-backend-icon')?.getAttribute('identifier')).toBe('actions-lock');
    });
});
