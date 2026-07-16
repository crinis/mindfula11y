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
 * Renders a heading tag as a descendant of a referenced ancestor heading.
 *
 * This ViewHelper uses the `ancestorId` argument to fetch the heading relation previously registered for that ancestor (see
 * HeadingRelationRegistry's class docblock for the "must render before" ordering constraint this depends on). If the ancestor comes after, the heading type cannot be determined
 * from the registry, and you must provide the `type` argument or the record arguments (`recordUid`, `recordTableName`, `recordColumnName`).
 *
 * When the ancestor relation carries an explicitly configured child type (its `childType` argument or
 * tx_mindfula11y_childheadingtype column), that type names this heading's own level: it is used verbatim with the
 * default `levels` of 1 (which is also the only way a descendant can render as h1), and deeper `levels` continue
 * from it (childType + levels - 1). Otherwise the ancestor's own level is incremented by `levels` (e.g. h2 → h3).
 * If the increment would exceed h6, it falls back to <p>.
 * An explicit `type` argument is NOT incremented - it is used verbatim as the tag name, same as HeadingViewHelper and SiblingViewHelper (this matches the
 * argument's documented "the value will be rendered directly" semantics shared by all three heading ViewHelpers).
 *
 * A `relationId` argument registers this heading's own relation, so nested descendants can derive from it and the
 * hierarchy composes one level per nesting.
 *
 * During a validated structure-analysis request, a data attribute with the ancestorId is added; the heading-structure
 * module renders derived rows read-only with a jump to the container row that owns the shared child-type column.
 *
 * Resolution cascade (see AbstractHeadingViewHelper::resolveHeadingType()):
 * - If the `type` argument is provided, it is validated and used directly, unincremented, as the tag name.
 * - Otherwise, if the `ancestorId` argument refers to an ancestor that appears before this ViewHelper in the template,
 *   the ancestor's configured child type (verbatim + levels - 1) or its own level (+ levels) is used as the tag name.
 * - If neither of the above applies, but record arguments are provided, the heading type is resolved from the database, incremented, and used as the tag name.
 * - If none of these sources are available, the default tag is used.
 *
 * Example usage:
 * <mindfula11y:heading.descendant ancestorId="{relationId}" levels="1">Content</mindfula11y:heading.descendant>
 *
 * Nested composition:
 * <mindfula11y:heading.descendant ancestorId="container-1" relationId="child-1">Child</mindfula11y:heading.descendant>
 * <mindfula11y:heading.descendant ancestorId="child-1">Grandchild, one level deeper</mindfula11y:heading.descendant>
 */
class DescendantViewHelper extends AbstractHeadingViewHelper
{
    /**
     * Whether the current render's level came from the ancestor's configured child type
     * (verbatim semantics) rather than from incrementing a resolved level.
     */
    protected bool $resolvedFromChildType = false;

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
        $this->registerArgument('relationId', 'string', 'Registers this heading under this identifier so nested descendant/sibling headings can derive from it.', false, null);
        $this->registerCommonHeadingArguments();
        $this->registerChildTypeArguments();
    }

    /**
     * Resets the per-render resolution state: Fluid reuses ViewHelper instances across
     * loop iterations, and resolveRelatedHeadingType() is skipped entirely when an
     * explicit `type` argument is given — stale state would leak into
     * addAnalysisDataAttributes() otherwise.
     *
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();
        $this->resolvedFromChildType = false;
    }

    /**
     * Resolves the heading type from the ancestor's registered relation: an explicitly
     * configured child type wins (verbatim semantics, see transformResolvedType()),
     * otherwise the ancestor's own level is used (increment semantics). Null when the
     * ancestor is unknown or registered no type at all.
     *
     * @return HeadingType|null
     */
    protected function resolveRelatedHeadingType(): ?HeadingType
    {
        // Cast: Fluid v2 (TYPO3 13) passes an integer value (e.g. {data.uid})
        // into string-typed arguments uncast; Fluid v4 casts it.
        $relation = $this->headingRelationRegistry->resolve((string)$this->arguments['ancestorId']);

        if (null !== $relation?->childType) {
            $this->resolvedFromChildType = true;
            return $relation->childType;
        }

        return $relation?->type;
    }

    /**
     * Increments a registry- or record-resolved heading type by the `levels` argument.
     * An ancestor-configured child type names this heading's own level, so it is
     * incremented by one less (verbatim at the default levels=1). Not applied to an
     * explicit `type` argument (see resolveHeadingType()).
     *
     * @param HeadingType $type
     * @return HeadingType
     */
    protected function transformResolvedType(HeadingType $type): HeadingType
    {
        $levels = $this->arguments['levels'] ?? 1;
        return $type->increment($this->resolvedFromChildType ? $levels - 1 : $levels);
    }

    /**
     * Adds the ancestor-id (always) and this heading's own relation-id and
     * child-type coordinates (when it is a container itself). Derived rows carry
     * nothing editable — the ancestor's container row owns the child-type select,
     * and the ancestor-id drives the module's jump affordance.
     *
     * @return void
     */
    protected function addAnalysisDataAttributes(): void
    {
        $this->tag->addAttribute('data-mindfula11y-ancestor-id', (string)$this->arguments['ancestorId']);

        if ($this->publishesRelation()) {
            $this->tag->addAttribute('data-mindfula11y-relation-id', (string)$this->arguments['relationId']);
        }

        $this->addChildTypeDataAttributesTo($this->tag);
    }
}
