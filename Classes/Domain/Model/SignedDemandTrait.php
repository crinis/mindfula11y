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
 * Value-object plumbing shared by the session-bound signed demands
 * ({@see CreateScanDemand}, {@see GenerateAltTextDemand}): verbatim expiry
 * and signature storage, and the strict request-field gate. Signing and
 * validation live in {@see DemandSignatureService}, fresh expiries are the
 * issuing factories' concern — the demands themselves stay deterministic
 * value objects.
 *
 * The session-less {@see StructureAnalysisTicket} deliberately does NOT share
 * this lifecycle — its signing lives in StructureAnalysisTicketService.
 *
 * Using classes must declare `public const LIFETIME` (seconds a fresh demand
 * stays redeemable), `public const SIGNING_CONTEXT` (the stable HMAC domain)
 * and implement {@see SignedDemandInterface::signedProperties()}.
 */
trait SignedDemandTrait
{
    private readonly int $expiresAt;
    private readonly string $signature;

    /**
     * Call from the constructor: stores expiry and (client-supplied)
     * signature verbatim. An unset expiry stays 0 and fails closed at
     * validation — the issuing factory passes time() + LIFETIME. Freshly
     * issued demands carry no signature — the authoritative one is added by
     * DemandSignatureService::serialize() at rendering time.
     */
    private function initializeSignedDemand(int $expiresAt, string $signature): void
    {
        $this->expiresAt = $expiresAt;
        $this->signature = $signature;
    }

    /**
     * The strict shared gate of every fromRequestData(): the fields all signed
     * demands require before any signature math runs.
     *
     * @param array<string, mixed> $data
     * @return array{userId: int, expiresAt: int, signature: string}|null
     */
    private static function extractRequiredRequestFields(array $data): ?array
    {
        $signature = $data['signature'] ?? null;
        $userId = (int)($data['userId'] ?? 0);
        $expiresAt = (int)($data['expiresAt'] ?? 0);
        if ($userId <= 0
            || $expiresAt <= 0
            || !is_string($signature)
            || $signature === ''
        ) {
            return null;
        }

        return ['userId' => $userId, 'expiresAt' => $expiresAt, 'signature' => $signature];
    }

    public function getExpiresAt(): int
    {
        return $this->expiresAt;
    }

    public function getSignature(): string
    {
        return $this->signature;
    }

    public function maximumLifetime(): int
    {
        return self::LIFETIME;
    }

    public function signingContext(): string
    {
        return self::SIGNING_CONTEXT;
    }
}
