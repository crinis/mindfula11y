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

import { Task, type TaskFunctionOptions, TaskStatus } from '@lit/task';
import { lll } from '@typo3/core/lit-helper.js';
import type { CSSResult, PropertyValues, TemplateResult } from 'lit';
import { html, LitElement, nothing } from 'lit';
import { customElement, property, state } from 'lit/decorators.js';
import { literal, type StaticValue, html as staticHtml } from 'lit/static-html.js';
import '@typo3/backend/element/icon-element.js';
import '@typo3/backend/element/spinner-element.js';
import '../heading-structure/heading-structure.js';
import '../landmark-structure/landmark-structure.js';
import '../notice/notice.js';
import { LiveAnnouncer } from '../../lib/live-announcer.js';
import { noticeState, renderSeverityChip, renderViewportBadges } from '../../lib/status-render.js';
import { mergeViewports } from '../../lib/structure/analysis.js';
import { StructureAnalysisError } from '../../lib/structure/error.js';
import type {
    HeadingAnalysis,
    LandmarkAnalysis,
    StructureDomain,
    StructureError,
    StructureViewport,
} from '../../lib/structure/types.js';
import { StructureErrorSeverity } from '../../lib/structure/types.js';
import { activateTabFromKeydown, renderTablist, renderTabPanel, type TabDescriptor } from '../../lib/tabs.js';
import { type StructureAnalysis, StructureAnalysisCoordinator } from '../../service/structure/coordinator.js';
import { baseStyles } from '../../styles/base-styles.js';
import buttonStyles from '../../styles/button.css.js';
import findingsStyles from '../../styles/findings.css.js';
import noticeStyles from '../../styles/notice.css.js';
import placeholderStyles from '../../styles/placeholder.css.js';
import tabsStyles from '../../styles/tabs.css.js';
import viewportStyles from '../../styles/viewport.css.js';
import componentStyles from './structure.css.js';

/** One findings-summary chip: an error type with its occurrence count. */
interface Finding {
    key: string;
    severity: StructureErrorSeverity;
    count: number;
    domain: StructureDomain;
    viewports: StructureViewport[];
}

/**
 * Static h1–h6 tag names for the single-view title. The level is a fixed
 * whitelist (never attacker-controlled), so it is safe to interpolate as a
 * static tag via `lit/static-html`; unknown levels fall back to `h2`.
 */
const HEADING_TAGS: Record<number, StaticValue> = {
    1: literal`h1`,
    2: literal`h2`,
    3: literal`h3`,
    4: literal`h4`,
    5: literal`h5`,
    6: literal`h6`,
};

/** Per-domain analysis slice, before it is narrowed to the concrete heading/landmark shape. */
type DomainAnalysis = HeadingAnalysis | LandmarkAnalysis;

/**
 * Everything that differs between the heading and landmark domains, looked up
 * once per call site instead of branching on `tab === 'headings'` throughout
 * the component.
 */
interface DomainDescriptor {
    /** Label key of the tab / single-view heading. */
    labelKey: string;
    /** Tag of the view element rendering this domain — also used to query it back for focus. */
    tag: 'mindfula11y-heading-structure' | 'mindfula11y-landmark-structure';
    /** Reads the existing boolean `@property` gating access to this domain. */
    hasAccess: (self: Structure) => boolean;
    /** Narrows a merged analysis result down to this domain's slice. */
    analysisOf: (analysis: StructureAnalysis) => DomainAnalysis | null;
    /** Renders this domain's view element bound to its slice of the analysis. */
    renderView: (analysis: DomainAnalysis | null, pageErrors: StructureError[]) => TemplateResult;
}

const DOMAINS: Record<StructureDomain, DomainDescriptor> = {
    headings: {
        labelKey: 'mindfula11y.structure.headings',
        tag: 'mindfula11y-heading-structure',
        hasAccess: (self: Structure): boolean => self.hasHeadingStructureAccess,
        analysisOf: (analysis: StructureAnalysis): DomainAnalysis | null => analysis.headings,
        renderView: (analysis: DomainAnalysis | null, pageErrors: StructureError[]): TemplateResult =>
            html`<mindfula11y-heading-structure
                .nodes=${analysis?.nodes ?? []}
                .pageErrors=${pageErrors}
            ></mindfula11y-heading-structure>`,
    },
    landmarks: {
        labelKey: 'mindfula11y.structure.landmarks',
        tag: 'mindfula11y-landmark-structure',
        hasAccess: (self: Structure): boolean => self.hasLandmarkStructureAccess,
        analysisOf: (analysis: StructureAnalysis): DomainAnalysis | null => analysis.landmarks,
        renderView: (analysis: DomainAnalysis | null, pageErrors: StructureError[]): TemplateResult =>
            html`<mindfula11y-landmark-structure
                .nodes=${analysis?.nodes ?? []}
                .pageErrors=${pageErrors}
            ></mindfula11y-landmark-structure>`,
    },
};

