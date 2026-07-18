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

namespace MindfulMarkup\MindfulA11y\Security;

use MindfulMarkup\MindfulA11y\Domain\Model\SignedScopeInterface;

/**
 * The single definition of the bounded-expiry window shared by all signed
 * scopes ({@see SignedScopeInterface}).
 *
 * Deliberately only the formula is shared — every trust boundary that
 * evaluates a scope keeps its own call (defense in depth; do not deduplicate
 * the call sites).
 */
final class SignedScopePolicy
{
    /**
     * Whether the scope is redeemable at $now: strictly unexpired AND no
     * further in the future than the scope type's maximum lifetime allows —
     * a forged far-future expiry fails even before any signature comparison.
     */
    public static function isCurrent(SignedScopeInterface $scope, int $now): bool
    {
        return $scope->getExpiresAt() > $now
            && $scope->getExpiresAt() <= $now + $scope->maximumLifetime();
    }
}
