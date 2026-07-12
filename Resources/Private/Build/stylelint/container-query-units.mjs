import stylelint from 'stylelint';

const { createPlugin, utils } = stylelint;

const ruleName = 'mindfula11y/container-query-units';

const messages = utils.ruleMessages(ruleName, {
    rejected: (value, unit) =>
        `Unexpected "${unit}" threshold "${value}" in @container prelude; container query thresholds use rem only — em resolves against the CONTAINER's font-size and drifts, px ignores the user's text-size preference (see AGENTS.md)`,
});

const meta = {
    url: 'https://github.com/crinis/mindfula11y/blob/main/Resources/Private/Build/stylelint/container-query-units.mjs',
};

/**
 * Any dimension (number + unit) in a `@container` prelude that is not `rem`.
 * `media-feature-name-unit-allowed-list` covers `@media` only, so this closes
 * the `@container` half of the "breakpoints are always rem" convention.
 */
const NON_REM_DIMENSION =
    /(-?\d*\.?\d+)(px|r?em|ex|ch|cap|ic|lh|rlh|v[wh]|v(?:i|b|min|max)|[sld]v[iwhb]|cq[wibhx]|cqmin|cqmax)\b/gi;

/**
 * Stylelint rule enforcing the project convention "Container query thresholds
 * use `rem` — never `em` (resolves against the container's font-size and
 * drifts) and never `px`". Applies to every dimension appearing in a
 * `@container` size condition; style queries carry no dimensions and named
 * containers are idents, so neither triggers false positives.
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

        root.walkAtRules(/^container$/i, (atRule) => {
            for (const match of atRule.params.matchAll(NON_REM_DIMENSION)) {
                if (match[2].toLowerCase() === 'rem') {
                    continue;
                }

                utils.report({
                    message: messages.rejected(match[0], match[2]),
                    node: atRule,
                    result,
                    ruleName,
                    word: match[0],
                });
            }
        });
    };
};

ruleFunction.ruleName = ruleName;
ruleFunction.messages = messages;
ruleFunction.meta = meta;

export default createPlugin(ruleName, ruleFunction);
