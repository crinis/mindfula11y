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

use MindfulMarkup\MindfulA11y\Domain\Model\HeadingRelation;
use MindfulMarkup\MindfulA11y\Enum\HeadingType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HeadingRelationTest extends TestCase
{
    #[Test]
    public function defaultsToAutomaticChildType(): void
    {
        $relation = new HeadingRelation(HeadingType::H2);

        self::assertSame(HeadingType::H2, $relation->type);
        self::assertNull($relation->childType);
    }

    #[Test]
    public function carriesAnExplicitChildType(): void
    {
        $relation = new HeadingRelation(
            type: HeadingType::H2,
            childType: HeadingType::H3,
        );

        self::assertSame(HeadingType::H2, $relation->type);
        self::assertSame(HeadingType::H3, $relation->childType);
    }

    #[Test]
    public function supportsHeadinglessPublisher(): void
    {
        $relation = new HeadingRelation(
            type: null,
            childType: HeadingType::H1,
        );

        self::assertNull($relation->type);
        self::assertSame(HeadingType::H1, $relation->childType);
    }
}
