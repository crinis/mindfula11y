<?php

/*
 * Packaging excludes for `tailor ter:publish`, wired up via the
 * TYPO3_EXCLUDE_FROM_PACKAGING environment variable in ter-release.yml.
 *
 * Tailor does NOT merge this with its defaults — this file REPLACES
 * conf/ExcludeFromPackaging.php entirely, so the relevant defaults are
 * repeated below. Keep in sync with the export-ignore list in .gitattributes
 * (the Composer/Packagist channel).
 *
 * Matching semantics (see tailor's VersionService::createZipArchiveFromPath):
 * - 'directories' entries are case-insensitive regex fragments anchored to the
 *   START of the path relative to the extension root — escape slashes.
 * - 'files' entries are case-insensitive regex fragments anchored to the END
 *   of the basename (a leading dot therefore needs no special handling).
 */

return [
    'directories' => [
        // Tailor defaults.
        '\.build',
        '\.ddev',
        '\.git',
        '\.github',
        '\.gitlab',
        '\.idea',
        '\.phive',
        'bin',
        'build',
        'node_modules',
        'public',
        'tailor-version-artefact',
        'tailor-version-upload',
        'tests',
        'Tests',
        'tools',
        'vendor',
        // Development-only site sets and their Fluid resources — the dev set
        // configures the DDEV install, the demo set exists only to exercise
        // visual_editor coexistence on the development site.
        'Configuration\/Sets\/MindfulA11yDev',
        'Configuration\/Sets\/MindfulA11yVisualEditorDemo',
        'Resources\/Private\/VisualEditorDemo',
        // Rendered on docs.typo3.org from the git repository; matches the
        // export-ignore in .gitattributes.
        'Documentation',
        // Frontend sources and build tooling — only the transpiled output in
        // Resources/Public/JavaScript ships.
        'Resources\/Private\/Build',
        'Resources\/Private\/Source',
    ],
    'files' => [
        // Tailor defaults (trimmed to what can occur in this repository).
        'DS_Store',
        'composer\.lock',
        'editorconfig',
        'gitattributes',
        'gitignore',
        'gitmodules',
        'package-lock\.json',
        'package\.json',
        'phpstan\.neon',
        'phpunit\.xml',
        // Frontend toolchain configs and agent instructions.
        'tsconfig\.json',
        'biome\.json',
        'stylelint\.config\.mjs',
        'AGENTS\.md',
        'CLAUDE\.md',
    ],
];
