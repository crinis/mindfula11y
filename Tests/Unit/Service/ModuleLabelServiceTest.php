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

namespace MindfulMarkup\MindfulA11y\Tests\Unit\Service;

use MindfulMarkup\MindfulA11y\Service\BackendUserProvider;
use MindfulMarkup\MindfulA11y\Service\ModuleLabelService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

final class ModuleLabelServiceTest extends TestCase
{
    private bool $backendUserWasSet;
    private mixed $previousBackendUser;

    protected function setUp(): void
    {
        $this->backendUserWasSet = array_key_exists('BE_USER', $GLOBALS);
        $this->previousBackendUser = $GLOBALS['BE_USER'] ?? null;
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->user = ['uid' => 1];
        $GLOBALS['BE_USER'] = $backendUser;
    }

    protected function tearDown(): void
    {
        if ($this->backendUserWasSet) {
            $GLOBALS['BE_USER'] = $this->previousBackendUser;
        } else {
            unset($GLOBALS['BE_USER']);
        }
    }

    private function subject(): ModuleLabelService
    {
        $languageService = $this->createMock(LanguageService::class);
        $languageService->method('sL')->willReturnCallback(
            static fn(string $input): string => 'translated:' . substr($input, strlen(ModuleLabelService::LANGUAGE_FILE)),
        );
        $languageServiceFactory = $this->createMock(LanguageServiceFactory::class);
        $languageServiceFactory->method('createFromUserPreferences')->willReturn($languageService);

        return new ModuleLabelService($languageServiceFactory, new BackendUserProvider());
    }

    #[Test]
    public function selectedIdsYieldOnlyThoseLabels(): void
    {
        self::assertSame(
            [
                'mindfula11y.altText.generate.loading' => 'translated:altText.generate.loading',
                'mindfula11y.altText.generate.success' => 'translated:altText.generate.success',
            ],
            $this->subject()->getInlineLanguageLabels(['altText.generate.loading', 'altText.generate.success']),
        );
    }

    #[Test]
    public function withoutASelectionEveryLabelIncludingTheDerivedFamiliesIsReturned(): void
    {
        $labels = $this->subject()->getInlineLanguageLabels();

        self::assertArrayHasKey('mindfula11y.altText.generate.loading', $labels);
        self::assertArrayHasKey('mindfula11y.structure.landmarks.role.main', $labels);
        self::assertArrayHasKey('mindfula11y.structure.headings.level.h1', $labels);
    }

    #[Test]
    public function unknownIdIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->subject()->getInlineLanguageLabels(['altText.generate.typo']);
    }
}
