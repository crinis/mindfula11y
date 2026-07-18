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
 * The session-bound flavor of {@see SignedScopeInterface}: a demand whose
 * scope is HMAC-protected by {@see DemandSignatureService} — the models stay
 * pure value objects and never sign themselves. The session-less bearer
 * {@see StructureAnalysisTicket} deliberately does not implement this; its
 * detached-signature wire format lives in StructureAnalysisTicketService.
 */
interface SignedDemandInterface extends SignedScopeInterface
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

    /**
     * The stable HMAC domain of this demand type (its SIGNING_CONTEXT) —
     * deliberately decoupled from the PHP class name. Change it whenever the
     * signed payload's shape or semantics change, so previously rendered
     * demands fail closed.
     */
    public function signingContext(): string;

    /** The client-supplied signature carried for validation; '' on a freshly issued demand. */
    public function getSignature(): string;

    /**
     * The serialized wire shape. Carries the stored signature only — markup
     * rendering must go through {@see DemandSignatureService::serialize()},
     * which replaces it with the authoritative one.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
