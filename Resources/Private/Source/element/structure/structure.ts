/*
 * Mindful A11y extension for TYPO3 integrating accessibility tools into the backend.
 * Copyright (C) 2026  Mindful Markup, Felix Spittel
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 */

import { Task, TaskStatus } from '@lit/task';
import { lll } from '@typo3/core/lit-helper.js';
import type { CSSResult, PropertyValues, TemplateResult } from 'lit';
import { html, LitElement, nothing } from 'lit';
import { customElement, property, state } from 'lit/decorators.js';
import '@typo3/backend/element/icon-element.js';
import '@typo3/backend/element/spinner-element.js';
import '../heading-structure/heading-structure.js';
import '../landmark-structure/landmark-structure.js';
import '../notice/notice.js';
import { analyzeHeadings } from '../../lib/heading-analysis.js';
import { analyzeLandmarks } from '../../lib/landmark-analysis.js';
import { LiveAnnouncer } from '../../lib/live-announcer.js';
import { activateTabFromKeydown } from '../../lib/tablist.js';
import type { HeadingAnalysis, LandmarkAnalysis, StructureError } from '../../lib/types.js';
import { noticeState, StructureErrorSeverity, severityLabelKey } from '../../lib/types.js';
import { ContentLoader } from '../../service/content-loader.js';
import { baseStyles } from '../../styles/base-styles.js';
import buttonStyles from '../../styles/button.css.js';
import findingsStyles from '../../styles/findings.css.js';
import noticeStyles from '../../styles/notice.css.js';
import placeholderStyles from '../../styles/placeholder.css.js';
import tabsStyles from '../../styles/tabs.css.js';
import componentStyles from './structure.css.js';

type TabName = 'headings' | 'landmarks';

interface StructureAnalysis {
    headings: HeadingAnalysis | null;
    landmarks: LandmarkAnalysis | null;
}

/** One findings-summary chip: an error type with its occurrence count. */
interface Finding {
    key: string;
    severity: StructureErrorSeverity;
    count: number;
    domain: TabName;
}

/**
 * Container of the structure views: fetches the annotated frontend preview,
 * runs the heading/landmark analyzers and presents both views as segmented
 * tabs with severity badges, a clickable findings summary and a live region
 * announcing (re-)analysis results.
 *
 * Views render from the last completed analysis so the DOM stays mounted while
 * a save-triggered re-analysis runs — the editing control keeps focus.
 */
@customElement('mindfula11y-structure')
export class Structure extends LitElement {
    static override styles: CSSResult[] = [
        ...baseStyles,
        noticeStyles,
        tabsStyles,
        findingsStyles,
        buttonStyles,
        placeholderStyles,
        componentStyles,
    ];

    @property({ attribute: 'preview-url' }) previewUrl: string = '';
    @property({ type: Number, attribute: 'heading-level' }) headingLevel: number = 2;
    @property({ type: Boolean, attribute: 'has-heading-structure-access' }) hasHeadingStructureAccess: boolean = false;
    @property({ type: Boolean, attribute: 'has-landmark-structure-access' }) hasLandmarkStructureAccess: boolean =
        false;

    @state() private analysis: StructureAnalysis | null = null;
    @state() private activeTab: TabName = 'headings';

    private readonly contentLoader: ContentLoader = new ContentLoader();
    private readonly announcer: LiveAnnouncer = new LiveAnnouncer(this);
    private isRefresh: boolean = false;

    private readonly analyzeTask = new Task(this, {
        args: (): readonly [string, boolean, boolean] => [
            this.previewUrl,
            this.hasHeadingStructureAccess,
            this.hasLandmarkStructureAccess,
        ],
        task: async ([previewUrl, hasHeadings, hasLandmarks]: readonly [string, boolean, boolean]): Promise<void> => {
            if (previewUrl === '') {
                return;
            }
            const htmlText = await this.contentLoader.load(previewUrl);
            const doc = new DOMParser().parseFromString(htmlText, 'text/html');
            this.analysis = {
                headings: hasHeadings ? analyzeHeadings(doc) : null,
                landmarks: hasLandmarks ? analyzeLandmarks(doc) : null,
            };
            await this.announceResult();
        },
    });

    constructor() {
        super();
        this.addEventListener('mindfula11y:structure:changed', () => {
            this.contentLoader.invalidate(this.previewUrl);
            this.isRefresh = true;
            void this.analyzeTask.run();
        });
    }

