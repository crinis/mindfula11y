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
import {
    noticeState,
    renderCountBadge,
    renderLoadingPlaceholder,
    renderNoticeBody,
    renderSeverityChip,
    renderViewportBadges,
} from '../../lib/status-render.js';
import { StructureAnalysisError } from '../../lib/structure/error.js';
import {
    aggregateFindings,
    enabledDomains,
    type Finding,
    pageErrors,
    severityCounts,
} from '../../lib/structure/findings.js';
import type {
    HeadingAnalysis,
    LandmarkAnalysis,
    StructureAnalysis,
    StructureDomain,
    StructureError,
} from '../../lib/structure/types.js';
import { type TabDescriptor, TabsController } from '../../lib/tabs.js';
import { StructureAnalysisCoordinator } from '../../service/structure/coordinator.js';
import { baseStyles } from '../../styles/base-styles.js';
import buttonStyles from '../../styles/button.css.js';
import findingsStyles from '../../styles/findings.css.js';
import noticeStyles from '../../styles/notice.css.js';
import placeholderStyles from '../../styles/placeholder.css.js';
import tabsStyles from '../../styles/tabs.css.js';
import viewportStyles from '../../styles/viewport.css.js';
import componentStyles from './structure.css.js';

/**
 * Static h1–h6 tag names for the single-view title. The level is a fixed
 * whitelist (never attacker-controlled), so it is safe to interpolate as a
 * static tag via `lit/static-html`; unknown levels fall back to `h2`.
 */
const FALLBACK_HEADING_TAG: StaticValue = literal`h2`;
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
    /** Narrows a merged analysis result down to this domain's slice. */
    analysisOf: (analysis: StructureAnalysis) => DomainAnalysis | null;
    /** Renders this domain's view element bound to its slice of the analysis. */
    renderView: (analysis: DomainAnalysis | null, pageLevelErrors: StructureError[]) => TemplateResult;
}

