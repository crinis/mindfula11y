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

use MindfulMarkup\MindfulA11y\Enum\HeadingType;
use MindfulMarkup\MindfulA11y\ViewHelpers\AbstractHeadingViewHelper;

/**
 * Renders a heading tag at the same level as a referenced sibling heading.
 *
 * This ViewHelper uses the `siblingId` argument to fetch the heading type previously registered by a mindfula11y:heading viewhelper with a matching `relationId` argument (see
 * HeadingRelationRegistry's class docblock for the "must render before" ordering constraint this depends on). If the sibling comes after, the heading type cannot be determined
 * from the registry, and you must provide the `type` argument or the record arguments (`recordUid`, `recordTableName`, `recordColumnName`).
 *
 * The tag can be overridden with the `type` argument. During a validated
 * structure-analysis request, a data attribute with the siblingId is added for
 * analysis purposes.
 *
 * Resolution cascade (see AbstractHeadingViewHelper::resolveHeadingType()):
 * - If the `type` argument is provided, it is validated and used directly as the tag name.
 * - Otherwise, if the `siblingId` argument refers to a sibling that appears before this ViewHelper in the template,
 *   the heading type is fetched from the HeadingRelationRegistry and used as the tag name.
 * - If neither of the above applies, but record arguments are provided, the heading type is resolved from the database and used as the tag name.
 * - If none of these sources are available, the default tag is used.
 *
 * Usage example:
 * <mindfula11y:heading.sibling siblingId="{relationId}">Content</mindfula11y:heading.sibling>
 */
class SiblingViewHelper extends AbstractHeadingViewHelper
{
    /**
     * Registers all arguments for the SiblingViewHelper, including sibling reference and record information.
     *
     * @return void
     */
    public function initializeArguments(): void
    {
        parent::initializeArguments();
        $this->registerArgument('siblingId', 'string', 'The relationId of the heading sibling.', true);
        $this->registerCommonHeadingArguments();
    }

    /**
     * Resolves the sibling's heading type from the HeadingRelationRegistry: a sibling
     * shares the referenced heading's own (logical) level; the relation's configured
     * child type only concerns descendants.
     *
     * @return HeadingType|null
     */
    protected function resolveRelatedHeadingType(): ?HeadingType
    {
        // Cast: Fluid v2 (TYPO3 13) passes an integer value (e.g. {data.uid})
        // into string-typed arguments uncast; Fluid v4 casts it.
        return $this->headingRelationRegistry->resolve((string)$this->arguments['siblingId'])?->type;
    }

    /**
     * Adds the sibling-id data attribute for a validated structure-analysis request.
     *
     * @return void
     */
    protected function addAnalysisDataAttributes(): void
    {
        $this->tag->addAttribute('data-mindfula11y-sibling-id', (string)$this->arguments['siblingId']);
    }
}
