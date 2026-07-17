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

use MindfulMarkup\MindfulA11y\Domain\Model\HeadingRelation;
use MindfulMarkup\MindfulA11y\Service\HeadingRecordResolver;
use MindfulMarkup\MindfulA11y\Service\HeadingRelationRegistry;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractTagBasedViewHelper;
use TYPO3Fluid\Fluid\Core\ViewHelper\TagBuilder;
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
 * argument is always used verbatim - see DescendantViewHelper), sets the tag name and
 * publishes the heading relation via registerHeadingRelation(). Output is then suppressed
 * — nothing is rendered, but the relation stays registered — when the content is empty or
 * `renderTag` is false, so an element keeps its logical heading level for descendants even
 * when it shows no heading of its own. Otherwise structure-analysis data attributes are
 * added via addAnalysisDataAttributes() and the tag renders.
 * Subclasses customize resolveRelatedHeadingType(), transformResolvedType() and
 * addAnalysisDataAttributes(); registerHeadingRelation() publishes for every subclass that
 * registers a `relationId` argument (HeadingViewHelper, DescendantViewHelper).
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
     * Registers the `renderTag` argument shared by all heading ViewHelpers.
     *
     * @return void
     */
    public function initializeArguments(): void
    {
        parent::initializeArguments();
        $this->registerArgument('renderTag', 'bool', 'Whether to output the heading tag. When false, nothing is rendered but the heading relation is still registered, so the element keeps its logical level for descendant/sibling headings (e.g. header_layout "hidden").', false, true);
    }

    /**
     * Registers common arguments for heading ViewHelpers (table, column, UID, type).
     *
     * @return void
     */
    protected function registerCommonHeadingArguments(): void
    {
        $this->registerRecordArguments(
            'Name of field that stores the heading type. (Defaults to tx_mindfula11y_headingtype)',
            'tx_mindfula11y_headingtype',
        );
        $this->registerArgument('type', 'string', 'The heading type to use (h1, h2, h3, h4, h5, h6, p, div, etc.). If not provided, the value will be fetched from the database record, relationship or set to "h2".', false, null);
    }

    /**
     * Registers the child-type arguments for publishing ViewHelpers (those with a
     * `relationId`): the explicit child heading type and the DB column it lives in.
     *
     * @return void
     */
    protected function registerChildTypeArguments(): void
    {
        $this->registerArgument('childType', 'string', 'Heading type (h1-h6, p or div) that descendant headings referencing this heading via ancestorId use verbatim. An empty value means automatic (one level below this heading). If the argument is omitted entirely, the value is fetched from the record column named by childTypeColumnName — but only when that column exists in the record table\'s TCA (tables without it, e.g. custom tables, resolve to automatic). Pass it from template data to save the database query.', false, null);
        $this->registerArgument('childTypeColumnName', 'string', 'Name of the DB column that stores the child heading type on the record given by recordUid/recordTableName. Only consulted when the column is defined in that table\'s TCA. (Defaults to tx_mindfula11y_childheadingtype)', false, 'tx_mindfula11y_childheadingtype');
    }

    /**
     * Resolves the explicitly configured child heading type: a provided `childType`
     * argument wins — an empty string counts as provided and means "automatic" WITHOUT
     * falling through to the database (this is how templates pass an empty record field) —
     * otherwise the record's child-type column is consulted, provided the record's table
     * actually defines it (see resolveChildTypeColumnName()). Returns null for
     * "automatic"; h1-h6, p and div are returned verbatim.
     *
     * @return HeadingType|null
     */
    protected function resolveChildType(): ?HeadingType
    {
        if (!$this->hasArgument('childTypeColumnName')) {
            // Subclass without child-type arguments (SiblingViewHelper).
            return null;
        }

        if ($this->hasArgument('childType')) {
            return HeadingType::tryFrom((string)$this->arguments['childType']);
        }

        $childTypeColumnName = $this->resolveChildTypeColumnName();
        if ($this->hasRecordInformation() && $childTypeColumnName !== null) {
            return $this->resolveRecordHeadingType(
                $this->arguments['recordUid'],
                $this->arguments['recordTableName'],
                $childTypeColumnName,
            );
        }

        return null;
    }

    /**
     * The child-type column usable on the current record's table: the
     * childTypeColumnName argument, but only when that table's TCA actually defines
     * the column. Third-party tables typically configure only their own heading
     * column, while the default tx_mindfula11y_childheadingtype exists on tt_content
     * alone — selecting it on a table without it would abort rendering with an SQL
     * error on MySQL/MariaDB/PostgreSQL (sqlite silently returns a string literal
     * instead). A column missing from TCA is not editable either, so it is no
     * coordinate target for the structure module and registerHeadingRelation()
     * publishes no source coordinates for it.
     */
    protected function resolveChildTypeColumnName(): ?string
    {
        if (!$this->hasArgument('childTypeColumnName')) {
            return null;
        }

        $columnName = (string)$this->arguments['childTypeColumnName'];
        $tableName = (string)($this->arguments['recordTableName'] ?? '');
        if ($columnName === '' || !isset($GLOBALS['TCA'][$tableName]['columns'][$columnName])) {
            return null;
        }

        return $columnName;
    }

    /**
     * Whether this ViewHelper publishes a heading relation: a `relationId`
     * argument is registered and non-empty. Gates relation registration and
     * every container-side structure-analysis emission.
     */
    protected function publishesRelation(): bool
    {
        return $this->hasArgument('relationId') && $this->arguments['relationId'] !== '';
    }

    /**
     * Adds the child-type column's record coordinates and stored value to the given
     * tag for a validated structure-analysis request. The container element owns the
     * column, so its own row in the heading-structure module hosts the child-type
     * select; derived descendant rows stay read-only and jump here. Emitted only
     * when this heading publishes a relation (descendants can only reference it via
     * relationId) and the column is usable on the record's table (see
     * resolveChildTypeColumnName()). Also used for the suppressed-container marker.
     *
     * @param TagBuilder $tag
     * @return void
     */
    protected function addChildTypeDataAttributesTo(TagBuilder $tag): void
    {
        if (!$this->publishesRelation()) {
            return;
        }
        $childTypeColumnName = $this->resolveChildTypeColumnName();
        if (!$this->hasRecordInformation() || $childTypeColumnName === null) {
            return;
        }
        $tag->addAttribute('data-mindfula11y-childtype-table-name', $this->arguments['recordTableName']);
        $tag->addAttribute('data-mindfula11y-childtype-column-name', $childTypeColumnName);
        $tag->addAttribute('data-mindfula11y-childtype-uid', (string)$this->arguments['recordUid']);
        $tag->addAttribute('data-mindfula11y-childtype-value', $this->resolveChildType()?->value ?? '');
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
     * Publishes this ViewHelper's heading relation to the HeadingRelationRegistry for
     * later sibling/descendant ViewHelpers to consume: the finally rendered tag as the
     * element's own (logical) level and the explicitly configured child type.
     *
     * A no-op for subclasses without a `relationId` argument (SiblingViewHelper) or when
     * none is given. Publishing is independent of output suppression — a heading that
     * renders nothing still anchors its descendants.
     *
     * Note: reads $this->tag->getTagName() rather than the resolved HeadingType so that
     * a default-tag fallback (no type/registry/record resolved) is registered too.
     *
     * @return void
     */
    protected function registerHeadingRelation(): void
    {
        if (!$this->publishesRelation()) {
            return;
        }

        $this->headingRelationRegistry->register(
            (string)$this->arguments['relationId'],
            new HeadingRelation(
                type: HeadingType::tryFrom($this->tag->getTagName()),
                childType: $this->resolveChildType(),
            ),
        );
    }

    /**
     * Marker emitted in place of suppressed output on a validated structure-analysis
     * request, for headings that publish a relation: without it, a container that
     * renders no heading of its own is invisible to the analyzer — its derived
     * descendants have no jump target and its child-type column no editable row.
     * The marker is hidden (never exposed to users or assistive technology) and
     * carries the coordinates the visible row would have carried; the analyzer maps
     * it to a container row. `data-mindfula11y-record-value` repeats the resolved
     * tag name because nothing renders that could imply the stored level. Normal
     * frontend requests keep returning an empty string.
     *
     * @return string The marker markup, or '' outside analysis requests / without a relation.
     */
    protected function renderContainerMarker(): string
    {
        if (!$this->isStructureAnalysisRequest() || !$this->publishesRelation()) {
            return '';
        }

        $marker = new TagBuilder('span');
        $marker->forceClosingTag(true);
        $marker->addAttribute('hidden', 'hidden');
        $marker->addAttribute('data-mindfula11y-container', $this->tag->getTagName());
        $marker->addAttribute('data-mindfula11y-relation-id', (string)$this->arguments['relationId']);
        $this->addRecordDataAttributes($marker);
        if ($this->hasRecordInformation()) {
            $marker->addAttribute('data-mindfula11y-record-value', $this->tag->getTagName());
        }
        $this->addChildTypeDataAttributesTo($marker);

        return $marker->render();
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
     * The heading relation is always registered first. Output is suppressed afterwards
     * when the rendered content is empty or `renderTag` is false — an empty heading tag
     * is an accessibility defect in itself, and a suppressed heading must still anchor
     * its descendants' levels — on structure-analysis requests a hidden container marker
     * is emitted instead (see renderContainerMarker()).
     *
     * A validated structure-analysis request receives only stable relation and record
     * coordinates. Edit links and TCA options are added later by the authenticated
     * backend enrichment endpoint.
     *
     * @return string Rendered HTML of the heading tag, or an empty string when suppressed
     */
    public function render(): string
    {
        $headingType = $this->resolveHeadingType();
        if (null !== $headingType) {
            $this->tag->setTagName($headingType->value);
        }

        $this->registerHeadingRelation();

        $content = (string)$this->renderChildren();
        if (!$this->arguments['renderTag'] || trim($content) === '') {
            return $this->renderContainerMarker();
        }

        if ($this->isStructureAnalysisRequest()) {
            $this->addAnalysisDataAttributes();
        }

        $this->tag->setContent($content);
        return $this->tag->render();
    }
}
