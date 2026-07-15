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

namespace MindfulMarkup\MindfulA11y\Exception;

/**
 * A scan could not be created.
 *
 * Carries exactly what the AJAX layer needs to render the uniform localized
 * error body: the error label key, the HTTP status, and — when an upstream
 * API supplied an actionable explanation — a description override.
 */
final class ScanCreationException extends \Exception
{
    public function __construct(
        public readonly string $labelKey,
        public readonly int $statusCode,
        public readonly ?string $description = null,
    ) {
        parent::__construct($labelKey, $statusCode);
    }
}
