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
 * Renders the landmark ViewHelper through real Fluid to pin the element/role
 * mapping — in particular that banner and contentinfo carry an explicit role
 * attribute: header/footer only expose these landmark roles implicitly when
 * they are NOT descendants of sectioning content, and content elements
 * typically render inside main/section.
 */
final class LandmarkViewHelperTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'mindfulmarkup/mindfula11y',
    ];

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
            languageId: 1,
            workspaceId: 0,
            pageRecordSnapshot: str_repeat('a', 64),
            backendUserId: 1,
            backendOrigin: 'https://backend.example',
            frontendOrigin: 'https://frontend.example',
            target: '/fr/',
            expiresAt: PHP_INT_MAX,
        );

        return (new ServerRequest('https://frontend.example/fr/'))
            ->withAttribute(StructureAnalysisTicket::REQUEST_ATTRIBUTE, $ticket);
    }

    #[Test]
    public function bannerRendersHeaderWithExplicitRole(): void
    {
        $output = $this->render('<mindfula11y:landmark role="banner">Header</mindfula11y:landmark>');

        self::assertStringContainsString('<header role="banner">Header</header>', $output);
    }

    #[Test]
    public function contentinfoRendersFooterWithExplicitRole(): void
    {
        $output = $this->render('<mindfula11y:landmark role="contentinfo">Footer</mindfula11y:landmark>');

        self::assertStringContainsString('<footer role="contentinfo">Footer</footer>', $output);
    }

    #[Test]
    public function navigationKeepsImplicitRole(): void
    {
        // nav maps to the navigation landmark regardless of nesting, so no
        // explicit role attribute must be emitted.
        $output = $this->render('<mindfula11y:landmark role="navigation">Links</mindfula11y:landmark>');

        self::assertStringContainsString('<nav>Links</nav>', $output);
    }

    #[Test]
    public function tagNameOverrideKeepsExplicitRole(): void
    {
        $output = $this->render('<mindfula11y:landmark role="navigation" tagName="div">Links</mindfula11y:landmark>');

        self::assertStringContainsString('<div role="navigation">Links</div>', $output);
    }

    #[Test]
    public function ticketlessFrontendRequestEmitsNoAnalysisAnnotations(): void
    {
        // The ticket attribute is the sole gate for analysis annotations: a
        // regular frontend request must yield clean public markup even with
        // record coordinates configured, or record uids and column names
        // would leak to every visitor.
        $output = $this->render(
            '<mindfula11y:landmark role="navigation" recordUid="102">Links</mindfula11y:landmark>',
            new ServerRequest('https://frontend.example/'),
        );

        self::assertStringContainsString('<nav>Links</nav>', $output);
        self::assertStringNotContainsString('data-mindfula11y-', $output);
    }

    #[Test]
    public function defaultLayoutAnnotatesTranslatedLandmarkWithLocalizedRecordUid(): void
    {
        $context = $this->get(RenderingContextFactory::class)->create([], $this->structureAnalysisRequest());
        $context->getTemplatePaths()->setLayoutRootPaths([
            10 => __DIR__ . '/../../../Resources/Private/Layouts/',
        ]);
        $context->getTemplatePaths()->setTemplateSource(
            '<html xmlns:f="http://typo3.org/ns/TYPO3/CMS/Fluid/ViewHelpers" data-namespace-typo3-fluid="true">'
            . '<f:layout name="Default" />'
            . '<f:section name="Before"></f:section>'
            . '<f:section name="Header"></f:section>'
            . '<f:section name="Main">Body</f:section>'
            . '<f:section name="Footer"></f:section>'
            . '<f:section name="After"></f:section>'
            . '</html>'
        );
        $view = new TemplateView($context);
        $view->assign('data', [
            'uid' => 100,
            '_LOCALIZED_UID' => 102,
            'frame_class' => 'none',
            'tx_mindfula11y_landmark' => 'navigation',
            'tx_mindfula11y_arialabelledby' => 0,
            'tx_mindfula11y_arialabel' => '',
            'header' => '',
            'space_before_class' => '',
            'space_after_class' => '',
        ]);

        $output = trim($view->render());

        self::assertStringContainsString('data-mindfula11y-record-uid="102"', $output);
        self::assertStringNotContainsString('data-mindfula11y-record-uid="100"', $output);
    }
}
