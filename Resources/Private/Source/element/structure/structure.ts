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
import Client from '@typo3/backend/storage/client.js';
import { lll } from '@typo3/core/lit-helper.js';
import type { CSSResult, PropertyValues, TemplateResult } from 'lit';
import { html, LitElement, nothing } from 'lit';
import { customElement, property, state } from 'lit/decorators.js';
import '@typo3/backend/element/icon-element.js';
import '@typo3/backend/element/spinner-element.js';
import '../heading-structure/heading-structure.js';
import '../landmark-structure/landmark-structure.js';
import '../interactive-labels/interactive-labels.js';
import '../notice/notice.js';
import { LiveAnnouncer } from '../../lib/live-announcer.js';
import {
    impactState,
    renderCountBadge,
    renderDisclosureMarker,
    renderExternalLink,
    renderFindingPill,
    renderNoticeBody,
    renderProgressNotice,
    renderSeverityChip,
    renderSeverityLabel,
    renderViewportBadges,
    severityLabelKey,
    totalCount,
    worstImpact,
} from '../../lib/status-render.js';
import { StructureAnalysisError } from '../../lib/structure/error.js';
import { aggregateFindings, enabledDomains, pageErrors, severityCounts } from '../../lib/structure/findings.js';
import type {
    HeadingAnalysis,
    LandmarkAnalysis,
    StructureAnalysis,
    StructureDomain,
    StructureError,
} from '../../lib/structure/types.js';
import { type TabDescriptor, TabsController } from '../../lib/tabs.js';
import type { ImpactSeverity } from '../../lib/types.js';
import { StructureAnalysisCoordinator } from '../../service/structure/coordinator.js';
import { baseStyles } from '../../styles/base-styles.js';
import buttonStyles from '../../styles/button.css.js';
import disclosureStyles from '../../styles/disclosure.css.js';
import findingsStyles from '../../styles/findings.css.js';
import noticeStyles from '../../styles/notice.css.js';
import tabsStyles from '../../styles/tabs.css.js';
import viewportStyles from '../../styles/viewport.css.js';
import componentStyles from './structure.css.js';

/**
 * localStorage key remembering whether the page-module disclosure is open
 * (core prefixes it with `t3-`). Shared by every page the editor visits: the
 * choice is about how the editor works, not about one page's structure.
 */
const EXPANDED_STORAGE_KEY: string = 'mindfula11y-structure-expanded';

/** Per-domain analysis slice, before it is narrowed to the concrete heading/landmark shape. */
type DomainAnalysis = HeadingAnalysis | LandmarkAnalysis;

