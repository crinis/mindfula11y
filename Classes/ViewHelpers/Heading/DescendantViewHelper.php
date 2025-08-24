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

namespace MindfulMarkup\MindfulA11y\ViewHelpers\Heading;

use MindfulMarkup\MindfulA11y\ViewHelpers\AbstractHeadingViewHelper;
use MindfulMarkup\MindfulA11y\Enum\HeadingType;

/**
 * Descendant ViewHelper for rendering a heading tag as a descendant of an ancestor heading.
 *
 * This ViewHelper receives an ancestorId and a levels argument. It tries to get the heading type from the cache and increments it by levels.
 * If the ancestor is not a heading (hX), the descendant uses the same tag as the ancestor. If the descendant would become a <h7>, it becomes a <p> instead.
 * The tag can also be overridden with the tagName argument. If the request has the Mindfula11y-Structure-Analysis header set and the backend user is logged in,
 * a data attribute with the ancestorId is added to the tag.
 *
 * Usage example:
 * <mindfula11y:heading.descendant ancestorId="{relationId}" levels="1">Content</mindfula11y:heading.descendant>
 */
class DescendantViewHelper extends AbstractHeadingViewHelper
{
    /**
     * Initialize the ViewHelper arguments.
     */
    public function initializeArguments(): void
    {
        parent::initializeArguments();
        $this->registerArgument('ancestorId', 'string', 'The relationId of the ancestor heading.', true);
        $this->registerArgument('levels', 'int', 'How many levels to increment the heading type.', false, 1);
        $this->registerArgument('type', 'string', 'The heading type to use (h1, h2, h3, h4, h5, h6, p, div, etc.). Takes precedence over calculated descendant heading type.', false, null);
    }

    /**
     * Set the current tag name based on the ancestor heading type and levels.
     */
    public function initialize(): void
    {
        parent::initialize();

        if (!empty($this->arguments['type'])) {
            $this->tag->setTagName($this->arguments['type']);
            return;
        }

        $ancestorId = $this->arguments['ancestorId'];

        if ($this->runtimeCache->has('mindfula11y_heading_type_' . $ancestorId)) {
            $ancestorType = $this->runtimeCache->get('mindfula11y_heading_type_' . $ancestorId);
        } else {
            $ancestorType = null;
        }

        if (!empty($ancestorType)) {
            // Try to use the HeadingType enum to compute the incremented value.
            $enum = HeadingType::tryFrom(strtolower($ancestorType));
            if (null !== $enum) {
                $descendantType = $enum->increment($this->arguments['levels'] ?? 1);
                $this->tag->setTagName($descendantType->value);
                return;
            }
        }

        $this->tag->setTagName(self::DEFAULT_TYPE);
    }

    /**
     * Render the descendant heading tag.
     *
     * If the Mindfula11y-Structure-Analysis header is set and the backend user is logged in,
     * adds a data attribute with the ancestorId to the tag.
     *
     * @return string The rendered tag HTML.
     */
    public function render(): string
    {
        if ($this->isStructureAnalysisRequest()) {
            $this->tag->addAttribute('data-mindfula11y-ancestor-id', $this->arguments['ancestorId']);
        }
        $this->tag->setContent($this->renderChildren());
        return $this->tag->render();
    }
}
