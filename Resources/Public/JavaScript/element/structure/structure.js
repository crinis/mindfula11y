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
import "@typo3/backend/element/icon-element.js";
import "@typo3/backend/element/spinner-element.js";
import "../heading-structure/heading-structure.js";
import "../landmark-structure/landmark-structure.js";
import "../notice/notice.js";
import { analyzeHeadings } from "../../lib/heading-analysis.js";
import { analyzeLandmarks } from "../../lib/landmark-analysis.js";
import { LiveAnnouncer } from "../../lib/live-announcer.js";
import { activateTabFromKeydown } from "../../lib/tablist.js";
import { noticeState, StructureErrorSeverity, severityLabelKey } from "../../lib/types.js";
import { ContentLoader } from "../../service/content-loader.js";
import { baseStyles } from "../../styles/base-styles.js";
import buttonStyles from "../../styles/button.css.js";
import findingsStyles from "../../styles/findings.css.js";
import noticeStyles from "../../styles/notice.css.js";
import placeholderStyles from "../../styles/placeholder.css.js";
import tabsStyles from "../../styles/tabs.css.js";
import componentStyles from "./structure.css.js";
let Structure = class extends LitElement {
  constructor() {
    super();
    this.previewUrl = "";
    this.headingLevel = 2;
    this.hasHeadingStructureAccess = false;
    this.hasLandmarkStructureAccess = false;
    this.analysis = null;
    this.activeTab = "headings";
    this.contentLoader = new ContentLoader();
    this.announcer = new LiveAnnouncer(this);
    this.isRefresh = false;
    this.analyzeTask = new Task(this, {
      args: () => [
        this.previewUrl,
        this.hasHeadingStructureAccess,
        this.hasLandmarkStructureAccess
      ],
      task: async ([previewUrl, hasHeadings, hasLandmarks]) => {
        if (previewUrl === "") {
          return;
        }
        const htmlText = await this.contentLoader.load(previewUrl);
        const doc = new DOMParser().parseFromString(htmlText, "text/html");
        this.analysis = {
          headings: hasHeadings ? analyzeHeadings(doc) : null,
          landmarks: hasLandmarks ? analyzeLandmarks(doc) : null
        };
        await this.announceResult();
      }
    });
    this.handleTabKeydown = (event) => {
      void activateTabFromKeydown(this, event, this.enabledTabs(), this.activeTab, (tab) => {
        this.activeTab = tab;
      });
    };
    this.addEventListener("mindfula11y:structure:changed", () => {
      this.contentLoader.invalidate(this.previewUrl);
      this.isRefresh = true;
      void this.analyzeTask.run();
    });
  }
  willUpdate(changed) {
    if ((changed.has("hasHeadingStructureAccess") || changed.has("hasLandmarkStructureAccess")) && !this.enabledTabs().includes(this.activeTab)) {
      this.activeTab = this.enabledTabs()[0] ?? "headings";
    }
  }
  render() {
    return html`<div class="structure">
            ${this.renderHeader()}
            ${this.announcer.render()}
            ${this.renderBody()}
        </div>`;
  }
  enabledTabs() {
    const tabs = [];
    if (this.hasHeadingStructureAccess) {
      tabs.push("headings");
    }
    if (this.hasLandmarkStructureAccess) {
      tabs.push("landmarks");
    }
    return tabs;
  }
  renderHeader() {
    const tabs = this.enabledTabs();
    if (tabs.length < 2) {
      const single = tabs[0];
      return single === void 0 ? nothing : this.renderHeading(this.tabLabel(single));
    }
    return html`<div class="tabs" role="tablist" aria-label=${lll("mindfula11y.structure")}>
            ${tabs.map((tab) => this.renderTab(tab))}
        </div>`;
  }
  renderTab(tab) {
    const selected = this.activeTab === tab;
    const counts = this.severityCounts(tab);
    return html`<button
            type="button"
            role="tab"
            id="tab-${tab}"
            data-tab=${tab}
            aria-selected=${selected ? "true" : "false"}
            aria-controls="panel-${tab}"
            tabindex=${selected ? "0" : "-1"}
            ?disabled=${this.analysis === null && this.analyzeTask.status === TaskStatus.PENDING}
            @click=${() => {
      this.activeTab = tab;
    }}
            @keydown=${this.handleTabKeydown}
        >
            ${this.tabLabel(tab)} ${this.renderTabBadge(counts)}
        </button>`;
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
  renderBody() {
    if (this.analyzeTask.status === TaskStatus.ERROR) {
      return html`<mindfula11y-notice state="danger">
                <span>
                    <span class="notice-title">${lll("mindfula11y.general.error.loading")}</span>
                    ${lll("mindfula11y.general.error.loading.description")}
                </span>
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
    if (this.analysis === null) {
      return html`<div class="placeholder">
                <typo3-backend-spinner size="default"></typo3-backend-spinner>
                <span>${lll("mindfula11y.structure.analyzing")}</span>
            </div>`;
    }
    const tabs = this.enabledTabs();
    return html`${this.renderSummary()}
        ${tabs.map((tab) => this.renderPanel(tab, tabs.length > 1))}`;
  }
  renderPanel(tab, withTabs) {
    const busy = this.analyzeTask.status === TaskStatus.PENDING;
    const view = tab === "headings" ? html`<mindfula11y-heading-structure
                      .nodes=${this.analysis?.headings?.nodes ?? []}
                      .pageErrors=${this.pageErrors("headings")}
                  ></mindfula11y-heading-structure>` : html`<mindfula11y-landmark-structure
                      .nodes=${this.analysis?.landmarks?.nodes ?? []}
                      .pageErrors=${this.pageErrors("landmarks")}
                  ></mindfula11y-landmark-structure>`;
    if (!withTabs) {
      return html`<div class="panel" aria-busy=${busy ? "true" : "false"}>${view}</div>`;
    }
    return html`<div
            class="panel"
            role="tabpanel"
            id="panel-${tab}"
            aria-labelledby="tab-${tab}"
            tabindex="0"
            aria-busy=${busy ? "true" : "false"}
            ?hidden=${this.activeTab !== tab}
        >
            ${view}
        </div>`;
  }
  renderSummary() {
    const findings = this.aggregateFindings();
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
                            <typo3-backend-icon
                                identifier=${finding.severity === StructureErrorSeverity.Error ? "status-dialog-error" : "status-dialog-warning"}
                                size="small"
                            ></typo3-backend-icon>
                            <span><span class="sr-only">${lll(severityLabelKey(finding.severity))}: </span>${lll(finding.key)}</span>
                            <span class="finding-count">${finding.count}</span>
                        </button>
                    </li>`
    )}
            </ul>
        </section>`;
  }
  renderHeading(content) {
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
  tabLabel(tab) {
    return tab === "headings" ? lll("mindfula11y.structure.headings") : lll("mindfula11y.structure.landmarks");
  }
  domainErrors(domain) {
    const analysis = domain === "headings" ? this.analysis?.headings : this.analysis?.landmarks;
    return analysis?.errors ?? [];
  }
  pageErrors(domain) {
    return this.domainErrors(domain).filter((error) => error.nodeId === null);
  }
  severityCounts(domain) {
    const errors = this.domainErrors(domain);
    return {
      errors: errors.filter((error) => error.severity === StructureErrorSeverity.Error).length,
      warnings: errors.filter((error) => error.severity === StructureErrorSeverity.Warning).length
    };
  }
  aggregateFindings() {
    const findings = /* @__PURE__ */ new Map();
    for (const domain of this.enabledTabs()) {
      for (const error of this.domainErrors(domain)) {
        const existing = findings.get(error.key);
        if (existing === void 0) {
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
  async handleFindingClick(finding) {
    this.activeTab = finding.domain;
    await this.updateComplete;
    const view = this.renderRoot.querySelector(
      finding.domain === "headings" ? "mindfula11y-heading-structure" : "mindfula11y-landmark-structure"
    );
    view?.focusFirstIssue(finding.key);
  }
  /** Announces the analysis outcome with total error/warning counts. */
  async announceResult() {
    let errors = 0;
    let warnings = 0;
    for (const domain of this.enabledTabs()) {
      const counts = this.severityCounts(domain);
      errors += counts.errors;
      warnings += counts.warnings;
    }
    const key = this.isRefresh ? "mindfula11y.structure.updated" : "mindfula11y.structure.analyzed";
    this.isRefresh = false;
    await this.announcer.announce(lll(key, errors, warnings));
  }
};
Structure.styles = [
  ...baseStyles,
  noticeStyles,
  tabsStyles,
  findingsStyles,
  buttonStyles,
  placeholderStyles,
  componentStyles
];
__decorateClass([
  property({ attribute: "preview-url" })
], Structure.prototype, "previewUrl", 2);
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
__decorateClass([
  state()
], Structure.prototype, "activeTab", 2);
Structure = __decorateClass([
  customElement("mindfula11y-structure")
], Structure);
export {
  Structure
};
