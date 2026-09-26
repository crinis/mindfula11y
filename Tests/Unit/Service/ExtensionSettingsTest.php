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

use MindfulMarkup\MindfulA11y\Service\ExtensionSettings;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

final class ExtensionSettingsTest extends TestCase
{
    #[Test]
    public function storedValueIsReturnedAsStored(): void
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->with('mindfula11y')->willReturn(['openAIApiKey' => 'sk-test']);

        self::assertSame('sk-test', (new ExtensionSettings($extensionConfiguration))->get('openAIApiKey', ''));
    }

    #[Test]
    public function missingKeyReadsAsTheDefault(): void
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn([]);
        $subject = new ExtensionSettings($extensionConfiguration);

        self::assertSame('auto', $subject->get('openAIChatImageDetail', 'auto'));
        self::assertNull($subject->get('openAIChatImageDetail'));
    }

    #[Test]
    public function unsyncedConfigurationReadsAsDefaultsInsteadOfThrowing(): void
    {
        // Unsynced/legacy deployments have no extension configuration at all;
        // ExtensionConfiguration::get() then throws.
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willThrowException(
            new ExtensionConfigurationExtensionNotConfiguredException('not configured', 1509654728),
        );

        self::assertFalse((new ExtensionSettings($extensionConfiguration))->get('disableAltTextGeneration', false));
    }

    #[Test]
    public function configurationIsReadOncePerInstanceAndOnlyOnFirstAccess(): void
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->expects(self::once())->method('get')->willReturn(['a' => '1', 'b' => '2']);
        $subject = new ExtensionSettings($extensionConfiguration);

        self::assertSame('1', $subject->get('a'));
        self::assertSame('2', $subject->get('b'));
    }

    #[Test]
    public function nothingIsReadUntilTheFirstAccess(): void
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->expects(self::never())->method('get');

        new ExtensionSettings($extensionConfiguration);
    }
}
