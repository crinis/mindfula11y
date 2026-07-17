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

namespace MindfulMarkup\MindfulA11y\Service;

use MindfulMarkup\MindfulA11y\Domain\Model\HeadingRelation;

/**
 * Coordinates heading relations across `<mindfula11y:heading>`, `<mindfula11y:heading.sibling>`
 * and `<mindfula11y:heading.descendant>` ViewHelpers within a single template render, keyed
 * by the template author's `relationId`/`siblingId`/`ancestorId` argument.
 *
 * Publishers are `<mindfula11y:heading>` — which registers even when its output is
 * suppressed (empty content or `renderTag="false"`), so a headingless container still
 * anchors its descendants — and `<mindfula11y:heading.descendant>` via its own
 * `relationId`, which lets nested descendants derive from each other. Each entry is a
 * {@see HeadingRelation} carrying the publisher's own logical level and an optional
 * explicit child type; the child-type column's record coordinates deliberately do NOT
 * travel through the registry (see the HeadingRelation docblock) — they are published
 * as markup data attributes instead.
 *
 * Ordering constraint: a relation must be `register()`-ed before a
 * `<mindfula11y:heading.sibling>` or `<mindfula11y:heading.descendant>` referencing that
 * same identifier is rendered. Fluid evaluates ViewHelper nodes in document order, so the
 * referencing ViewHelper must appear later in the template than the one registering the
 * relation. If the reference appears first (or the identifier was never registered),
 * `resolve()` returns null and the calling ViewHelper falls back to its `type` argument,
 * its record lookup, or its default tag.
 *
 * Request-scoped by construction: this is a plain autowired (shared) DI service backed by
 * an array property, not a cache. TYPO3's Fluid ServiceProvider tags every ViewHelperInterface
 * implementation for a compiler pass that forces `shared: false` on the ViewHelper classes
 * themselves (see vendor/typo3/cms-fluid/Configuration/Services.php) so each tag occurrence
 * gets a fresh ViewHelper instance — but this registry is a regular service injected into
 * those ViewHelpers, so it stays `shared: true` (a singleton) for the lifetime of the DI
 * container that constructed it. TYPO3 builds a fresh container for every incoming request
 * (\TYPO3\CMS\Core\Core\Bootstrap::init()), so under PHP-FPM (with or without process
 * recycling) two consecutive requests each get their own container and therefore their own
 * registry instance with an empty array — nothing leaks between requests.
 */
final class HeadingRelationRegistry
{
    /**
     * @var array<string, HeadingRelation>
     */
    private array $relations = [];

    public function register(string $relationId, HeadingRelation $relation): void
    {
        $this->relations[$relationId] = $relation;
    }

    public function resolve(string $relationId): ?HeadingRelation
    {
        return $this->relations[$relationId] ?? null;
    }
}
