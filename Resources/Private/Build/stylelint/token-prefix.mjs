import stylelint from 'stylelint';

const { createPlugin, utils } = stylelint;

const ruleName = 'mindfula11y/token-prefix';

const messages = utils.ruleMessages(ruleName, {
    rejected: (property, allowed) =>
        `Unexpected custom property "${property}" consumed via var(); components reference only ${allowed.join(
            ', ',
        )} tokens — TYPO3's raw \`--typo3-*\`/\`--token-color-*\` variables are internal API and must be bridged through styles/tokens.css (see AGENTS.md)`,
});

const meta = {
    url: 'https://github.com/crinis/mindfula11y/blob/main/Resources/Private/Build/stylelint/token-prefix.mjs',
};

/** Every custom property referenced inside a var() function. */
const VAR_REFERENCE = /var\(\s*(--[a-z0-9-]+)/gi;

/**
 * Stylelint rule enforcing the token bridge: declaration values may only
 * consume custom properties whose name starts with one of the allowed
 * prefixes. Component CSS is restricted to `--mindfula11y-*`; the bridge file
 * `styles/tokens.css` is additionally allowed `--typo3-*` via an override.
 * Keeps every dependency on TYPO3's internal CSS API in one file.
 *
 * @param {true} primary Enables the rule.
 * @param {{ allowedPrefixes?: string[] }} [secondaryOptions] Permitted custom
 *   property name prefixes (default: `['--mindfula11y-']`).
 * @returns {import('stylelint').Rule}
 */
const ruleFunction = (primary, secondaryOptions) => {
    return (root, result) => {
        const validOptions = utils.validateOptions(
            result,
            ruleName,
            { actual: primary, possible: [true] },
            {
                actual: secondaryOptions,
                possible: { allowedPrefixes: [(value) => typeof value === 'string'] },
                optional: true,
            },
        );

        if (!validOptions) {
            return;
        }

        const allowedPrefixes = secondaryOptions?.allowedPrefixes ?? ['--mindfula11y-'];

        root.walkDecls((decl) => {
            for (const match of decl.value.matchAll(VAR_REFERENCE)) {
                const property = match[1];

                if (allowedPrefixes.some((prefix) => property.startsWith(prefix))) {
                    continue;
                }

                utils.report({
                    message: messages.rejected(property, allowedPrefixes),
                    node: decl,
                    result,
                    ruleName,
                    word: property,
                });
            }
        });
    };
};

ruleFunction.ruleName = ruleName;
ruleFunction.messages = messages;
ruleFunction.meta = meta;

export default createPlugin(ruleName, ruleFunction);
