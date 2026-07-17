import type { Plugin } from 'vite';
import { defineConfig } from 'vitest/config';

/**
 * Component sources import `*.css.js` modules (e.g. `./structure-view.css.js`)
 * that only exist in the build output: `Build/build.mjs` compiles each Source
 * `.css` into a sibling `.css.js` CSSResult module (see
 * `Resources/Private/Build/types/css-modules.d.ts`). Under vitest those
 * imports cannot resolve from Source, so this plugin serves every `.css.js`
 * request made by a Source module as an empty lit CSSResult — styles are
 * irrelevant to the behavior under test.
 */
const cssResultStub: Plugin = {
    name: 'mindfula11y:css-result-stub',
    enforce: 'pre',
    resolveId(source: string, importer: string | undefined): string | null {
        if (source.endsWith('.css.js') && importer?.includes('Resources/Private/Source/')) {
            // \0 marks the id as virtual so other plugins leave it alone.
            return `\0mindfula11y-css-stub:${source}`;
        }
        return null;
    },
    load(id: string): string | null {
        if (id.startsWith('\0mindfula11y-css-stub:')) {
            return "import { css } from 'lit';\nexport default css``;";
        }
        return null;
    },
};

/**
 * `@typo3/*` modules come from the TYPO3 core importmap at runtime and don't
 * exist under node_modules. Tests mock their BEHAVIOR per-file with `vi.mock`
 * (the project convention — never here). This plugin only makes the specifiers
 * *resolvable*: happy-dom tests go through vite's web transform, whose
 * import-analysis eagerly resolves every import at transform time — before
 * `vi.mock` can intercept — so an unresolvable bare specifier fails the whole
 * suite. (Node-environment tests use the ssr transform, which defers
 * resolution to runtime; they never hit this.) The stub is inert: when a test
 * mocks the module the stub is never executed, and an unmocked use fails
 * loudly with a missing-export error. Resolution is deliberately importer-
 * independent: `vi.mock('@typo3/…')` resolves the specifier from the test
 * file, and both must map to the same id for the mock to attach.
 */
const typo3ImportmapStub: Plugin = {
    name: 'mindfula11y:typo3-importmap-stub',
    enforce: 'pre',
    resolveId(source: string): string | null {
        if (source.startsWith('@typo3/')) {
            return `\0mindfula11y-typo3-stub:${source}`;
        }
        return null;
    },
    load(id: string): string | null {
        if (id.startsWith('\0mindfula11y-typo3-stub:')) {
            return 'export default {};';
        }
        return null;
    },
};

/**
 * Restrict discovery to the real frontend tests. Without this, vitest's
 * default glob also sweeps .test-root/ — the PHP functional-test instances
 * symlink the extension into themselves (typo3conf/ext/mindfula11y), so every
 * frontend test would be discovered and run once per leftover instance.
 */
export default defineConfig({
    plugins: [cssResultStub, typo3ImportmapStub],
    test: {
        include: ['Tests/Frontend/**/*.test.ts'],
    },
});
