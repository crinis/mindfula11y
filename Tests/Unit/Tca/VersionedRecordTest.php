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

namespace MindfulMarkup\MindfulA11y\Tests\Unit\Tca;

use MindfulMarkup\MindfulA11y\Tca\VersionedRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Versioning\VersionState;

final class VersionedRecordTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>, bool}>
     */
    public static function rows(): iterable
    {
        yield 'delete placeholder' => [['t3ver_state' => VersionState::DELETE_PLACEHOLDER->value], true];
        yield 'delete placeholder as a database string' => [['t3ver_state' => (string)VersionState::DELETE_PLACEHOLDER->value], true];
        yield 'default state' => [['t3ver_state' => 0], false];
        yield 'new placeholder' => [['t3ver_state' => VersionState::NEW_PLACEHOLDER->value], false];
        yield 'moved pointer' => [['t3ver_state' => VersionState::MOVE_POINTER->value], false];
        yield 'null state' => [['t3ver_state' => null], false];
        yield 'row without versioning fields' => [['uid' => 1], false];
        yield 'unknown state' => [['t3ver_state' => 99], false];
    }

    /**
     * @param array<string, mixed> $row
     */
    #[Test]
    #[DataProvider('rows')]
    public function isDeletePlaceholder(array $row, bool $expected): void
    {
        self::assertSame($expected, VersionedRecord::isDeletePlaceholder($row));
    }
}
