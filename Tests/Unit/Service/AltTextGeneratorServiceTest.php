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
use MindfulMarkup\MindfulA11y\Service\ExtensionSettings;
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
            new OpenAIService(new ExtensionSettings($extensionConfiguration), $requestFactory, $this->createMock(LoggerInterface::class)),
            $this->createMock(LoggerInterface::class),
        );

        self::assertSame('Generated alt', $service->generate($file));
        $requestBody = json_decode((string)($capturedOptions['body'] ?? ''), true);
        self::assertSame('auto', $requestBody['input'][0]['content'][0]['detail'] ?? null);
    }

    /**
     * The image is read into memory and base64 inflates it by a further ~4/3,
     * so an oversized file must be rejected BEFORE getContents() runs — the
     * check exists to bound memory, and reading first would defeat it.
     */
    #[Test]
    public function oversizedImageIsRejectedWithoutReadingTheFile(): void
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn([]);

        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->expects(self::never())->method('request');

        $file = $this->createMock(FileInterface::class);
        $file->method('getSize')->willReturn(21 * 1024 * 1024);
        $file->method('getIdentifier')->willReturn('/huge.png');
        $file->expects(self::never())->method('getContents');

        $service = new AltTextGeneratorService(
            new OpenAIService(new ExtensionSettings($extensionConfiguration), $requestFactory, $this->createMock(LoggerInterface::class)),
            $this->createMock(LoggerInterface::class),
        );

        self::assertNull($service->generate($file));
    }

    /**
     * getSize() is a file access, not a property read: core throws for a
     * deleted file and otherwise asks the storage driver whenever the size was
     * never indexed, which fails on an unreachable remote storage. The service
     * documents a null return for a failed generation, and the AJAX controller
     * turns exactly that into the localized error body — an escaping exception
     * would degrade it to an untyped 500.
     */
    #[Test]
    public function unreadableFileSizeFailsAsNullInsteadOfEscaping(): void
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn([]);

        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->expects(self::never())->method('request');

        $file = $this->createMock(FileInterface::class);
        $file->method('getSize')->willThrowException(
            new \RuntimeException('File has been deleted.', 1329821480),
        );
        $file->method('getIdentifier')->willReturn('/gone.png');
        $file->expects(self::never())->method('getContents');

        // The editor only learns that generation failed, so the operator-facing
        // trace is the whole diagnosis for an unreachable storage.
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            self::stringContains('could not be read'),
            self::callback(static fn(array $context): bool => ($context['file'] ?? null) === '/gone.png'),
        );

        $service = new AltTextGeneratorService(
            new OpenAIService(new ExtensionSettings($extensionConfiguration), $requestFactory, $this->createMock(LoggerInterface::class)),
            $logger,
        );

        self::assertNull($service->generate($file));
    }
}
