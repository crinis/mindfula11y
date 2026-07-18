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

namespace MindfulMarkup\MindfulA11y\Domain\Model;

use MindfulMarkup\MindfulA11y\Security\SignedScopePolicy;

/**
 * A signed scope: an immutable authorization scope whose integrity is
 * HMAC-protected while it travels through the client between server-side
 * issuance and server-side redemption. This is the umbrella over the
 * extension's three signed wire objects, which come in two security models:
 *
 * - Session-bound demands ({@see CreateScanDemand}, {@see GenerateAltTextDemand},
 *   both {@see SignedDemandInterface}): rendered into authenticated backend
 *   markup and redeemed against a session-authenticated AJAX endpoint. The
 *   session authenticates the user; the embedded HMAC only guarantees the
 *   server-derived scope was not altered in the browser.
 * - The bearer ticket ({@see StructureAnalysisTicket}): redeemed by a
 *   session-less frontend GET, so the detached-signature token is the only
 *   identity and authorization is re-derived from its claims on every
 *   redemption — hence its far shorter lifetime.
 *
 * Every scope expires: redemption enforces the shared bounded-expiry window
 * via {@see SignedScopePolicy::isCurrent()}. Each type also declares its
 * stable `SIGNING_CONTEXT` HMAC domain next to its payload definition —
 * change that context whenever the payload's shape or semantics change, so
 * previously issued scopes fail closed.
 */
interface SignedScopeInterface
{
    /** Unix timestamp after which this scope must not be redeemed. */
    public function getExpiresAt(): int;

    /** Maximum seconds a scope of this type stays redeemable (its LIFETIME). */
    public function maximumLifetime(): int;
}
