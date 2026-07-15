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

namespace MindfulMarkup\MindfulA11y\Tests\Unit\Domain\Model;

use MindfulMarkup\MindfulA11y\Domain\Model\StructureAnalysisTicket;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\ServerRequest;

final class StructureAnalysisTicketTest extends TestCase
{
    private const NOW = 1700000000;
    private const LIFETIME = 15;

    /** @return array<string, mixed> */
    private static function validClaims(): array
    {
        return [
            'version' => StructureAnalysisTicket::VERSION,
            'requestId' => str_repeat('ab', 16),
            'pageId' => 42,
            'languageId' => 1,
            'workspaceId' => 2,
            'backendUserId' => 3,
            'backendOrigin' => 'https://backend.example',
            'frontendOrigin' => 'https://frontend.example',
            'target' => '/page?a=1',
            'expiresAt' => self::NOW + self::LIFETIME,
        ];
    }

    #[Test]
    public function validClaimsResolveToTicket(): void
    {
        $ticket = StructureAnalysisTicket::fromClaims(self::validClaims(), self::NOW, self::LIFETIME);

        self::assertNotNull($ticket);
        self::assertSame(str_repeat('ab', 16), $ticket->requestId);
        self::assertSame(42, $ticket->pageId);
        self::assertSame(1, $ticket->languageId);
        self::assertSame(2, $ticket->workspaceId);
        self::assertSame(3, $ticket->backendUserId);
        self::assertSame('https://backend.example', $ticket->backendOrigin);
        self::assertSame('https://frontend.example', $ticket->frontendOrigin);
        self::assertSame('/page?a=1', $ticket->target);
        self::assertFalse($ticket->isExpired(self::NOW));
    }

    #[Test]
    public function serializationRoundTripsThroughFromClaims(): void
    {
        $ticket = StructureAnalysisTicket::fromClaims(self::validClaims(), self::NOW, self::LIFETIME);
        self::assertNotNull($ticket);

        $claims = json_decode(json_encode($ticket, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        self::assertNotNull(StructureAnalysisTicket::fromClaims($claims, self::NOW, self::LIFETIME));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidClaimsProvider(): iterable
    {
        $valid = self::validClaims();
        yield 'wrong version' => [['version' => StructureAnalysisTicket::VERSION - 1] + $valid];
        yield 'missing version' => [array_diff_key($valid, ['version' => 0])];
        yield 'requestId too short' => [['requestId' => 'abc123'] + $valid];
        yield 'requestId uppercase hex' => [['requestId' => strtoupper(str_repeat('ab', 16))] + $valid];
        yield 'requestId non-hex' => [['requestId' => str_repeat('zz', 16)] + $valid];
        yield 'pageId zero' => [['pageId' => 0] + $valid];
        yield 'pageId as string' => [['pageId' => '42'] + $valid];
        yield 'negative languageId' => [['languageId' => -1] + $valid];
        yield 'negative workspaceId' => [['workspaceId' => -1] + $valid];
        yield 'backendUserId zero' => [['backendUserId' => 0] + $valid];
        yield 'missing backendOrigin' => [array_diff_key($valid, ['backendOrigin' => ''])];
        yield 'non-string frontendOrigin' => [['frontendOrigin' => 42] + $valid];
        yield 'non-string target' => [['target' => null] + $valid];
        yield 'already expired' => [['expiresAt' => self::NOW] + $valid];
        yield 'expiry beyond maximum lifetime' => [['expiresAt' => self::NOW + self::LIFETIME + 1] + $valid];
        yield 'expiresAt as string' => [['expiresAt' => (string)(self::NOW + 5)] + $valid];
    }

    /** @param array<string, mixed> $claims */
    #[Test]
    #[DataProvider('invalidClaimsProvider')]
    public function invalidClaimsAreRejected(array $claims): void
    {
        self::assertNull(StructureAnalysisTicket::fromClaims($claims, self::NOW, self::LIFETIME));
    }

    #[Test]
    public function expiryBoundaryIsExclusive(): void
    {
        $ticket = StructureAnalysisTicket::fromClaims(self::validClaims(), self::NOW, self::LIFETIME);

        self::assertNotNull($ticket);
        self::assertFalse($ticket->isExpired($ticket->expiresAt - 1));
        self::assertTrue($ticket->isExpired($ticket->expiresAt));
    }

    #[Test]
    public function fromRequestReturnsAttachedTicketOnly(): void
    {
        $ticket = StructureAnalysisTicket::fromClaims(self::validClaims(), self::NOW, self::LIFETIME);
        self::assertNotNull($ticket);

        $bare = new ServerRequest('https://frontend.example/page');
        self::assertNull(StructureAnalysisTicket::fromRequest($bare));

        $wrongType = $bare->withAttribute(StructureAnalysisTicket::REQUEST_ATTRIBUTE, 'not-a-ticket');
        self::assertNull(StructureAnalysisTicket::fromRequest($wrongType));

        $carrying = $bare->withAttribute(StructureAnalysisTicket::REQUEST_ATTRIBUTE, $ticket);
        self::assertSame($ticket, StructureAnalysisTicket::fromRequest($carrying));
    }
}
