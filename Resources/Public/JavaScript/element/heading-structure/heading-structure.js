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
import Notification from "@typo3/backend/notification.js";
import { lll } from "@typo3/core/lit-helper.js";
import { html, nothing } from "lit";
import { customElement } from "lit/decorators.js";
import { repeat } from "lit/directives/repeat.js";
import "@typo3/backend/element/icon-element.js";
import { renderViewportBadges } from "../../lib/status-render.js";
import { HEADING_ERROR_KEYS, StructureErrorSeverity } from "../../lib/structure/types.js";
import {
  StructureView
} from "../structure-view/structure-view.js";
import componentStyles from "./heading-structure.css.js";
let HeadingStructure = class extends StructureView {
  constructor() {
    super(...arguments);
    this.controlSelector = '[data-control="level"], [data-control="child-level"]';
    this.emptyLabelKey = "mindfula11y.structure.headings.noHeadings";
    this.labelPrefix = "mindfula11y.structure.headings";
    /**
     * Relation ids of headings actually present in the analyzed document,
     * rebuilt per render: a suppressed container heading registers a relation
     * without leaving a DOM node, so a jump affordance targeting it would
     * dead-end in an "Ancestor not found" notice — such targets get no jump.
     */
    this.knownRelationIds = /* @__PURE__ */ new Set();
  }
  renderNodes(nodes) {
    this.knownRelationIds = this.collectRelationIds(nodes, /* @__PURE__ */ new Set());
    return this.renderTree(nodes);
  }
  /** Heading page errors render as rows in the same flat list as node errors. */
  renderPageErrors() {
    return nothing;
  }
  collectRelationIds(nodes, ids) {
    for (const node of nodes) {
      if (node.relationId !== "") {
        ids.add(node.relationId);
      }
      this.collectRelationIds(node.children, ids);
    }
    return ids;
  }
  relationTargetExists(node) {
    const targetId = node.relation?.targetRelationId ?? "";
    return targetId !== "" && this.knownRelationIds.has(targetId);
  }
  renderTree(nodes) {
    return html`<ol class="tree">
            ${this.pageErrors.map((error) => this.renderPageIssueItem(error))}
            ${repeat(
      this.flattenTree(nodes, 0),
      (item) => item.key,
      (item) => item.template
    )}
        </ol>`;
  }
  /** A page-level heading finding has no affected node, so it becomes its own unindented issue row. */
  renderPageIssueItem(error) {
    return this.renderIssueItem(error, {
      issueKind: "page",
      issueOptions: { pageScope: true }
    });
  }
  /** One consistent list row for issue-only cases. */
  renderIssueItem(error, options) {
    return this.renderListItem(
      this.renderHeadingRow({
        errors: [error],
        issueId: options.issueId,
        issueOptions: options.issueOptions
      }),
      options
    );
  }
  /**
   * Pre-order flattening of the analyzer's tree into ONE list: nesting depth
   * would double-book (and in skip/container/pre-H1 cases contradict) the
   * heading level every row already announces, so hierarchy is conveyed by
   * the per-row level text and the indentation is purely visual, derived
   * from the heading level itself. Missing-level placeholders precede their
   * skipping heading at the absent levels' indents.
   */
  flattenTree(nodes, parentIndent) {
    const items = [];
    for (const node of nodes) {
      for (let missingLevel = node.level - node.skippedLevels; missingLevel < node.level; missingLevel++) {
        items.push({
          key: `${node.id}#missing-${missingLevel}`,
          template: this.renderPlaceholderItem(node, missingLevel)
        });
      }
      const indent = node.kind === "container" ? this.containerIndent(node, parentIndent) : node.level;
      items.push({ key: node.id, template: this.renderItem(node, indent) });
      items.push(...this.flattenTree(node.children, indent));
    }
    return items;
  }
  /**
   * A container row's indent expresses its parental role, one step above the
   * level its children derive: the explicitly stored child type when set,
   * else the automatic derivation base — its own (unrendered) level, or the
   * tree parent when it has none. Its stored level itself is communicated by
   * the row's level select, not by indentation.
   */
  containerIndent(node, parentIndent) {
    const childType = /^h([1-6])$/.exec(node.childTypeRecord?.storedValue ?? "");
    if (childType !== null) {
      return Number.parseInt(childType[1] ?? "0", 10) - 1;
    }
    return node.level > 0 ? node.level : parentIndent + 1;
  }
  renderItem(node, indent) {
    return this.renderListItem(this.renderRow(node), {
      indent,
      focusLabelId: this.rowLabelId(node.id)
    });
  }
  /** Visible row content that names the native list-item focus fallback. */
  rowLabelId(nodeId) {
    return `heading-row-label-${nodeId}`;
  }
  /** The single list-item shell used by every ordinary and issue-only row. */
  renderListItem(content, options) {
    return html`<li
            class="node"
            data-issue-kind=${options.issueKind ?? nothing}
            data-focus-fallback=${options.focusLabelId ?? nothing}
            style=${options.indent === void 0 ? nothing : `--mindfula11y-heading-structure-indent: ${options.indent - 1}`}
        >
            ${content}
        </li>`;
  }
  /**
   * Issue-only stand-in row for one skipped heading level, indented at the
   * missing level's own step. The level directly above the skipping heading
   * carries the `skip-…` id its describedby references.
   */
  renderPlaceholderItem(node, missingLevel) {
    const error = node.errors.find((candidate) => candidate.key === HEADING_ERROR_KEYS.skippedLevel) ?? {
      key: HEADING_ERROR_KEYS.skippedLevel,
      severity: StructureErrorSeverity.Error,
      nodeId: node.id,
      viewports: node.viewports
    };
    return this.renderIssueItem(error, {
      issueKind: "missing-level",
      indent: missingLevel,
      ...missingLevel === node.level - 1 ? { issueId: `skip-${node.id}` } : {},
      issueOptions: {
        labelKey: "mindfula11y.structure.headings.error.skippedLevel.inline",
        labelArguments: [missingLevel]
      }
    });
  }
  /**
   * Errors rendered as cues inside the affected row itself. Every node
   * finding renders in-row except an ordinary heading's skipped level: its
   * missing-level placeholder row (see renderPlaceholderItem()) already IS the finding,
   * placed where the missing level belongs, so an in-row chip would only
   * duplicate it. Container rows keep their attributed skip in-row — they
   * never render placeholders.
   */
  inRowErrors(node) {
    if (node.kind === "container") {
      return node.errors;
    }
    return node.errors.filter((error) => error.key !== HEADING_ERROR_KEYS.skippedLevel);
  }
  /**
   * References everything describing the row's state: the in-row error cues
   * (`issue-…`) and, for an unattributed skip, the innermost missing-level
   * placeholder's message (`skip-…`) — so the select announces "Missing
   * heading level N …" instead of a generic chip.
   */
  describedby(node) {
    const ids = [];
    if (this.inRowErrors(node).length > 0) {
      ids.push(`issue-${node.id}`);
    }
    if (node.skippedLevels > 0) {
      ids.push(`skip-${node.id}`);
    }
    return ids.length > 0 ? ids.join(" ") : nothing;
  }
  renderRow(node) {
    const isContainer = node.kind === "container";
    const label = node.label !== "" ? node.label : lll(
      isContainer ? "mindfula11y.structure.headings.container" : "mindfula11y.structure.headings.unlabeled"
    );
    const editable = node.record !== null && node.record.editLink !== "";
    const hasChildControl = this.hasChildLevelControl(node);
    const inRowErrors = this.inRowErrors(node);
    const content = html`<div class="heading" id=${this.rowLabelId(node.id)}>
                ${this.renderLevelControl(node, label, editable)}
                ${isContainer ? html`<span class="text">
                              <span class="container-badge">
                                  <typo3-backend-icon identifier="overlay-hidden" size="small"></typo3-backend-icon>
                                  ${label}
                              </span>
                          </span>` : html`<span class="text" ?data-empty=${node.label === ""}>${label}</span>`}
            </div>
            ${this.renderChildLevelControl(node, label)}
            <div class="meta" ?data-child-control=${hasChildControl}>
                ${renderViewportBadges(node.viewports)}
                <span class="actions">
                    ${editable && this.hasRecord(node) ? this.renderEditLink(node, label) : nothing}
                    ${this.renderBusySpinner(node)}
                </span>
            </div>`;
    return this.renderHeadingRow({
      errors: inRowErrors,
      content,
      nodeId: node.id,
      relationId: node.relationId,
      container: isContainer,
      childControl: hasChildControl,
      issueId: `issue-${node.id}`,
      issueOptions: (error) => ({ showViewports: !this.hasSameViewports(error, node) })
    });
  }
  /** The single row shell used by issue-only, ordinary heading and hidden-container rows. */
  renderHeadingRow(options) {
    const hasError = options.errors.some((error) => error.severity === StructureErrorSeverity.Error);
    return html`<div
            class="row"
            data-node-id=${options.nodeId ?? nothing}
            data-relation-id=${options.relationId ?? nothing}
            ?data-container=${options.container ?? false}
            ?data-child-control=${options.childControl ?? false}
            ?data-error=${hasError}
            ?data-warning=${options.errors.length > 0 && !hasError}
        >
            ${options.content ?? nothing}
            ${options.errors.length > 0 ? this.renderIssueGroup(options.errors, {
      className: "row-issues",
      id: options.issueId,
      issueOptions: options.issueOptions
    }) : nothing}
        </div>`;
  }
  /** Whether issue viewport badges would merely repeat the affected row's badges. */
  hasSameViewports(error, node) {
    return error.viewports.length === node.viewports.length && error.viewports.every((viewport) => node.viewports.includes(viewport));
  }
  renderLevelControl(node, label, editable) {
    if (node.relation !== null) {
      const hasTarget = this.relationTargetExists(node);
      const content = this.renderRelationLevelContent(node, hasTarget);
      if (!hasTarget) {
        return html`<span class="level" data-relation data-relation-kind=${node.relation.kind}>${content}</span>`;
      }
      return html`<button
                type="button"
                class="level"
                data-relation
                data-relation-kind=${node.relation.kind}
                data-control="level"
                aria-describedby=${this.describedby(node)}
                @click=${() => this.handleRelationJump(node)}
            >
                ${content}
            </button>`;
    }
    if (editable && node.record !== null && Object.keys(node.availableTypes).length > 0) {
      const currentValue = node.record.storedValue ?? `h${node.level}`;
      return this.renderValueSelect(node, {
        id: `level-${node.id}`,
        className: "level",
        ariaLabel: `${lll("mindfula11y.structure.headings.type")}: ${label}`,
        currentValue,
        options: this.buildLevelOptions(node.availableTypes, currentValue, node.level > 0 ? node.level : null)
      });
    }
    return html`<span class="level" data-locked>
            ${this.renderLockedChip(node.level > 0 ? this.headingLevelLabel(node.level) : "\u2014")}
        </span>`;
  }
  /** Shared visible and screen-reader explanation for both relation variants. */
  renderRelationLevelContent(node, hasTarget) {
    const relationKey = node.relation?.kind === "ancestor" ? "mindfula11y.structure.headings.relation.descendant" : "mindfula11y.structure.headings.relation.sibling";
    return html`${this.headingLevelLabel(node.level)}
            <span class="relation-label">${lll(relationKey)}</span>
            <typo3-backend-icon
                identifier=${hasTarget ? "actions-link" : "actions-lock"}
                size="small"
                aria-hidden="true"
            ></typo3-backend-icon>
            <span class="sr-only">
                ${lll("mindfula11y.structure.headings.relation.readonly")}
                ${hasTarget ? lll("mindfula11y.structure.headings.relation.jump") : nothing}
            </span>`;
  }
  /**
   * Select writing the container-owned child-type column, rendered on the row
   * of the element that stores it: changing the children's level is visibly an
   * action on the container, and every derived row stays read-only with a jump
   * here. Omitted without editable coordinates (no perms, column not in the
   * record type's showitem, custom table without the column).
   */
  renderChildLevelControl(node, label) {
    if (!this.hasChildLevelControl(node)) {
      return nothing;
    }
    const record = node.childTypeRecord;
    if (record === null) {
      return nothing;
    }
    const currentValue = record.storedValue ?? "";
    const childLevel = node.level > 0 ? node.level + 1 : null;
    return html`<span class="child-control">
            <label id="child-level-label-${node.id}" class="child-label" for="child-level-${node.id}"
                >${lll("mindfula11y.structure.headings.childType")}</label
            >
            <span id="child-level-context-${node.id}" class="sr-only">: ${label}</span>
            ${this.renderValueSelect(node, {
      id: `child-level-${node.id}`,
      className: "child-level",
      ariaLabelledby: `child-level-label-${node.id} child-level-context-${node.id}`,
      currentValue,
      options: this.buildLevelOptions(node.availableChildTypes, currentValue, childLevel),
      record,
      describedby: `child-level-note-${node.id}`
    })}
            <span id="child-level-note-${node.id}" class="sr-only"
                >${lll("mindfula11y.structure.headings.childType.applies")}</span
            >
        </span>`;
  }
  hasChildLevelControl(node) {
    return node.childTypeRecord !== null && node.childTypeRecord.editLink !== "" && Object.keys(node.availableChildTypes).length > 0;
  }
  /** Same localized level label used by FormEngine options and every module badge. */
  headingLevelLabel(level) {
    return lll(`mindfula11y.structure.headings.level.h${level}`);
  }
  /**
   * Maps a level/child-type option map to display labels via levelOptionLabel(),
   * shared by the level and child-type selects (they differ only in the source
   * map and the effective level passed to the "automatic" option).
   */
  buildLevelOptions(available, currentValue, effectiveLevel) {
    return Object.fromEntries(
      Object.entries(available).map(([type, typeLabel]) => [
        type,
        this.levelOptionLabel(type, typeLabel, currentValue, effectiveLevel)
      ])
    );
  }
  /**
   * Select option labels use the intent-based, translated TCA labels. A currently
   * selected "automatic" option carries the
   * effective level (own level for the level select, own level + 1 for the
   * child-type select), so the level stays visible in the collapsed select.
   * An effective level past H6 shows the paragraph label: HeadingType::increment() overflows
   * derived levels beyond h6 to a paragraph, so an H6 container's automatic
   * children must never be promised an "H7".
   */
  levelOptionLabel(type, typeLabel, currentValue, effectiveLevel) {
    if (type === "" && currentValue === "" && effectiveLevel !== null) {
      const effectiveLabel = effectiveLevel > 6 ? lll("mindfula11y.structure.headings.level.p") : this.headingLevelLabel(effectiveLevel);
      return `${typeLabel}: ${effectiveLabel}`;
    }
    return typeLabel;
  }
  handleRelationJump(node) {
    const targetId = node.relation?.targetRelationId ?? "";
    const rows = targetId === "" ? [] : Array.from(
      this.renderRoot.querySelectorAll(`[data-relation-id="${CSS.escape(targetId)}"]`)
    );
    const own = this.renderRoot.querySelector(`[data-node-id="${CSS.escape(node.id)}"]`);
    const target = rows.filter(
      (row) => own === null || (row.compareDocumentPosition(own) & Node.DOCUMENT_POSITION_FOLLOWING) !== 0
    ).at(-1) ?? rows.at(0) ?? null;
    if (target === null) {
      Notification.warning(
        lll("mindfula11y.structure.headings.relation.notFound"),
        lll("mindfula11y.structure.headings.relation.notFound.description")
      );
      return;
    }
    this.focusRow(target, {
      preferredControl: node.relation?.kind === "ancestor" ? "child-level" : "level",
      fallbackToOtherControls: false
    });
  }
};
HeadingStructure.styles = [...StructureView.viewStyles, componentStyles];
HeadingStructure = __decorateClass([
  customElement("mindfula11y-heading-structure")
], HeadingStructure);
export {
  HeadingStructure
};
