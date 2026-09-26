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

namespace MindfulMarkup\MindfulA11y\Domain\Model;

/**
 * Configuration used to select file references without alt text from a
 * specific table. A plain value object handed from AltTextFinderService to
 * AltlessFileReferenceRepository.
 */
final readonly class AltlessFileReferenceTable
{
    /**
     * @param string $tableName The name of the table to filter.
     * @param array<string> $fileColumnNames The names of the file columns.
     * @param array<string, array<string>> $authModeColumns The authMode columns and their allowed values.
     * @param array<int> $pageIds The page IDs to filter by.
     */
    public function __construct(
        public string $tableName,
        public array $fileColumnNames,
        public array $authModeColumns,
        public array $pageIds,
    ) {}
}
