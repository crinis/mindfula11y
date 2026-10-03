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
vi.mock('@typo3/backend/element/icon-element.js', () => ({}));

import type { ScanResults } from '../../../Resources/Private/Source/element/scan-results/scan-results.js';
import '../../../Resources/Private/Source/element/scan-results/scan-results.js';
import type { AgentFindingDto, ScanResult, ViolationDto } from '../../../Resources/Private/Source/lib/scan/types.js';
import { AiAuditStatus, ScanStatus } from '../../../Resources/Private/Source/lib/scan/types.js';

const violation = (ruleId: string, impact: ViolationDto['impact']): ViolationDto => ({
    rule: { id: ruleId, description: `${ruleId} description`, helpUrl: null },
    impact,
    issues: [{ id: 1, pageUrl: null, selector: `#${ruleId}`, context: null }],
});

const finding = (skill: string, message: string): AgentFindingDto => ({
    skill,
    category: 'issue',
    wcag: null,
    severity: 'moderate',
    confidence: 0.9,
    needsHumanReview: false,
    pageUrl: null,
    selector: null,
    message,
    suggestion: null,
    details: null,
    model: null,
});

const resultWith = (partial: Partial<ScanResult>): ScanResult => ({
    status: ScanStatus.Completed,
    violations: [],
    totalIssueCount: 0,
    mode: null,
    targets: [],
    progress: null,
    aiAudit: null,
    agentFindings: [],
    updatedAt: null,
    ...partial,
});

const mount = async (result: ScanResult): Promise<ScanResults> => {
    const view = document.createElement('mindfula11y-scan-results');
    view.result = result;
    document.body.append(view);
    await view.updateComplete;
    return view;
};

/** The rule id a violation card's summary names (its `<code>`) — classes are styling-only. */
const ruleIdOf = (details: Element): string | null | undefined => details.querySelector('summary code')?.textContent;

const card = (view: ScanResults, ruleId: string): HTMLDetailsElement => {
    for (const details of view.renderRoot.querySelectorAll<HTMLDetailsElement>('details[data-impact]')) {
        if (ruleIdOf(details) === ruleId) {
            return details;
        }
    }
    throw new Error(`Card for ${ruleId} not rendered.`);
};

describe('ScanResults', () => {
    afterEach(() => {
        document.body.replaceChildren();
    });

    it('sorts violation cards worst-first without mutating the input', async () => {
        const violations = [violation('minor-rule', 'minor'), violation('critical-rule', 'critical')];
        const view = await mount(resultWith({ violations }));

        const ids = [...view.renderRoot.querySelectorAll('details[data-impact]')].map(ruleIdOf);
        expect(ids).toEqual(['critical-rule', 'minor-rule']);
        // toSorted: the caller-owned result object must stay untouched.
        expect(violations[0]?.rule.id).toBe('minor-rule');
    });

    it("retains a card's open state when a refresh reorders the violations", async () => {
        // First result: alpha is moderate and renders second.
        const view = await mount(
            resultWith({ violations: [violation('alpha', 'moderate'), violation('beta', 'critical')] }),
        );
        card(view, 'alpha').open = true;

        // The refresh drops beta and adds a minor gamma, so alpha now sorts
        // first. Keyed rendering must move the open card, not leave `open`
        // glued to the second DOM position (which is now gamma).
        view.result = resultWith({ violations: [violation('gamma', 'minor'), violation('alpha', 'moderate')] });
        await view.updateComplete;

        const ids = [...view.renderRoot.querySelectorAll('details[data-impact]')].map(ruleIdOf);
        expect(ids).toEqual(['alpha', 'gamma']);
        expect(card(view, 'alpha').open).toBe(true);
        expect(card(view, 'gamma').open).toBe(false);
    });

    it('renders one card per rule and impact, each counting its own issues', async () => {
        const view = await mount(
            resultWith({ violations: [violation('image-alt', 'critical'), violation('image-alt', 'minor')] }),
        );

        const cards = [...view.renderRoot.querySelectorAll('details[data-impact]')];
        expect(cards.map((details) => [ruleIdOf(details), details.getAttribute('data-impact')])).toEqual([
            ['image-alt', 'critical'],
            ['image-alt', 'minor'],
        ]);
        // The summary chips (the only buttons) count per impact, matching the cards below them.
        const chips = [...view.renderRoot.querySelectorAll('button')].map((chip) =>
            chip.textContent?.replace(/\s+/g, ' ').trim(),
        );
        expect(chips).toHaveLength(2);
        expect(chips[0]).toContain('mindfula11y.severity.critical 1');
        expect(chips[1]).toContain('mindfula11y.severity.minor 1');
    });

    it('groups AI findings by skill in first-occurrence order', async () => {
        const view = await mount(
            resultWith({
                aiAudit: {
                    status: AiAuditStatus.Completed,
                    requestedSkills: ['alt_text', 'link_purpose'],
                    tasksTotal: 3,
                    tasksCompleted: 3,
                    tasksFailed: 0,
                },
                agentFindings: [
                    finding('alt_text', 'first alt finding'),
                    finding('link_purpose', 'link finding'),
                    finding('alt_text', 'second alt finding'),
                ],
            }),
        );

        // One section per skill, each headed by an h3 naming the skill.
        const titles = [...view.renderRoot.querySelectorAll('section > h3')];
        const sections = titles.map((title) => title.parentElement);
        expect(titles[0]?.textContent).toContain('mindfula11y.scan.aiAudit.skill.alt_text');
        expect(titles[1]?.textContent).toContain('mindfula11y.scan.aiAudit.skill.link_purpose');
        expect(sections[0]?.querySelectorAll(':scope > ul > li')).toHaveLength(2);
        expect(sections[1]?.querySelectorAll(':scope > ul > li')).toHaveLength(1);
    });
});
