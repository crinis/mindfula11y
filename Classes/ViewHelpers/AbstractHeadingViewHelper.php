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

use MindfulMarkup\MindfulA11y\Service\HeadingRecordResolver;
use MindfulMarkup\MindfulA11y\Service\HeadingRelationRegistry;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractTagBasedViewHelper;
use MindfulMarkup\MindfulA11y\Enum\HeadingType;

/**
 * Abstract base class for all heading-related ViewHelpers in MindfulA11y.
 *
 * Provides shared logic for runtime cache, context, request handling, and database access.
 * All heading ViewHelpers should extend this class to ensure consistent behavior and dependency injection.
 *
 * Template method: render() resolves the final HeadingType via resolveHeadingType()
 * (cascade: explicit `type` argument -> a related heading's type, via
 * resolveRelatedHeadingType()/HeadingRelationRegistry -> a database record's stored type
 * -> the default tag name set by the constructor/initialize()), applies
 * transformResolvedType() to registry/record-resolved types only (an explicit `type`
 * argument is always used verbatim - see DescendantViewHelper), sets the tag name, adds
 * structure-analysis data attributes via addAnalysisDataAttributes(), then renders.
 * Subclasses customize resolveRelatedHeadingType(), transformResolvedType() and
 * addAnalysisDataAttributes(); HeadingViewHelper additionally overrides
 * registerHeadingRelation() to publish its resolved type to the HeadingRelationRegistry.
 * See HeadingRelationRegistry's class docblock for the "ancestor must render before
 * descendant/sibling" ordering constraint this cascade depends on.
 */
abstract class AbstractHeadingViewHelper extends AbstractTagBasedViewHelper
{
    use StructureAnalysisAwareTrait;

    protected $tagName = HeadingType::H2->value;

    /**
     * Resolves record heading types, honouring the current workspace/language
     * Context (see HeadingRecordResolver).
     *
     * @var HeadingRecordResolver
     */
    protected HeadingRecordResolver $headingRecordResolver;

    /**
     * Injects the HeadingRecordResolver.
     *
     * @param HeadingRecordResolver $headingRecordResolver
     * @return void
     */
    public function injectHeadingRecordResolver(HeadingRecordResolver $headingRecordResolver): void
    {
        $this->headingRecordResolver = $headingRecordResolver;
    }

    /**
     * HeadingRelationRegistry instance coordinating heading types between this
     * ViewHelper and related sibling/descendant ViewHelpers (see the registry's
     * class docblock for the ordering constraint).
     *
     * @var HeadingRelationRegistry
     */
    protected HeadingRelationRegistry $headingRelationRegistry;

    /**
     * Injects the HeadingRelationRegistry.
     *
     * @param HeadingRelationRegistry $headingRelationRegistry
     * @return void
     */
    public function injectHeadingRelationRegistry(HeadingRelationRegistry $headingRelationRegistry): void
    {
        $this->headingRelationRegistry = $headingRelationRegistry;
    }

    /**
     * Resolves the heading type for a record.
     *
     * @param int $recordUid UID of the record to fetch heading type from.
     * @param string $recordTableName Name of the DB table containing the heading type field.
     * @param string $recordColumnName Name of the DB column for the heading type.
     *
     * @return HeadingType|null The resolved heading type or null if not found.
     */
    protected function resolveRecordHeadingType(
        int $recordUid,
        string $recordTableName,
        string $recordColumnName,
    ): ?HeadingType {
        $record = $this->headingRecordResolver->resolve($recordTableName, $recordUid, $recordColumnName);

        if (null !== $record && !empty($record[$recordColumnName])) {
            // tryFrom() already returns ?HeadingType (null for an unknown value),
            // which is exactly what the callers expect to call ->value/->increment() on.
            return HeadingType::tryFrom($record[$recordColumnName]);
        }

        return null;
    }

