import stylelint from 'stylelint';

const { createPlugin, utils } = stylelint;

const ruleName = 'mindfula11y/mobile-first';

const messages = utils.ruleMessages(ruleName, {
    rejected: (query) =>
        `Unexpected descending query "${query}"; author mobile-first — set the baseline for small viewports and add complexity with ascending thresholds only ("width >= …", "inline-size >= …").`,
});

const meta = {
    url: 'https://github.com/crinis/mindfula11y/blob/main/Resources/Private/Build/stylelint/mobile-first.mjs',
};

/** Size features whose upper bounds break the mobile-first authoring direction. */
const SIZE = '(?:width|height|inline-size|block-size)';

/** `max-width` / `max-inline-size` … colon syntax — an explicit upper bound. */
const MAX_PREFIX = new RegExp(`\\bmax-${SIZE}\\b`, 'i');

/** A size feature bounded from above: `width <= …`, `inline-size < …`. */
const FEATURE_UPPER_BOUND = new RegExp(`(?<![-\\w])${SIZE}\\s*<=?`, 'i');

/** A value bounding a size feature from above: `… >= width`, `… > inline-size`. */
const VALUE_UPPER_BOUND = new RegExp(`>=?\\s*${SIZE}(?![-\\w])`, 'i');

/**
 * Reports whether a media/container query prelude sets an UPPER bound on a size
 * feature — i.e. a descending, desktop-first query. Handles both range syntax
 * (feature-left `width <= x`, value-left `x >= width`) and the legacy `max-*`
 * colon syntax, across physical (`width`/`height`) and logical
 * (`inline-size`/`block-size`) features.
 *
 * Ascending queries (`min-width`, `width >= x`, `x <= width`) and non-size
 * features (`hover`, `orientation`, `prefers-*`, `resolution`) are not flagged.
 *
 * @param {string} params The at-rule prelude (everything after `@media` / `@container`).
 * @returns {boolean} `true` when the query is descending (desktop-first).
 */
const isDescending = (params) =>
    MAX_PREFIX.test(params) || FEATURE_UPPER_BOUND.test(params) || VALUE_UPPER_BOUND.test(params);

/**
 * Stylelint rule enforcing the project convention "Mobile First (required): all
 * styles start from the mobile baseline; expand layout with `min-width` /
 * `width >=` thresholds only." Applies to both `@media` (page-level layout) and
 * `@container` (component) queries. Implemented locally — no third-party
 * dependency.
 *
 * @param {true} primary Enables the rule.
 * @returns {import('stylelint').Rule}
 */
const ruleFunction = (primary) => {
    return (root, result) => {
        const validOptions = utils.validateOptions(result, ruleName, {
            actual: primary,
            possible: [true],
        });

        if (!validOptions) {
            return;
        }

        root.walkAtRules(/^(media|container)$/i, (atRule) => {
            if (!isDescending(atRule.params)) {
                return;
            }

            utils.report({
                message: messages.rejected(`@${atRule.name} ${atRule.params}`),
                node: atRule,
                result,
                ruleName,
                word: atRule.params,
            });
        });
    };
};

ruleFunction.ruleName = ruleName;
ruleFunction.messages = messages;
ruleFunction.meta = meta;

export default createPlugin(ruleName, ruleFunction);
