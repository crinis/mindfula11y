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
import { html } from 'lit';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

// The component's runtime-only imports: @typo3/* modules come from the TYPO3
// importmap and don't exist under node_modules, so each is mocked per-file
// (the project convention — no global vitest aliases).
const notificationError = vi.hoisted(() => vi.fn());
const updateField = vi.hoisted(() => vi.fn<(record: unknown, value: string) => Promise<void>>());

vi.mock('@typo3/core/lit-helper.js', () => ({
    lll: (key: string): string => key,
}));
vi.mock('@typo3/backend/notification.js', () => ({
    default: { error: notificationError, success: vi.fn() },
}));
// Side-effect element registrations — the templates only render the tags.
vi.mock('@typo3/backend/element/icon-element.js', () => ({}));
vi.mock('@typo3/backend/element/spinner-element.js', () => ({}));
vi.mock('../../../Resources/Private/Source/service/record-api.js', () => {
    const module = {};
    Reflect.set(
        module,
        'RecordApi',
        class {
            updateField = updateField;
        },
    );
    return module;
});

import type { StructureViewNode } from '../../../Resources/Private/Source/element/structure-view/structure-view.js';
import { StructureView } from '../../../Resources/Private/Source/element/structure-view/structure-view.js';
import { type StructureError, StructureErrorSeverity } from '../../../Resources/Private/Source/lib/structure/types.js';
import type { RecordReference } from '../../../Resources/Private/Source/lib/types.js';

interface TestNode extends StructureViewNode<TestNode> {
    /** Stored value backing the node's select — what `live()` reverts to. */
    value: string;
}

/**
 * Minimal concrete subclass exercising the base class end to end: one row per
 * node carrying the shared value select, busy spinner, issue list and edit
 * link — the same building blocks the heading tree and landmark map use.
 */
class TestStructureView extends StructureView<TestNode> {
    protected override readonly controlSelector: string = '[data-control="value"]';
    protected override readonly emptyLabelKey: string = 'mindfula11y.structure.test.empty';
    protected override readonly labelPrefix: string = 'mindfula11y.structure.test';

    protected override renderNodes(nodes: TestNode[]): TemplateResult {
        return html`<ul>
            ${nodes.map(
                (node) => html`<li data-node-id=${node.id}>
                    ${this.renderValueSelect(node, {
                        id: `value-${node.id}`,
                        className: 'value',
                        ariaLabel: `Value of ${node.id}`,
                        currentValue: node.value,
                        options: { h1: 'Heading 1', h2: 'Heading 2', h3: 'Heading 3' },
                    })}
                    ${this.renderBusySpinner(node)}
                    ${this.hasRecord(node) ? this.renderEditLink(node, node.id) : ''}
                    ${this.renderNodeIssues(node)} ${node.children.length > 0 ? this.renderNodes(node.children) : ''}
                </li>`,
            )}
        </ul>`;
    }
}

if (customElements.get('mindfula11y-test-structure-view') === undefined) {
    customElements.define('mindfula11y-test-structure-view', TestStructureView);
}

declare global {
    interface HTMLElementTagNameMap {
        'mindfula11y-test-structure-view': TestStructureView;
    }
}

const makeRecord = (over: Partial<RecordReference> = {}): RecordReference => ({
    tableName: 'tt_content',
    columnName: 'header_type',
    uid: 11,
    editLink: '/edit/tt_content/11',
    ...over,
});

const makeNode = (id: string, over: Partial<TestNode> = {}): TestNode => ({
    id,
    record: makeRecord(),
    value: 'h2',
    viewports: ['desktop'],
    errors: [],
    children: [],
    ...over,
});

const makeError = (key: string, nodeId: string | null): StructureError => ({
    key,
    severity: StructureErrorSeverity.Error,
    nodeId,
    viewports: ['desktop'],
});

const mount = async (nodes: TestNode[], pageErrors: StructureError[] = []): Promise<TestStructureView> => {
    const view = document.createElement('mindfula11y-test-structure-view');
    view.nodes = nodes;
    view.pageErrors = pageErrors;
    document.body.append(view);
    await view.updateComplete;
    return view;
};

const querySelect = (view: TestStructureView, nodeId: string): HTMLSelectElement => {
    const select = view.renderRoot.querySelector<HTMLSelectElement>(
        `[data-node-id="${nodeId}"] [data-control="value"]`,
    );
    if (select === null) {
        throw new Error(`No value select rendered for node ${nodeId}`);
    }
    return select;
};

