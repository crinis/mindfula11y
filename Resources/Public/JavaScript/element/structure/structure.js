var __defProp = Object.defineProperty;
var __getOwnPropDesc = Object.getOwnPropertyDescriptor;
var __decorateClass = (decorators, target, key, kind) => {
  var result = kind > 1 ? void 0 : kind ? __getOwnPropDesc(target, key) : target;
  for (var i = decorators.length - 1, decorator; i >= 0; i--)
    if (decorator = decorators[i])
      result = (kind ? decorator(target, key, result) : decorator(result)) || result;
  if (kind && result) __defProp(target, key, result);
  return result;
};
import { Task, TaskStatus } from "@lit/task";
import Client from "@typo3/backend/storage/client.js";
import { lll } from "@typo3/core/lit-helper.js";
import { html, LitElement, nothing } from "lit";
import { customElement, property, state } from "lit/decorators.js";
import "@typo3/backend/element/icon-element.js";
import "@typo3/backend/element/spinner-element.js";
import "../heading-structure/heading-structure.js";
import "../landmark-structure/landmark-structure.js";
import "../interactive-labels/interactive-labels.js";
import "../notice/notice.js";
import { LiveAnnouncer } from "../../lib/live-announcer.js";
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
  worstImpact
} from "../../lib/status-render.js";
import { StructureAnalysisError } from "../../lib/structure/error.js";
import { aggregateFindings, enabledDomains, pageErrors, severityCounts } from "../../lib/structure/findings.js";
import { TabsController } from "../../lib/tabs.js";
import { StructureAnalysisCoordinator } from "../../service/structure/coordinator.js";
import { baseStyles } from "../../styles/base-styles.js";
import buttonStyles from "../../styles/button.css.js";
import disclosureStyles from "../../styles/disclosure.css.js";
import findingsStyles from "../../styles/findings.css.js";
import noticeStyles from "../../styles/notice.css.js";
import tabsStyles from "../../styles/tabs.css.js";
import viewportStyles from "../../styles/viewport.css.js";
import componentStyles from "./structure.css.js";
const EXPANDED_STORAGE_KEY = "mindfula11y-structure-expanded";
const DOMAINS = {
  headings: {
    labelKey: "mindfula11y.structure.headings",
    tag: "mindfula11y-heading-structure",
    analysisOf: (analysis) => analysis.headings,
    renderView: (analysis, pageLevelErrors) => html`<mindfula11y-heading-structure
        .nodes=${analysis?.nodes ?? []}
        .pageErrors=${pageLevelErrors}
      ></mindfula11y-heading-structure>`
  },
  landmarks: {
    labelKey: "mindfula11y.structure.landmarks",
    tag: "mindfula11y-landmark-structure",
    analysisOf: (analysis) => analysis.landmarks,
    renderView: (analysis, pageLevelErrors) => html`<mindfula11y-landmark-structure
        .nodes=${analysis?.nodes ?? []}
        .pageErrors=${pageLevelErrors}
      ></mindfula11y-landmark-structure>`
  }
};
const DEFAULT_DOMAIN = "headings";
let Structure = class extends LitElement {
  constructor() {
    super();
    this.pageId = 0;
    this.languageId = 0;
    this.hasHeadingStructureAccess = false;
    this.hasLandmarkStructureAccess = false;
    this.interactiveLabelCount = 0;
    this.hasInteractiveLabels = false;
    this.collapsible = false;
    this.analysis = null;
    /**
     * Mirror of the native `open` state, kept only so a re-render (a
     * save-triggered re-analysis) re-applies it. Deliberately NOT reactive:
     * `<details>` owns the state, and re-rendering on toggle would rebuild
     * both structure trees for a state the browser has already applied.
     */
    this.expanded = false;
    this.announcer = new LiveAnnouncer(this);
    this.coordinator = StructureAnalysisCoordinator.createDefault();
    this.tabs = new TabsController(
      this,
      () => this.enabledTabs(),
      DEFAULT_DOMAIN
    );
    this.analyzeTask = new Task(this, {
      args: () => [
        this.pageId,
        this.languageId,
        this.hasHeadingStructureAccess,
        this.hasLandmarkStructureAccess
      ],
      task: async ([pageId, languageId, hasHeadings, hasLandmarks], { signal }) => {
        if (pageId <= 0) {
          return;
        }
        const analysis = await this.coordinator.analyze(
          { pageId, languageId, headings: hasHeadings, landmarks: hasLandmarks },
          this.renderRoot,
          signal
        );
        const isRefresh = this.analysis !== null;
        this.analysis = analysis;
        await this.announceResult(signal, isRefresh);
        signal.throwIfAborted();
      }
    });
    this.addEventListener("mindfula11y:structure:changed", () => {
      void this.analyzeTask.run();
    });
  }
  /**
   * Restores the remembered disclosure state. Read here rather than in the
   * constructor: `collapsible` is only set once attributes are applied, so
   * this is the first moment the surface that has no disclosure at all can
   * skip the storage read.
   */
  connectedCallback() {
    super.connectedCallback();
    if (this.collapsible) {
      this.expanded = Client.get(EXPANDED_STORAGE_KEY) === "1";
    }
  }
  disconnectedCallback() {
    this.analyzeTask.abort();
    super.disconnectedCallback();
  }
  willUpdate(changed) {
    if (changed.has("hasHeadingStructureAccess") || changed.has("hasLandmarkStructureAccess") || changed.has("interactiveLabelCount") || changed.has("hasInteractiveLabels")) {
      this.tabs.ensureActive(DEFAULT_DOMAIN);
    }
  }
  render() {
    return html`<div class="structure">
            ${this.announcer.render()}
            <div class="status-region" role="status">${this.renderError()}</div>
            ${this.renderErrorActions()}
            ${this.renderBody()}
        </div>`;
  }
  enabledStructureTabs() {
    return enabledDomains({
      headings: this.hasHeadingStructureAccess,
      landmarks: this.hasLandmarkStructureAccess
    });
  }
  enabledTabs() {
    const tabs = [...this.enabledStructureTabs()];
    if (this.hasInteractiveLabels && this.interactiveLabelCount > 0) {
      tabs.push("interactiveLabels");
    }
    return tabs;
  }
  renderTablist(tabs) {
    return this.tabs.renderTablist({
      ariaLabel: lll("mindfula11y.structure"),
      tabs: tabs.map((tab) => this.tabDescriptor(tab))
    });
  }
  tabDescriptor(tab) {
    if (tab === "interactiveLabels") {
      return {
        id: tab,
        label: this.tabLabel(tab),
        badge: renderCountBadge(
          impactState("minor"),
          this.interactiveLabelCount,
          `${this.interactiveLabelCount} ${this.tabLabel(tab)}`
        )
      };
    }
    return {
      id: tab,
      label: this.tabLabel(tab),
      badge: this.renderTabBadge(severityCounts(this.analysis, [tab]))
    };
  }
  /** Count badge of the domain's worst present impact (worst-first, like the scan view). */
  renderTabBadge(counts) {
    const worst = worstImpact(counts);
    if (worst === void 0) {
      return nothing;
    }
    return renderCountBadge(impactState(worst), counts[worst], `${counts[worst]} ${lll(severityLabelKey(worst))}`);
  }
  renderError() {
    if (this.analyzeTask.status !== TaskStatus.ERROR) {
      return nothing;
    }
    const error = this.analyzeTask.error;
    const description = error instanceof StructureAnalysisError ? lll(`mindfula11y.structure.error.rendering.${error.code}`) : lll("mindfula11y.structure.error.rendering.description");
    return html`<mindfula11y-notice state="danger">
      ${renderNoticeBody({ title: lll("mindfula11y.structure.error.rendering"), description })}
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
  renderErrorActions() {
    if (this.analyzeTask.status !== TaskStatus.ERROR) {
      return nothing;
    }
    const error = this.analyzeTask.error;
    const pageUrl = error instanceof StructureAnalysisError ? error.pageUrl : void 0;
    return html`<div class="error-actions">
      <button
        type="button"
        class="button retry"
        @click=${() => {
      void this.analyzeTask.run();
    }}
      >
        ${lll("mindfula11y.structure.retry")}
      </button>
      ${pageUrl === void 0 ? nothing : renderExternalLink({
      className: "button open-page",
      href: pageUrl,
      content: lll("mindfula11y.structure.error.rendering.openPage")
    })}
    </div>`;
  }
  /**
   * Both surfaces render the same thing — status row, tablist, panels — and
   * differ only in that the page module folds everything below the row into
   * a disclosure whose summary IS that row.
   */
  renderBody() {
    if (this.analyzeTask.status === TaskStatus.ERROR) {
      return nothing;
    }
    if (this.analysis === null) {
      return renderProgressNotice(lll("mindfula11y.structure.analyzing"));
    }
    const tabs = this.enabledTabs();
    const structureTabs = this.enabledStructureTabs();
    const content = html`${this.renderTablist(tabs)}${tabs.map((tab) => this.renderPanel(tab))}`;
    const statusRow = this.renderStatusRow(severityCounts(this.analysis, structureTabs));
    if (!this.collapsible) {
      return html`${statusRow}${content}`;
    }
    return html`<details ?open=${this.expanded} @toggle=${(event) => this.handleToggle(event)}>
      <summary class="disclosure">${statusRow}</summary>
      <div class="body">${content}</div>
    </details>`;
  }
  /**
   * A domain's panel carries its own findings: the pills are jump targets
   * into the view right below them, so listing another tab's findings here
   * would offer jumps into a hidden panel.
   */
  renderPanel(tab) {
    if (tab === "interactiveLabels") {
      return this.tabs.renderPanel({
        tab,
        busy: false,
        content: html`<slot name="interactive-labels"></slot>`,
        label: this.tabLabel(tab)
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
      label: this.tabLabel(tab)
    });
  }
  /**
   * Mirrors the native disclosure state back into the component and
   * remembers it. Setting the `open` attribute on first render (restoring
   * a remembered expansion) queues a toggle task per the HTML spec, so this
   * also fires once with a state that already matches `expanded` — guard
   * against writing the (unchanged) value back to storage on every load.
   */
  handleToggle(event) {
    const open = event.currentTarget.open;
    if (open === this.expanded) {
      return;
    }
    this.expanded = open;
    Client.set(EXPANDED_STORAGE_KEY, open ? "1" : "0");
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
  renderStatusRow(totals) {
    const worst = worstImpact(totals);
    if (worst === void 0) {
      return html`<mindfula11y-notice state="success">
        <span>${lll("mindfula11y.structure.noIssues")}</span>${this.renderMarker()}
      </mindfula11y-notice>`;
    }
    return html`<mindfula11y-notice state=${impactState(worst)} count=${totalCount(totals)}>
      ${renderSeverityLabel(worst, "mindfula11y.structure.issuesFound")}${this.renderMarker()}
    </mindfula11y-notice>`;
  }
  /** The disclosure chevron — part of the status row on the page-module surface only. */
  renderMarker() {
    return this.collapsible ? renderDisclosureMarker("trailing") : nothing;
  }
  renderFindings(tab) {
    const findings = aggregateFindings(this.analysis, tab);
    if (findings.length === 0) {
      return nothing;
    }
    return html`<ul class="findings" aria-label=${lll("mindfula11y.structureErrors")}>
      ${findings.map(
      (finding) => renderFindingPill(
        finding.severity,
        () => this.focusFinding(tab, finding.key),
        html`${renderSeverityChip(finding.severity, finding.key)}
          <strong class="finding-count">${lll("mindfula11y.structure.findingCount", finding.count)}</strong>
          ${renderViewportBadges(finding.viewports)}`
      )
    )}
    </ul>`;
  }
  tabLabel(tab) {
    if (tab === "interactiveLabels") {
      return "Labels";
    }
    return lll(DOMAINS[tab].labelKey);
  }
  /**
   * Jumps to the finding's first occurrence. No tab switch is needed: a
   * pill only exists in its own domain's panel, and an inactive panel is
   * `hidden`, so the finding's view is the one on screen.
   */
  focusFinding(tab, key) {
    this.renderRoot.querySelector(DOMAINS[tab].tag)?.focusFirstIssue(key);
  }
  /**
   * Announces the analysis outcome with total moderate/minor counts — the
   * only impacts the structure analyzers emit (all their findings are axe
   * best practices; a future higher-impact rule must extend the label).
   */
  async announceResult(signal, isRefresh) {
    const { moderate, minor } = severityCounts(this.analysis, this.enabledStructureTabs());
    const key = isRefresh ? "mindfula11y.structure.updated" : "mindfula11y.structure.analyzed";
    await this.announcer.announce(lll(key, moderate, minor), signal);
  }
};
Structure.styles = [
  ...baseStyles,
  noticeStyles,
  disclosureStyles,
  tabsStyles,
  findingsStyles,
  buttonStyles,
  viewportStyles,
  componentStyles
];
__decorateClass([
  property({ type: Number, attribute: "page-id" })
], Structure.prototype, "pageId", 2);
__decorateClass([
  property({ type: Number, attribute: "language-id" })
], Structure.prototype, "languageId", 2);
__decorateClass([
  property({ type: Boolean, attribute: "has-heading-structure-access" })
], Structure.prototype, "hasHeadingStructureAccess", 2);
__decorateClass([
  property({ type: Boolean, attribute: "has-landmark-structure-access" })
], Structure.prototype, "hasLandmarkStructureAccess", 2);
__decorateClass([
  property({ type: Number, attribute: "interactive-label-count" })
], Structure.prototype, "interactiveLabelCount", 2);
__decorateClass([
  property({ type: Boolean, attribute: "has-interactive-labels" })
], Structure.prototype, "hasInteractiveLabels", 2);
__decorateClass([
  property({ type: Boolean })
], Structure.prototype, "collapsible", 2);
__decorateClass([
  state()
], Structure.prototype, "analysis", 2);
Structure = __decorateClass([
  customElement("mindfula11y-structure")
], Structure);
export {
  Structure
};
