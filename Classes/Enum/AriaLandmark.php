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
 * Enumeration for ARIA landmarks.
 * 
 * Maps landmark types to string values based on ARIA role names.
 * Ordered by expected usage frequency.
 */
enum AriaLandmark: string
{
    case NONE = '';
    case REGION = 'region';
    case NAVIGATION = 'navigation';
    case COMPLEMENTARY = 'complementary';
    case MAIN = 'main';
    case BANNER = 'banner';
    case CONTENTINFO = 'contentinfo';
    case SEARCH = 'search';
    case FORM = 'form';

    /**
     * Get the label key for this landmark type
     */
    public function getLabelKey(): string
    {
        return 'LLL:EXT:mindfula11y/Resources/Private/Language/Database.xlf:ttContent.columns.mindfula11y.landmark.items.' . ($this->value ?: 'none');
    }

    /**
     * The preferred native HTML element for this landmark role. Always prefers native
     * HTML elements over the generic "div + role" fallback.
     *
     * Keep in sync with the IMPLICIT_ROLES map in
     * Resources/Private/Source/lib/structure/landmark-analysis.ts (that map is the inverse:
     * element -> role, this is role -> element).
     *
     * @return string The native HTML element name, or 'div' when no landmark-specific
     *                 element applies (NONE, or any value without a mapped case).
     */
    public function element(): string
    {
        return match ($this) {
            self::NAVIGATION => 'nav',
            self::MAIN => 'main',
            self::BANNER => 'header',
            self::CONTENTINFO => 'footer',
            self::COMPLEMENTARY => 'aside',
            self::SEARCH => 'search',
            self::FORM => 'form',
            self::REGION => 'section',
            default => 'div',
        };
    }
}
