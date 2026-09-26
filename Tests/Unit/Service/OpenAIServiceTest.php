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
use MindfulMarkup\MindfulA11y\Service\OpenAIService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Failure diagnosability of the OpenAI client: a failed or unparseable
 * generation is a paid API call that silently produced nothing — it must
 * leave a log trail (the sibling ScanApiService models the same discipline).
 */
final class OpenAIServiceTest extends TestCase
{
    /** @param array<string, string> $configuration */
    private function openAIService(
        RequestFactory $requestFactory,
        LoggerInterface $logger,
        array $configuration = ['openAIApiKey' => 'sk-test'],
    ): OpenAIService {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->with('mindfula11y')->willReturn($configuration);

        return new OpenAIService(new ExtensionSettings($extensionConfiguration), $requestFactory, $logger);
    }

    #[Test]
    public function missingExtensionConfigurationDisablesGenerationInsteadOfThrowing(): void
    {
        // Unsynced/legacy deployments have no extension configuration at all;
        // ExtensionConfiguration::get() then throws. The service must treat
        // that as "generation disabled" — isEnabledAndConfigured() is called
        // from FormEngine render paths where an exception breaks the form.
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willThrowException(
            new \TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException(
                'not configured',
                1509654728,
            ),
        );
        $service = new OpenAIService(
            new ExtensionSettings($extensionConfiguration),
            $this->createMock(RequestFactory::class),
            $this->createMock(LoggerInterface::class),
        );

        self::assertFalse($service->isEnabledAndConfigured());
    }

    #[Test]
    public function failedApiRequestIsLoggedAndReturnsNull(): void
    {
        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->method('request')->willThrowException(new \RuntimeException('connection refused'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        self::assertNull($this->openAIService($requestFactory, $logger)->respond('instructions', []));
    }

    #[Test]
    public function requestIsBoundedByATimeout(): void
    {
        // Core's HTTP.timeout default is 0 (wait forever): without an explicit
        // bound a stalled upstream hangs the editor's generation request.
        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->expects(self::once())
            ->method('request')
            ->with(
                self::anything(),
                'POST',
                self::callback(static fn(array $options): bool => ($options['timeout'] ?? null) === 60
                    && ($options['connect_timeout'] ?? null) === 10),
            )
            ->willThrowException(new \RuntimeException('timed out'));

        self::assertNull($this->openAIService($requestFactory, $this->createMock(LoggerInterface::class))->respond('instructions', []));
    }

    #[Test]
    public function nonStringOutputTextIsLoggedAndReturnsNull(): void
    {
        $stream = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $stream->method('getContents')->willReturn(json_encode([
            'output' => [
                ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => ['not', 'a', 'string']]]],
            ],
        ], JSON_THROW_ON_ERROR));
        $response = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $response->method('getBody')->willReturn($stream);
        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->method('request')->willReturn($response);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        self::assertNull($this->openAIService($requestFactory, $logger)->respond('instructions', []));
    }
}