    /**
     * Registers common arguments for heading ViewHelpers (table, column, UID, type).
     *
     * @return void
     */
    protected function registerCommonHeadingArguments(): void
    {
        $this->registerArgument('recordTableName', 'string', 'Database table name of the record with the heading. (Defaults to tt_content)', false, 'tt_content');
        $this->registerArgument('recordColumnName', 'string', 'Name of field that stores the heading type. (Defaults to tx_mindfula11y_headingtype)', false, 'tx_mindfula11y_headingtype');
        $this->registerArgument('recordUid', 'int', 'The UID of the record with the heading.', false, null);
        $this->registerArgument('type', 'string', 'The heading type to use (h1, h2, h3, h4, h5, h6, p, div, etc.). If not provided, the value will be fetched from the database record, relationship or set to "h2".', false, null);
    }

    /**
     * Resolves the HeadingType to render, following the shared cascade:
     * 1. An explicit `type` argument - validated via HeadingType::tryFrom() and used
     *    verbatim (transformResolvedType() is NOT applied - see DescendantViewHelper).
     * 2. A related heading's type via resolveRelatedHeadingType() (registry lookup;
     *    always null for HeadingViewHelper, which has no incoming relation to resolve).
     * 3. The record's stored type, if record information is available.
     * 4. Neither: returns null, and render() leaves the tag's default name untouched.
     *
     * Steps 2 and 3 are passed through transformResolvedType().
     *
     * @return HeadingType|null The resolved heading type, or null to keep the default tag.
     */
    protected function resolveHeadingType(): ?HeadingType
    {
        if (!empty($this->arguments['type'])) {
            return HeadingType::tryFrom($this->arguments['type']);
        }

        $relatedType = $this->resolveRelatedHeadingType();
        if (null !== $relatedType) {
            return $this->transformResolvedType($relatedType);
        }

        if ($this->hasRecordInformation()) {
            $recordType = $this->resolveRecordHeadingType(
                $this->arguments['recordUid'],
                $this->arguments['recordTableName'],
                $this->arguments['recordColumnName'],
            );
            if (null !== $recordType) {
                return $this->transformResolvedType($recordType);
            }
        }

        return null;
    }

    /**
     * Resolves the heading type from a related heading (sibling/ancestor), if this
     * ViewHelper has such a relation. Overridden by SiblingViewHelper and
     * DescendantViewHelper to query the HeadingRelationRegistry; HeadingViewHelper has
     * no incoming relation and keeps this default.
     *
     * @return HeadingType|null
     */
    protected function resolveRelatedHeadingType(): ?HeadingType
    {
        return null;
    }

    /**
     * Transforms a registry- or record-resolved heading type before it is applied to the
     * tag. The identity transform by default; DescendantViewHelper overrides this to
     * increment the level. Not applied to an explicit `type` argument (see
     * resolveHeadingType()).
     *
     * @param HeadingType $type
     * @return HeadingType
     */
    protected function transformResolvedType(HeadingType $type): HeadingType
    {
        return $type;
    }

    /**
     * Publishes this ViewHelper's resolved heading type to the HeadingRelationRegistry
     * for later sibling/descendant ViewHelpers to consume. A no-op by default; overridden
     * by HeadingViewHelper only (Sibling/Descendant consume relations, they don't publish
     * one).
     *
     * @return void
     */
    protected function registerHeadingRelation(): void
    {
    }

    /**
     * Adds this ViewHelper's structure-analysis data attributes to the tag. Called only
     * for a validated structure-analysis request. Each heading ViewHelper exposes
     * different relation/record coordinates, so there is no shared implementation.
     *
     * @return void
     */
    abstract protected function addAnalysisDataAttributes(): void;

    /**
     * Renders the heading tag.
     *
     * A validated structure-analysis request receives only stable relation and record
     * coordinates. Edit links and TCA options are added later by the authenticated
     * backend enrichment endpoint.
     *
     * @return string Rendered HTML of the heading tag
     */
    public function render(): string
    {
        $headingType = $this->resolveHeadingType();
        if (null !== $headingType) {
            $this->tag->setTagName($headingType->value);
        }

        $this->registerHeadingRelation();

        if ($this->isStructureAnalysisRequest()) {
            $this->addAnalysisDataAttributes();
        }

        $this->tag->setContent($this->renderChildren());
        return $this->tag->render();
    }
}
