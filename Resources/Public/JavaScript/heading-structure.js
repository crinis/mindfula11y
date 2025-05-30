/*
 * Mindful A11y extension for TYPO3 integrating accessibility tools into the backend.
 * Copyright (C) 2025  Mindful Markup, Felix Spittel
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

/**
 * @file heading-structure.js
 * @description Web component for visualizing and editing the heading structure of an HTML document in TYPO3.
 * @typedef {import('./types.js').HeadingTreeNode} HeadingTreeNode
 * @typedef {import('./types.js').HeadingStructureError} HeadingStructureError
 */
import { LitElement, html, css } from "lit";
import { Task } from "@lit/task";
import AjaxRequest from "@typo3/core/ajax/ajax-request.js";
import Notification from "@typo3/backend/notification.js";
import HeadingLevel from "./heading-level.js";

/**
 * Web component for visualizing and editing the heading structure of an HTML document in TYPO3.
 *
 * @class HeadingStructure
 * @extends LitElement
 */
export class HeadingStructure extends LitElement {
  /**
   * CSS styles for the component.
   *
   * @returns {import('lit').CSSResult} The CSSResult for the component styles.
   */
  static get styles() {
    return css`
      .mindfula11y-tree {
        --spacing: 1.5rem;
        --radius: 0.5rem;
        --color: var(--typo3-badge-success-border-color);
        --color-error: var(--typo3-badge-danger-border-color);
        --border-width: 4px;
      }

      .mindfula11y-heading-structure__errors + .mindfula11y-tree {
        margin-block-start: 1.5rem;
      }

      .mindfula11y-tree .mindfula11y-tree__node {
        display: block;
        position: relative;
        padding-left: calc(
          2 * var(--spacing) - var(--radius) - var(--border-width)
        );
      }

      .mindfula11y-tree ol {
        margin-left: calc(var(--radius) - var(--spacing));
        padding-left: 0;
      }

      .mindfula11y-tree ol .mindfula11y-tree__node {
        border-left: var(--border-width) solid var(--color);
      }

      .mindfula11y-tree ol .mindfula11y-tree__node:last-child {
        border-color: transparent;
      }

      .mindfula11y-tree ol .mindfula11y-tree__node::before {
        content: "";
        display: block;
        position: absolute;
        top: calc(var(--spacing) / -2);
        left: calc(-1 * var(--border-width));
        width: calc(var(--spacing) + var(--border-width));
        height: calc(var(--spacing) + 1px);
        border: solid var(--color);
        border-width: 0 0 var(--border-width) var(--border-width);
      }

      .mindfula11y-level {
        display: flex;
        min-height: 2.25em;
        align-items: center;
        gap: 1em;
      }

      .mindfula11y-level__input {
        width: 3.5rem;
      }

      .mindfula11y-tree .mindfula11y-tree__node::after,
      .mindfula11y-tree .mindfula11y-level::before {
        content: "";
        display: block;
        position: absolute;
        top: calc(var(--spacing) / 2 - var(--radius));
        left: calc(var(--spacing) - var(--radius) - 1px);
        width: calc(2 * var(--radius));
        height: calc(2 * var(--radius));
        border-radius: 50%;
        background: var(--color);
      }

      .mindfula11y-tree .mindfula11y-level::before {
        z-index: 1;
      }

      .mindfula11y-tree ol .mindfula11y-tree__node--error {
        border-color: var(--color-error);
      }

      .mindfula11y-tree ol .mindfula11y-tree__node--error::before {
        border-color: var(--color-error);
      }

      .mindfula11y-tree .mindfula11y-tree__node--error::after,
      .mindfula11y-level.mindfula11y-level--error::before {
        background: var(--color-error);
      }
    `;
  }

  /**
   * Component properties.
   *
   * @property {string} previewUrl - The URL to fetch the content to analyze.
   * @returns {Object} The properties definition object for LitElement.
   */
  static get properties() {
    return {
      previewUrl: { type: String },
    };
  }

  /**
   * Constructor for the HeadingStructure component.
   *
   * Initializes the preview URL and sets up the task to fetch and process the headings asynchronously.
   */
  constructor() {
    super();
    this.previewUrl = "";
    this.firstRun = true; // Used that users don't get an alert on first load.

    // Initialize the task to fetch and process the headings.
    this.loadHeadingsTask = new Task(
      this,
      async ([previewUrl]) => {
        let previewHtml;
        try {
          previewHtml = await this.fetchPreview(previewUrl);
        } catch (error) {
          Notification.notice(
            TYPO3.lang["mindfula11y.features.headingStructure.error.loading"],
            TYPO3.lang[
              "mindfula11y.features.headingStructure.error.loading.description"
            ]
          );
          return null;
        }
        return this.selectHeadings(previewHtml);
      },
      () => [this.previewUrl], // Dependencies for the task,
      {
        autoRun: false,
      }
    );
  }

