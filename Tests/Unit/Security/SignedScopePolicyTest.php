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

namespace MindfulMarkup\MindfulA11y\Tests\Unit\Security;

use MindfulMarkup\MindfulA11y\Domain\Model\SignedScopeInterface;
use MindfulMarkup\MindfulA11y\Security\SignedScopePolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The single definition of the bounded-expiry window every signed scope is
 * judged against: current means strictly unexpired AND not further in the
 * future than the scope type's maximum lifetime allows (a forged far-future
 * expiry fails even with an intact signature).
 */
final class SignedScopePolicyTest extends TestCase
{
    private const NOW = 1700000000;
    private const MAXIMUM_LIFETIME = 15;

    private static function scope(int $expiresAt): SignedScopeInterface
    {
        return new class ($expiresAt, self::MAXIMUM_LIFETIME) implements SignedScopeInterface {
            public function __construct(
                private readonly int $expiresAt,
                private readonly int $maximumLifetime,
            ) {
            }

            public function getExpiresAt(): int
            {
                return $this->expiresAt;
            }

            public function maximumLifetime(): int
            {
                return $this->maximumLifetime;
            }
        };
    }

    /** @return iterable<string, array{int, bool}> */
    public static function expiryWindowProvider(): iterable
    {
        yield 'inside the window' => [self::NOW + 1, true];
        yield 'exactly at now (lower bound is exclusive)' => [self::NOW, false];
        yield 'at the maximum lifetime (upper bound is inclusive)' => [self::NOW + self::MAXIMUM_LIFETIME, true];
        yield 'one beyond the maximum lifetime' => [self::NOW + self::MAXIMUM_LIFETIME + 1, false];
        yield 'already expired' => [self::NOW - 1, false];
        yield 'unset expiry fails closed' => [0, false];
    }

    #[Test]
    #[DataProvider('expiryWindowProvider')]
    public function expiryWindowIsBoundedOnBothSides(int $expiresAt, bool $expected): void
    {
        self::assertSame($expected, SignedScopePolicy::isCurrent(self::scope($expiresAt), self::NOW));
    }
}