const DOMAINS: Record<StructureDomain, DomainDescriptor> = {
    headings: {
        labelKey: 'mindfula11y.structure.headings',
        tag: 'mindfula11y-heading-structure',
        analysisOf: (analysis: StructureAnalysis): DomainAnalysis | null => analysis.headings,
        renderView: (analysis: DomainAnalysis | null, pageLevelErrors: StructureError[]): TemplateResult =>
            html`<mindfula11y-heading-structure
                .nodes=${analysis?.nodes ?? []}
                .pageErrors=${pageLevelErrors}
            ></mindfula11y-heading-structure>`,
    },
    landmarks: {
        labelKey: 'mindfula11y.structure.landmarks',
        tag: 'mindfula11y-landmark-structure',
        analysisOf: (analysis: StructureAnalysis): DomainAnalysis | null => analysis.landmarks,
        renderView: (analysis: DomainAnalysis | null, pageLevelErrors: StructureError[]): TemplateResult =>
            html`<mindfula11y-landmark-structure
                .nodes=${analysis?.nodes ?? []}
                .pageErrors=${pageLevelErrors}
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

    private readonly announcer: LiveAnnouncer = new LiveAnnouncer(this);
    private readonly coordinator: StructureAnalysisCoordinator = StructureAnalysisCoordinator.createDefault();
    private readonly tabs: TabsController<StructureDomain> = new TabsController(
        this,
        () => this.enabledTabs(),
        DEFAULT_DOMAIN,
    );

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
        if (changed.has('hasHeadingStructureAccess') || changed.has('hasLandmarkStructureAccess')) {
            this.tabs.ensureActive(DEFAULT_DOMAIN);
        }
    }

    override render(): TemplateResult {
        return html`<div class="structure">
            ${this.renderHeader()}
            ${this.announcer.render()}
            <div class="status-region" role="status">${this.renderError()}</div>
            ${this.renderErrorRetry()}
            ${this.renderBody()}
        </div>`;
    }

    private enabledTabs(): StructureDomain[] {
        return enabledDomains(this.enabledFlags());
    }

    private enabledFlags(): { headings: boolean; landmarks: boolean } {
        return {
            headings: this.hasHeadingStructureAccess,
            landmarks: this.hasLandmarkStructureAccess,
        };
    }

    private renderHeader(): TemplateResult | typeof nothing {
        const tabs = this.enabledTabs();
        if (tabs.length < 2) {
            // A single view needs no tab widget — a plain heading carries the context.
            const single = tabs[0];
            return single === undefined ? nothing : this.renderHeading(this.tabLabel(single));
        }
        return this.tabs.renderTablist({
            ariaLabel: lll('mindfula11y.structure'),
            tabs: tabs.map((tab) => this.tabDescriptor(tab)),
        });
    }

    private tabDescriptor(tab: StructureDomain): TabDescriptor<StructureDomain> {
        return {
            id: tab,
            label: this.tabLabel(tab),
            badge: this.renderTabBadge(severityCounts(this.analysis, tab)),
            disabled: this.analysis === null && this.analyzeTask.status === TaskStatus.PENDING,
        };
    }

    private renderTabBadge(counts: { errors: number; warnings: number }): TemplateResult | typeof nothing {
        if (counts.errors > 0) {
            return renderCountBadge('danger', counts.errors, `${counts.errors} ${lll('mindfula11y.severity.error')}`);
        }
        if (counts.warnings > 0) {
            return renderCountBadge(
                'warning',
                counts.warnings,
                `${counts.warnings} ${lll('mindfula11y.severity.warning')}`,
            );
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
            ${renderNoticeBody({ title: lll('mindfula11y.structure.error.rendering'), description })}
        </mindfula11y-notice>`;
    }

    /**
     * Rendered OUTSIDE the role="status" container: role="status" is
     * implicitly atomic, so an embedded control would be re-announced as
     * status text — and a live region must not contain interactive content.
     */
    private renderErrorRetry(): TemplateResult | typeof nothing {
        if (this.analyzeTask.status !== TaskStatus.ERROR) {
            return nothing;
        }
        return html`<button
            type="button"
            class="button retry"
            @click=${(): void => {
                void this.analyzeTask.run();
            }}
        >
            ${lll('mindfula11y.structure.retry')}
        </button>`;
    }

    private renderBody(): TemplateResult | typeof nothing {
        if (this.analyzeTask.status === TaskStatus.ERROR) {
            return nothing;
        }
        if (this.analysis === null) {
            return renderLoadingPlaceholder(lll('mindfula11y.structure.analyzing'));
        }

        const tabs = this.enabledTabs();
        return html`${this.renderSummary()}
        ${tabs.map((tab) => this.renderPanel(tab, tabs.length > 1))}`;
    }

    private renderPanel(tab: StructureDomain, withTabs: boolean): TemplateResult {
        const busy = this.analyzeTask.status === TaskStatus.PENDING;
        const domain = DOMAINS[tab];
        const analysis = this.analysis === null ? null : domain.analysisOf(this.analysis);
        const view = domain.renderView(analysis, pageErrors(this.analysis, tab));

        return this.tabs.renderPanel({
            tab,
            withTablist: withTabs,
            busy,
            content: view,
        });
    }

    private renderSummary(): TemplateResult | typeof nothing {
        const findings = aggregateFindings(this.analysis, this.enabledFlags());
        if (findings.length === 0) {
            // No all-clear message by design: silence means no problems, the
            // live region still announces the analysis result.
            return nothing;
        }
        return html`<div class="summary">
            <ul class="findings" aria-label=${lll('mindfula11y.structureErrors')}>
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
        </div>`;
    }

    private renderHeading(content: string): TemplateResult {
        const tag = HEADING_TAGS[this.headingLevel] ?? FALLBACK_HEADING_TAG;
        return staticHtml`<${tag} class="title">${content}</${tag}>`;
    }

    private tabLabel(tab: StructureDomain): string {
        return lll(DOMAINS[tab].labelKey);
    }

    private async handleFindingClick(finding: Finding): Promise<void> {
        this.tabs.select(finding.domain);
        await this.updateComplete;
        const view = this.renderRoot.querySelector(DOMAINS[finding.domain].tag);
        view?.focusFirstIssue(finding.key);
    }

    /** Announces the analysis outcome with total error/warning counts. */
    private async announceResult(signal: AbortSignal, isRefresh: boolean): Promise<void> {
        let errors = 0;
        let warnings = 0;
        for (const domain of this.enabledTabs()) {
            const counts = severityCounts(this.analysis, domain);
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
