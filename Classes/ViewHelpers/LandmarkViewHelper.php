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
 * <mindfula11y:landmark recordUid="{data.uid}" role="{data.tx_mindfula11y_landmark}" aria="{label: data.tx_mindfula11y_landmark_label, labelledby: data.tx_mindfula11y_landmark_labelledby}">{data.bodytext}</mindfula11y:landmark>
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
     * Initialize the ViewHelper arguments.
     */
    public function initializeArguments(): void
    {
        parent::initializeArguments();
        $this->registerRecordArguments(
            'Name of field that stores the role. Together with recordUid and recordTableName it only annotates the element for structure analysis — no database fallback is performed. (Defaults to tx_mindfula11y_landmark)',
            'tx_mindfula11y_landmark',
        );
        $this->registerArgument('role', 'string', 'The landmark role value. (Defaults to "")', false, "");
        $this->registerArgument('tagName', 'string', 'Override the HTML tag name regardless of the role. The role attribute will still be applied. (Defaults to "")', false, "");
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
        // If no role and no explicit tagName were provided, remove aria attributes
        // to avoid exposing aria-label/aria-labelledby without landmark semantics.
        if (empty($this->arguments['role']) && empty($this->arguments['tagName'])) {
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
        $role = $this->arguments['role'];
        $tagNameOverride = $this->arguments['tagName'];

        // Use tag name override if provided, regardless of role
        if (!empty($tagNameOverride)) {
            $this->tag->setTagName($tagNameOverride);
            // Add role attribute if a role is specified
            if (!empty($role)) {
                $this->tag->addAttribute('role', $role);
            }
            return;
        }

        $landmarkType = AriaLandmark::tryFrom($role);
        $this->tag->setTagName($landmarkType?->element() ?? 'div');

        // header/footer expose banner/contentinfo only when NOT nested inside
        // sectioning content, and content elements typically render inside
        // main/section — make these two roles explicit so the editor-selected
        // landmark reaches assistive technology regardless of nesting.
        if ($landmarkType === AriaLandmark::BANNER || $landmarkType === AriaLandmark::CONTENTINFO) {
            $this->tag->addAttribute('role', $landmarkType->value);
        }
    }
}
