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
import { noticeState, renderSeverityChip, renderViewportBadges } from "../../lib/status-render.js";
import { StructureErrorSeverity } from "../../lib/structure/types.js";
import { StructureView } from "../../lib/structure-view.js";
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
            ${repeat(
      this.flattenTree(nodes, 0),
      (item) => item.key,
      (item) => item.template
    )}
        </ol>`;
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
    return html`<li class="node" style=${`--mindfula11y-heading-structure-indent: ${indent - 1}`}>
            ${this.renderRow(node)}
        </li>`;
  }
  /**
   * Dashed stand-in row for one skipped heading level, indented at the
   * missing level's own step. The level directly above the skipping heading
   * carries the `skip-…` id its describedby references.
   */
  renderPlaceholderItem(node, missingLevel) {
    return html`<li
            class="node"
            data-placeholder
            style=${`--mindfula11y-heading-structure-indent: ${missingLevel - 1}`}
        >
            <div class="row">
                <span class="level" data-missing>H${missingLevel}</span>
                <span class="text" id=${missingLevel === node.level - 1 ? `skip-${node.id}` : nothing}
                    >${lll("mindfula11y.structure.headings.error.skippedLevel.inline", missingLevel)}</span
                >
            </div>
        </li>`;
  }
  /**
   * Errors rendered as cues inside the affected row itself. Every node
   * finding renders in-row except an ordinary heading's skipped level: its
   * missing-level placeholder row (see renderNode()) already IS the finding,
   * placed where the missing level belongs, so an in-row chip would only
   * duplicate it. Container rows keep their attributed skip in-row — they
   * never render placeholders.
   */
  inRowErrors(node) {
    if (node.kind === "container") {
      return node.errors;
    }
    return node.errors.filter((error) => error.key !== "mindfula11y.structure.headings.error.skippedLevel");
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
    const inRowErrors = this.inRowErrors(node);
    const hasErrorSeverity = inRowErrors.some((error) => error.severity === StructureErrorSeverity.Error);
    return html`<div
            class="row"
            data-node-id=${node.id}
            data-relation-id=${node.relationId}
            ?data-container=${isContainer}
            ?data-error=${hasErrorSeverity}
            ?data-warning=${inRowErrors.length > 0 && !hasErrorSeverity}
        >
            ${this.renderLevelControl(node, label, editable)}
            ${isContainer ? html`<span class="text">
                          <span class="container-badge">
                              <typo3-backend-icon identifier="overlay-hidden" size="small"></typo3-backend-icon>
                              ${label}
                          </span>
                      </span>` : html`<span class="text" ?data-empty=${node.label === ""}>${label}</span>`}
            ${this.renderChildLevelControl(node, label)}
            ${inRowErrors.length > 0 ? html`<span class="row-issues" id="issue-${node.id}"
                          >${inRowErrors.map((error) => this.renderRowIssue(node, error))}</span
                      >` : nothing}
            ${renderViewportBadges(node.viewports)}
            ${editable && this.hasRecord(node) ? this.renderEditLink(node, label) : nothing}
            ${this.renderBusySpinner(node)}
        </div>`;
  }
  /**
   * A finding rendered as a cue inside the affected row. Same inline notice
   * as the base renderIssue(), but the finding's viewport badges are omitted
   * when they merely repeat the row's own badges rendered right beside it —
   * they only appear when the finding is viewport-specific.
   */
  renderRowIssue(node, error) {
    const sameViewports = error.viewports.length === node.viewports.length && error.viewports.every((viewport) => node.viewports.includes(viewport));
    return html`<p class="notice issue" data-state=${noticeState(error.severity)} data-variant="inline" data-scope="node">
            ${renderSeverityChip(error.severity, error.key)}
            ${sameViewports ? nothing : renderViewportBadges(error.viewports)}
        </p>`;
  }
  renderLevelControl(node, label, editable) {
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
    if (node.relation !== null) {
      const relationLabel = node.relation.kind === "ancestor" ? lll("mindfula11y.structure.headings.relation.descendant") : lll("mindfula11y.structure.headings.relation.sibling");
      if (!this.relationTargetExists(node)) {
        return html`<span class="level" data-relation>
                    H${node.level}
                    <typo3-backend-icon identifier="actions-link" size="small"></typo3-backend-icon>
                    <span class="sr-only">${relationLabel}</span>
                </span>`;
      }
      return html`<button
                type="button"
                class="level"
                data-relation
                data-control="level"
                aria-label="H${node.level} — ${relationLabel}. ${lll("mindfula11y.structure.headings.relation.jump")}"
                aria-describedby=${this.describedby(node)}
                @click=${() => this.handleRelationJump(node)}
            >
                H${node.level}
                <typo3-backend-icon identifier="actions-link" size="small"></typo3-backend-icon>
            </button>`;
    }
    return html`<span class="level" data-locked>
            ${this.renderLockedChip(node.level > 0 ? `H${node.level}` : "\u2014")}
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
    const record = node.childTypeRecord;
    if (record === null || record.editLink === "" || Object.keys(node.availableChildTypes).length === 0) {
      return nothing;
    }
    const currentValue = record.storedValue ?? "";
    const childLevel = node.level > 0 ? node.level + 1 : null;
    return html`<span class="child-control">
            <span class="child-label" aria-hidden="true">${lll("mindfula11y.structure.headings.childType")}</span>
            ${this.renderValueSelect(node, {
      id: `child-level-${node.id}`,
      className: "child-level",
      ariaLabel: `${lll("mindfula11y.structure.headings.childType")}: ${label}`,
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
   * Select option labels: h1-h6 as compact uppercase levels; other values keep
   * their translated label. A currently selected "automatic" option carries the
   * effective level (own level for the level select, own level + 1 for the
   * child-type select), so the level stays visible in the collapsed select.
   * An effective level past H6 shows as P: HeadingType::increment() overflows
   * derived levels beyond h6 to a paragraph, so an H6 container's automatic
   * children must never be promised an "H7".
   */
  levelOptionLabel(type, typeLabel, currentValue, effectiveLevel) {
    if (/^h[1-6]$/.test(type)) {
      return type.toUpperCase();
    }
    if (type === "" && currentValue === "" && effectiveLevel !== null) {
      return effectiveLevel > 6 ? `${typeLabel} (P)` : `${typeLabel} (H${effectiveLevel})`;
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
    this.focusRow(target);
  }
};
HeadingStructure.styles = [...StructureView.viewStyles, componentStyles];
HeadingStructure = __decorateClass([
  customElement("mindfula11y-heading-structure")
], HeadingStructure);
export {
  HeadingStructure
};
