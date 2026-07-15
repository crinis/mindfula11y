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

use MindfulMarkup\MindfulA11y\Enum\HeadingType;

/**
 * Heading ViewHelper to allow editing heading types using the heading structure module.
 *
 * This ViewHelper renders a heading element and adds data attributes with DB information
 * in case we use the heading structure backend module. The `relationId` argument can be used to cache and reference
 * the heading type for use by sibling or descendant headings.
 *
 * Usage examples:
 *
 * Basic usage with ability to edit heading type from backend module. The heading type will be fetched from the database:
 * <mindfula11y:heading recordUid="{data.uid}" recordTableName="tt_content" recordColumnName="tx_mindfula11y_headingtype">{data.header}</mindfula11y:heading>
 *
 * Recommended: Set the heading type directly (saves a database query):
 * <mindfula11y:heading recordUid="{data.uid}" recordTableName="tt_content" recordColumnName="tx_mindfula11y_headingtype" type="{data.tx_mindfula11y_headingtype}">{data.header}</mindfula11y:heading>
 *
 * Specify heading type without way to edit it: Use for dependent headings like child headings.
 * <mindfula11y:heading type="h2">{data.header}</mindfula11y:heading>
 *
 * Example using relationId for referencing in siblings/descendants:
 * <mindfula11y:heading relationId="mainHeading" type="h2">Main heading</mindfula11y:heading>
 * <mindfula11y:heading.sibling siblingId="mainHeading">Sibling at same level</mindfula11y:heading.sibling>
 * <mindfula11y:heading.descendant ancestorId="mainHeading" levels="1">Child heading</mindfula11y:heading.descendant>
 */
class HeadingViewHelper extends AbstractHeadingViewHelper
{
    /**
     * Initialize the ViewHelper arguments.
     */
    public function initializeArguments(): void
    {
        parent::initializeArguments();
        $this->registerArgument('relationId', 'string', 'The relation identifier for this heading (used for rendering related headings).', false, null);
        $this->registerCommonHeadingArguments();
    }

    /**
     * Publishes the tag name this heading was finally rendered with (resolved type or
     * default) to the HeadingRelationRegistry under `relationId`, for later
     * sibling/descendant ViewHelpers to consume. A no-op if no `relationId` is given.
     *
     * Note: reads $this->tag->getTagName() rather than the resolved HeadingType so that
     * a default-tag fallback (no type/registry/record resolved) is registered too, same
     * as when this used the runtime cache directly.
     *
     * @return void
     */
    protected function registerHeadingRelation(): void
    {
        if (!empty($this->arguments['relationId'])) {
            $this->headingRelationRegistry->register(
                $this->arguments['relationId'],
                HeadingType::from($this->tag->getTagName()),
            );
        }
    }

    /**
     * Adds the relationId and record coordinate data attributes for a validated
     * structure-analysis request.
     *
     * @return void
     */
    protected function addAnalysisDataAttributes(): void
    {
        if (!empty($this->arguments['relationId'])) {
            $this->tag->addAttribute('data-mindfula11y-relation-id', $this->arguments['relationId']);
        }

        $this->addRecordDataAttributes();
    }
}
