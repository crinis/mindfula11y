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
            new ExtensionSettings($extensionConfiguration),
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

        $service = new ScanApiService(new ExtensionSettings($extensionConfiguration), $requestFactory, $logger);

        // "\xB1\x31" is malformed UTF-8 — json_encode() cannot represent it.
        self::assertNull($service->createScan(['https://example.com/'], scanOptions: ['auth' => "\xB1\x31"]));
    }

    /**
     * The scanner's problem detail is third-party text describing a request
     * that carried this installation's API token and the site's Basic Auth
     * credentials. An upstream that echoes its input back in a validation error
     * would otherwise hand those to any editor able to induce one, so no value
     * this installation sent may survive into the client-facing message.
     */
    #[Test]
    public function reflectedCredentialsAreRedactedFromTheClientFacingDetail(): void
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->with('mindfula11y')->willReturn([
            'scannerApiUrl' => 'https://scanner.example',
            'scannerApiToken' => 'super-secret-api-token',
        ]);

        $reflected = 'Invalid request: {"basicAuth":{"username":"site-user","password":"site-password-1234"},'
            . '"token":"super-secret-api-token"}';
        $stream = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $stream->method('__toString')->willReturn(json_encode([
            'title' => 'Bad Request',
            'detail' => $reflected,
        ], JSON_THROW_ON_ERROR));
        $response = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(400);
        $response->method('getBody')->willReturn($stream);

        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->method('request')->willReturn($response);

        $service = new ScanApiService(
            new ExtensionSettings($extensionConfiguration),
            $requestFactory,
            $this->createMock(LoggerInterface::class),
        );

        try {
            $service->createScan(
                ['https://example.com/'],
                scanOptions: ['basicAuth' => ['username' => 'site-user', 'password' => 'site-password-1234']],
            );
            self::fail('the scanner rejection must surface as an exception');
        } catch (\MindfulMarkup\MindfulA11y\Exception\ScanApiRequestException $exception) {
            $detail = $exception->getProblemDetail();

            self::assertStringNotContainsString('super-secret-api-token', $detail, 'API token must not be echoed back');
            self::assertStringNotContainsString('site-password-1234', $detail, 'Basic Auth password must not be echoed back');
            self::assertStringNotContainsString('site-user', $detail, 'Basic Auth username must not be echoed back');
            self::assertStringContainsString('[redacted]', $detail, 'the message is redacted, not discarded');
            self::assertStringContainsString('Invalid request', $detail, 'the actionable part survives');
        }
    }

    /**
     * Short credentials are still credentials: a staging password like
     * "preview" must not reach the editor just because it is under eight
     * characters. Redaction may not fail open on length.
     */
    #[Test]
    public function shortReflectedCredentialsAreRedactedFromTheClientFacingDetail(): void
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->with('mindfula11y')->willReturn([
            'scannerApiUrl' => 'https://scanner.example',
        ]);

        $reflected = 'Invalid request: {"basicAuth":{"username":"web","password":"preview"}}';
        $stream = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $stream->method('__toString')->willReturn(json_encode([
            'title' => 'Bad Request',
            'detail' => $reflected,
        ], JSON_THROW_ON_ERROR));
        $response = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(400);
        $response->method('getBody')->willReturn($stream);

        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->method('request')->willReturn($response);

        $service = new ScanApiService(
            new ExtensionSettings($extensionConfiguration),
            $requestFactory,
            $this->createMock(LoggerInterface::class),
        );

        try {
            $service->createScan(
                ['https://example.com/'],
                scanOptions: ['basicAuth' => ['username' => 'web', 'password' => 'preview']],
            );
            self::fail('the scanner rejection must surface as an exception');
        } catch (\MindfulMarkup\MindfulA11y\Exception\ScanApiRequestException $exception) {
            $detail = $exception->getProblemDetail();

            self::assertStringNotContainsString('preview', $detail, 'a short Basic Auth password must not be echoed back');
            self::assertStringNotContainsString('"web"', $detail, 'a short Basic Auth username must not be echoed back');
            self::assertStringContainsString('Invalid request', $detail, 'the actionable part survives');
        }
    }

    /**
     * Redaction is a single pass: a short secret must not match inside the
     * "[redacted]" marker inserted for a longer one, and a secret contained in
     * another must not break the longer match.
     */
    #[Test]
    public function redactionDoesNotRewriteItsOwnMarkerOrSplitNestedSecrets(): void
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->with('mindfula11y')->willReturn([
            'scannerApiUrl' => 'https://scanner.example',
        ]);

        $stream = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $stream->method('__toString')->willReturn(json_encode([
            'title' => 'Bad Request',
            'detail' => 'Rejected credentials act / act-staging-2026',
        ], JSON_THROW_ON_ERROR));
        $response = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(400);
        $response->method('getBody')->willReturn($stream);

        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->method('request')->willReturn($response);

        $service = new ScanApiService(
            new ExtensionSettings($extensionConfiguration),
            $requestFactory,
            $this->createMock(LoggerInterface::class),
        );

        try {
            $service->createScan(
                ['https://example.com/'],
                scanOptions: ['basicAuth' => ['username' => 'act', 'password' => 'act-staging-2026']],
            );
            self::fail('the scanner rejection must surface as an exception');
        } catch (\MindfulMarkup\MindfulA11y\Exception\ScanApiRequestException $exception) {
            self::assertSame('Rejected credentials [redacted] / [redacted]', $exception->getProblemDetail());
        }
    }

    /**
     * An unbounded upstream string would turn a backend error message into a
     * channel for bulk third-party output.
     */
    #[Test]
    public function clientFacingDetailIsLengthCapped(): void
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->with('mindfula11y')->willReturn([
            'scannerApiUrl' => 'https://scanner.example',
        ]);

        $stream = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $stream->method('__toString')->willReturn(json_encode([
            'detail' => str_repeat('A', 5000),
        ], JSON_THROW_ON_ERROR));
        $response = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(400);
        $response->method('getBody')->willReturn($stream);

        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->method('request')->willReturn($response);

        $service = new ScanApiService(
            new ExtensionSettings($extensionConfiguration),
            $requestFactory,
            $this->createMock(LoggerInterface::class),
        );

        try {
            $service->createScan(['https://example.com/']);
            self::fail('the scanner rejection must surface as an exception');
        } catch (\MindfulMarkup\MindfulA11y\Exception\ScanApiRequestException $exception) {
            self::assertLessThanOrEqual(500, mb_strlen($exception->getProblemDetail()));
        }
    }

    /**
     * A logger that keeps every record, so tests can inspect the context the
     * service hands to the log.
     */
    private function recordingLogger(): \Psr\Log\AbstractLogger
    {
        return new class extends \Psr\Log\AbstractLogger {
            /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string)$message, 'context' => $context];
            }
        };
    }

    private function serviceAnswering(int $status, string $body, LoggerInterface $logger): ScanApiService
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->with('mindfula11y')->willReturn([
            'scannerApiUrl' => 'https://scanner.example',
            'scannerApiToken' => 'super-secret-api-token',
        ]);
        $stream = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $stream->method('__toString')->willReturn($body);
        $response = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getBody')->willReturn($stream);
        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->method('request')->willReturn($response);

        return new ScanApiService(new ExtensionSettings($extensionConfiguration), $requestFactory, $logger);
    }

    /**
     * Valid JSON is not necessarily an object: a scalar body must degrade to
     * the documented null (logged) instead of a TypeError on the ?array
     * return type.
     */
    #[Test]
    public function scalarJsonBodyIsLoggedAndReturnsNull(): void
    {
        $logger = $this->recordingLogger();

        self::assertNull($this->serviceAnswering(201, '42', $logger)->createScan(['https://example.com/']));
        self::assertCount(1, $logger->records, 'the unusable body leaves a log trail');
    }

    /**
     * Log files are read by more people than the extension configuration:
     * the problem details logged for a rejected request must carry the same
     * redaction as the editor-facing message.
     */
    #[Test]
    public function reflectedCredentialsAreRedactedFromTheLogContext(): void
    {
        $logger = $this->recordingLogger();
        $service = $this->serviceAnswering(400, json_encode([
            'title' => 'Bad token super-secret-api-token',
            'detail' => 'Invalid request: {"password":"site-password-1234","token":"super-secret-api-token"}',
            'errors' => [['field' => 'basicAuth.password', 'value' => 'site-password-1234']],
        ], JSON_THROW_ON_ERROR), $logger);

        try {
            $service->createScan(
                ['https://example.com/'],
                scanOptions: ['basicAuth' => ['username' => 'site-user', 'password' => 'site-password-1234']],
            );
            self::fail('the scanner rejection must surface as an exception');
        } catch (\MindfulMarkup\MindfulA11y\Exception\ScanApiRequestException) {
        }

        self::assertCount(1, $logger->records);
        $logged = json_encode($logger->records, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('super-secret-api-token', $logged, 'API token must not reach the log');
        self::assertStringNotContainsString('site-password-1234', $logged, 'Basic Auth password must not reach the log');
        self::assertStringContainsString('Invalid request', $logged, 'the diagnostic part survives');
    }

    /**
     * MindfulAPI rejects `"skills": []` with a validation error, and an empty
     * selection means "no AI review" — the request must never carry it, nor
     * fall back to `{}`, which would run every server-enabled skill.
     */
    #[Test]
    public function anEmptySkillSelectionNeverReachesTheScanner(): void
    {
        $bodies = [];
        $service = $this->serviceRecordingRequestBodies($bodies);

        $service->createScan(['https://example.com/'], includeAiAudit: true, aiAuditSkills: []);
        $service->createScan(['https://example.com/'], includeAiAudit: true, aiAuditSkills: null);
        $service->createScan(['https://example.com/'], includeAiAudit: true, aiAuditSkills: ['image_alt_text']);

        self::assertArrayNotHasKey('aiAudit', $bodies[0]);
        self::assertSame([], $bodies[1]['aiAudit'], 'unset skills: every server-enabled skill ({})');
        self::assertSame(['skills' => ['image_alt_text']], $bodies[2]['aiAudit']);
    }

    /**
     * A service whose scanner accepts every create; the decoded request bodies
     * are collected in $bodies.
     *
     * @param list<array<string, mixed>> $bodies
     */
    private function serviceRecordingRequestBodies(array &$bodies): ScanApiService
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->with('mindfula11y')->willReturn([
            'scannerApiUrl' => 'https://scanner.example',
        ]);
        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->method('request')->willReturnCallback(
            function (string $uri, string $method, array $options) use (&$bodies): \Psr\Http\Message\ResponseInterface {
                $bodies[] = json_decode((string)$options['body'], true, flags: JSON_THROW_ON_ERROR);

                return new \TYPO3\CMS\Core\Http\JsonResponse(['id' => 1, 'status' => 'pending'], 201);
            }
        );

        return new ScanApiService(new ExtensionSettings($extensionConfiguration), $requestFactory, $this->recordingLogger());
    }

    /**
     * MindfulAPI throttles per client IP, and every editor of an installation
     * shares the TYPO3 server's. A rate-limited poll must stay recognizable —
     * with the wait the API asks for — instead of degrading to the generic
     * failure the scan view re-polls into.
     */
    #[Test]
    public function rateLimitedScanLoadSurfacesTheStatusAndTheRequestedWait(): void
    {
        $service = $this->serviceAnsweringWithHeaders(
            429,
            json_encode(['title' => 'Too Many Requests', 'status' => 429, 'detail' => 'ThrottlerException: Too Many Requests'], JSON_THROW_ON_ERROR),
            ['Retry-After' => '17'],
        );

        try {
            $service->getScan('42');
            self::fail('a rate-limited load must surface as an exception');
        } catch (\MindfulMarkup\MindfulA11y\Exception\ScanApiRequestException $exception) {
            self::assertSame(429, $exception->getStatusCode());
            self::assertSame(17, $exception->getRetryAfter());
        }
    }

    #[Test]
    public function retryAfterGivenAsHttpDateIsConvertedToSeconds(): void
    {
        $service = $this->serviceAnsweringWithHeaders(
            429,
            '{}',
            ['Retry-After' => gmdate('D, d M Y H:i:s \G\M\T', time() + 120)],
        );

        try {
            $service->getScan('42');
            self::fail('a rate-limited load must surface as an exception');
        } catch (\MindfulMarkup\MindfulA11y\Exception\ScanApiRequestException $exception) {
            self::assertEqualsWithDelta(120, $exception->getRetryAfter(), 2);
        }
    }

    #[Test]
    public function otherFailedScanLoadsStillDegradeToNull(): void
    {
        self::assertNull($this->serviceAnsweringWithHeaders(503, '{}', ['Retry-After' => '5'])->getScan('42'));
    }

    /**
     * @param array<string, string> $headers
     */
    private function serviceAnsweringWithHeaders(int $status, string $body, array $headers): ScanApiService
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->with('mindfula11y')->willReturn([
            'scannerApiUrl' => 'https://scanner.example',
        ]);
        $response = new \TYPO3\CMS\Core\Http\Response('php://temp', $status, $headers);
        $response->getBody()->write($body);
        $response->getBody()->rewind();
        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->method('request')->willReturn($response);

        return new ScanApiService(new ExtensionSettings($extensionConfiguration), $requestFactory, $this->recordingLogger());
    }

    #[Test]
    public function invalidJsonBodyIsRedactedInTheLogContext(): void
    {
        $logger = $this->recordingLogger();

        self::assertNull(
            $this->serviceAnswering(201, '<html>echo: site-password-1234 super-secret-api-token</html>', $logger)->createScan(
                ['https://example.com/'],
                scanOptions: ['basicAuth' => ['username' => 'site-user', 'password' => 'site-password-1234']],
            )
        );

        self::assertCount(1, $logger->records);
        $logged = json_encode($logger->records, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('super-secret-api-token', $logged, 'API token must not reach the log');
        self::assertStringNotContainsString('site-password-1234', $logged, 'Basic Auth password must not reach the log');
        self::assertStringContainsString('<html>echo', $logged, 'the diagnostic part survives');
    }
}