/** Domain shown before access is known and to fall back to once it changes. */
const DEFAULT_DOMAIN: StructureDomain = 'headings';

/**
 * Container of the structure views: renders the annotated frontend preview at
 * mobile and desktop sizes, runs the heading/landmark analyzers and presents both views as segmented
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
        viewportStyles,
        componentStyles,
    ];

    @property({ type: Number, attribute: 'page-id' }) pageId: number = 0;
    @property({ type: Number, attribute: 'language-id' }) languageId: number = 0;
    @property({ type: Number, attribute: 'heading-level' }) headingLevel: number = 2;
    @property({ type: Boolean, attribute: 'has-heading-structure-access' }) hasHeadingStructureAccess: boolean = false;
    @property({ type: Boolean, attribute: 'has-landmark-structure-access' }) hasLandmarkStructureAccess: boolean =
        false;

    @state() private analysis: StructureAnalysis | null = null;
    @state() private activeTab: StructureDomain = DEFAULT_DOMAIN;

    private readonly announcer: LiveAnnouncer = new LiveAnnouncer(this);
    private readonly coordinator: StructureAnalysisCoordinator = StructureAnalysisCoordinator.createDefault();

    private readonly analyzeTask = new Task(this, {
        args: (): readonly [number, number, boolean, boolean] => [
            this.pageId,
            this.languageId,
            this.hasHeadingStructureAccess,
            this.hasLandmarkStructureAccess,
        ],
        task: async (
            [pageId, languageId, hasHeadings, hasLandmarks]: readonly [number, number, boolean, boolean],
            { signal }: TaskFunctionOptions,
        ): Promise<void> => {
            if (pageId <= 0) {
                return;
            }
            const analysis = await this.coordinator.analyze(
                { pageId, languageId, headings: hasHeadings, landmarks: hasLandmarks },
                this.renderRoot,
                signal,
            );
            const isRefresh = this.analysis !== null;
            this.analysis = analysis;
            await this.announceResult(signal, isRefresh);
            signal.throwIfAborted();
        },
    });

    constructor() {
        super();
        this.addEventListener('mindfula11y:structure:changed', () => {
            void this.analyzeTask.run();
        });
    }

    override disconnectedCallback(): void {
        this.analyzeTask.abort();
        super.disconnectedCallback();
    }

    protected override willUpdate(changed: PropertyValues<this>): void {
        if (
            (changed.has('hasHeadingStructureAccess') || changed.has('hasLandmarkStructureAccess')) &&
            !this.enabledTabs().includes(this.activeTab)
        ) {
            this.activeTab = this.enabledTabs()[0] ?? DEFAULT_DOMAIN;
        }
    }

    override render(): TemplateResult {
        return html`<div class="structure">
            ${this.renderHeader()}
            ${this.announcer.render()}
            <div class="status-region" role="status">${this.renderError()}</div>
            ${this.renderBody()}
        </div>`;
    }

    private enabledTabs(): StructureDomain[] {
        return (Object.keys(DOMAINS) as StructureDomain[]).filter((domain) => DOMAINS[domain].hasAccess(this));
    }

    private renderHeader(): TemplateResult | typeof nothing {
        const tabs = this.enabledTabs();
        if (tabs.length < 2) {
            // A single view needs no tab widget — a plain heading carries the context.
            const single = tabs[0];
            return single === undefined ? nothing : this.renderHeading(this.tabLabel(single));
        }
        return renderTablist({
            ariaLabel: lll('mindfula11y.structure'),
            tabs: tabs.map((tab) => this.tabDescriptor(tab)),
            activeTab: this.activeTab,
            onSelect: (id: string): void => {
                this.activeTab = id as StructureDomain;
            },
            onKeydown: this.handleTabKeydown,
        });
    }

    private tabDescriptor(tab: StructureDomain): TabDescriptor {
        return {
            id: tab,
            label: this.tabLabel(tab),
            badge: this.renderTabBadge(this.severityCounts(tab)),
            disabled: this.analysis === null && this.analyzeTask.status === TaskStatus.PENDING,
        };
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

    private renderError(): TemplateResult | typeof nothing {
        if (this.analyzeTask.status !== TaskStatus.ERROR) {
            return nothing;
        }
        const error = this.analyzeTask.error;
        // Typed failures get a code-specific description; everything else
        // (unexpected exceptions, non-typed rejections) keeps the generic one.
        const description =
            error instanceof StructureAnalysisError
                ? lll(`mindfula11y.structure.error.rendering.${error.code}`)
                : lll('mindfula11y.structure.error.rendering.description');
        return html`<mindfula11y-notice state="danger">
            <span>
                <span class="notice-title">${lll('mindfula11y.structure.error.rendering')}</span>
                ${description}
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

    private renderBody(): TemplateResult | typeof nothing {
        if (this.analyzeTask.status === TaskStatus.ERROR) {
            return nothing;
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

    private renderPanel(tab: StructureDomain, withTabs: boolean): TemplateResult {
        const busy = this.analyzeTask.status === TaskStatus.PENDING;
        const domain = DOMAINS[tab];
        const analysis = this.analysis === null ? null : domain.analysisOf(this.analysis);
        const view = domain.renderView(analysis, this.pageErrors(tab));

        return renderTabPanel({
            tab,
            active: this.activeTab === tab,
            withTablist: withTabs,
            busy,
            content: view,
        });
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
                            ${renderSeverityChip(finding.severity, finding.key)}
                            <span class="finding-count">${finding.count}</span>
                            ${renderViewportBadges(finding.viewports)}
                        </button>
                    </li>`,
                )}
            </ul>
        </section>`;
    }

    private renderHeading(content: string): TemplateResult {
        const tag = HEADING_TAGS[this.headingLevel] ?? HEADING_TAGS[2];
        return staticHtml`<${tag} class="title">${content}</${tag}>`;
    }

    private tabLabel(tab: StructureDomain): string {
        return lll(DOMAINS[tab].labelKey);
    }

    private domainErrors(domain: StructureDomain): StructureError[] {
        if (this.analysis === null) {
            return [];
        }
        return DOMAINS[domain].analysisOf(this.analysis)?.errors ?? [];
    }

    private pageErrors(domain: StructureDomain): StructureError[] {
        return this.domainErrors(domain).filter((error) => error.nodeId === null);
    }

    private severityCounts(domain: StructureDomain): { errors: number; warnings: number } {
        const counts = { errors: 0, warnings: 0 };
        for (const error of this.domainErrors(domain)) {
            if (error.severity === StructureErrorSeverity.Error) {
                counts.errors += 1;
            } else {
                counts.warnings += 1;
            }
        }
        return counts;
    }

    private aggregateFindings(): Finding[] {
        const findings = new Map<string, Finding>();
        for (const domain of this.enabledTabs()) {
            for (const error of this.domainErrors(domain)) {
                // Keyed by domain + error key: the same label key can be reused by
                // both analyzers, and their findings must never merge into one chip.
                const findingKey = `${domain} ${error.key}`;
                const existing = findings.get(findingKey);
                if (existing === undefined) {
                    findings.set(findingKey, {
                        key: error.key,
                        severity: error.severity,
                        count: 1,
                        domain,
                        viewports: [...error.viewports],
                    });
                } else {
                    existing.count += 1;
                    existing.viewports = mergeViewports(existing.viewports, error.viewports);
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
        const view = this.renderRoot.querySelector(DOMAINS[finding.domain].tag);
        view?.focusFirstIssue(finding.key);
    }

    private handleTabKeydown = (event: KeyboardEvent): void => {
        void activateTabFromKeydown(this, event, this.enabledTabs(), this.activeTab, (tab) => {
            this.activeTab = tab;
        });
    };

    /** Announces the analysis outcome with total error/warning counts. */
    private async announceResult(signal: AbortSignal, isRefresh: boolean): Promise<void> {
        let errors = 0;
        let warnings = 0;
        for (const domain of this.enabledTabs()) {
            const counts = this.severityCounts(domain);
            errors += counts.errors;
            warnings += counts.warnings;
        }
        const key = isRefresh ? 'mindfula11y.structure.updated' : 'mindfula11y.structure.analyzed';
        await this.announcer.announce(lll(key, errors, warnings), signal);
    }
}

declare global {
    interface HTMLElementTagNameMap {
        'mindfula11y-structure': Structure;
    }
}
