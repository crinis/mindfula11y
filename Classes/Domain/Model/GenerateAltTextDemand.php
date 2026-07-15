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

namespace MindfulMarkup\MindfulA11y\Domain\Model;

use JsonSerializable;
use TYPO3\CMS\Core\Crypto\HashService;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Immutable, signed authorization scope for AI alternative-text generation.
 *
 * Like {@see CreateScanDemand}, this is a session-bound demand, not a
 * credential: it is rendered into authenticated backend markup and redeemed
 * against a session-authenticated AJAX endpoint. The session authenticates;
 * the HMAC guarantees the server-derived scope (record, file, columns —
 * ultimately what the paid generation runs against) was not altered by
 * scripts between render and redemption; the redeeming controller
 * additionally pins the demand to the same user and workspace.
 *
 * @phpstan-type SerializedGenerateAltTextDemand array{
 *   userId: int,
 *   pageUid: int,
 *   languageUid: int,
 *   workspaceId: int,
 *   recordTable: string,
 *   recordUid: int,
 *   fileUid: int,
 *   recordColumns: array<string>,
 *   expiresAt: int,
 *   signature: string
 * }
 */
final readonly class GenerateAltTextDemand implements JsonSerializable
{
    /** Demands are rendered into FormEngine/module markup that may stay open a while before use. */
    public const LIFETIME = 3600;

    private int $expiresAt;
    private string $signature;

    /**
     * @param int $userId Current user ID.
     * @param int $pageUid Page UID we are working on.
     * @param int $languageUid Language UID we are working in.
     * @param int $workspaceId Current workspace ID.
     * @param string $recordTable Record table name.
     * @param int $recordUid Record UID.
     * @param int $fileUid File UID to generate alt text for.
     * @param array<string> $recordColumns Affected record columns.
     * @param int $expiresAt Unix timestamp after which this demand must not be redeemed.
     * @param string $signature Optional pre-computed HMAC signature; generated from the other properties when empty.
     */
    public function __construct(
        private int $userId,
        private int $pageUid,
        private int $languageUid,
        private int $workspaceId,
        private string $recordTable,
        private int $recordUid,
        private int $fileUid,
        private array $recordColumns,
        int $expiresAt = 0,
        string $signature = '',
    ) {
        $this->expiresAt = $expiresAt > 0 ? $expiresAt : time() + self::LIFETIME;
        $this->signature = $signature !== '' ? $signature : $this->createSignature();
    }

    /** @param array<string, mixed> $data */
    public static function fromRequestData(array $data): ?self
    {
        $recordTable = $data['recordTable'] ?? null;
        $recordColumns = $data['recordColumns'] ?? null;
        $signature = $data['signature'] ?? null;
        $userId = (int)($data['userId'] ?? 0);
        $expiresAt = (int)($data['expiresAt'] ?? 0);
        if ($userId <= 0
            || $expiresAt <= 0
            || !is_string($recordTable)
            || !is_array($recordColumns)
            || !is_string($signature)
            || $signature === ''
        ) {
            return null;
        }

        return new self(
            userId: $userId,
            pageUid: (int)($data['pageUid'] ?? 0),
            languageUid: (int)($data['languageUid'] ?? 0),
            workspaceId: (int)($data['workspaceId'] ?? 0),
            recordTable: $recordTable,
            recordUid: (int)($data['recordUid'] ?? 0),
            fileUid: (int)($data['fileUid'] ?? 0),
            recordColumns: $recordColumns,
            expiresAt: $expiresAt,
            signature: $signature,
        );
    }

    /**
     * Get the user ID.
     */
    public function getUserId(): int
    {
        return $this->userId;
    }

    /**
     * Get the page UID.
     */
    public function getPageUid(): int
    {
        return $this->pageUid;
    }

    /**
     * Get the language UID.
     */
    public function getLanguageUid(): int
    {
        return $this->languageUid;
    }

    /**
     * Get the workspace ID.
     */
    public function getWorkspaceId(): int
    {
        return $this->workspaceId;
    }

    /**
     * Get the record table.
     */
    public function getRecordTable(): string
    {
        return $this->recordTable;
    }

    /**
     * Get the record UID.
     */
    public function getRecordUid(): int
    {
        return $this->recordUid;
    }

    /**
     * Get the file UID to generate alt text for.
     */
    public function getFileUid(): int
    {
        return $this->fileUid;
    }

    /**
     * Get the affected record columns.
     *
     * @return array<string>
     */
    public function getRecordColumns(): array
    {
        return $this->recordColumns;
    }

    /**
     * Get the signature of the properties of this object for request validation.
     */
    public function getSignature(): string
    {
        return $this->signature;
    }

    private function createSignature(): string
    {
        $hashService = GeneralUtility::makeInstance(HashService::class);
        return $hashService->hmac(
            implode('|', [
                (string)$this->userId,
                (string)$this->pageUid,
                (string)$this->languageUid,
                (string)$this->workspaceId,
                $this->recordTable,
                (string)$this->recordUid,
                (string)$this->fileUid,
                implode(',', $this->recordColumns),
                (string)$this->expiresAt,
            ]),
            self::class
        );
    }

    public function validateSignature(): bool
    {
        $expectedHash = $this->createSignature();
        $now = time();
        return $this->expiresAt > $now
            && $this->expiresAt <= $now + self::LIFETIME
            && hash_equals($expectedHash, $this->signature);
    }

    /** @return SerializedGenerateAltTextDemand */
    public function toArray(): array
    {
        return [
            'userId' => $this->userId,
            'pageUid' => $this->pageUid,
            'languageUid' => $this->languageUid,
            'workspaceId' => $this->workspaceId,
            'recordTable' => $this->recordTable,
            'recordUid' => $this->recordUid,
            'fileUid' => $this->fileUid,
            'recordColumns' => $this->recordColumns,
            'expiresAt' => $this->expiresAt,
            'signature' => $this->signature,
        ];
    }

    /** @return SerializedGenerateAltTextDemand */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
