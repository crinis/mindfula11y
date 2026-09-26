<?php

declare(strict_types=1);

/*
 * Mindful A11y extension for TYPO3 integrating accessibility tools into the backend.
 * Copyright (C) 2025  Mindful Markup, Felix Spittel
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

namespace MindfulMarkup\MindfulA11y\ViewHelpers;

use MindfulMarkup\MindfulA11y\Enum\AriaLandmark;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractTagBasedViewHelper;

/**
 * Landmark ViewHelper to render semantic landmark elements.
 * 
 * This ViewHelper renders appropriate HTML landmark elements with ARIA attributes
 * and adds data attributes for backend module integration.
 * 
 * Usage examples:
 *
 * Basic usage with database fields:
 * <mindfula11y:landmark recordUid="{data.uid}" role="{data.tx_mindfula11y_landmark}" aria="{f:if(condition: '{data.tx_mindfula11y_arialabelledby} && {data.header}', then: '{labelledby: \'c{data.uid}-heading\'}', else: '{label: data.tx_mindfula11y_arialabel}')}">{data.bodytext}</mindfula11y:landmark>
 *
 * Simple usage without database integration:
 * <mindfula11y:landmark role="main" aria="{label: 'Main content area'}">Main content</mindfula11y:landmark>
 *
 * Navigation landmark example:
 * <mindfula11y:landmark role="navigation" aria="{labelledby: 'nav-heading'}">Navigation content</mindfula11y:landmark>
 *
 * Override the HTML tag while keeping the role:
 * <mindfula11y:landmark role="navigation" tagName="div">Navigation content</mindfula11y:landmark>
 *
 * No landmark (uses div by default):
 * <mindfula11y:landmark>Regular content without landmark semantics</mindfula11y:landmark>
 *
 * No landmark with custom tag:
 * <mindfula11y:landmark tagName="section">Regular content without landmark semantics</mindfula11y:landmark>
 */
class LandmarkViewHelper extends AbstractTagBasedViewHelper
{
    use StructureAnalysisAwareTrait;

    /**
     * Whether the rendered element ends up conveying a landmark role at all.
     *
     * Decided in determineElementAndRole() and consumed by render(): an
     * accessible name on a generic element is noise assistive technology
     * cannot attach to anything.
     */
    private bool $hasLandmarkSemantics = false;

    /**
     * Initialize the ViewHelper arguments.
     */
    public function initializeArguments(): void
    {
        parent::initializeArguments();
        $this->registerRecordArguments(
            'Name of field that stores the role. Together with recordUid and recordTableName it only annotates the element for structure analysis — no database fallback is performed. (Defaults to tx_mindfula11y_landmark)',
            'tx_mindfula11y_landmark',
        );
        $this->registerArgument('role', 'string', 'The landmark role value. Only the landmark roles are accepted; any other value is ignored. (Defaults to "")', false, "");
        $this->registerArgument('tagName', 'string', 'Override the HTML tag name regardless of the role. A landmark role is still applied. Written into the markup as given. (Defaults to "")', false, "");
    }

    /**
     * Set the current tag name and role based on the landmark type.
     */
    public function initialize(): void
    {
        parent::initialize();
        $this->determineElementAndRole();
    }

    /**
     * Render the landmark element.
     * 
     * A validated structure-analysis request receives only stable record
     * coordinates. Edit links and TCA options are added later by the
     * authenticated backend enrichment endpoint.
     * 
     * @return string The rendered tag HTML.
     */
    public function render(): string
    {
        // HTML has no self-closing syntax for the elements this ViewHelper can
        // render, so `<main />` from empty children would be parsed as an
        // unclosed start tag and swallow every following sibling.
        $this->tag->forceClosingTag(true);

        // Drop the accessible name unless the element actually conveys a
        // landmark. This follows the RESOLVED outcome, not the raw arguments:
        // an unrecognized role and a generic override (div, span, a custom
        // element) both end up without landmark semantics, and an accessible
        // name on a generic element is noise assistive technology cannot
        // attach to anything.
        if (!$this->hasLandmarkSemantics) {
            $this->tag->removeAttribute('aria-label');
            $this->tag->removeAttribute('aria-labelledby');
        }

        // Never emit empty aria-label/aria-labelledby attributes. An empty value
        // (e.g. a region landmark whose record has neither a header nor an explicit
        // label) provides no accessible name and only adds noise to the markup.
        foreach (['aria-label', 'aria-labelledby'] as $ariaAttribute) {
            if ($this->tag->hasAttribute($ariaAttribute) && trim((string)$this->tag->getAttribute($ariaAttribute)) === '') {
                $this->tag->removeAttribute($ariaAttribute);
            }
        }

        if ($this->isStructureAnalysisRequest()) {
            $this->addRecordDataAttributes();
        }
        $this->tag->setContent($this->renderChildren());
        return $this->tag->render();
    }