/** Simulates a user picking a new option (Lit's `@change` listens on the select itself). */
const changeValue = (select: HTMLSelectElement, value: string): void => {
    select.value = value;
    select.dispatchEvent(new Event('change'));
};

/** Macrotask hop: lets the in-flight save settle and Lit re-render. */
const tick = (): Promise<void> => new Promise((resolve) => setTimeout(resolve, 0));

interface DeferredSave {
    resolve: () => void;
    reject: (error: Error) => void;
}

/** Makes the next `updateField` call hang until the test settles it. */
const deferSave = (): DeferredSave => {
    const deferred: DeferredSave = {
        resolve: (): void => undefined,
        reject: (): void => undefined,
    };
    updateField.mockImplementation(
        () =>
            new Promise<void>((resolve, reject) => {
                deferred.resolve = resolve;
                deferred.reject = reject;
            }),
    );
    return deferred;
};

type StructureChangedEvent = HTMLElementEventMap['mindfula11y:structure:changed'];

const captureChangeEvents = (view: TestStructureView): StructureChangedEvent[] => {
    const events: StructureChangedEvent[] = [];
    view.addEventListener('mindfula11y:structure:changed', (event) => events.push(event));
    return events;
};

describe('StructureView', () => {
    beforeEach(() => {
        updateField.mockReset();
        notificationError.mockReset();
    });

    afterEach(() => {
        document.body.replaceChildren();
    });

    it('persists a changed value through RecordApi and dispatches mindfula11y:structure:changed', async () => {
        updateField.mockResolvedValue(undefined);
        const node = makeNode('n1');
        const view = await mount([node]);
        const events = captureChangeEvents(view);

        changeValue(querySelect(view, 'n1'), 'h3');
        await tick();

        expect(updateField).toHaveBeenCalledTimes(1);
        expect(updateField).toHaveBeenCalledWith(node.record, 'h3');
        expect(events).toHaveLength(1);
        const event = events[0];
        expect(event?.bubbles).toBe(true);
        expect(event?.composed).toBe(true);
        expect(event?.detail).toEqual({
            nodeId: 'n1',
            tableName: 'tt_content',
            uid: 11,
            columnName: 'header_type',
            value: 'h3',
        });
    });

    it('saves against the explicit record option instead of node.record when given', async () => {
        updateField.mockResolvedValue(undefined);
        const childTypeRecord = makeRecord({ uid: 42, columnName: 'tx_child_type' });
        class ChildTargetView extends TestStructureView {
            protected override renderNodes(nodes: TestNode[]): TemplateResult {
                return html`<ul>
                    ${nodes.map(
                        (node) => html`<li data-node-id=${node.id}>
                            ${this.renderValueSelect(node, {
                                id: `value-${node.id}`,
                                className: 'value',
                                ariaLabel: `Value of ${node.id}`,
                                currentValue: node.value,
                                options: { h1: 'Heading 1', h2: 'Heading 2' },
                                record: childTypeRecord,
                            })}
                        </li>`,
                    )}
                </ul>`;
            }
        }
        if (customElements.get('mindfula11y-test-structure-view-child') === undefined) {
            customElements.define('mindfula11y-test-structure-view-child', ChildTargetView);
        }
        const view = document.createElement('mindfula11y-test-structure-view-child') as ChildTargetView;
        view.nodes = [makeNode('n1')];
        document.body.append(view);
        await view.updateComplete;
        const events = captureChangeEvents(view);

        changeValue(querySelect(view, 'n1'), 'h1');
        await tick();

        expect(updateField).toHaveBeenCalledWith(childTypeRecord, 'h1');
        expect(events[0]?.detail.uid).toBe(42);
        expect(events[0]?.detail.columnName).toBe('tx_child_type');
    });

    it('does not save when the selected value equals the stored value', async () => {
        const view = await mount([makeNode('n1')]);

        changeValue(querySelect(view, 'n1'), 'h2');
        await tick();

        expect(updateField).not.toHaveBeenCalled();
    });

    it('reverts the select to the stored value via live() and shows the error toast when the save fails', async () => {
        updateField.mockRejectedValue(new Error('DataHandler said no'));
        const view = await mount([makeNode('n1')]);
        const events = captureChangeEvents(view);
        const select = querySelect(view, 'n1');

        changeValue(select, 'h3');
        expect(select.value).toBe('h3');
        await tick();
        await view.updateComplete;

        // busyNodeId resetting in the finally re-renders the row; the live()
        // binding pushes the stored value back into the same select element.
        expect(querySelect(view, 'n1')).toBe(select);
        expect(select.value).toBe('h2');
        expect(events).toHaveLength(0);
        expect(notificationError).toHaveBeenCalledWith(
            'mindfula11y.structure.test.error.store',
            'mindfula11y.structure.test.error.store.description',
        );
    });

    it('ignores a change made while a save is in flight and reverts it on completion', async () => {
        const deferred = deferSave();
        const view = await mount([makeNode('n1')]);
        const events = captureChangeEvents(view);
        const select = querySelect(view, 'n1');

        changeValue(select, 'h3');
        await view.updateComplete;
        // The row shows the busy spinner while the save is pending …
        expect(view.renderRoot.querySelector('typo3-backend-spinner')).not.toBeNull();
        // … and the select stays enabled (disabling would blur keyboard users).
        expect(select.disabled).toBe(false);

        changeValue(select, 'h1');
        await tick();
        expect(updateField).toHaveBeenCalledTimes(1);

        deferred.resolve();
        await tick();
        await view.updateComplete;

        // Only the first change was saved and reported; the dropped second
        // change is visually reverted to the stored value by live().
        expect(events).toHaveLength(1);
        expect(events[0]?.detail.value).toBe('h3');
        expect(select.value).toBe('h2');
        expect(view.renderRoot.querySelector('typo3-backend-spinner')).toBeNull();
    });

    it('saves a change on a different row while another row is mid-save', async () => {
        // The re-entry guard is per node: only the row whose save is in
        // flight drops repeat changes. A concurrent edit on ANOTHER row must
        // persist normally instead of being silently reverted.
        const deferred = deferSave();
        const nodeA = makeNode('n1');
        const nodeB = makeNode('n2', { record: makeRecord({ uid: 22 }) });
        const view = await mount([nodeA, nodeB]);
        const events = captureChangeEvents(view);

        changeValue(querySelect(view, 'n1'), 'h3');
        await view.updateComplete;

        updateField.mockResolvedValue(undefined);
        changeValue(querySelect(view, 'n2'), 'h1');
        await tick();

        expect(updateField).toHaveBeenCalledTimes(2);
        expect(updateField).toHaveBeenLastCalledWith(nodeB.record, 'h1');

        deferred.resolve();
        await tick();
        await view.updateComplete;

        expect(events).toHaveLength(2);
        expect(events.map((event) => event.detail.nodeId).sort()).toEqual(['n1', 'n2']);
        // The select reverts to the (stale) stored node value via live() until
        // the container delivers refreshed nodes — as it does in production in
        // response to the change events asserted above.
        expect(querySelect(view, 'n2').value).toBe('h2');
    });

    it('restores focus to the saved control once the container delivers new nodes', async () => {
        updateField.mockResolvedValue(undefined);
        const view = await mount([makeNode('n1'), makeNode('n2')]);
        const select = querySelect(view, 'n1');
        select.focus();

        changeValue(select, 'h3');
        await tick();

        // The container reacts to mindfula11y:structure:changed by
        // re-analyzing and assigning a fresh nodes array.
        view.nodes = [makeNode('n1', { value: 'h3' }), makeNode('n2')];
        await view.updateComplete;

        expect(view.shadowRoot?.activeElement).toBe(querySelect(view, 'n1'));
    });

    it('focusControl focuses the row control and flashes the highlight', async () => {
        const view = await mount([makeNode('n1'), makeNode('n2')]);

        view.focusControl('n2');

        expect(view.shadowRoot?.activeElement).toBe(querySelect(view, 'n2'));
        const row = view.renderRoot.querySelector('[data-node-id="n2"]');
        expect(row?.hasAttribute('data-highlight')).toBe(true);
    });

    it('focusFirstIssue focuses the page-level issue message for a page-scope error', async () => {
        const pageError = makeError('mindfula11y.structure.test.error.missingH1', null);
        const view = await mount([makeNode('n1')], [pageError]);

        view.focusFirstIssue(pageError.key);

        const issue = view.renderRoot.querySelector<HTMLElement>('[data-scope="page"]');
        expect(issue).not.toBeNull();
        expect(view.shadowRoot?.activeElement).toBe(issue);
    });

    it('focusFirstIssue focuses the control of the (nested) node carrying the error', async () => {
        const errorKey = 'mindfula11y.structure.test.error.skippedLevel';
        const child = makeNode('n1-child', { errors: [makeError(errorKey, 'n1-child')] });
        const view = await mount([makeNode('n1', { children: [child] })]);

        view.focusFirstIssue(errorKey);

        expect(view.shadowRoot?.activeElement).toBe(querySelect(view, 'n1-child'));
    });
});