  /**
   * Disables the default shadow DOM and renders into the light DOM.
   *
   * @returns {HTMLElement} The root element for the component (this).
   */
  createRenderRoot() {
    return this;
  }

  /**
   * Renders the heading structure component, including errors and the heading tree.
   *
   * @returns {import('lit').TemplateResult} The rendered template for the component.
   */
  render() {
    return html`
      <style>
        ${this.constructor.styles}
      </style>
      ${this.loadHeadingsTask.render({
        complete: (headings) => {
          if (this.firstRun) {
            this.firstRun = false;
          }
          const errors = this.buildErrorList(Array.from(headings));
          return html`
            ${this.renderErrors(errors)}
            ${null !== headings && 0 < headings.length
              ? this.renderHeadingTree(
                  this.buildHeadingTree(Array.from(headings))
                )
              : ""}
          `;
        },
      })}
    `;
  }

  /**
   * Build a tree structure from a flat NodeList based on heading levels.
   * Also detects skipped heading levels (e.g., h4 after h2).
   *
   * @param {Array<HTMLElement>} headings - Array of heading elements.
   * @returns {Array<HeadingTreeNode>} Tree structure of headings.
   */
  buildHeadingTree(headings) {
    const rootNodes = [];
    const parentStack = [];

    headings.forEach((element, idx) => {
      const level = parseInt(element.tagName[1], 10);

      // Find the correct parent for this node
      while (
        parentStack.length &&
        parentStack[parentStack.length - 1].level >= level
      ) {
        parentStack.pop();
      }

      // Calculate skippedLevels
      let skippedLevels = 0;
      if (parentStack.length > 0) {
        const parentLevel = parentStack[parentStack.length - 1].level;
        skippedLevels = level > parentLevel + 1 ? level - parentLevel - 1 : 0;
      } else {
        // No parent: if not <h1>, treat as skipped levels
        if (level > 1) {
          skippedLevels = level - 1;
        }
      }

      /**
       * @type {HeadingTreeNode}
       */
      const node = { element, level, children: [], skippedLevels };

      if (parentStack.length === 0) {
        // No parent, this is a root node
        rootNodes.push(node);
      } else {
        // Add as a child to the last node in the stack
        parentStack[parentStack.length - 1].children.push(node);
      }

      // Push this node to the stack as a potential parent for future nodes
      parentStack.push(node);
    });

    return rootNodes;
  }

