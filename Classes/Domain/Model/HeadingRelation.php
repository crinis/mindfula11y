<?php
declare(strict_types=1);

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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 */

namespace MindfulMarkup\MindfulA11y\Domain\Model;

use MindfulMarkup\MindfulA11y\Enum\HeadingType;

/**
 * A heading relation published to the HeadingRelationRegistry by a rendered (or
 * output-suppressed) heading ViewHelper, for sibling/descendant ViewHelpers to consume.
 *
 * An element has a logical heading level whether or not its heading is rendered:
 * `$type` carries that level (null when the publisher resolved no type at all).
 * `$childType` is the explicitly configured level for descendant headings — when set,
 * descendants use it verbatim instead of deriving `$type` + 1, which is also the only
 * way a descendant can become an `<h1>`. Null means "automatic".
 *
 * The child-type column's record coordinates do NOT travel through the registry:
 * the publishing element emits them on its own tag (or its suppressed-container
 * marker) as `data-mindfula11y-childtype-*` attributes, so the heading-structure
 * module edits the column on the container's row.
 */
final readonly class HeadingRelation
{
    public function __construct(
        public ?HeadingType $type,
        public ?HeadingType $childType = null,
    ) {}
}
