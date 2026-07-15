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
 * Renders a heading tag as a descendant of a referenced ancestor heading, incrementing the heading level as needed.
 *
 * This ViewHelper uses the `ancestorId` argument to fetch the heading type previously registered for that ancestor (see
 * HeadingRelationRegistry's class docblock for the "must render before" ordering constraint this depends on). If the ancestor comes after, the heading type cannot be determined
 * from the registry, and you must provide the `type` argument or the record arguments (`recordUid`, `recordTableName`, `recordColumnName`).
 *
 * The ancestor's or record's heading type is incremented by the specified number of levels (e.g., h2 → h3). If the increment would exceed h6, it falls back to <p>.
 * An explicit `type` argument is NOT incremented - it is used verbatim as the tag name, same as HeadingViewHelper and SiblingViewHelper (this matches the
 * argument's documented "the value will be rendered directly" semantics shared by all three heading ViewHelpers). During a validated
 * structure-analysis request, a data attribute with the ancestorId is added for
 * analysis purposes.
 *
 * Resolution cascade (see AbstractHeadingViewHelper::resolveHeadingType()):
 * - If the `type` argument is provided, it is validated and used directly, unincremented, as the tag name.
 * - Otherwise, if the `ancestorId` argument refers to an ancestor that appears before this ViewHelper in the template,
 *   the heading type is fetched from the HeadingRelationRegistry, incremented by the specified number of levels, and used as the tag name.
 * - If neither of the above applies, but record arguments are provided, the heading type is resolved from the database, incremented, and used as the tag name.
 * - If none of these sources are available, the default tag is used.
 *
 * Example usage:
 * <mindfula11y:heading.descendant ancestorId="{relationId}" levels="1">Content</mindfula11y:heading.descendant>
 */
class DescendantViewHelper extends AbstractHeadingViewHelper
{
    /**
     * Registers all arguments for the DescendantViewHelper, including ancestor reference and heading increment.
     *
     * @return void
     */
    public function initializeArguments(): void
    {
        parent::initializeArguments();
        $this->registerArgument('ancestorId', 'string', 'The relationId of the heading ancestor.', true);
        $this->registerArgument('levels', 'int', 'How many levels to increment the heading type.', false, 1);
        $this->registerCommonHeadingArguments();
    }

    /**
     * Resolves the ancestor's heading type from the HeadingRelationRegistry.
     *
     * @return HeadingType|null
     */
    protected function resolveRelatedHeadingType(): ?HeadingType
    {
        return $this->headingRelationRegistry->resolve($this->arguments['ancestorId']);
    }

    /**
     * Increments a registry- or record-resolved heading type by the `levels` argument.
     * Not applied to an explicit `type` argument (see resolveHeadingType()).
     *
     * @param HeadingType $type
     * @return HeadingType
     */
    protected function transformResolvedType(HeadingType $type): HeadingType
    {
        return $type->increment($this->arguments['levels'] ?? 1);
    }

    /**
     * Adds the ancestor-id data attribute for a validated structure-analysis request.
     *
     * @return void
     */
    protected function addAnalysisDataAttributes(): void
    {
        $this->tag->addAttribute('data-mindfula11y-ancestor-id', $this->arguments['ancestorId']);
    }
}