  /**
   * Recursively render the heading tree as nested lists.
   * Adds nested <ol> wrappers for skipped heading levels to visualize the structure.
   * The first <ol> gets the mindfula11y-tree class, others do not.
   *
   * @param {Array<HeadingTreeNode>} nodes - The heading tree nodes.
   * @param {boolean} [isRoot=true] - Whether this is the root <ol>.
   * @returns {import('lit').TemplateResult|null} The rendered heading tree or null if no nodes.
   */
  renderHeadingTree(nodes, isRoot = true) {
    if (!nodes || !nodes.length) return null;

    return html`<ol class="${isRoot ? " mindfula11y-tree" : ""}">
      ${nodes.map((node) => {
        const hasError = node.skippedLevels > 0;
        let content = html`
          <li
            class="mindfula11y-tree__node${hasError
              ? " mindfula11y-tree__node--error"
              : ""}"
          >
            ${this.createHeadingLevel(node)}
            ${this.renderHeadingTree(node.children, false)}
          </li>
        `;
        // If there are skipped levels, wrap in extra <li><ol> for each skipped level
        for (let i = 0; i < node.skippedLevels; i++) {
          content = html`<li
            class="mindfula11y-tree__node mindfula11y-tree__node--error"
          >
            <strong class="text-danger"
              >${TYPO3.lang[
                "mindfula11y.features.headingStructure.error.skippedLevel.inline"
              ].replace("%1$d", node.level - i - 1)}</strong
            >
            <ol>
              ${content}
            </ol>
          </li>`;
        }
        return content;
      })}
    </ol>`;
  }

  /**
   * Create a heading-level component for a given heading node.
   * Optionally adds a visual indication if a skipped level is detected.
   *
   * @param {HeadingTreeNode} node - The heading tree node to create.
   * @returns {import('lit').TemplateResult} The created heading-level element.
   */
  createHeadingLevel(node) {
    const availableLevels = JSON.parse(
      node.element.dataset.mindfula11yAvailableLevels || "{}"
    );

    const hasError = node.skippedLevels > 0;
    return html`
      <mindfula11y-heading-level
        class="mindfula11y-level${hasError ? " mindfula11y-level--error" : ""}"
        .level="${node.level}"
        .availableLevels="${availableLevels}"
        recordTableName="${node.element.dataset.mindfula11yRecordTableName}"
        recordColumnName="${node.element.dataset.mindfula11yRecordColumnName}"
        recordUid="${node.element.dataset.mindfula11yRecordUid}"
        recordEditLink="${node.element.dataset.mindfula11yRecordEditLink}"
        .hasError="${node.hasError}"
        label="${node.element.innerText}"
        @mindfula11y-heading-level-changed="${(_) => {
          this.loadHeadingsTask.run();
        }}"
      >
      </mindfula11y-heading-level>
    `;
  }

  /**
   * Select headings from the given HTML string.
   *
   * Parses the HTML string and selects all heading elements (h1-h6).
   *
   * @param {string} htmlString - The HTML string to parse for headings.
   * @returns {NodeListOf<HTMLElement>} A NodeList of heading elements.
   */
  selectHeadings(htmlString) {
    const parser = new DOMParser();
    return parser
      .parseFromString(htmlString, "text/html")
      .querySelectorAll(`h1, h2, h3, h4, h5, h6`);
  }

  /**
   * Fetch the preview content from the server.
   *
   * Sends an AJAX request to the server to fetch the preview content.
   * The response is expected to be HTML content.
   *
   * @returns {Promise<string>} The HTML content of the preview.
   * @throws {Error} Throws an error if the request fails.
   */
  async fetchPreview() {
    const response = await new AjaxRequest(this.previewUrl).get({
      headers: {
        "Mindfula11y-Heading-Structure": "1",
      },
    });

    return await response.resolve();
  }

  /**
   * Build a list of errors for the heading structure.
   *
   * @param {Array<HTMLElement>} headings - The list of heading elements.
   *
   * @returns {Array<HeadingStructureError>} List of error messages for the heading structure.
   */
  buildErrorList(headings) {
    const errors = [];
    const hasH1 = headings.some((h) => h.tagName === "H1");

    if (!hasH1) {
      errors.push({
        count: 1,
        title:
          TYPO3.lang["mindfula11y.features.headingStructure.error.missingH1"],
        description:
          TYPO3.lang[
            "mindfula11y.features.headingStructure.error.missingH1.description"
          ],
      });
    }

    // Use the heading tree to count headings with skippedLevels > 0
    let skippedErrorHeadings = 0;
    const countSkippedHeadings = (nodes) => {
      nodes.forEach((node) => {
        if (node.skippedLevels > 0) {
          skippedErrorHeadings++;
        }
        if (node.children && node.children.length > 0) {
          countSkippedHeadings(node.children);
        }
      });
    };
    const headingTree = this.buildHeadingTree(headings);
    countSkippedHeadings(headingTree);

    if (skippedErrorHeadings > 0) {
      errors.push({
        count: skippedErrorHeadings,
        title:
          TYPO3.lang["mindfula11y.features.headingStructure.error.skippedLevel"],
        description:
          TYPO3.lang[
            "mindfula11y.features.headingStructure.error.skippedLevel.description"
          ],
      });
    }
    return errors;
  }

  /**
   * Render the error list for the heading structure.
   *
   * @param {Array<HeadingStructureError>} errors - The error list to render.
   * @returns {import('lit').TemplateResult} The rendered error list.
   */
  renderErrors(errors) {
    return html`
      ${errors.length > 0
        ? html`
            <section
              class="mindfula11y-heading-structure__errors"
              role="${this.firstRun ? "" : "alert"}"
            >
              <ul class="list-unstyled">
                ${errors.map(
                  (error) => html`
                    <li class="alert alert-warning">
                      <p class="lead">
                        ${error.title}
                        <span class="badge rounded-pill">${error.count}</span>
                      </p>
                      <p class="mb-0">${error.description}</p>
                    </li>
                  `
                )}
              </ul>
            </section>
          `
        : ""}
    `;
  }
}

customElements.define("mindfula11y-heading-structure", HeadingStructure);

export default HeadingStructure;