    /**
     * Determine the appropriate HTML element and role based on landmark type.
     * Always prefers native HTML elements over role attributes.
     * Uses div tag when no landmark role is defined.
     */
    protected function determineElementAndRole(): void
    {
        $role = (string)($this->arguments['role'] ?? '');
        $tagNameOverride = $this->resolveTagNameOverride();
        $landmarkType = AriaLandmark::tryFrom($role);
        // AriaLandmark::NONE is the empty string, so tryFrom('') yields a case
        // rather than null — neither it nor an unrecognized role is a landmark.
        $roleIsLandmark = null !== $landmarkType && AriaLandmark::NONE !== $landmarkType;

        // Use tag name override if provided, regardless of role
        if (null !== $tagNameOverride) {
            $this->tag->setTagName($tagNameOverride);

            if ($roleIsLandmark) {
                // Only landmark roles are emitted. The documented binding feeds
                // this argument from record data, so an unrecognized value —
                // `presentation`/`none` left behind by a TCA item change, say —
                // must not reach the markup and strip the element out of the
                // accessibility tree. The no-override branch already drops such
                // values; this branch has to agree, or the outcome would hinge
                // on the unrelated tagName argument.
                $this->tag->addAttribute('role', $role);
            } elseif (null !== ($implicitRole = self::nestingDependentRole($tagNameOverride))) {
                // header/footer expose banner/contentinfo only when NOT nested
                // in sectioning content. Without an explicit role the element is
                // generic wherever content elements usually render, so keeping
                // an accessible name on it would label nothing.
                $this->tag->addAttribute('role', $implicitRole);
            }

            // An override may itself carry the landmark: `tagName="nav"` with no
            // role is still a navigation landmark, while `div`, `span` or a
            // custom element is not. section/form only become landmarks once
            // they have an accessible name, which is exactly the attribute this
            // flag decides to keep.
            $this->hasLandmarkSemantics = $roleIsLandmark
                || in_array($tagNameOverride, self::landmarkTagNames(), true);
            return;
        }

        $this->hasLandmarkSemantics = $roleIsLandmark;
        $this->tag->setTagName($landmarkType?->element() ?? 'div');

        // header/footer expose banner/contentinfo only when NOT nested inside
        // sectioning content, and content elements typically render inside
        // main/section — make these two roles explicit so the editor-selected
        // landmark reaches assistive technology regardless of nesting.
        if (null !== ($implicitRole = self::nestingDependentRole($this->tag->getTagName()))) {
            $this->tag->addAttribute('role', $implicitRole);
        }
    }

    /**
     * The landmark role an element exposes only while NOT nested in sectioning
     * content, or null for elements whose role does not depend on nesting.
     *
     * `header` and `footer` are the only two: they map to banner/contentinfo at
     * the top level and are generic anywhere else. Content elements typically
     * render inside main/section, so the role has to be written explicitly for
     * the editor's choice to reach assistive technology at all — which makes
     * this the single condition under which such an element is landmark-bearing.
     */
    private static function nestingDependentRole(string $tagName): ?string
    {
        foreach ([AriaLandmark::BANNER, AriaLandmark::CONTENTINFO] as $landmark) {
            if ($landmark->element() === $tagName) {
                return $landmark->value;
            }
        }

        return null;
    }

    /**
     * The elements that expose a landmark role of their own.
     *
     * Derived from AriaLandmark rather than restated: it already owns the
     * role -> element mapping, and a hardcoded copy would silently go stale if
     * a landmark case were added — treating the new element as generic and
     * stripping the accessible name that makes it a landmark. `section` and
     * `form` are included although they only become landmarks once they carry
     * an accessible name; keeping that name is what makes them one. Cases
     * without a landmark-specific element (NONE) map to `div` and drop out.
     *
     * @return list<string>
     */
    private static function landmarkTagNames(): array
    {
        $tagNames = [];
        foreach (AriaLandmark::cases() as $landmark) {
            $element = $landmark->element();
            if ($element !== 'div' && !in_array($element, $tagNames, true)) {
                $tagNames[] = $element;
            }
        }

        return $tagNames;
    }

    /**
     * The `tagName` argument, or null when no override was given.
     *
     * Used as given: like every Fluid tag argument, this is a template-author
     * surface, and a template author writing the element name has already
     * decided what the element is. `role` is the argument that carries record
     * data, and it is validated in determineElementAndRole().
     *
     * Lower-cased so the landmark-semantics comparison is not defeated by
     * `<NAV>`; HTML element names are case-insensitive, and custom element names
     * must be lowercase anyway.
     */
    private function resolveTagNameOverride(): ?string
    {
        $tagNameOverride = strtolower((string)($this->arguments['tagName'] ?? ''));

        return $tagNameOverride === '' ? null : $tagNameOverride;
    }
}
