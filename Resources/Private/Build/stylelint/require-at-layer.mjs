import stylelint from 'stylelint';

const { createPlugin, utils } = stylelint;

const ruleName = 'mindfula11y/require-at-layer';

const messages = utils.ruleMessages(ruleName, {
    missingLayer: (selector) => `Expected rule "${selector}" to live inside a named @layer block`,
    unsupportedLayer: (name, supported) => `Unexpected @layer name "${name}" (allowed: ${supported.join(', ')})`,
});

const meta = {
    url: 'https://github.com/crinis/mindfula11y/blob/main/Resources/Private/Build/stylelint/require-at-layer.mjs',
};

/**
 * At-rules whose descendant rules are not subject to the cascade-layer
 * convention. Keyframe selectors (`0%`, `from`) and `@font-face` blocks never
 * carry a layer of their own.
 */
const LAYER_EXEMPT_AT_RULES = /^(-\w+-)?keyframes$|^font-face$/i;

/**
 * Walks up the PostCSS ancestor chain of a rule to find the nearest enclosing
 * `@layer { }` block, or reports that the rule is exempt from the convention.
 *
 * @param {import('postcss').Rule} rule The style rule whose ancestry is inspected.
 * @returns {{ layer: import('postcss').AtRule | null, exempt: boolean }}
 *   `layer` is the enclosing `@layer` block (or `null`); `exempt` is `true`
 *   when the rule sits inside a layer-exempt at-rule (e.g. `@keyframes`).
 */
const findEnclosingLayer = (rule) => {
    let ancestor = rule.parent;

    while (ancestor && ancestor.type !== 'root') {
        if (ancestor.type === 'atrule') {
            const atRuleName = ancestor.name.toLowerCase();

            if (LAYER_EXEMPT_AT_RULES.test(atRuleName)) {
                return { layer: null, exempt: true };
            }

            // A block `@layer name { }` owns child nodes; the `@layer a, b;`
            // declaration statement owns none and must not satisfy the rule.
            if (atRuleName === 'layer' && ancestor.nodes) {
                return { layer: ancestor, exempt: false };
            }
        }

        ancestor = ancestor.parent;
    }

    return { layer: null, exempt: false };
};

/**
 * Stylelint rule enforcing the project convention "Every rule must belong to
 * exactly one layer": every style rule has to be nested inside one of the
 * project's named `@layer` blocks. Implemented locally to avoid a third-party
 * plugin dependency.
 *
 * @param {true} primary Enables the rule.
 * @param {{ supportedLayerNames?: string[] }} [secondaryOptions] Optional list
 *   of permitted layer names; an `@layer` block whose name is not listed is
 *   reported (catches typos such as `@layer component`).
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
                possible: { supportedLayerNames: [(value) => typeof value === 'string'] },
                optional: true,
            },
        );

        if (!validOptions) {
            return;
        }

        const supportedLayerNames = secondaryOptions?.supportedLayerNames;

        // Every style rule must resolve to an enclosing @layer block.
        root.walkRules((rule) => {
            const { layer, exempt } = findEnclosingLayer(rule);

            if (exempt || layer) {
                return;
            }

            utils.report({
                message: messages.missingLayer(rule.selector),
                node: rule,
                result,
                ruleName,
            });
        });

        // Every @layer block must use one of the project's seven layer names.
        if (supportedLayerNames) {
            root.walkAtRules('layer', (atRule) => {
                // Skip the `@layer a, b, c;` declaration statement (no block).
                if (!atRule.nodes) {
                    return;
                }

                const layerName = atRule.params.trim();

                if (!supportedLayerNames.includes(layerName)) {
                    utils.report({
                        message: messages.unsupportedLayer(layerName, supportedLayerNames),
                        node: atRule,
                        result,
                        ruleName,
                        word: layerName || undefined,
                    });
                }
            });
        }
    };
};

ruleFunction.ruleName = ruleName;
ruleFunction.messages = messages;
ruleFunction.meta = meta;

export default createPlugin(ruleName, ruleFunction);
