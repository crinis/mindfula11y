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

use MindfulMarkup\MindfulA11y\Service\ValidationErrorTitleConfiguration;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

final class ValidationErrorTitleConfigurationTest extends TestCase
{
    #[Test]
    public function missingExtensionConfigurationReadsAsDisabledInsteadOfThrowing(): void
    {
        // Composer deployments may run requests before extension:setup has
        // synced the configuration; ExtensionConfiguration::get() then throws.
        // The title prefix is opt-in, so an unsynced install must read as off.
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willThrowException(
            new \TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException(
                'not configured',
                1509654728,
            ),
        );

        $subject = new ValidationErrorTitleConfiguration($extensionConfiguration);

        self::assertFalse($subject->isEnabled());
    }

    #[Test]
    public function storedTruthyValueReadsAsEnabled(): void
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->with('mindfula11y', 'enableValidationErrorTitlePrefix')->willReturn('1');

        $subject = new ValidationErrorTitleConfiguration($extensionConfiguration);

        self::assertTrue($subject->isEnabled());
    }
}
