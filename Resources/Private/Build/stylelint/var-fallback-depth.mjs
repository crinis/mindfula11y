import stylelint from 'stylelint';

const { createPlugin, utils } = stylelint;

const ruleName = 'mindfula11y/var-fallback-depth';

const messages = utils.ruleMessages(ruleName, {
    rejected: (depth) =>
        `Unexpected var() fallback depth ${depth}; keep depth ≤ 2 — var(--component-x, var(--global-default)) is the sanctioned parent-overridable form, a third level signals a missing token (see AGENTS.md)`,
});

const meta = {
    url: 'https://github.com/crinis/mindfula11y/blob/main/Resources/Private/Build/stylelint/var-fallback-depth.mjs',
};

/**
 * Computes the maximum nesting depth of `var()` functions inside a declaration
 * value. Only `var(` parentheses count toward the depth — other functions
 * (`calc()`, `clamp()`, `color-mix()`) wrapping or wrapped by `var()` do not.
 *
 * @param {string} value The declaration value to scan.
 * @returns {number} The deepest `var()` nesting level (0 = no var()).
 */
const maxVarDepth = (value) => {
    const lower = value.toLowerCase();
    /** @type {boolean[]} Open parens; `true` when the paren belongs to var(). */
    const stack = [];
    let varDepth = 0;
    let max = 0;

    for (let i = 0; i < lower.length; i++) {
        if (lower[i] === '(') {
            const isVar = /(?:^|[^a-z0-9-])var$/.test(lower.slice(Math.max(0, i - 4), i));
            stack.push(isVar);
            if (isVar) {
                varDepth++;
                if (varDepth > max) {
                    max = varDepth;
                }
            }
        } else if (lower[i] === ')' && stack.length > 0) {
            if (stack.pop()) {
                varDepth--;
            }
        }
    }

    return max;
};

/**
 * Stylelint rule enforcing the project convention "Keep `var()` fallback depth
 * ≤ 2". `var(--x, 1rem)` and the parent-overridable
 * `var(--component-x, var(--global-default))` are both fine; a third level
 * (`var(--a, var(--b, var(--c)))`) signals a missing token and is reported.
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

        root.walkDecls((decl) => {
            const depth = maxVarDepth(decl.value);
            if (depth <= 2) {
                return;
            }

            utils.report({
                message: messages.rejected(depth),
                node: decl,
                result,
                ruleName,
                word: decl.value,
            });
        });
    };
};

ruleFunction.ruleName = ruleName;
ruleFunction.messages = messages;
ruleFunction.meta = meta;

export default createPlugin(ruleName, ruleFunction);