/** Tabs shown in the page-module overview. Interactive labels are UI-only and are not part of the structure analysis. */
type OverviewTab = StructureDomain | 'interactiveLabels';

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
        disclosureStyles,
        tabsStyles,
        findingsStyles,
        buttonStyles,
        viewportStyles,
        componentStyles,
    ];

    @property({ type: Number, attribute: 'page-id' }) pageId: number = 0;
    @property({ type: Number, attribute: 'language-id' }) languageId: number = 0;
    @property({ type: Boolean, attribute: 'has-heading-structure-access' }) hasHeadingStructureAccess: boolean = false;
    @property({ type: Boolean, attribute: 'has-landmark-structure-access' }) hasLandmarkStructureAccess: boolean =
        false;
    @property({ type: Number, attribute: 'interactive-label-count' }) interactiveLabelCount: number = 0;
    @property({ type: Boolean, attribute: 'has-interactive-labels' }) hasInteractiveLabels: boolean = false;
    /**
     * Page-module mode: the trees sit behind a disclosure so the widget does
     * not push the content elements below the fold. Set from Fluid in
     * Templates/Backend/WebLayout/Overview.html only.
     */
    @property({ type: Boolean }) collapsible: boolean = false;

    @state() private analysis: StructureAnalysis | null = null;

    /**
     * Mirror of the native `open` state, kept only so a re-render (a
     * save-triggered re-analysis) re-applies it. Deliberately NOT reactive:
     * `<details>` owns the state, and re-rendering on toggle would rebuild
     * both structure trees for a state the browser has already applied.
     */
    private expanded: boolean = false;

    private readonly announcer: LiveAnnouncer = new LiveAnnouncer(this);
    private readonly coordinator: StructureAnalysisCoordinator = StructureAnalysisCoordinator.createDefault();
    private readonly tabs: TabsController<OverviewTab> = new TabsController(
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

    /**
     * Restores the remembered disclosure state. Read here rather than in the
     * constructor: `collapsible` is only set once attributes are applied, so
     * this is the first moment the surface that has no disclosure at all can
     * skip the storage read.
     */
    override connectedCallback(): void {
        super.connectedCallback();
        if (this.collapsible) {
            this.expanded = Client.get(EXPANDED_STORAGE_KEY) === '1';
        }
    }

    override disconnectedCallback(): void {
        this.analyzeTask.abort();
        super.disconnectedCallback();
    }

    protected override willUpdate(changed: PropertyValues<this>): void {
        if (
            changed.has('hasHeadingStructureAccess') ||
            changed.has('hasLandmarkStructureAccess') ||
            changed.has('interactiveLabelCount') ||
            changed.has('hasInteractiveLabels')
        ) {
            this.tabs.ensureActive(DEFAULT_DOMAIN);
        }
    }

    override render(): TemplateResult {
        return html`<div class="structure">
            ${this.announcer.render()}
            <div class="status-region" role="status">${this.renderError()}</div>
            ${this.renderErrorActions()}
            ${this.renderBody()}
        </div>`;
    }

    private enabledStructureTabs(): StructureDomain[] {
        return enabledDomains({
            headings: this.hasHeadingStructureAccess,
            landmarks: this.hasLandmarkStructureAccess,
        });
    }

    private enabledTabs(): OverviewTab[] {
        const tabs: OverviewTab[] = [...this.enabledStructureTabs()];

        if (this.hasInteractiveLabels && this.interactiveLabelCount > 0) {
            tabs.push('interactiveLabels');
        }

        return tabs;
    }

    private renderTablist(tabs: readonly OverviewTab[]): TemplateResult | typeof nothing {
        return this.tabs.renderTablist({
            ariaLabel: lll('mindfula11y.structure'),
            tabs: tabs.map((tab) => this.tabDescriptor(tab)),
        });
    }

    private tabDescriptor(tab: OverviewTab): TabDescriptor<OverviewTab> {
        // Interactive labels are rendered from the Fluid slot and therefore
        // only have a total count, not structure-analysis severity counts.
        if (tab === 'interactiveLabels') {
            return {
                id: tab,
                label: this.tabLabel(tab),
                badge: renderCountBadge(
                    impactState('minor'),
                    this.interactiveLabelCount,
                    `${this.interactiveLabelCount} ${this.tabLabel(tab)}`,
                ),
            };
        }

        // No disabled state: while the first analysis is pending, renderBody
        // shows the progress notice without a tablist, so descriptors are only
        // built once an analysis (or its superseded predecessor) is present.
        return {
            id: tab,
            label: this.tabLabel(tab),
            badge: this.renderTabBadge(severityCounts(this.analysis, [tab])),
        };
    }

    /** Count badge of the domain's worst present impact (worst-first, like the scan view). */
    private renderTabBadge(counts: Record<ImpactSeverity, number>): TemplateResult | typeof nothing {
        const worst = worstImpact(counts);
        if (worst === undefined) {
            return nothing;
        }
        return renderCountBadge(impactState(worst), counts[worst], `${counts[worst]} ${lll(severityLabelKey(worst))}`);
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
     * The open-page link is the recovery path for pages behind HTTP auth:
     * a top-level navigation gets the browser sign-in prompt the sandboxed
     * frame cannot show, and the per-origin auth cache then lets Retry
     * succeed.
     */
    private renderErrorActions(): TemplateResult | typeof nothing {
        if (this.analyzeTask.status !== TaskStatus.ERROR) {
            return nothing;
        }
        const error = this.analyzeTask.error;
        const pageUrl = error instanceof StructureAnalysisError ? error.pageUrl : undefined;
        return html`<div class="error-actions">
      <button
        type="button"
        class="button retry"
        @click=${(): void => {
            void this.analyzeTask.run();
        }}
      >
        ${lll('mindfula11y.structure.retry')}
      </button>
      ${
          pageUrl === undefined
              ? nothing
              : renderExternalLink({
                    className: 'button open-page',
                    href: pageUrl,
                    content: lll('mindfula11y.structure.error.rendering.openPage'),
                })
}
    </div>`;
    }

    /**
     * Both surfaces render the same thing — status row, tablist, panels — and
     * differ only in that the page module folds everything below the row into
     * a disclosure whose summary IS that row.
     */
    private renderBody(): TemplateResult | typeof nothing {
        if (this.analyzeTask.status === TaskStatus.ERROR) {
            return nothing;
        }
        if (this.analysis === null) {
            // Nothing to disclose yet, so the pending row stands on its own.
            return renderProgressNotice(lll('mindfula11y.structure.analyzing'));
        }

        const tabs = this.enabledTabs();
        const structureTabs = this.enabledStructureTabs();
        const content = html`${this.renderTablist(tabs)}${tabs.map((tab) => this.renderPanel(tab))}`;
        const statusRow = this.renderStatusRow(severityCounts(this.analysis, structureTabs));

        if (!this.collapsible) {
            return html`${statusRow}${content}`;
        }
        return html`<details ?open=${this.expanded} @toggle=${(event: Event): void => this.handleToggle(event)}>
      <summary class="disclosure">${statusRow}</summary>
      <div class="body">${content}</div>
    </details>`;
    }

    /**
     * A domain's panel carries its own findings: the pills are jump targets
     * into the view right below them, so listing another tab's findings here
     * would offer jumps into a hidden panel.
     */
    private renderPanel(tab: OverviewTab): TemplateResult {
        if (tab === 'interactiveLabels') {
            return this.tabs.renderPanel({
                tab,
                busy: false,
                content: html`<slot name="interactive-labels"></slot>`,
                label: this.tabLabel(tab),
            });
        }

        const domain = DOMAINS[tab];
        const analysis = this.analysis === null ? null : domain.analysisOf(this.analysis);
        return this.tabs.renderPanel({
            tab,
            busy: this.analyzeTask.status === TaskStatus.PENDING,
            content: html`${this.renderFindings(tab)}${domain.renderView(analysis, pageErrors(this.analysis, tab))}`,
            // Used only without a tablist, where nothing else names this view —
            // the status row above speaks for the widget, not for the domain.
            label: this.tabLabel(tab),
        });
    }

    /**
     * Mirrors the native disclosure state back into the component and
     * remembers it. Setting the `open` attribute on first render (restoring
     * a remembered expansion) queues a toggle task per the HTML spec, so this
     * also fires once with a state that already matches `expanded` — guard
     * against writing the (unchanged) value back to storage on every load.
     */
    private handleToggle(event: Event): void {
        const open = (event.currentTarget as HTMLDetailsElement).open;
        if (open === this.expanded) {
            return;
        }
        this.expanded = open;
        Client.set(EXPANDED_STORAGE_KEY, open ? '1' : '0');
    }

    /**
     * The widget's aggregate state in the overview callout's standardized
     * notice register — the same row shape as the alt-text count and the scan
     * status: message, then the shared issue-count badge. The state follows
     * the worst impact actually present, so a minor-only page does not read as
     * loud as a scan with serious violations (structure findings are all axe
     * best practices); the per-severity split stays on the tab badges and the
     * findings pills inside.
     *
     * The worst impact reaches assistive technology as text via
     * `renderSeverityLabel`, not by tint alone: the notice's state icon is
     * aria-hidden (core hardcodes that in Icon::render()) and two impacts
     * share one icon.
     */
    private renderStatusRow(totals: Record<ImpactSeverity, number>): TemplateResult {
        const worst = worstImpact(totals);
        if (worst === undefined) {
            return html`<mindfula11y-notice state="success">
        <span>${lll('mindfula11y.structure.noIssues')}</span>${this.renderMarker()}
      </mindfula11y-notice>`;
        }
        return html`<mindfula11y-notice state=${impactState(worst)} count=${totalCount(totals)}>
      ${renderSeverityLabel(worst, 'mindfula11y.structure.issuesFound')}${this.renderMarker()}
    </mindfula11y-notice>`;
    }

    /** The disclosure chevron — part of the status row on the page-module surface only. */
    private renderMarker(): TemplateResult | typeof nothing {
        return this.collapsible ? renderDisclosureMarker('trailing') : nothing;
    }

    private renderFindings(tab: StructureDomain): TemplateResult | typeof nothing {
        const findings = aggregateFindings(this.analysis, tab);
        if (findings.length === 0) {
            // The pill list stays silent when there is nothing to jump to —
            // the status row already carries the all-clear message.
            return nothing;
        }
        return html`<ul class="findings" aria-label=${lll('mindfula11y.structureErrors')}>
      ${findings.map((finding) =>
          renderFindingPill(
              finding.severity,
              (): void => this.focusFinding(tab, finding.key),
              html`${renderSeverityChip(finding.severity, finding.key)}
          <strong class="finding-count">${lll('mindfula11y.structure.findingCount', finding.count)}</strong>
          ${renderViewportBadges(finding.viewports)}`,
          ),
      )}
    </ul>`;
    }

    private tabLabel(tab: OverviewTab): string {
        if (tab === 'interactiveLabels') {
            return 'Labels';
        }

        return lll(DOMAINS[tab].labelKey);
    }

    /**
     * Jumps to the finding's first occurrence. No tab switch is needed: a
     * pill only exists in its own domain's panel, and an inactive panel is
     * `hidden`, so the finding's view is the one on screen.
     */
    private focusFinding(tab: StructureDomain, key: string): void {
        this.renderRoot.querySelector(DOMAINS[tab].tag)?.focusFirstIssue(key);
    }

    /**
     * Announces the analysis outcome with total moderate/minor counts — the
     * only impacts the structure analyzers emit (all their findings are axe
     * best practices; a future higher-impact rule must extend the label).
     */
    private async announceResult(signal: AbortSignal, isRefresh: boolean): Promise<void> {
        const { moderate, minor } = severityCounts(this.analysis, this.enabledStructureTabs());
        const key = isRefresh ? 'mindfula11y.structure.updated' : 'mindfula11y.structure.analyzed';
        await this.announcer.announce(lll(key, moderate, minor), signal);
    }
}

declare global {
    interface HTMLElementTagNameMap {
        'mindfula11y-structure': Structure;
    }
}
