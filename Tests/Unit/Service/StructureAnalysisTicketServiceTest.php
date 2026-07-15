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

use MindfulMarkup\MindfulA11y\Domain\Model\StructureAnalysisTicket;
use MindfulMarkup\MindfulA11y\Service\StructureAnalysisTicketService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Crypto\HashService;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Utility\StringUtility;

final class StructureAnalysisTicketServiceTest extends TestCase
{
    private StructureAnalysisTicketService $subject;
    private HashService $hashService;

    protected function setUp(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = str_repeat('a1b2c3d4', 12);
        $this->hashService = new HashService();
        $this->subject = new StructureAnalysisTicketService($this->hashService);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']);
    }

    private function issueToken(string $targetUrl = 'https://frontend.example/page?b=2&a=1'): string
    {
        $result = $this->subject->issueAnalysisUrl($targetUrl, 42, 1, 2, 3, 'https://backend.example');
        parse_str((string)(new Uri($result['url']))->getQuery(), $query);
        $token = $query[StructureAnalysisTicketService::TICKET_QUERY_PARAMETER] ?? '';
        self::assertIsString($token);
        self::assertNotSame('', $token);
        return $token;
    }

    #[Test]
    public function issuedTicketValidatesAndCarriesNormalizedScope(): void
    {
        $ticket = $this->subject->validate($this->issueToken());

        self::assertNotNull($ticket);
        self::assertSame(42, $ticket->pageId);
        self::assertSame(1, $ticket->languageId);
        self::assertSame(2, $ticket->workspaceId);
        self::assertSame(3, $ticket->backendUserId);
        self::assertSame('https://backend.example', $ticket->backendOrigin);
        self::assertSame('https://frontend.example', $ticket->frontendOrigin);
        // Query keys sorted, ticket parameter never part of the signed target.
        self::assertSame('/page?a=1&b=2', $ticket->target);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $ticket->requestId);
    }

    #[Test]
    public function issuedUrlKeepsExistingQueryAndAppendsTicket(): void
    {
        $result = $this->subject->issueAnalysisUrl('https://frontend.example/page?b=2&a=1', 42, 0, 0, 3, 'https://backend.example');
        parse_str((string)(new Uri($result['url']))->getQuery(), $query);

        self::assertSame('1', $query['a'] ?? null);
        self::assertSame('2', $query['b'] ?? null);
        self::assertArrayHasKey(StructureAnalysisTicketService::TICKET_QUERY_PARAMETER, $query);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $result['requestId']);
    }

    /** @return iterable<string, array{callable(string): string}> */
    public static function tamperedTokenProvider(): iterable
    {
        yield 'flipped signature character' => [static function (string $token): string {
            $flipped = substr($token, -1) === 'a' ? 'b' : 'a';
            return substr($token, 0, -1) . $flipped;
        }];
        yield 'missing signature part' => [static fn(string $token): string => explode('.', $token, 2)[0]];
        yield 'empty token' => [static fn(string $token): string => ''];
        yield 'replaced payload keeping signature' => [static function (string $token): string {
            [, $signature] = explode('.', $token, 2);
            return StringUtility::base64urlEncode('{"version":2}') . '.' . $signature;
        }];
    }

    /** @param callable(string): string $tamper */
    #[Test]
    #[DataProvider('tamperedTokenProvider')]
    public function tamperedTokenIsRejected(callable $tamper): void
    {
        self::assertNull($this->subject->validate($tamper($this->issueToken())));
    }

    #[Test]
    public function tamperedClaimsCannotBeReSignedWithoutTheKey(): void
    {
        $token = $this->issueToken();
        [$payload] = explode('.', $token, 2);
        $claims = json_decode(StringUtility::base64urlDecode($payload, true) ?: '', true);
        self::assertIsArray($claims);

        $claims['pageId'] = 4242;
        $forgedPayload = StringUtility::base64urlEncode(json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $forgedSignature = hash_hmac('sha1', $forgedPayload, 'guessed-secret');

        self::assertNull($this->subject->validate($forgedPayload . '.' . $forgedSignature));
    }

    #[Test]
    public function expiredTicketIsRejectedEvenWithValidSignature(): void
    {
        // Reproduce the wire format with a past expiry; the signing context is
        // part of the service contract (class name + ticket version).
        $claims = [
            'version' => StructureAnalysisTicket::VERSION,
            'requestId' => str_repeat('cd', 16),
            'pageId' => 42,
            'languageId' => 0,
            'workspaceId' => 0,
            'backendUserId' => 3,
            'backendOrigin' => 'https://backend.example',
            'frontendOrigin' => 'https://frontend.example',
            'target' => '/page',
            'expiresAt' => time() - 1,
        ];
        $payload = StringUtility::base64urlEncode(json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $signature = $this->hashService->hmac(
            $payload,
            StructureAnalysisTicketService::class . ':v' . StructureAnalysisTicket::VERSION,
        );

        self::assertNull($this->subject->validate($payload . '.' . $signature));
    }

    #[Test]
    public function invalidAuthorizationScopeIsRejectedAtIssuance(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->subject->issueAnalysisUrl('https://frontend.example/page', 0, 0, 0, 3, 'https://backend.example');
    }

    /** @return iterable<string, array{string, string}> */
    public static function normalizedOriginProvider(): iterable
    {
        yield 'lowercases scheme and host' => ['HTTPS://Example.COM', 'https://example.com'];
        yield 'strips default https port' => ['https://example.com:443', 'https://example.com'];
        yield 'strips default http port' => ['http://example.com:80', 'http://example.com'];
        yield 'keeps custom port' => ['https://example.com:8443', 'https://example.com:8443'];
        yield 'allows trailing slash' => ['https://example.com/', 'https://example.com'];
        yield 'punycodes unicode hosts' => ['https://exämple.com', 'https://xn--exmple-cua.com'];
        yield 'brackets keep ipv6 hosts' => ['http://[::1]:8080', 'http://[::1]:8080'];
    }

    #[Test]
    #[DataProvider('normalizedOriginProvider')]
    public function originsAreNormalized(string $origin, string $expected): void
    {
        self::assertSame($expected, $this->subject->normalizeOrigin($origin));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidOriginProvider(): iterable
    {
        yield 'path component' => ['https://example.com/foo'];
        yield 'query component' => ['https://example.com?x=1'];
        yield 'userinfo' => ['https://user:pass@example.com'];
        yield 'non-http scheme' => ['ftp://example.com'];
        yield 'missing scheme' => ['example.com'];
        yield 'fragment' => ['https://example.com#top'];
    }

    #[Test]
    #[DataProvider('invalidOriginProvider')]
    public function invalidOriginsAreRejected(string $origin): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->subject->normalizeOrigin($origin);
    }

    #[Test]
    public function originFromUrlExtractsSchemeHostAndPort(): void
    {
        self::assertSame('https://example.com:8443', $this->subject->originFromUrl('https://example.com:8443/page?x=1'));
        self::assertSame('https://example.com', $this->subject->originFromUrl('https://example.com/page'));
    }

    #[Test]
    public function originFromUrlRejectsRelativeUrls(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->subject->originFromUrl('/page?x=1');
    }

    /** @return iterable<string, array{string, string}> */
    public static function normalizedTargetProvider(): iterable
    {
        yield 'sorts query and strips ticket parameter' => [
            'https://example.com/page?b=2&' . StructureAnalysisTicketService::TICKET_QUERY_PARAMETER . '=x&a=1',
            '/page?a=1&b=2',
        ];
        yield 'defaults to root path' => ['https://example.com', '/'];
        yield 'keeps plain path' => ['https://example.com/sub/page', '/sub/page'];
    }

    #[Test]
    #[DataProvider('normalizedTargetProvider')]
    public function targetsAreNormalized(string $url, string $expected): void
    {
        self::assertSame($expected, $this->subject->normalizeTarget($url));
    }
}