    protected override willUpdate(changed: PropertyValues<this>): void {
        if (
            (changed.has('hasHeadingStructureAccess') || changed.has('hasLandmarkStructureAccess')) &&
            !this.enabledTabs().includes(this.activeTab)
        ) {
            this.activeTab = this.enabledTabs()[0] ?? 'headings';
        }
    }

    override render(): TemplateResult {
        return html`<div class="structure">
            ${this.renderHeader()}
            ${this.announcer.render()}
            ${this.renderBody()}
        </div>`;
    }

    private enabledTabs(): TabName[] {
        const tabs: TabName[] = [];
        if (this.hasHeadingStructureAccess) {
            tabs.push('headings');
        }
        if (this.hasLandmarkStructureAccess) {
            tabs.push('landmarks');
        }
        return tabs;
    }

    private renderHeader(): TemplateResult | typeof nothing {
        const tabs = this.enabledTabs();
        if (tabs.length < 2) {
            // A single view needs no tab widget — a plain heading carries the context.
            const single = tabs[0];
            return single === undefined ? nothing : this.renderHeading(this.tabLabel(single));
        }
        return html`<div class="tabs" role="tablist" aria-label=${lll('mindfula11y.structure')}>
            ${tabs.map((tab) => this.renderTab(tab))}
        </div>`;
    }

    private renderTab(tab: TabName): TemplateResult {
        const selected = this.activeTab === tab;
        const counts = this.severityCounts(tab);
        return html`<button
            type="button"
            role="tab"
            id="tab-${tab}"
            data-tab=${tab}
            aria-selected=${selected ? 'true' : 'false'}
            aria-controls="panel-${tab}"
            tabindex=${selected ? '0' : '-1'}
            ?disabled=${this.analysis === null && this.analyzeTask.status === TaskStatus.PENDING}
            @click=${(): void => {
                this.activeTab = tab;
            }}
            @keydown=${this.handleTabKeydown}
        >
            ${this.tabLabel(tab)} ${this.renderTabBadge(counts)}
        </button>`;
    }

    private renderTabBadge(counts: { errors: number; warnings: number }): TemplateResult | typeof nothing {
        if (counts.errors > 0) {
            return html`<span class="notice count" data-state="danger" data-variant="pill"
                >${counts.errors}<span class="sr-only"> ${lll('mindfula11y.severity.error')}</span></span
            >`;
        }
        if (counts.warnings > 0) {
            return html`<span class="notice count" data-state="warning" data-variant="pill"
                >${counts.warnings}<span class="sr-only"> ${lll('mindfula11y.severity.warning')}</span></span
            >`;
        }
        return nothing;
    }

    private renderBody(): TemplateResult {
        if (this.analyzeTask.status === TaskStatus.ERROR) {
            return html`<mindfula11y-notice state="danger">
                <span>
                    <span class="notice-title">${lll('mindfula11y.general.error.loading')}</span>
                    ${lll('mindfula11y.general.error.loading.description')}
                </span>
                <button
                    type="button"
                    class="button retry"
                    @click=${(): void => {
                        void this.analyzeTask.run();
                    }}
                >
                    ${lll('mindfula11y.structure.retry')}
                </button>
            </mindfula11y-notice>`;
        }

        if (this.analysis === null) {
            return html`<div class="placeholder">
                <typo3-backend-spinner size="default"></typo3-backend-spinner>
                <span>${lll('mindfula11y.structure.analyzing')}</span>
            </div>`;
        }

        const tabs = this.enabledTabs();
        return html`${this.renderSummary()}
        ${tabs.map((tab) => this.renderPanel(tab, tabs.length > 1))}`;
    }

    private renderPanel(tab: TabName, withTabs: boolean): TemplateResult {
        const busy = this.analyzeTask.status === TaskStatus.PENDING;
        const view =
            tab === 'headings'
                ? html`<mindfula11y-heading-structure
                      .nodes=${this.analysis?.headings?.nodes ?? []}
                      .pageErrors=${this.pageErrors('headings')}
                  ></mindfula11y-heading-structure>`
                : html`<mindfula11y-landmark-structure
                      .nodes=${this.analysis?.landmarks?.nodes ?? []}
                      .pageErrors=${this.pageErrors('landmarks')}
                  ></mindfula11y-landmark-structure>`;

        if (!withTabs) {
            return html`<div class="panel" aria-busy=${busy ? 'true' : 'false'}>${view}</div>`;
        }
        return html`<div
            class="panel"
            role="tabpanel"
            id="panel-${tab}"
            aria-labelledby="tab-${tab}"
            tabindex="0"
            aria-busy=${busy ? 'true' : 'false'}
            ?hidden=${this.activeTab !== tab}
        >
            ${view}
        </div>`;
    }

