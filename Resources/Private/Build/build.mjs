/*
 * Mindful A11y extension for TYPO3 integrating accessibility tools into the backend.
 * Copyright (C) 2026  Mindful Markup, Felix Spittel
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 */

/**
 * Frontend build: Resources/Private/Source → Resources/Public/JavaScript.
 *
 * - Backend `.ts` files are transpiled 1:1 by esbuild (`bundle: false`), so
 *   bare imports (`lit`, `@lit/task`, `@typo3/…`) pass through to TYPO3's
 *   importmap. Exceptional self-contained bundles are declared in
 *   `bundledEntryPoints` below, each with its reason.
 * - `.css` files are transformed/minified by Lightning CSS and emitted as
 *   `<name>.css.js` ES modules exporting a Lit `CSSResult` (via `unsafeCSS`),
 *   importable from components as `import styles from './<name>.css.js'`.
 * - No sourcemaps: output is committed and must not reference private paths.
 *
 * Usage: `node Resources/Private/Build/build.mjs [--watch]`
 */

import { watch } from 'node:fs';
import { mkdir, readdir, readFile, rm, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import esbuild from 'esbuild';
import { Features, transform } from 'lightningcss';

const buildDir = path.dirname(fileURLToPath(import.meta.url));
const sourceDir = path.resolve(buildDir, '..', 'Source');
const packageRoot = path.resolve(buildDir, '..', '..', '..');
const outDir = path.join(packageRoot, 'Resources', 'Public', 'JavaScript');

/**
 * Entry points shipped as self-contained, minified bundles instead of 1:1
 * modules. Every entry needs a reason recorded here — bundling is the
 * exception, and an output path listed here is a contract: PHP loads these
 * files by path (not via the importmap), so renaming one means updating its
 * PHP reader in the same change.
 *
 * - service/structure/runner.ts: injected inline into iframe analysis
 *   responses by StructureAnalysisResponseMiddleware (which reads the built
 *   file from Resources/Public/JavaScript). The response cache-busts this one
 *   URL, so its local analyzer graph must ride along — a browser must never
 *   combine a fresh runner with year-cached dependencies from an older
 *   extension build.
 */
const bundledEntryPoints = [path.join(sourceDir, 'service', 'structure', 'runner.ts')];

/** Browser floor of the TYPO3 14 backend (Lightning CSS version encoding: major << 16 | minor << 8). */
const cssTargets = {
    chrome: 135 << 16,
    edge: 135 << 16,
    firefox: 128 << 16,
    safari: (17 << 16) | (4 << 8),
};

/**
 * Output subdirectories owned by this build. Files in them with no matching
 * source are stale (renamed/deleted source) and get pruned. The legacy flat
 * `.js` files at the output root are never touched.
 */
const ownedOutDirs = ['element', 'lib', 'service', 'styles'];

const collectSources = async () => {
    const ts = [];
    const css = [];

    for (const entry of await readdir(sourceDir, { recursive: true, withFileTypes: true })) {
        if (!entry.isFile()) {
            continue;
        }
        const absolute = path.join(entry.parentPath, entry.name);
        if (entry.name.endsWith('.ts') && !entry.name.endsWith('.d.ts')) {
            ts.push(absolute);
        } else if (entry.name.endsWith('.css')) {
            css.push(absolute);
        }
    }

    return { ts, css };
};

const buildCss = async (files) => {
    for (const file of files) {
        const relative = path.relative(sourceDir, file);
        const { code } = transform({
            filename: relative,
            code: await readFile(file),
            minify: true,
            targets: cssTargets,
            // Never downlevel light-dark(): the fallback keys off
            // prefers-color-scheme, but the TYPO3 backend toggles its scheme
            // via a data attribute + the color-scheme property — exactly what
            // native light-dark() resolves against. Browsers below the floor
            // simply skip the (decorative) declaration.
            exclude: Features.LightDark,
        });
        const module = `import { unsafeCSS } from 'lit';\nexport default unsafeCSS(${JSON.stringify(code.toString())});\n`;
        const outFile = path.join(outDir, `${relative}.js`);
        await mkdir(path.dirname(outFile), { recursive: true });
        await writeFile(outFile, module);
    }
};

/**
 * Fails the build on relative `.css.js` imports with no matching source
 * stylesheet: esbuild passes them through unresolved (`bundle: false`) and the
 * wildcard `*.css.js` ambient declaration hides them from tsc, so a renamed or
 * mistyped stylesheet would otherwise surface only as a browser 404 that kills
 * the importing module graph.
 */
const validateCssImports = async (tsFiles, cssFiles) => {
    const cssSources = new Set(cssFiles);
    const missing = [];
    for (const file of tsFiles) {
        const content = await readFile(file, 'utf8');
        for (const match of content.matchAll(/(?:import|from)\s+['"](\.[^'"]*\.css\.js)['"]/g)) {
            const source = path.resolve(path.dirname(file), match[1].replace(/\.js$/, ''));
            if (!cssSources.has(source)) {
                missing.push(
                    `${path.relative(sourceDir, file)} imports '${match[1]}' but ${path.relative(sourceDir, source)} does not exist`,
                );
            }
        }
    }
    if (missing.length > 0) {
        throw new Error(`Unresolved CSS module import(s):\n${missing.map((line) => `  - ${line}`).join('\n')}`);
    }
};

const buildTs = async (files) => {
    const unbundledFiles = files.filter((file) => !bundledEntryPoints.includes(file));
    await esbuild.build({
        entryPoints: unbundledFiles,
        outdir: outDir,
        outbase: sourceDir,
        bundle: false,
        format: 'esm',
        target: 'es2022',
        sourcemap: false,
        tsconfig: path.join(packageRoot, 'tsconfig.json'),
    });

    for (const entryPoint of bundledEntryPoints.filter((file) => files.includes(file))) {
        await esbuild.build({
            entryPoints: [entryPoint],
            outfile: path.join(outDir, path.relative(sourceDir, entryPoint).replace(/\.ts$/, '.js')),
            bundle: true,
            format: 'esm',
            target: 'es2022',
            minify: true,
            sourcemap: false,
            tsconfig: path.join(packageRoot, 'tsconfig.json'),
        });
    }
};

const pruneOrphans = async (ts, css) => {
    const expected = new Set([
        ...ts.map((file) => path.join(outDir, path.relative(sourceDir, file).replace(/\.ts$/, '.js'))),
        ...css.map((file) => path.join(outDir, `${path.relative(sourceDir, file)}.js`)),
    ]);

    for (const dir of ownedOutDirs) {
        let entries;
        try {
            entries = await readdir(path.join(outDir, dir), { recursive: true, withFileTypes: true });
        } catch {
            continue; // Directory not built yet.
        }
        for (const entry of entries) {
            const absolute = path.join(entry.parentPath, entry.name);
            if (entry.isFile() && !expected.has(absolute)) {
                await rm(absolute);
                console.warn(`Pruned stale output ${path.relative(packageRoot, absolute)}`);
            }
        }
    }
};

const runBuild = async () => {
    const { ts, css } = await collectSources();
    await validateCssImports(ts, css);
    await Promise.all([buildCss(css), ts.length > 0 ? buildTs(ts) : Promise.resolve()]);
    await pruneOrphans(ts, css);
    console.warn(
        `Built ${ts.length} TS module(s) and ${css.length} stylesheet(s) → ${path.relative(packageRoot, outDir)}`,
    );
};

await runBuild();

if (process.argv.includes('--watch')) {
    let pending = null;
    let building = false;
    let queued = false;
    // Builds must not overlap: two concurrent runs write and prune the same
    // output tree, and the older one finishing last leaves stale output.
    const rebuild = async () => {
        if (building) {
            queued = true;
            return;
        }
        building = true;
        try {
            await runBuild();
        } catch (error) {
            console.error(error);
        }
        building = false;
        if (queued) {
            queued = false;
            void rebuild();
        }
    };
    console.warn('Watching Resources/Private/Source for changes…');
    watch(sourceDir, { recursive: true }, (_eventType, filename) => {
        if (filename === null || (!filename.endsWith('.ts') && !filename.endsWith('.css'))) {
            return;
        }
        clearTimeout(pending);
        pending = setTimeout(() => {
            void rebuild();
        }, 100);
    });
}
