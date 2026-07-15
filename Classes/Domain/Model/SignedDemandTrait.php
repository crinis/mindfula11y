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

use TYPO3\CMS\Core\Crypto\HashService;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Lifecycle shared by the session-bound signed demands
 * ({@see CreateScanDemand}, {@see GenerateAltTextDemand}): expiry
 * initialization, HMAC generation over the demand's scope, and the combined
 * expiry-window + signature validation. Centralized so a security-policy
 * change cannot silently apply to one demand type but not the other.
 *
 * The session-less {@see StructureAnalysisTicket} deliberately does NOT share
 * this lifecycle — its signing lives in StructureAnalysisTicketService.
 *
 * Using classes must declare `public const LIFETIME` (seconds a fresh demand
 * stays redeemable) and implement {@see signedProperties()}.
 */
trait SignedDemandTrait
{
    private readonly int $expiresAt;
    private readonly string $signature;

    /**
     * The ordered HMAC payload segments of this demand type.
     *
     * The order and formatting are a wire contract with already-rendered
     * demands — never reorder or reformat; append only together with a
     * signing-context change. The list must include (string)$this->expiresAt.
     *
     * @return list<string>
     */
    abstract private function signedProperties(): array;

    /**
     * Call from the constructor: resolves a fresh expiry when none is given
     * and signs the demand unless a (client-supplied) signature is replayed
     * for later validation.
     */
    private function initializeSignedDemand(int $expiresAt, string $signature): void
    {
        $this->expiresAt = $expiresAt > 0 ? $expiresAt : time() + self::LIFETIME;
        $this->signature = $signature !== '' ? $signature : $this->createSignature();
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

    private function createSignature(): string
    {
        $hashService = GeneralUtility::makeInstance(HashService::class);
        // static::class keeps each demand type's HMAC domain-separated: a
        // signature for one demand type never validates for another.
        return $hashService->hmac(implode('|', $this->signedProperties()), static::class);
    }

    /**
     * A demand is valid only while unexpired, within its type's maximum
     * lifetime window (a forged far-future expiry fails even before the
     * signature comparison), and carrying an intact HMAC over its scope.
     */
    public function validateSignature(): bool
    {
        $expectedHash = $this->createSignature();
        $now = time();
        return $this->expiresAt > $now
            && $this->expiresAt <= $now + static::LIFETIME
            && hash_equals($expectedHash, $this->signature);
    }
}
