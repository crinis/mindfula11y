import stylelint from 'stylelint';

const { createPlugin, utils } = stylelint;

const ruleName = 'mindfula11y/require-focus-visible';

const messages = utils.ruleMessages(ruleName, {
    rejected: (selector) => `Unexpected ":focus" in "${selector}"; style keyboard focus with ":focus-visible" instead`,
});

const meta = {
    url: 'https://github.com/crinis/mindfula11y/blob/main/Resources/Private/Build/stylelint/require-focus-visible.mjs',
};

/**
 * Matches a bare `:focus` pseudo-class. The negative look-ahead excludes
 * `:focus-visible` and `:focus-within`, both of which are followed by a hyphen
 * and remain allowed.
 */
const BARE_FOCUS = /:focus(?![\w-])/;

/**
 * Stylelint rule enforcing the project convention "Use `:focus-visible`
 * exclusively for styling keyboard focus rings". Styling the bare `:focus`
 * pseudo-class is reported; `:focus-visible` and `:focus-within` are allowed.
 * Implemented locally to avoid a third-party plugin dependency.
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

        root.walkRules((rule) => {
            if (!BARE_FOCUS.test(rule.selector)) {
                return;
            }

            utils.report({
                message: messages.rejected(rule.selector),
                node: rule,
                result,
                ruleName,
                word: ':focus',
            });
        });
    };
};

ruleFunction.ruleName = ruleName;
ruleFunction.messages = messages;
ruleFunction.meta = meta;

export default createPlugin(ruleName, ruleFunction);
