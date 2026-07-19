/*
 * Mindful A11y extension for TYPO3 integrating accessibility tools into the backend.
 * Copyright (C) 2026  Mindful Markup, Felix Spittel
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 */

// @vitest-environment happy-dom

import { beforeEach, describe, expect, it } from 'vitest';
import {
    analyzeLandmarks,
    LANDMARK_SELECTOR,
} from '../../../../Resources/Private/Source/lib/structure/landmark-analysis.js';
import type { LandmarkNode } from '../../../../Resources/Private/Source/lib/structure/types.js';

const flatten = (nodes: LandmarkNode[]): LandmarkNode[] => nodes.flatMap((node) => [node, ...flatten(node.children)]);

describe('analyzeLandmarks', () => {
    beforeEach(() => {
        document.body.replaceChildren();
    });

    it('omits unnamed native forms but keeps explicit form roles', () => {
        document.body.innerHTML = `
            <main></main>
            <form><input name="search"></form>
            <form><input name="login"></form>
            <div role="form"><input name="contact"></div>
        `;

        const analysis = analyzeLandmarks(document);
        expect(flatten(analysis.nodes).map((node) => node.role)).toEqual(['main', 'form']);
        expect(analysis.errors).toEqual([]);
    });

    it('includes forms named by aria-label, aria-labelledby, or title', () => {
        document.body.innerHTML = `
            <main></main>
            <form aria-label="Search"></form>
            <h2 id="login-title">Login</h2><form aria-labelledby="login-title"></form>
            <form title="Contact"></form>
        `;

        const forms = flatten(analyzeLandmarks(document).nodes).filter((node) => node.role === 'form');
        expect(forms.map((node) => node.label)).toEqual(['Search', 'Login', 'Contact']);
    });

    it('uses aria-labelledby before aria-label for landmark names', () => {
        document.body.innerHTML = `
            <main></main>
            <h2 id="products-title">Referenced products</h2>
            <nav aria-label="Literal products" aria-labelledby="products-title"></nav>
        `;

        const navigation = flatten(analyzeLandmarks(document).nodes).find((node) => node.role === 'navigation');
        expect(navigation?.label).toBe('Referenced products');
    });

    it('allows the same label on different landmark roles', () => {
        document.body.innerHTML = `
            <main></main>
            <nav aria-label="Products"></nav>
            <section aria-label="Products"></section>
        `;

        const analysis = analyzeLandmarks(document);
        expect(analysis.errors).toEqual([]);
    });

    it('warns when same-labelled navigation landmarks represent different content', () => {
        document.body.innerHTML = `
            <main></main>
            <nav aria-label="Products"><a href="/first">First</a></nav>
            <nav aria-label="Products"><a href="/second">Second</a></nav>
        `;

        const analysis = analyzeLandmarks(document);
        expect(analysis.errors).toHaveLength(2);
        expect(analysis.errors.every((error) => error.key.endsWith('duplicateSameLabel'))).toBe(true);
        expect(analysis.errors.every((error) => error.severity === 'moderate')).toBe(true);
    });

    it('allows the same label for navigation landmarks with identical non-empty links', () => {
        document.body.innerHTML = `
            <main></main>
            <nav aria-label="Primary"><a href="/one">One</a><a href="/two">Two</a></nav>
            <nav aria-label="Primary"><a href="/two">Two</a><a href="/one">One</a></nav>
        `;

        expect(analyzeLandmarks(document).errors).toEqual([]);
    });

    it('uses aria-labelledby before aria-label when comparing navigation links', () => {
        document.body.innerHTML = `
            <main></main>
            <span id="first-link-name">First destination</span>
            <span id="second-link-name">Second destination</span>
            <nav aria-label="Primary">
                <a href="/one" aria-label="Same literal" aria-labelledby="first-link-name">Ignored text</a>
            </nav>
            <nav aria-label="Primary">
                <a href="/one" aria-label="Same literal" aria-labelledby="second-link-name">Ignored text</a>
            </nav>
        `;

        const errors = analyzeLandmarks(document).errors;
        expect(errors).toHaveLength(2);
        expect(errors.every((error) => error.key.endsWith('duplicateSameLabel'))).toBe(true);
    });

    it('retains native landmarks when none is ignored for focusability or global ARIA', () => {
        document.body.innerHTML = `
            <main role="none" tabindex="-1"></main>
            <nav role="presentation" aria-describedby="navigation-description"></nav>
            <p id="navigation-description">Description</p>
        `;

        expect(flatten(analyzeLandmarks(document).nodes).map((node) => node.role)).toEqual(['main', 'navigation']);
    });

    it('keeps stable ids when responsive duplicates are filtered independently', () => {
        document.body.innerHTML = `
            <main></main>
            <nav data-viewport="mobile" data-mindfula11y-record-table-name="tt_content"
                data-mindfula11y-record-column-name="landmark" data-mindfula11y-record-uid="10"></nav>
            <nav data-viewport="desktop" data-mindfula11y-record-table-name="tt_content"
                data-mindfula11y-record-column-name="landmark" data-mindfula11y-record-uid="10"></nav>
        `;

        const isMobile = (element: HTMLElement): boolean => element.dataset.viewport !== 'desktop';
        const isDesktop = (element: HTMLElement): boolean => element.dataset.viewport !== 'mobile';
        const mobile = flatten(analyzeLandmarks(document, { viewport: 'mobile', isExposed: isMobile }).nodes);
        const desktop = flatten(analyzeLandmarks(document, { viewport: 'desktop', isExposed: isDesktop }).nodes);

        expect(mobile.map((node) => node.id)).toContain('tt_content:10:landmark');
        expect(desktop.map((node) => node.id)).toContain('tt_content:10:landmark#1');
    });

    it('flags missingMain when no main landmark is present', () => {
        document.body.innerHTML = `
            <nav aria-label="Primary"></nav>
        `;

        const analysis = analyzeLandmarks(document);
        expect(analysis.errors).toHaveLength(1);
        expect(analysis.errors[0]?.key.endsWith('missingMain')).toBe(true);
        expect(analysis.errors[0]?.severity).toBe('moderate');
        expect(analysis.errors[0]?.nodeId).toBeNull();
    });

    it('flags duplicateMain once per extra main landmark', () => {
        document.body.innerHTML = `
            <main></main>
            <main></main>
        `;

        const analysis = analyzeLandmarks(document);
        const duplicateMainErrors = analysis.errors.filter((error) => error.key.endsWith('duplicateMain'));
        expect(duplicateMainErrors).toHaveLength(2);
        expect(duplicateMainErrors.every((error) => error.severity === 'moderate')).toBe(true);
        expect(duplicateMainErrors.every((error) => error.nodeId !== null)).toBe(true);
    });

    it('flags multipleUnlabeled for unlabeled landmarks sharing a role, even with different content', () => {
        document.body.innerHTML = `
            <main></main>
            <nav><a href="/a">A</a></nav>
            <nav><a href="/b">B</a></nav>
        `;

        const analysis = analyzeLandmarks(document);
        const multipleUnlabeledErrors = analysis.errors.filter((error) =>
            error.key.endsWith('multipleUnlabeledLandmarks'),
        );
        expect(multipleUnlabeledErrors).toHaveLength(2);
        expect(multipleUnlabeledErrors.every((error) => error.severity === 'moderate')).toBe(true);
    });

    // The duplicate/top-level singleton tests use explicit role attributes:
    // happy-dom cannot evaluate LANDMARK_SELECTOR's self-referential :not()
    // compounds, so native header/footer never surface as banner/contentinfo
    // in this suite (see the tripwire tests below).
    it('flags duplicateBanner once per banner instance', () => {
        document.body.innerHTML = `
            <main></main>
            <div role="banner">First header</div>
            <div role="banner">Second header</div>
        `;

        const analysis = analyzeLandmarks(document);
        const duplicateBannerErrors = analysis.errors.filter((error) => error.key.endsWith('duplicateBanner'));
        expect(duplicateBannerErrors).toHaveLength(2);
        expect(duplicateBannerErrors.every((error) => error.severity === 'moderate')).toBe(true);
        expect(duplicateBannerErrors.every((error) => error.nodeId !== null)).toBe(true);
    });

    it('flags duplicateContentinfo once per contentinfo instance', () => {
        document.body.innerHTML = `
            <main></main>
            <div role="contentinfo">First footer</div>
            <div role="contentinfo">Second footer</div>
        `;

        const analysis = analyzeLandmarks(document);
        const duplicateErrors = analysis.errors.filter((error) => error.key.endsWith('duplicateContentinfo'));
        expect(duplicateErrors).toHaveLength(2);
        expect(duplicateErrors.every((error) => error.severity === 'moderate')).toBe(true);
    });

    it('does not restate singleton duplicates through the label rules', () => {
        // Two unlabeled banners are exactly one problem (duplicateBanner) —
        // multipleUnlabeled must not double-flag them; likewise two banners
        // sharing a label stay out of duplicateSameLabel.
        document.body.innerHTML = `
            <main></main>
            <div role="banner">First</div>
            <div role="banner">Second</div>
            <div role="contentinfo" aria-label="Legal">First</div>
            <div role="contentinfo" aria-label="Legal">Second</div>
        `;

        const analysis = analyzeLandmarks(document);
        const keys = analysis.errors.map((error) => error.key);
        expect(keys.some((key) => key.endsWith('multipleUnlabeledLandmarks'))).toBe(false);
        expect(keys.some((key) => key.endsWith('duplicateSameLabel'))).toBe(false);
        expect(keys.filter((key) => key.endsWith('duplicateBanner'))).toHaveLength(2);
        expect(keys.filter((key) => key.endsWith('duplicateContentinfo'))).toHaveLength(2);
    });

    it('accepts a single top-level banner and contentinfo without findings', () => {
        document.body.innerHTML = `
            <div role="banner">Header</div>
            <main></main>
            <div role="contentinfo">Footer</div>
        `;

        expect(analyzeLandmarks(document).errors).toEqual([]);
    });

    it('flags main nested inside another landmark as mainNotTopLevel', () => {
        document.body.innerHTML = `
            <div role="banner">Header</div>
            <div role="region" aria-label="Wrapper"><main></main></div>
        `;

        const analysis = analyzeLandmarks(document);
        const notTopLevelErrors = analysis.errors.filter((error) => error.key.endsWith('mainNotTopLevel'));
        expect(notTopLevelErrors).toHaveLength(1);
        expect(notTopLevelErrors[0]?.severity).toBe('moderate');
        const main = flatten(analysis.nodes).find((node) => node.role === 'main');
        expect(notTopLevelErrors[0]?.nodeId).toBe(main?.id);
    });

    it('flags banner and contentinfo nested inside another landmark', () => {
        document.body.innerHTML = `
            <main>
                <div role="banner">Nested header</div>
                <div role="contentinfo">Nested footer</div>
            </main>
        `;

        const analysis = analyzeLandmarks(document);
        expect(analysis.errors.filter((error) => error.key.endsWith('bannerNotTopLevel'))).toHaveLength(1);
        expect(analysis.errors.filter((error) => error.key.endsWith('contentinfoNotTopLevel'))).toHaveLength(1);
        // A single nested banner is nested, not duplicated.
        expect(analysis.errors.some((error) => error.key.includes('duplicate'))).toBe(false);
    });

    it('excludes header/footer nested inside sectioning content from banner/contentinfo', () => {
        // Verified against real Chromium (devtools protocol) that the production
        // selector (LANDMARK_SELECTOR) correctly matches only the page-level
        // header/footer and excludes ones nested in article/aside/header/footer/
        // main/nav/section. happy-dom (pinned ^20.10.6 here) has a matching bug
        // for the `header header` / `footer footer` self-referential compounds
        // inside :not() — both querySelectorAll and Element.matches() reject
        // EVERY header/footer element in the document, even one with no header/
        // footer ancestor at all. This test pins that current (degraded) in-suite
        // behavior — no header/footer is ever exposed as banner/contentinfo here,
        // nested or not — so a future happy-dom upgrade that fixes the bug is
        // caught by a failing test instead of silently changing landmark output.
        // The tripwire test below guards the exclusion list itself.
        document.body.innerHTML = `
            <main><header>Header in main</header></main>
            <article><footer>Footer in article</footer></article>
            <section aria-label="Section label"><header>Header in section</header></section>
            <aside><footer>Footer in aside</footer></aside>
            <nav aria-label="Nav"><header>Header in nav</header></nav>
            <header>Page header</header>
            <footer>Page footer</footer>
        `;

        const analysis = analyzeLandmarks(document);
        const roles = flatten(analysis.nodes)
            .map((node) => node.role)
            .sort();
        expect(roles).toEqual(['complementary', 'main', 'navigation', 'region']);
    });

    it('keeps the sectioning-scope exclusions in the header/footer selector clauses', () => {
        // Tripwire against deleting or weakening the sectioning-content exclusion
        // list: happy-dom (^20.10.6) cannot evaluate these :not() compounds in
        // either selector path (querySelectorAll and Element.matches() both
        // reject every header/footer once the self-referential `header header` /
        // `footer footer` compound is present — verified against real Chromium,
        // which evaluates the full selector correctly), so the behavioral test
        // above would still pass with the exclusions removed. Pinning the
        // selector source is the strongest in-suite guard available.
        for (const tag of ['header', 'footer']) {
            const scopes = ['article', 'aside', 'footer', 'header', 'main', 'nav', 'section'];
            const clause = `${tag}:not(${scopes.map((scope) => `${scope} ${tag}`).join(', ')})`;
            expect(LANDMARK_SELECTOR).toContain(clause);
        }
    });
});
