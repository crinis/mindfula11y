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

namespace MindfulMarkup\MindfulA11y\Tca;

/**
 * Typed access to a table's TCA localization ctrl fields.
 *
 * TYPO3 core offers no API for these reads, so every call site used to spell
 * out the $GLOBALS['TCA'] path with its own fallback ('' vs. a hardcoded
 * column name vs. none at all). These helpers pin one behavior: an empty
 * field name — and consequently 0 from the value readers — for tables
 * without localization support.
 */
final class TranslationFields
{
    public static function languageFieldName(string $table): string
    {
        return (string)($GLOBALS['TCA'][$table]['ctrl']['languageField'] ?? '');
    }

    public static function translationParentFieldName(string $table): string
    {
        return (string)($GLOBALS['TCA'][$table]['ctrl']['transOrigPointerField'] ?? '');
    }

    /** @param array<string, mixed> $record */
    public static function languageId(string $table, array $record): int
    {
        $languageField = self::languageFieldName($table);

        return $languageField === '' ? 0 : (int)($record[$languageField] ?? 0);
    }

    /** @param array<string, mixed> $record */
    public static function translationParentUid(string $table, array $record): int
    {
        $translationParentField = self::translationParentFieldName($table);

        return $translationParentField === '' ? 0 : (int)($record[$translationParentField] ?? 0);
    }
}
