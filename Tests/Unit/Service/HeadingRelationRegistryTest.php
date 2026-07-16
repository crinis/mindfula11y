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

use MindfulMarkup\MindfulA11y\Domain\Model\HeadingRelation;
use MindfulMarkup\MindfulA11y\Enum\HeadingType;
use MindfulMarkup\MindfulA11y\Service\HeadingRelationRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HeadingRelationRegistryTest extends TestCase
{
    #[Test]
    public function resolveReturnsRegisteredRelation(): void
    {
        $registry = new HeadingRelationRegistry();
        $relation = new HeadingRelation(HeadingType::H2, HeadingType::H4);

        $registry->register('container-1', $relation);

        self::assertSame($relation, $registry->resolve('container-1'));
    }

    #[Test]
    public function resolveReturnsNullForUnknownRelationId(): void
    {
        $registry = new HeadingRelationRegistry();

        self::assertNull($registry->resolve('never-registered'));
    }

    #[Test]
    public function registerOverwritesExistingRelationId(): void
    {
        $registry = new HeadingRelationRegistry();
        $first = new HeadingRelation(HeadingType::H2);
        $second = new HeadingRelation(HeadingType::H3);

        $registry->register('container-1', $first);
        $registry->register('container-1', $second);

        self::assertSame($second, $registry->resolve('container-1'));
    }
}
