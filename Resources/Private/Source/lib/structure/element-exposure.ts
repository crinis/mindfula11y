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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 */

/** Injectable predicate used by the analyzers and their DOM-independent tests. */
export type ElementExposurePredicate = (element: HTMLElement) => boolean;

/**
 * ARIA 1.2 global states and properties which cause browsers to ignore a
 * conflicting none/presentation role and retain the element's native role.
 */
const GLOBAL_ARIA_ATTRIBUTES = new Set([
    'aria-atomic',
    'aria-busy',
    'aria-controls',
    'aria-current',
    'aria-describedby',
    'aria-details',
    'aria-disabled',
    'aria-dropeffect',
    'aria-errormessage',
    'aria-flowto',
    'aria-grabbed',
    'aria-haspopup',
    'aria-hidden',
    'aria-invalid',
    'aria-keyshortcuts',
    'aria-label',
    'aria-labelledby',
    'aria-live',
    'aria-owns',
    'aria-relevant',
    'aria-roledescription',
]);

const isFocusable = (element: HTMLElement): boolean => {
    if (element.matches(':disabled')) {
        return false;
    }
    if (element.hasAttribute('tabindex') || element.isContentEditable) {
        return true;
    }
    return element.matches(
        'a[href], area[href], button, input:not([type="hidden"]), select, textarea, iframe, object, embed, summary, audio[controls], video[controls]',
    );
};

const hasGlobalAriaAttribute = (element: HTMLElement): boolean =>
    Array.from(element.attributes).some((attribute) => GLOBAL_ARIA_ATTRIBUTES.has(attribute.name.toLowerCase()));

/**
 * Whether none/presentation actually suppresses this element's native role.
 * Browsers retain native semantics when the element is focusable or carries a
 * global ARIA state/property (presentational-role conflict resolution).
 */
export const hasPresentationalRole = (element: HTMLElement): boolean => {
    const role = element.getAttribute('role')?.trim().toLowerCase() ?? '';
    return (role === 'none' || role === 'presentation') && !isFocusable(element) && !hasGlobalAriaAttribute(element);
};

/**
 * Composes the predicate both analyzers filter their candidates with: exposed in
 * the accessibility tree and not stripped of its native role by none/presentation.
 * Callers pass `StructureAnalysisOptions.isExposed` to substitute the exposure
 * half; the presentational-role half always applies.
 */
export const resolveExposure =
    (isExposed: ElementExposurePredicate = isElementExposed): ElementExposurePredicate =>
    (element: HTMLElement): boolean =>
        isExposed(element) && !hasPresentationalRole(element);

const isInsideVisibleSummary = (element: HTMLElement, details: HTMLDetailsElement): boolean => {
    const summary = Array.from(details.children).find((child) => child.tagName === 'SUMMARY');
    return summary?.contains(element) === true;
};

/**
 * Approximates whether an element participates in the browser accessibility tree.
 * Opacity and off-screen positioning are deliberately ignored because neither
 * removes content from assistive technology.
 */
export const isElementExposed = (element: HTMLElement): boolean => {
    let current: HTMLElement | null = element;
    const view = element.ownerDocument.defaultView;

    while (current !== null) {
        if (
            current.hidden ||
            current.hasAttribute('inert') ||
            current.getAttribute('aria-hidden')?.trim().toLowerCase() === 'true'
        ) {
            return false;
        }

        if (
            current.tagName === 'DETAILS' &&
            !current.hasAttribute('open') &&
            !isInsideVisibleSummary(element, current as HTMLDetailsElement)
        ) {
            return false;
        }

        if (view !== null) {
            const style = view.getComputedStyle(current);
            if (
                style.display === 'none' ||
                style.contentVisibility === 'hidden' ||
                (current === element && (style.visibility === 'hidden' || style.visibility === 'collapse'))
            ) {
                return false;
            }
        }
        current = current.parentElement;
    }

    return true;
};
