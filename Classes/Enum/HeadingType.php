<?php
declare(strict_types=1);

/*
 * Mindful A11y extension for TYPO3 integrating accessibility tools into the backend.
 * Copyright (C) 2025  Mindful Markup, Felix Spittel
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 */

namespace MindfulMarkup\MindfulA11y\Enum;

/**
 * Enum for available heading types in content elements
 */
enum HeadingType: string
{
    case H1 = 'h1';
    case H2 = 'h2';
    case H3 = 'h3';
    case H4 = 'h4';
    case H5 = 'h5';
    case H6 = 'h6';
    case P = 'p';
    case DIV = 'div';

    /**
     * Get all heading types as an array of values
     *
     * @return array<string>
     */
    public static function getValues(): array
    {
        return array_map(fn(self $case) => $case->value, self::cases());
    }

    /**
     * Check if a value is a valid heading type
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, self::getValues(), true);
    }

    /**
     * Get the label key for this heading type
     */
    public function getLabelKey(): string
    {
        return 'LLL:EXT:mindfula11y/Resources/Private/Language/Database.xlf:ttContent.columns.mindfula11y.headingType.items.' . $this->value;
    }

    /**
     * Check if this is an actual heading element (h1-h6)
     */
    public function isHeading(): bool
    {
        return in_array($this, [self::H1, self::H2, self::H3, self::H4, self::H5, self::H6], true);
    }

    /**
     * Get the numeric level for heading elements (1-6), null for non-heading elements
     */
    public function getNumericLevel(): ?int
    {
        return match ($this) {
            self::H1 => 1,
            self::H2 => 2,
            self::H3 => 3,
            self::H4 => 4,
            self::H5 => 5,
            self::H6 => 6,
            default => null,
        };
    }

    /**
     * Create HeadingType from numeric level (for migration purposes)
     */
    public static function fromNumericLevel(int $level): ?self
    {
        return match ($level) {
            1 => self::H1,
            2 => self::H2,
            3 => self::H3,
            4 => self::H4,
            5 => self::H5,
            6 => self::H6,
            -1 => self::P, // Legacy "no heading" value
            default => null,
        };
    }
}
