/**
 * mindfula11y CSS rule set — adapted from the mbase design-system conventions
 * for TYPO3 backend web components rendering into shadow roots.
 *
 * Lints the CSS sources in Resources/Private/Source/ (compiled into Lit
 * CSSResult modules by the build). The mindfula11y/* plugins are vendored
 * copies from @mindfulmarkup/mbase-build — see AGENTS.md for the conventions
 * they enforce.
 */

import containerQueryUnits from './Resources/Private/Build/stylelint/container-query-units.mjs';
import mobileFirst from './Resources/Private/Build/stylelint/mobile-first.mjs';
import requireAtLayer from './Resources/Private/Build/stylelint/require-at-layer.mjs';
import requireFocusVisible from './Resources/Private/Build/stylelint/require-focus-visible.mjs';
import tokenPrefix from './Resources/Private/Build/stylelint/token-prefix.mjs';
import varFallbackDepth from './Resources/Private/Build/stylelint/var-fallback-depth.mjs';

export default {
    extends: ['stylelint-config-standard'],
    plugins: [
        'stylelint-declaration-block-no-ignored-properties',
        containerQueryUnits,
        mobileFirst,
        requireAtLayer,
        requireFocusVisible,
        tokenPrefix,
        varFallbackDepth,
    ],
    languageOptions: {
        directionality: {
            block: 'top-to-bottom',
            inline: 'left-to-right',
        },
    },
    rules: {
        'color-no-hex': true,
        'color-named': 'never',
        'alpha-value-notation': 'number',
        'font-weight-notation': 'numeric',
        // color-mix() over token vars is the one allowed color function (matches
        // TYPO3 core's own shadow components); light-dark() comes resolved
        // through the --typo3-* tokens and must not be hand-rolled.
        'function-disallowed-list': [
            'rgb',
            'rgba',
            'hsl',
            'hsla',
            'hwb',
            'lab',
            'lch',
            'oklab',
            'oklch',
            'color',
            'light-dark',
        ],
        'property-layout-mappings': 'flow-relative',
        'value-keyword-layout-mappings': 'flow-relative',
        'unit-layout-mappings': 'flow-relative',
        'unit-disallowed-list': [
            'pt',
            'cm',
            'mm',
            'in',
            'ex',
            'q',
            'pc',
            'vw',
            'vh',
            'vmin',
            'vmax',
            'svw',
            'svh',
            'lvw',
            'lvh',
            'dvw',
            'dvh',
        ],
        'media-feature-name-unit-allowed-list': {
            width: ['rem'],
            height: ['rem'],
        },
        'declaration-property-unit-disallowed-list': {
            'font-size': ['px'],
            'line-height': ['px', 'em', 'rem', '%', 'vw', 'vh'],
            // Spacing comes exclusively from the fluid Utopia scale tokens
            // (--mindfula11y-space-* rem / --mindfula11y-space-fixed-* px);
            // raw lengths may only be em (text-tracking padding) or 0.
            '/^(margin|padding|gap|row-gap|column-gap|inset)/': ['px', 'rem'],
        },
        'declaration-property-value-allowed-list': {
            'z-index': ['/^var\\(--.*\\)$/', '-1', '0', '1'],
        },
        'declaration-property-value-disallowed-list': {
            transition: ['/(^|\\s|,)all(\\s|,|$)/i'],
            'transition-property': ['/(^|\\s|,)all(\\s|,|$)/i'],
        },
        'property-disallowed-list': ['overflow-x', 'overflow-y'],
        'selector-max-specificity': '0,3,0',
        'selector-max-id': 0,
        'selector-max-type': 0,
        'max-nesting-depth': [
            3,
            {
                ignore: ['blockless-at-rules', 'pseudo-classes'],
                ignoreAtRules: ['layer', 'container', 'media', 'supports'],
            },
        ],
        'selector-nested-pattern': [
            '^(?!& [^>+~])[\\s\\S]*$',
            {
                splitList: true,
                message:
                    "Do not use '& ' for descendant selectors — drop the '&' and write the selector directly (see AGENTS.md).",
            },
        ],
        'at-rule-no-unknown': [
            true,
            {
                ignoreAtRules: ['layer', 'custom-media', 'container'],
            },
        ],
        'import-notation': 'string',
        'custom-media-pattern': '^-?[a-z0-9][a-z0-9-]*$',
        // Declared custom properties are always namespaced; consumption is
        // guarded by mindfula11y/token-prefix.
        'custom-property-pattern': '^mindfula11y-[a-z0-9-]+$',
        'at-rule-empty-line-before': null,
        'comment-empty-line-before': null,
        'custom-property-empty-line-before': null,
        'declaration-empty-line-before': null,
        'rule-empty-line-before': null,
        'declaration-block-no-duplicate-properties': [
            true,
            {
                ignore: ['consecutive-duplicates-with-different-values'],
            },
        ],
        'declaration-property-value-no-unknown': true,
        'plugin/declaration-block-no-ignored-properties': true,
        'mindfula11y/token-prefix': true,
        'mindfula11y/container-query-units': true,
        'mindfula11y/var-fallback-depth': true,
        'mindfula11y/require-at-layer': [
            true,
            {
                supportedLayerNames: ['reset', 'base', 'component', 'utilities'],
            },
        ],
        'mindfula11y/require-focus-visible': true,
        'mindfula11y/mobile-first': true,
    },
    overrides: [
        {
            // The token bridge is the ONLY file allowed to touch TYPO3's
            // internal --typo3-* variables, and its hardcoded fallback values
            // are the only raw colors in the codebase.
            files: ['**/Resources/Private/Source/styles/tokens.css'],
            rules: {
                'color-no-hex': null,
                // The bridge also owns the categorical landmark-role palette,
                // whose scheme-aware values are hand-written light-dark() pairs.
                'function-disallowed-list': [
                    'rgb',
                    'rgba',
                    'hsl',
                    'hsla',
                    'hwb',
                    'lab',
                    'lch',
                    'oklab',
                    'oklch',
                    'color',
                ],
                'custom-property-pattern': '^(mindfula11y|typo3)-[a-z0-9-]+$',
                'mindfula11y/token-prefix': [
                    true,
                    {
                        allowedPrefixes: ['--mindfula11y-', '--typo3-'],
                    },
                ],
            },
        },
        {
            // Reset and base layers normalize raw elements inside shadow roots
            // and pin :host typography — bare type selectors are their job.
            files: ['**/Resources/Private/Source/styles/reset.css', '**/Resources/Private/Source/styles/base.css'],
            rules: {
                'selector-max-type': null,
            },
        },
        {
            // The sr-only clip pattern needs its literal -1px margin.
            files: ['**/Resources/Private/Source/styles/utilities.css'],
            rules: {
                'declaration-property-unit-disallowed-list': {
                    'font-size': ['px'],
                    'line-height': ['px', 'em', 'rem', '%', 'vw', 'vh'],
                },
            },
        },
    ],
};
