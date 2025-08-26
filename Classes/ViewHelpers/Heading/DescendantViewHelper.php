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
 * This ViewHelper uses the `ancestorId` argument to fetch the heading type previously cached for that ancestor. This only works if the ancestor
 * (referenced by `ancestorId`) appears before the current record in the template. If the ancestor comes after, the heading type cannot be determined
 * from the cache, and you must provide the `type` argument or the record arguments (`recordUid`, `recordTableName`, `recordColumnName`).
 *
 * The ancestor's heading type is incremented by the specified number of levels (e.g., h2 → h3). If the increment would exceed h6, it falls back to <p>.
 * The tag can be overridden with the `type` argument. If the request has the Mindfula11y-Structure-Analysis header set and the backend user is logged in,
 * a data attribute with the ancestorId is added to the tag for analysis purposes.
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
        $this->registerArgument('ancestorId', 'string', 'The relationId of the ancestor heading.', true);
        $this->registerArgument('levels', 'int', 'How many levels to increment the heading type.', false, 1);
        $this->registerCommonHeadingArguments();
    }

    /**
     * Initializes the tag name for the descendant heading based on the ancestor's type and the increment level.
     *
     * Logic:
     * - If the `type` argument is provided, it is used directly as the tag name.
     * - Otherwise, if the `ancestorId` argument refers to an ancestor that appears before this ViewHelper in the template,
     *   the heading type is fetched from the runtime cache, incremented by the specified number of levels, and used as the tag name.
     * - If neither of the above applies, but record arguments are provided, the heading type is resolved from the database, incremented, and used as the tag name.
     * - If none of these sources are available, the default tag is used.
     *
     * Note: The cache lookup for `ancestorId` only works if the referenced ancestor appears before this ViewHelper in the template.
     * If the ancestor comes after, you must provide the `type` or record arguments to ensure the correct tag is used.
     *
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();

        if (!empty($this->arguments['type'])) {
            $this->tag->setTagName($this->arguments['type']);
        } else if ($this->runtimeCache->has('mindfula11y_heading_type_' . $this->arguments['ancestorId'])) {
            $cachedAncestorType = $this->runtimeCache->get('mindfula11y_heading_type_' . $this->arguments['ancestorId']);
            $headingType = HeadingType::tryFrom($cachedAncestorType);
            if (null !== $headingType) {
                $this->tag->setTagName($headingType->increment($this->arguments['levels'])->value);
            }
        } else if($this->hasRecordInformation()) {
            $headingType = $this->resolveHeadingType(
                $this->arguments['recordUid'],
                $this->arguments['recordTableName'],
                $this->arguments['recordColumnName']
            );

            if (null !== $headingType) {
                $this->tag->setTagName($headingType->increment($this->arguments['levels'])->value);
            }
        }
    }

    /**
     * Renders the descendant heading tag, optionally adding a data attribute for structure analysis.
     *
     * If the Mindfula11y-Structure-Analysis header is set and the backend user is logged in,
     * adds a data-mindfula11y-ancestor-id attribute to the tag for analysis purposes.
     *
     * @return string The rendered HTML for the descendant heading tag.
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
