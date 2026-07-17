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

use MindfulMarkup\MindfulA11y\Domain\Model\SignedDemandInterface;
use TYPO3\CMS\Core\Crypto\HashService;

/**
 * The single HMAC authority for the session-bound signed demands: issuance
 * signs at serialization time ({@see serialize()}), redemption re-derives the
 * signature from the demand's scope and compares ({@see isValid()}).
 * Centralized so a security-policy change cannot silently apply to one demand
 * type but not the other. The signature bytes are a wire contract with
 * already-rendered demands (payload order and the FQCN HMAC domain) — see
 * SignedDemandInterface::signedProperties().
 */
final readonly class DemandSignatureService
{
    public function __construct(
        private HashService $hashService,
    ) {}

    /**
     * The authoritative HMAC over the demand's scope. The concrete demand
     * class keeps each type's HMAC domain-separated: a signature for one
     * demand type never validates for another.
     */
    public function sign(SignedDemandInterface $demand): string
    {
        // JSON preserves property boundaries and array structure. A delimiter-
        // joined payload would make distinct scopes such as ["a|b", "c"] and
        // ["a", "b|c"] indistinguishable to the HMAC.
        $payload = json_encode($demand->signedProperties(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return $this->hashService->hmac($payload, $demand::class);
    }

    /**
     * A demand is valid only while unexpired, within its type's maximum
     * lifetime window (a forged far-future expiry fails even before the
     * signature comparison), and carrying an intact HMAC over its scope.
     */
    public function isValid(SignedDemandInterface $demand): bool
    {
        $now = time();
        return $demand->getExpiresAt() > $now
            && $demand->getExpiresAt() <= $now + $demand->maximumLifetime()
            && hash_equals($this->sign($demand), $demand->getSignature());
    }

    /**
     * The demand's wire shape with the authoritative signature — the only
     * sanctioned way to render a demand into markup or JS init data. A demand
     * serialized any other way carries no valid signature and fails closed at
     * redemption.
     *
     * @return array<string, mixed>
     */
    public function serialize(SignedDemandInterface $demand): array
    {
        return array_replace($demand->toArray(), ['signature' => $this->sign($demand)]);
    }
}
