/**
 * @file types.js
 * @description Shared JSDoc typedefs for MindfulA11y web components.
 */

/**
 * @typedef {Object} AltTextDemand
 * @property {number} pageUid - UID of the page where the element is rendered.
 * @property {number} languageUid - UID of the language for the record.
 * @property {number} fileUid - UID of the sys_file record.
 * @property {string} signature - HMAC signature for request validation.
 */

/**
 * @typedef {Object} HeadingTreeNode
 * @property {HTMLElement} element - The DOM element representing the heading.
 * @property {number} level - The heading level (e.g., 1 for <h1>, 2 for <h2>, etc.).
 * @property {Array<HeadingTreeNode>} children - An array of child heading nodes.
 * @property {number} skippedLevels - The number of skipped heading levels before this heading.
 */

/**
 * @typedef {Object} HeadingStructureError
 * @property {number} count - The number of occurrences of this error type.
 * @property {string} title - The error title or summary.
 * @property {string} description - The detailed error description.
 */
