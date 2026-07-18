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
 *   fileReferenceUid: int,
 *   fileSnapshot: string,
 *   recordSnapshot: string,
 *   fileReferenceSnapshot: string,
 *   recordColumns: array<string>,
 *   expiresAt: int,
 *   signature: string
 * }
 */
final readonly class GenerateAltTextDemand implements SignedDemandInterface
{
    use SignedDemandTrait;

    /** Demands are rendered into FormEngine/module markup that may stay open a while before use. */
    public const LIFETIME = 3600;

    /** Stable HMAC domain — change only together with a payload shape/semantics change. */
    public const SIGNING_CONTEXT = 'mindfula11y:demand:generate-alt-text';

    /**
     * @param int $userId Current user ID.
     * @param int $pageUid Page UID we are working on.
     * @param int $languageUid Language UID we are working in.
     * @param int $workspaceId Current workspace ID.
     * @param string $recordTable Record table name.
     * @param int $recordUid Record UID.
     * @param int $fileUid File UID to generate alt text for.
     * @param int $fileReferenceUid Exact sys_file_reference UID, or 0 for sys_file_metadata.
     * @param string $fileSnapshot SHA-256 fingerprint of the complete sys_file record.
     * @param string $recordSnapshot SHA-256 fingerprint of the complete target record.
     * @param string $fileReferenceSnapshot SHA-256 fingerprint of the exact file reference, or empty for metadata.
     * @param array<string> $recordColumns Affected record columns.
     * @param int $expiresAt Unix timestamp after which this demand must not be redeemed.
     * @param string $signature Client-supplied HMAC signature carried for validation; empty on a freshly issued demand.
     */
    public function __construct(
        private int $userId,
        private int $pageUid,
        private int $languageUid,
        private int $workspaceId,
        private string $recordTable,
        private int $recordUid,
        private int $fileUid,
        private int $fileReferenceUid,
        private string $fileSnapshot,
        private string $recordSnapshot,
        private string $fileReferenceSnapshot,
        private array $recordColumns,
        int $expiresAt = 0,
        string $signature = '',
    ) {
        $this->initializeSignedDemand($expiresAt, $signature);
    }

    /** @param array<string, mixed> $data */
    public static function fromRequestData(array $data): ?self
    {
        $required = self::extractRequiredRequestFields($data);
        $recordTable = $data['recordTable'] ?? null;
        $recordColumns = $data['recordColumns'] ?? null;
        $recordSnapshot = $data['recordSnapshot'] ?? null;
        $fileSnapshot = $data['fileSnapshot'] ?? null;
        $fileReferenceSnapshot = $data['fileReferenceSnapshot'] ?? null;
        $pageUid = (int)($data['pageUid'] ?? 0);
        $languageUid = (int)($data['languageUid'] ?? 0);
        $workspaceId = (int)($data['workspaceId'] ?? 0);
        $recordUid = (int)($data['recordUid'] ?? 0);
        $fileUid = (int)($data['fileUid'] ?? 0);
        $fileReferenceUid = (int)($data['fileReferenceUid'] ?? 0);
        if ($required === null
            || $pageUid < 0
            || $languageUid < -1
            || $workspaceId < 0
            || $recordUid <= 0
            || $fileUid <= 0
            || $fileReferenceUid < 0
            || !is_string($recordTable)
            || $recordTable === ''
            || !is_array($recordColumns)
            || !array_is_list($recordColumns)
            || $recordColumns === []
            || array_filter($recordColumns, static fn(mixed $column): bool => !is_string($column) || $column === '') !== []
            || !is_string($recordSnapshot)
            || preg_match('/^[a-f0-9]{64}$/', $recordSnapshot) !== 1
            || !is_string($fileSnapshot)
            || preg_match('/^[a-f0-9]{64}$/', $fileSnapshot) !== 1
            || !is_string($fileReferenceSnapshot)
            || ($fileReferenceSnapshot !== '' && preg_match('/^[a-f0-9]{64}$/', $fileReferenceSnapshot) !== 1)
        ) {
            return null;
        }

        return new self(
            userId: $required['userId'],
            pageUid: $pageUid,
            languageUid: $languageUid,
            workspaceId: $workspaceId,
            recordTable: $recordTable,
            recordUid: $recordUid,
            fileUid: $fileUid,
            fileReferenceUid: $fileReferenceUid,
            fileSnapshot: $fileSnapshot,
            recordSnapshot: $recordSnapshot,
            fileReferenceSnapshot: $fileReferenceSnapshot,
            recordColumns: $recordColumns,
            expiresAt: $required['expiresAt'],
            signature: $required['signature'],
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

    /** Exact sys_file_reference UID, or 0 when the target is sys_file_metadata. */
    public function getFileReferenceUid(): int
    {
        return $this->fileReferenceUid;
    }

    public function getRecordSnapshot(): string
    {
        return $this->recordSnapshot;
    }

    public function getFileSnapshot(): string
    {
        return $this->fileSnapshot;
    }

    public function getFileReferenceSnapshot(): string
    {
        return $this->fileReferenceSnapshot;
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

    /** @return list<string> */
    public function signedProperties(): array
    {
        return [
            (string)$this->userId,
            (string)$this->pageUid,
            (string)$this->languageUid,
            (string)$this->workspaceId,
            $this->recordTable,
            (string)$this->recordUid,
            (string)$this->fileUid,
            (string)$this->fileReferenceUid,
            $this->fileSnapshot,
            $this->recordSnapshot,
            $this->fileReferenceSnapshot,
            json_encode($this->recordColumns, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            (string)$this->expiresAt,
        ];
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
            'fileReferenceUid' => $this->fileReferenceUid,
            'fileSnapshot' => $this->fileSnapshot,
            'recordSnapshot' => $this->recordSnapshot,
            'fileReferenceSnapshot' => $this->fileReferenceSnapshot,
            'recordColumns' => $this->recordColumns,
            'expiresAt' => $this->expiresAt,
            'signature' => $this->signature,
        ];
    }

}
