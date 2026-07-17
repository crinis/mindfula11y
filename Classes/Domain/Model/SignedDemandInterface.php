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

use MindfulMarkup\MindfulA11y\Service\DemandSignatureService;

/**
 * A session-bound demand whose scope is HMAC-protected by
 * {@see DemandSignatureService} — the models stay pure value objects and never
 * sign themselves. The session-less {@see StructureAnalysisTicket} deliberately
 * does not implement this; its signing lives in StructureAnalysisTicketService.
 */
interface SignedDemandInterface
{
    /**
     * The ordered HMAC payload segments of this demand type.
     *
     * The order and formatting are a wire contract with already-rendered
     * demands — never reorder or reformat; append only together with a
     * signing-context change. The list must include (string)$this->expiresAt.
     *
     * @return list<string>
     */
    public function signedProperties(): array;

    /** Unix timestamp after which this demand must not be redeemed. */
    public function getExpiresAt(): int;

    /** The client-supplied signature carried for validation; '' on a freshly issued demand. */
    public function getSignature(): string;

    /** Maximum seconds a demand of this type stays redeemable (its LIFETIME). */
    public function maximumLifetime(): int;

    /**
     * The serialized wire shape. Carries the stored signature only — markup
     * rendering must go through {@see DemandSignatureService::serialize()},
     * which replaces it with the authoritative one.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
