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

namespace MindfulMarkup\MindfulA11y\Tests\Unit\Enum;

use MindfulMarkup\MindfulA11y\Enum\HeadingType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HeadingTypeTest extends TestCase
{
    /** @return array<string, array{HeadingType, int, HeadingType}> */
    public static function incrementProvider(): array
    {
        return [
            'h2 + 1 = h3' => [HeadingType::H2, 1, HeadingType::H3],
            'h1 + 5 = h6' => [HeadingType::H1, 5, HeadingType::H6],
            'h6 + 1 overflows to p' => [HeadingType::H6, 1, HeadingType::P],
            'h4 + 3 overflows to p' => [HeadingType::H4, 3, HeadingType::P],
            'h1 - 1 underflows to p' => [HeadingType::H1, -1, HeadingType::P],
            'h3 - 1 = h2' => [HeadingType::H3, -1, HeadingType::H2],
            'p unchanged' => [HeadingType::P, 1, HeadingType::P],
            'div unchanged' => [HeadingType::DIV, 3, HeadingType::DIV],
        ];
    }

    #[Test]
    #[DataProvider('incrementProvider')]
    public function incrementStepsWithinHeadingRange(HeadingType $type, int $levels, HeadingType $expected): void
    {
        self::assertSame($expected, $type->increment($levels));
    }

    /**
     * increment(0) must be the identity for h1-h6: DescendantViewHelper renders an
     * ancestor-configured child type verbatim via increment($levels - 1) with the
     * default levels of 1.
     */
    #[Test]
    #[DataProvider('identityProvider')]
    public function incrementByZeroIsIdentity(HeadingType $type): void
    {
        self::assertSame($type, $type->increment(0));
    }

    /** @return array<string, array{HeadingType}> */
    public static function identityProvider(): array
    {
        $cases = [];
        foreach (HeadingType::cases() as $type) {
            $cases[$type->value] = [$type];
        }
        return $cases;
    }
}
