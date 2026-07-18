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

use MindfulMarkup\MindfulA11y\Service\AltTextGeneratorService;
use MindfulMarkup\MindfulA11y\Service\OpenAIService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Resource\FileInterface;

/**
 * Configuration robustness of the generation flow: a missing extension
 * configuration must fall back to the default image detail instead of
 * aborting the generation with an exception (the OpenAIService dependency
 * already guards its own reads the same way).
 */
final class AltTextGeneratorServiceTest extends TestCase
{
    #[Test]
    public function missingExtensionConfigurationFallsBackToTheDefaultImageDetail(): void
    {
        // Unsynced/legacy deployments have no extension configuration at all;
        // ExtensionConfiguration::get() then throws.
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willThrowException(
            new ExtensionConfigurationExtensionNotConfiguredException('not configured', 1509654728),
        );

        $stream = $this->createMock(StreamInterface::class);
        $stream->method('getContents')->willReturn(json_encode([
            'output' => [[
                'type' => 'message',
                'content' => [['type' => 'output_text', 'text' => 'Generated alt']],
            ]],
        ], JSON_THROW_ON_ERROR));
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getBody')->willReturn($stream);

        $capturedOptions = null;
        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->method('request')->willReturnCallback(
            function (string $url, string $method, array $options) use (&$capturedOptions, $response): ResponseInterface {
                $capturedOptions = $options;
                return $response;
            },
        );

        $file = $this->createMock(FileInterface::class);
        $file->method('getContents')->willReturn('image-bytes');
        $file->method('getMimeType')->willReturn('image/png');

        $service = new AltTextGeneratorService(
            new OpenAIService($extensionConfiguration, $requestFactory, $this->createMock(LoggerInterface::class)),
            $extensionConfiguration,
        );

        self::assertSame('Generated alt', $service->generate($file));
        $requestBody = json_decode((string)($capturedOptions['body'] ?? ''), true);
        self::assertSame('auto', $requestBody['input'][0]['content'][0]['detail'] ?? null);
    }
}