    private renderSummary(): TemplateResult | typeof nothing {
        const findings = this.aggregateFindings();
        if (findings.length === 0) {
            // No all-clear message by design: silence means no problems, the
            // live region still announces the analysis result.
            return nothing;
        }
        return html`<section class="summary" aria-label=${lll('mindfula11y.structureErrors')}>
            <ul class="findings">
                ${findings.map(
                    (finding) => html`<li>
                        <button
                            type="button"
                            class="notice finding"
                            data-state=${noticeState(finding.severity)}
                            data-variant="pill"
                            @click=${(): void => {
                                void this.handleFindingClick(finding);
                            }}
                        >
                            <typo3-backend-icon
                                identifier=${
                                    finding.severity === StructureErrorSeverity.Error
                                        ? 'status-dialog-error'
                                        : 'status-dialog-warning'
                                }
                                size="small"
                            ></typo3-backend-icon>
                            <span><span class="sr-only">${lll(severityLabelKey(finding.severity))}: </span>${lll(finding.key)}</span>
                            <span class="finding-count">${finding.count}</span>
                        </button>
                    </li>`,
                )}
            </ul>
        </section>`;
    }

    private renderHeading(content: string): TemplateResult {
        switch (this.headingLevel) {
            case 1:
                return html`<h1 class="title">${content}</h1>`;
            case 3:
                return html`<h3 class="title">${content}</h3>`;
            case 4:
                return html`<h4 class="title">${content}</h4>`;
            case 5:
                return html`<h5 class="title">${content}</h5>`;
            case 6:
                return html`<h6 class="title">${content}</h6>`;
            default:
                return html`<h2 class="title">${content}</h2>`;
        }
    }

    private tabLabel(tab: TabName): string {
        return tab === 'headings' ? lll('mindfula11y.structure.headings') : lll('mindfula11y.structure.landmarks');
    }

    private domainErrors(domain: TabName): StructureError[] {
        const analysis = domain === 'headings' ? this.analysis?.headings : this.analysis?.landmarks;
        return analysis?.errors ?? [];
    }

    private pageErrors(domain: TabName): StructureError[] {
        return this.domainErrors(domain).filter((error) => error.nodeId === null);
    }

    private severityCounts(domain: TabName): { errors: number; warnings: number } {
        const errors = this.domainErrors(domain);
        return {
            errors: errors.filter((error) => error.severity === StructureErrorSeverity.Error).length,
            warnings: errors.filter((error) => error.severity === StructureErrorSeverity.Warning).length,
        };
    }

    private aggregateFindings(): Finding[] {
        const findings = new Map<string, Finding>();
        for (const domain of this.enabledTabs()) {
            for (const error of this.domainErrors(domain)) {
                const existing = findings.get(error.key);
                if (existing === undefined) {
                    findings.set(error.key, { key: error.key, severity: error.severity, count: 1, domain });
                } else {
                    existing.count += 1;
                }
            }
        }
        return Array.from(findings.values()).sort((a, b) => {
            if (a.severity === b.severity) {
                return 0;
            }
            return a.severity === StructureErrorSeverity.Error ? -1 : 1;
        });
    }

    private async handleFindingClick(finding: Finding): Promise<void> {
        this.activeTab = finding.domain;
        await this.updateComplete;
        const view = this.renderRoot.querySelector(
            finding.domain === 'headings' ? 'mindfula11y-heading-structure' : 'mindfula11y-landmark-structure',
        );
        view?.focusFirstIssue(finding.key);
    }

    private handleTabKeydown = (event: KeyboardEvent): void => {
        void activateTabFromKeydown(this, event, this.enabledTabs(), this.activeTab, (tab) => {
            this.activeTab = tab;
        });
    };

    /** Announces the analysis outcome with total error/warning counts. */
    private async announceResult(): Promise<void> {
        let errors = 0;
        let warnings = 0;
        for (const domain of this.enabledTabs()) {
            const counts = this.severityCounts(domain);
            errors += counts.errors;
            warnings += counts.warnings;
        }
        const key = this.isRefresh ? 'mindfula11y.structure.updated' : 'mindfula11y.structure.analyzed';
        this.isRefresh = false;
        await this.announcer.announce(lll(key, errors, warnings));
    }
}

declare global {
    interface HTMLElementTagNameMap {
        'mindfula11y-structure': Structure;
    }
}
