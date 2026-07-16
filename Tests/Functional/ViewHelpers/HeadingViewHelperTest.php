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
 */

namespace MindfulMarkup\MindfulA11y\Tests\Functional\ViewHelpers;

use MindfulMarkup\MindfulA11y\Domain\Model\StructureAnalysisTicket;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Fluid\Core\Rendering\RenderingContextFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use TYPO3Fluid\Fluid\View\TemplateView;

/**
 * Renders the heading ViewHelper family through real Fluid to pin the relation
 * cascade: registration-despite-suppression, verbatim child types (incl. h1),
 * nested descendant composition, sibling semantics and the container-side child-type
 * coordinates and suppressed-container markers that make the container row editable
 * in the module.
 */
final class HeadingViewHelperTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'mindfulmarkup/mindfula11y',
        // Provides tx_a11ytest_content: a custom heading-bearing table WITHOUT
        // the tt_content-specific child-type column.
        __DIR__ . '/../Fixtures/Extensions/a11y_test',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/HeadingViewHelperFixture.csv');
    }

    private function render(string $source, ?ServerRequestInterface $request = null): string
    {
        $context = $this->get(RenderingContextFactory::class)->create([], $request);
        $context->getTemplatePaths()->setTemplateSource(
            '<html xmlns:mindfula11y="http://typo3.org/ns/MindfulMarkup/MindfulA11y/ViewHelpers" data-namespace-typo3-fluid="true">'
            . $source
            . '</html>'
        );

        return trim((new TemplateView($context))->render());
    }

    private function structureAnalysisRequest(): ServerRequestInterface
    {
        $ticket = new StructureAnalysisTicket(
            requestId: str_repeat('ab', 16),
            pageId: 1,
            languageId: 0,
            workspaceId: 0,
            backendUserId: 1,
            backendOrigin: 'https://backend.example',
            frontendOrigin: 'https://frontend.example',
            target: '/',
            expiresAt: PHP_INT_MAX,
        );

        return (new ServerRequest('https://frontend.example/'))
            ->withAttribute(StructureAnalysisTicket::REQUEST_ATTRIBUTE, $ticket);
    }

    #[Test]
    public function descendantDerivesFromRenderedAncestor(): void
    {
        $output = $this->render(
            '<mindfula11y:heading type="h2" relationId="a">Parent</mindfula11y:heading>'
            . '<mindfula11y:heading.descendant ancestorId="a">Child</mindfula11y:heading.descendant>'
        );

        self::assertStringContainsString('<h2>Parent</h2>', $output);
        self::assertStringContainsString('<h3>Child</h3>', $output);
    }

    #[Test]
    public function descendantUsesConfiguredChildTypeVerbatim(): void
    {
        $output = $this->render(
            '<mindfula11y:heading type="h2" childType="h4" relationId="a">Parent</mindfula11y:heading>'
            . '<mindfula11y:heading.descendant ancestorId="a">Child</mindfula11y:heading.descendant>'
        );

        self::assertStringContainsString('<h2>Parent</h2>', $output);
        self::assertStringContainsString('<h4>Child</h4>', $output);
    }

    #[Test]
    public function descendantCanRenderAsH1ViaChildType(): void
    {
        $output = $this->render(
            '<mindfula11y:heading type="h2" childType="h1" relationId="a">Parent</mindfula11y:heading>'
            . '<mindfula11y:heading.descendant ancestorId="a">Child</mindfula11y:heading.descendant>'
        );

        self::assertStringContainsString('<h1>Child</h1>', $output);
    }

    #[Test]
    public function emptyHeadingRendersNothingButStillRegisters(): void
    {
        $output = $this->render(
            '<mindfula11y:heading type="h3" relationId="a"></mindfula11y:heading>'
            . '<mindfula11y:heading.descendant ancestorId="a">Child</mindfula11y:heading.descendant>'
        );

        self::assertStringNotContainsString('<h3>', $output);
        self::assertStringContainsString('<h4>Child</h4>', $output);
    }

    #[Test]
    public function renderTagFalseSuppressesOutputButStillRegisters(): void
    {
        $output = $this->render(
            '<mindfula11y:heading type="h2" relationId="a" renderTag="false">Hidden headline</mindfula11y:heading>'
            . '<mindfula11y:heading.descendant ancestorId="a">Child</mindfula11y:heading.descendant>'
        );

        self::assertStringNotContainsString('Hidden headline', $output);
        self::assertStringContainsString('<h3>Child</h3>', $output);
    }

    #[Test]
    public function nestedDescendantsComposeViaRelationId(): void
    {
        $output = $this->render(
            '<mindfula11y:heading type="h2" relationId="parent">Parent</mindfula11y:heading>'
            . '<mindfula11y:heading.descendant ancestorId="parent" relationId="child">Child</mindfula11y:heading.descendant>'
            . '<mindfula11y:heading.descendant ancestorId="child">Grandchild</mindfula11y:heading.descendant>'
        );

        self::assertStringContainsString('<h3>Child</h3>', $output);
        self::assertStringContainsString('<h4>Grandchild</h4>', $output);
    }

    #[Test]
    public function childTypeWithDeeperLevelsContinuesFromChildType(): void
    {
        $output = $this->render(
            '<mindfula11y:heading type="h2" childType="h3" relationId="a">Parent</mindfula11y:heading>'
            . '<mindfula11y:heading.descendant ancestorId="a" levels="2">Deep child</mindfula11y:heading.descendant>'
        );

        self::assertStringContainsString('<h4>Deep child</h4>', $output);
    }

    #[Test]
    public function siblingSharesAncestorOwnLevelNotChildType(): void
    {
        $output = $this->render(
            '<mindfula11y:heading type="h2" childType="h5" relationId="a">Parent</mindfula11y:heading>'
            . '<mindfula11y:heading.sibling siblingId="a">Sibling</mindfula11y:heading.sibling>'
        );

        self::assertStringContainsString('<h2>Sibling</h2>', $output);
    }

    #[Test]
    public function nonHeadingAncestorKeepsDescendantNonHeading(): void
    {
        $output = $this->render(
            '<mindfula11y:heading type="p" relationId="a">Parent</mindfula11y:heading>'
            . '<mindfula11y:heading.descendant ancestorId="a">Child</mindfula11y:heading.descendant>'
        );

        self::assertStringContainsString('<p>Child</p>', $output);
    }

    #[Test]
    public function integerRelationIdentifiersAreAccepted(): void
    {
        // Fluid v2 (TYPO3 13) passes integer variable values (e.g. {data.uid})
        // into string-typed arguments uncast; the ViewHelpers must cast at the
        // registry boundary instead of throwing a TypeError.
        $context = $this->get(RenderingContextFactory::class)->create();
        $context->getTemplatePaths()->setTemplateSource(
            '<html xmlns:mindfula11y="http://typo3.org/ns/MindfulMarkup/MindfulA11y/ViewHelpers" data-namespace-typo3-fluid="true">'
            . '<mindfula11y:heading type="h2" relationId="{uid}">Parent</mindfula11y:heading>'
            . '<mindfula11y:heading.descendant ancestorId="{uid}">Child</mindfula11y:heading.descendant>'
            . '<mindfula11y:heading.sibling siblingId="{uid}">Sibling</mindfula11y:heading.sibling>'
            . '</html>'
        );
        $view = new TemplateView($context);
        $view->assign('uid', 42);

        $output = trim($view->render());

        self::assertStringContainsString('<h3>Child</h3>', $output);
        self::assertStringContainsString('<h2>Sibling</h2>', $output);
    }

    #[Test]
    public function unknownAncestorFallsBackToDefaultTag(): void
    {
        $output = $this->render(
            '<mindfula11y:heading.descendant ancestorId="never-registered">Child</mindfula11y:heading.descendant>'
        );

        self::assertStringContainsString('<h2>Child</h2>', $output);
    }

    #[Test]
    public function childTypeResolvesFromRecordColumn(): void
    {
        $output = $this->render(
            '<mindfula11y:heading recordUid="800" relationId="a">Parent</mindfula11y:heading>'
            . '<mindfula11y:heading.descendant ancestorId="a">Child</mindfula11y:heading.descendant>'
        );

        self::assertStringContainsString('<h2>Parent</h2>', $output);
        self::assertStringContainsString('<h4>Child</h4>', $output);
    }

    #[Test]
    public function emptyChildTypeArgumentMeansAutomaticWithoutRecordFallback(): void
    {
        // childType="" (e.g. an empty template field) must NOT fall through to the
        // record's child-type column (which holds h4 for uid 800).
        $output = $this->render(
            '<mindfula11y:heading type="h2" childType="" recordUid="800" relationId="a">Parent</mindfula11y:heading>'
            . '<mindfula11y:heading.descendant ancestorId="a">Child</mindfula11y:heading.descendant>'
        );

        self::assertStringContainsString('<h3>Child</h3>', $output);
    }

    #[Test]
    public function customTableWithoutChildTypeColumnSkipsRecordFallback(): void
    {
        // A pre-existing custom-table integration only configures its own
        // heading column. The implicit child-type fallback must not SELECT the
        // default tx_mindfula11y_childheadingtype column on such a table — that
        // column does not exist there and the query would abort rendering.
        $output = $this->render(
            '<mindfula11y:heading recordUid="900" recordTableName="tx_a11ytest_content" recordColumnName="headingtype" relationId="a">Parent</mindfula11y:heading>'
            . '<mindfula11y:heading.descendant ancestorId="a">Child</mindfula11y:heading.descendant>'
        );

        self::assertStringContainsString('<h3>Parent</h3>', $output);
        self::assertStringContainsString('<h4>Child</h4>', $output);
    }

    #[Test]
    public function customTableDescendantEmitsNoChildTypeCoordinatesForAnalysis(): void
    {
        // Without a child-type column on the table there is nothing the module
        // could edit, so no record coordinates may be emitted for the derived row.
        $output = $this->render(
            '<mindfula11y:heading recordUid="900" recordTableName="tx_a11ytest_content" recordColumnName="headingtype" relationId="a">Parent</mindfula11y:heading>'
            . '<mindfula11y:heading.descendant ancestorId="a">Child</mindfula11y:heading.descendant>',
            $this->structureAnalysisRequest(),
        );

        $childMarkup = substr($output, (int)strpos($output, '<h4'));
        self::assertStringContainsString('data-mindfula11y-ancestor-id="a"', $childMarkup);
        self::assertStringNotContainsString('data-mindfula11y-record-uid', $childMarkup);
    }

    #[Test]
    public function derivedHeadingEmitsOnlyRelationCoordinatesForAnalysis(): void
    {
        // The container row owns the child-type select now: derived rows carry the
        // ancestor reference for the jump affordance and nothing editable.
        $output = $this->render(
            '<mindfula11y:heading recordUid="800" relationId="a">Parent</mindfula11y:heading>'
            . '<mindfula11y:heading.descendant ancestorId="a" relationId="child">Child</mindfula11y:heading.descendant>',
            $this->structureAnalysisRequest(),
        );

        $childMarkup = substr($output, (int)strpos($output, '<h4'));
        self::assertStringContainsString('data-mindfula11y-ancestor-id="a"', $childMarkup);
        self::assertStringContainsString('data-mindfula11y-relation-id="child"', $childMarkup);
        self::assertStringNotContainsString('data-mindfula11y-record-', $childMarkup);
    }

    #[Test]
    public function descendantWithoutAncestorRecordEmitsNoRecordCoordinates(): void
    {
        // The ancestor registered no record coordinates (template-only relation):
        // nothing is editable, so no coordinates may be emitted.
        $output = $this->render(
            '<mindfula11y:heading type="h2" relationId="a">Parent</mindfula11y:heading>'
            . '<mindfula11y:heading.descendant ancestorId="a">Child</mindfula11y:heading.descendant>',
            $this->structureAnalysisRequest(),
        );

        $childMarkup = substr($output, (int)strpos($output, '<h3'));
        self::assertStringContainsString('data-mindfula11y-ancestor-id="a"', $childMarkup);
        self::assertStringNotContainsString('data-mindfula11y-record-uid', $childMarkup);
    }

    #[Test]
    public function descendantWithDeeperLevelsEmitsNoRecordCoordinates(): void
    {
        // Derived rows never carry record coordinates; levels > 1 additionally
        // detaches the rendered level from the stored value.
        $output = $this->render(
            '<mindfula11y:heading recordUid="800" relationId="a">Parent</mindfula11y:heading>'
            . '<mindfula11y:heading.descendant ancestorId="a" levels="2">Child</mindfula11y:heading.descendant>',
            $this->structureAnalysisRequest(),
        );

        $childMarkup = substr($output, (int)strpos($output, '<h5'));
        self::assertStringContainsString('data-mindfula11y-ancestor-id="a"', $childMarkup);
        self::assertStringNotContainsString('data-mindfula11y-record-uid', $childMarkup);
    }

    #[Test]
    public function containerHeadingEmitsChildTypeCoordinatesOnItsOwnTag(): void
    {
        $output = $this->render(
            '<mindfula11y:heading recordUid="800" relationId="a">Parent</mindfula11y:heading>'
            . '<mindfula11y:heading.descendant ancestorId="a">Child</mindfula11y:heading.descendant>',
            $this->structureAnalysisRequest(),
        );

        $parentMarkup = substr($output, 0, (int)strpos($output, '<h4'));
        self::assertStringContainsString('data-mindfula11y-childtype-table-name="tt_content"', $parentMarkup);
        self::assertStringContainsString('data-mindfula11y-childtype-column-name="tx_mindfula11y_childheadingtype"', $parentMarkup);
        self::assertStringContainsString('data-mindfula11y-childtype-uid="800"', $parentMarkup);
        self::assertStringContainsString('data-mindfula11y-childtype-value="h4"', $parentMarkup);
    }

    #[Test]
    public function automaticContainerEmitsEmptyChildTypeValue(): void
    {
        // Record 801 stores no child type: the column is still the editable target,
        // with '' ("automatic") as the stored value.
        $output = $this->render(
            '<mindfula11y:heading recordUid="801" relationId="a">Parent</mindfula11y:heading>'
            . '<mindfula11y:heading.descendant ancestorId="a">Child</mindfula11y:heading.descendant>',
            $this->structureAnalysisRequest(),
        );

        $parentMarkup = substr($output, 0, (int)strpos($output, '<h4'));
        self::assertStringContainsString('data-mindfula11y-childtype-uid="801"', $parentMarkup);
        self::assertStringContainsString('data-mindfula11y-childtype-value=""', $parentMarkup);
    }

    #[Test]
    public function headingWithoutRelationIdEmitsNoChildTypeCoordinates(): void
    {
        // Nothing can reference this heading, so its child-type column is irrelevant.
        $output = $this->render(
            '<mindfula11y:heading recordUid="800">Parent</mindfula11y:heading>',
            $this->structureAnalysisRequest(),
        );

        self::assertStringNotContainsString('data-mindfula11y-childtype-', $output);
    }

    #[Test]
    public function customTableContainerEmitsNoChildTypeCoordinates(): void
    {
        // tx_a11ytest_content has no child-type column in TCA: no coordinates,
        // and (Global Constraints) no SELECT of the nonexistent column either.
        $output = $this->render(
            '<mindfula11y:heading recordUid="900" recordTableName="tx_a11ytest_content" recordColumnName="headingtype" relationId="a">Parent</mindfula11y:heading>',
            $this->structureAnalysisRequest(),
        );

        self::assertStringNotContainsString('data-mindfula11y-childtype-', $output);
    }

    #[Test]
    public function suppressedContainerEmitsHiddenMarkerForAnalysis(): void
    {
        // Record 800: own type h2 (headingtype column), child type h4. An empty
        // heading renders nothing, but the analysis request needs a container row:
        // jump target for descendants, and the editable child-type column's home.
        $output = $this->render(
            '<mindfula11y:heading recordUid="800" relationId="a"></mindfula11y:heading>'
            . '<mindfula11y:heading.descendant ancestorId="a">Child</mindfula11y:heading.descendant>',
            $this->structureAnalysisRequest(),
        );

        self::assertStringContainsString('data-mindfula11y-container="h2"', $output);
        self::assertStringContainsString('hidden', $output);
        self::assertStringContainsString('data-mindfula11y-relation-id="a"', $output);
        self::assertStringContainsString('data-mindfula11y-record-uid="800"', $output);
        self::assertStringContainsString('data-mindfula11y-record-value="h2"', $output);
        self::assertStringContainsString('data-mindfula11y-childtype-value="h4"', $output);
        self::assertStringContainsString('Child</h4>', $output);
    }

    #[Test]
    public function suppressedContainerRendersNothingOutsideAnalysis(): void
    {
        // Normal frontend output must stay byte-identical: no marker.
        $output = $this->render(
            '<mindfula11y:heading recordUid="800" relationId="a"></mindfula11y:heading>'
        );

        self::assertSame('', $output);
    }

    #[Test]
    public function suppressedHeadingWithoutRelationIdEmitsNoMarker(): void
    {
        // Nothing can reference it — a marker row would be pure noise.
        $output = $this->render(
            '<mindfula11y:heading recordUid="800"></mindfula11y:heading>',
            $this->structureAnalysisRequest(),
        );

        self::assertSame('', $output);
    }
}
