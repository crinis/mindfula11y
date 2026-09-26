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

use TYPO3\CMS\Core\Versioning\VersionState;

/**
 * Typed reads of a record row's workspace versioning fields.
 *
 * Pins one null-handling for the delete-placeholder predicate that call sites
 * used to spell out with their own variants: a row without t3ver_state (a
 * table without workspace support, or a partial row) is not a placeholder.
 */
final class VersionedRecord
{
    /** @param array<string, mixed> $row */
    public static function isDeletePlaceholder(array $row): bool
    {
        return VersionState::tryFrom((int)($row['t3ver_state'] ?? 0)) === VersionState::DELETE_PLACEHOLDER;
    }
}
