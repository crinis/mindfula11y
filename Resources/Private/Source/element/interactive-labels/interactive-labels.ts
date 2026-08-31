import { lll } from '@typo3/core/lit-helper.js';
import type { CSSResult, TemplateResult } from 'lit';
import { html, LitElement } from 'lit';
import { customElement, property } from 'lit/decorators.js';

import '../notice/notice.js';

import { impactState, renderCountBadge } from '../../lib/status-render.js';

import { baseStyles } from '../../styles/base-styles.js';
import findingsStyles from '../../styles/findings.css.js';
import labelStyles from './interactive-labels.css.js';

type InteractiveLabelSeverity = 'minor' | 'moderate' | 'serious' | 'critical';

interface InteractiveLabelFinding {
    value: string;
    rule?: string;
    severity: InteractiveLabelSeverity;

    overviewCount?: number;
    occurrenceCount?: number;
    distinctTargetCount?: number;

    isRepeated?: boolean;
    hasDifferentTargets?: boolean;
}

interface InteractiveLabelRow {
    value: string;
    ruleTitle: string;
    ruleDescription: string;
    severity: InteractiveLabelSeverity;
    count: number;
}

@customElement('mindfula11y-interactive-labels')
export class InteractiveLabels extends LitElement {
    static override styles: CSSResult[] = [...baseStyles, findingsStyles, labelStyles];

    @property({ type: Array })
    findings: InteractiveLabelFinding[] = [];

    override render(): TemplateResult {
        const rows = this.groupFindings();

        if (rows.length === 0) {
            return html`
        <mindfula11y-notice state="success">
                    <span>
                        ${lll('mindfula11y.interactiveLabels.noFindings')}
                    </span>
        </mindfula11y-notice>
      `;
        }

        return html`
      <div class="interactive-labels">
        ${rows.map((row) => this.renderRow(row))}
      </div>
    `;
    }

    private groupFindings(): InteractiveLabelRow[] {
        const grouped = new Map<string, InteractiveLabelRow>();

        for (const finding of this.findings) {
            if (!finding.rule) {
                continue;
            }

            const normalizedValue = finding.value.trim().toLocaleLowerCase();

            const key = `${finding.rule}:${normalizedValue}`;

            const existing = grouped.get(key);

            if (existing) {
                existing.count += finding.overviewCount ?? 1;
                continue;
            }

            const ruleTitleKey = `mindfula11y.findings.rule.title.${finding.rule}`;

            const ruleDescriptionKey = `mindfula11y.findings.rule.description.${finding.rule}`;

            const ruleTitle = lll(ruleTitleKey);
            const ruleDescription = lll(ruleDescriptionKey);

            grouped.set(key, {
                value: finding.value,
                ruleTitle,
                ruleDescription,
                severity: finding.severity,
                count: finding.overviewCount ?? 1,
            });
        }

        return [...grouped.values()];
    }

    private renderRow(row: InteractiveLabelRow): TemplateResult {
        return html`
      <details class="interactive-label-row">
        <summary class="row-summary">
                <span class="value">
                    ${row.value}
                </span>

          <span class="rule-title">
                    ${row.ruleTitle}
                </span>

          ${renderCountBadge(impactState(row.severity), row.count)}
        </summary>

        <div class="rule-description">
          <p class="rule-description-text">
            ${row.ruleDescription}
          </p>
        </div>
      </details>
    `;
    }
}

declare global {
    interface HTMLElementTagNameMap {
        'mindfula11y-interactive-labels': InteractiveLabels;
    }
}
