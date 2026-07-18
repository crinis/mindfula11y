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

use MindfulMarkup\MindfulA11y\Service\ScanApiService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Render-path robustness of the scanner client: isConfigured() runs on every
 * accessibility-module render, so a missing extension configuration must read
 * as "not configured" instead of throwing (the sibling OpenAIService models
 * the same discipline).
 */
final class ScanApiServiceTest extends TestCase
{
    #[Test]
    public function missingExtensionConfigurationReadsAsUnconfiguredInsteadOfThrowing(): void
    {
        // Unsynced/legacy deployments have no extension configuration at all;
        // ExtensionConfiguration::get() then throws.
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willThrowException(
            new \TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException(
                'not configured',
                1509654728,
            ),
        );
        $service = new ScanApiService(
            $extensionConfiguration,
            $this->createMock(RequestFactory::class),
            $this->createMock(LoggerInterface::class),
        );

        self::assertFalse($service->isConfigured());
    }

    #[Test]
    public function unencodableRequestBodyFailsCleanlyWithoutSendingARequest(): void
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->with('mindfula11y')->willReturn([
            'scannerApiUrl' => 'https://scanner.example',
        ]);
        $requestFactory = $this->createMock(RequestFactory::class);
        // On an encode failure json_encode() without JSON_THROW_ON_ERROR
        // yields false, which would go out as the literal request body.
        $requestFactory->expects(self::never())->method('request');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        $service = new ScanApiService($extensionConfiguration, $requestFactory, $logger);

        // "\xB1\x31" is malformed UTF-8 — json_encode() cannot represent it.
        self::assertNull($service->createScan(['https://example.com/'], scanOptions: ['auth' => "\xB1\x31"]));
    }
}
