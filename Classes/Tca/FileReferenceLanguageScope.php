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

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

/**
 * Which file references a listing of one language covers.
 *
 * The single rule shared by the Missing Alternative Texts query
 * (AltlessFileReferenceRepository) and the redemption of the generate demands
 * that list issues (AltTextDemandAuthorizationService) — one predicate in SQL
 * and in PHP side by side, so the list can never offer a Generate button the
 * endpoint then refuses, nor the endpoint accept a reference the list would
 * not show for the signed language.
 *
 * A reference of the listed language always counts, whatever its parent
 * stores. One stored for "All languages" (-1) — what FormEngine creates inside
 * "All languages" content — renders where its parent does, because the
 * frontend reaches inline references through the parent record: under an
 * all-languages parent or one of the listed language, but not under a parent
 * of another language (a translation renders its own parent's references). A
 * parent table without a language field has no such distinction, so its -1
 * references count for every language. The parent's language is
 * workspace-immutable, so judging the live row is exact.
 *
 * Only the language is decided here; access to both rows and to the signed
 * language is checked separately.
 */
final class FileReferenceLanguageScope
{
    private const ALL_LANGUAGES = -1;

    /**
     * @param array<string, mixed> $parentRecord The (workspace-overlaid) parent row.
     */
    public static function covers(int $languageId, int $referenceLanguageId, string $parentTable, array $parentRecord): bool
    {
        if ($referenceLanguageId === $languageId) {
            return true;
        }
        if ($referenceLanguageId !== self::ALL_LANGUAGES) {
            return false;
        }
        if (TranslationFields::languageFieldName($parentTable) === '') {
            return true;
        }
        $parentLanguageId = TranslationFields::languageId($parentTable, $parentRecord);

        return $parentLanguageId === $languageId || $parentLanguageId === self::ALL_LANGUAGES;
    }

    /**
     * covers() as a WHERE clause over `sys_file_reference` joined to the
     * parent table under its own name as alias.
     */
    public static function createClause(QueryBuilder $queryBuilder, string $parentTable, int $languageId): string
    {
        $referenceLanguageField = 'sys_file_reference.' . TranslationFields::languageFieldName('sys_file_reference');
        $parentLanguageField = TranslationFields::languageFieldName($parentTable);
        if ($parentLanguageField === '') {
            return (string)$queryBuilder->expr()->in(
                $referenceLanguageField,
                $queryBuilder->createNamedParameter([$languageId, self::ALL_LANGUAGES], Connection::PARAM_INT_ARRAY)
            );
        }

        return (string)$queryBuilder->expr()->or(
            $queryBuilder->expr()->eq($referenceLanguageField, $queryBuilder->createNamedParameter($languageId, Connection::PARAM_INT)),
            $queryBuilder->expr()->and(
                $queryBuilder->expr()->eq($referenceLanguageField, $queryBuilder->createNamedParameter(self::ALL_LANGUAGES, Connection::PARAM_INT)),
                $queryBuilder->expr()->in(
                    $parentTable . '.' . $parentLanguageField,
                    $queryBuilder->createNamedParameter([$languageId, self::ALL_LANGUAGES], Connection::PARAM_INT_ARRAY)
                ),
            ),
        );
    }
}
