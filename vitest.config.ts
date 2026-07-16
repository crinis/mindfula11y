import { defineConfig } from 'vitest/config';

/**
 * Restrict discovery to the real frontend tests. Without this, vitest's
 * default glob also sweeps .test-root/ — the PHP functional-test instances
 * symlink the extension into themselves (typo3conf/ext/mindfula11y), so every
 * frontend test would be discovered and run once per leftover instance.
 */
export default defineConfig({
    test: {
        include: ['Tests/Frontend/**/*.test.ts'],
    },
});
