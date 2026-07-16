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

namespace MindfulMarkup\MindfulA11y\Service;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Injectable access to the current backend user.
 *
 * The single home for the `$GLOBALS['BE_USER']` read, so consuming services
 * and controllers stay mockable in unit tests instead of each carrying its
 * own global accessor.
 */
final readonly class BackendUserProvider
{
    /**
     * The current backend user.
     *
     * Backend-scoped code may assume presence; in scopes without a backend
     * user (frontend, CLI) calling this is a programming error and surfaces
     * as a TypeError here instead of a null propagated into permission checks.
     */
    public function get(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }

    /**
     * The current backend user, only when it is an authenticated account.
     *
     * Returns null when no backend user exists or it carries no logged-in
     * user row. Use on surfaces reachable without a valid backend session
     * (e.g. ticketed frontend requests carrying a stale cookie).
     */
    public function getAuthenticated(): ?BackendUserAuthentication
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;

        return $backendUser instanceof BackendUserAuthentication && (int)($backendUser->user['uid'] ?? 0) > 0
            ? $backendUser
            : null;
    }
}
