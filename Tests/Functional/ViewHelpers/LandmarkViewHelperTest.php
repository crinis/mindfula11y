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

use PHPUnit\Framework\Attributes\Test;
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

    private function render(string $source): string
    {
        $context = $this->get(RenderingContextFactory::class)->create();
        $context->getTemplatePaths()->setTemplateSource(
            '<html xmlns:mindfula11y="http://typo3.org/ns/MindfulMarkup/MindfulA11y/ViewHelpers" data-namespace-typo3-fluid="true">'
            . $source
            . '</html>'
        );

        return trim((new TemplateView($context))->render());
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
}
