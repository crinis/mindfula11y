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
import { lll } from "@typo3/core/lit-helper.js";
import { html, LitElement, nothing } from "lit";
import { customElement, property, state } from "lit/decorators.js";
import { literal, html as staticHtml } from "lit/static-html.js";
import "@typo3/backend/element/icon-element.js";
import "@typo3/backend/element/spinner-element.js";
import "../heading-structure/heading-structure.js";
import "../landmark-structure/landmark-structure.js";
import "../notice/notice.js";
import { LiveAnnouncer } from "../../lib/live-announcer.js";
import {
  noticeState,
  renderLoadingPlaceholder,
  renderNoticeBody,
  renderSeverityChip,
  renderViewportBadges
} from "../../lib/status-render.js";
import { StructureAnalysisError } from "../../lib/structure/error.js";
import {
  aggregateFindings,
  enabledDomains,
  pageErrors,
  severityCounts
} from "../../lib/structure/findings.js";
import { TabsController } from "../../lib/tabs.js";
import { StructureAnalysisCoordinator } from "../../service/structure/coordinator.js";
import { baseStyles } from "../../styles/base-styles.js";
import buttonStyles from "../../styles/button.css.js";
import findingsStyles from "../../styles/findings.css.js";
import noticeStyles from "../../styles/notice.css.js";
import placeholderStyles from "../../styles/placeholder.css.js";
import tabsStyles from "../../styles/tabs.css.js";
import viewportStyles from "../../styles/viewport.css.js";
import componentStyles from "./structure.css.js";
const HEADING_TAGS = {
  1: literal`h1`,
  2: literal`h2`,
  3: literal`h3`,
  4: literal`h4`,
  5: literal`h5`,
  6: literal`h6`
};
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
    this.headingLevel = 2;
    this.hasHeadingStructureAccess = false;
    this.hasLandmarkStructureAccess = false;
    this.analysis = null;
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
  disconnectedCallback() {
    this.analyzeTask.abort();
    super.disconnectedCallback();
  }
  willUpdate(changed) {
    if (changed.has("hasHeadingStructureAccess") || changed.has("hasLandmarkStructureAccess")) {
      this.tabs.ensureActive(DEFAULT_DOMAIN);
    }
  }
  render() {
    return html`<div class="structure">
            ${this.renderHeader()}
            ${this.announcer.render()}
            <div class="status-region" role="status">${this.renderError()}</div>
            ${this.renderBody()}
        </div>`;
  }
  enabledTabs() {
    return enabledDomains(this.enabledFlags());
  }
  enabledFlags() {
    return {
      headings: this.hasHeadingStructureAccess,
      landmarks: this.hasLandmarkStructureAccess
    };
  }
  renderHeader() {
    const tabs = this.enabledTabs();
    if (tabs.length < 2) {
      const single = tabs[0];
      return single === void 0 ? nothing : this.renderHeading(this.tabLabel(single));
    }
    return this.tabs.renderTablist({
      ariaLabel: lll("mindfula11y.structure"),
      tabs: tabs.map((tab) => this.tabDescriptor(tab))
    });
  }
  tabDescriptor(tab) {
    return {
      id: tab,
      label: this.tabLabel(tab),
      badge: this.renderTabBadge(severityCounts(this.analysis, tab)),
      disabled: this.analysis === null && this.analyzeTask.status === TaskStatus.PENDING
    };
  }
  renderTabBadge(counts) {
    if (counts.errors > 0) {
      return html`<span class="notice count" data-state="danger" data-variant="pill"
                >${counts.errors}<span class="sr-only"> ${lll("mindfula11y.severity.error")}</span></span
            >`;
    }
    if (counts.warnings > 0) {
      return html`<span class="notice count" data-state="warning" data-variant="pill"
                >${counts.warnings}<span class="sr-only"> ${lll("mindfula11y.severity.warning")}</span></span
            >`;
    }
    return nothing;
  }
  renderError() {
    if (this.analyzeTask.status !== TaskStatus.ERROR) {
      return nothing;
    }
    const error = this.analyzeTask.error;
    const description = error instanceof StructureAnalysisError ? lll(`mindfula11y.structure.error.rendering.${error.code}`) : lll("mindfula11y.structure.error.rendering.description");
    return html`<mindfula11y-notice state="danger">
            ${renderNoticeBody({ title: lll("mindfula11y.structure.error.rendering"), description })}
            <button
                type="button"
                class="button retry"
                @click=${() => {
      void this.analyzeTask.run();
    }}
            >
                ${lll("mindfula11y.structure.retry")}
            </button>
        </mindfula11y-notice>`;
  }
  renderBody() {
    if (this.analyzeTask.status === TaskStatus.ERROR) {
      return nothing;
    }
    if (this.analysis === null) {
      return renderLoadingPlaceholder(lll("mindfula11y.structure.analyzing"));
    }
    const tabs = this.enabledTabs();
    return html`${this.renderSummary()}
        ${tabs.map((tab) => this.renderPanel(tab, tabs.length > 1))}`;
  }
  renderPanel(tab, withTabs) {
    const busy = this.analyzeTask.status === TaskStatus.PENDING;
    const domain = DOMAINS[tab];
    const analysis = this.analysis === null ? null : domain.analysisOf(this.analysis);
    const view = domain.renderView(analysis, pageErrors(this.analysis, tab));
    return this.tabs.renderPanel({
      tab,
      withTablist: withTabs,
      busy,
      content: view
    });
  }
  renderSummary() {
    const findings = aggregateFindings(this.analysis, this.enabledFlags());
    if (findings.length === 0) {
      return nothing;
    }
    return html`<section class="summary" aria-label=${lll("mindfula11y.structureErrors")}>
            <ul class="findings">
                ${findings.map(
      (finding) => html`<li>
                        <button
                            type="button"
                            class="notice finding"
                            data-state=${noticeState(finding.severity)}
                            data-variant="pill"
                            @click=${() => {
        void this.handleFindingClick(finding);
      }}
                        >
                            ${renderSeverityChip(finding.severity, finding.key)}
                            <span class="finding-count">${finding.count}</span>
                            ${renderViewportBadges(finding.viewports)}
                        </button>
                    </li>`
    )}
            </ul>
        </section>`;
  }
  renderHeading(content) {
    const tag = HEADING_TAGS[this.headingLevel] ?? HEADING_TAGS[2];
    return staticHtml`<${tag} class="title">${content}</${tag}>`;
  }
  tabLabel(tab) {
    return lll(DOMAINS[tab].labelKey);
  }
  async handleFindingClick(finding) {
    this.tabs.select(finding.domain);
    await this.updateComplete;
    const view = this.renderRoot.querySelector(DOMAINS[finding.domain].tag);
    view?.focusFirstIssue(finding.key);
  }
  /** Announces the analysis outcome with total error/warning counts. */
  async announceResult(signal, isRefresh) {
    let errors = 0;
    let warnings = 0;
    for (const domain of this.enabledTabs()) {
      const counts = severityCounts(this.analysis, domain);
      errors += counts.errors;
      warnings += counts.warnings;
    }
    const key = isRefresh ? "mindfula11y.structure.updated" : "mindfula11y.structure.analyzed";
    await this.announcer.announce(lll(key, errors, warnings), signal);
  }
};
Structure.styles = [
  ...baseStyles,
  noticeStyles,
  tabsStyles,
  findingsStyles,
  buttonStyles,
  placeholderStyles,
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
  property({ type: Number, attribute: "heading-level" })
], Structure.prototype, "headingLevel", 2);
__decorateClass([
  property({ type: Boolean, attribute: "has-heading-structure-access" })
], Structure.prototype, "hasHeadingStructureAccess", 2);
__decorateClass([
  property({ type: Boolean, attribute: "has-landmark-structure-access" })
], Structure.prototype, "hasLandmarkStructureAccess", 2);
__decorateClass([
  state()
], Structure.prototype, "analysis", 2);
Structure = __decorateClass([
  customElement("mindfula11y-structure")
], Structure);
export {
  Structure
};
