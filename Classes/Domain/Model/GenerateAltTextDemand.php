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
use TYPO3\CMS\Extbase\DomainObject\AbstractValueObject;

/**
 * Class GenerateAltTextDemand.
 *
 * This class is used to encapsulate the demand for alternative text generation.
 * It contains properties such as user ID, page UID, language UID, workspace ID, and a signature
 * for request validation.
 */
class GenerateAltTextDemand extends AbstractValueObject implements JsonSerializable
{
    /**
     * Current user ID.
     */
    protected int $userId = 0;

    /**
     * Page UID we are working on.
     */
    protected int $pageUid = 0;

    /**
     * Language UID we are working in.
     */
    protected int $languageUid = 0;

    /**
     * Current workspace ID.
     */
    protected int $workspaceId = 0;

    /**
     * Record table name.
     */
    protected string $recordTable = '';

    /**
     * Record UID.
     */
    protected int $recordUid = 0;

    /**
     * Affected record columns.
     */
    protected array $recordColumns = [];

    /**
     * Signature of all properties generated using hmac.
     */
    protected string $signature = '';

    /**
     * Constructor.
     */
    public function __construct(int $userId, int $pageUid, int $languageUid, int $workspaceId, string $recordTable, int $recordUid, array $recordColumns, string $signature = '')
    {
        $this->userId = $userId;
        $this->pageUid = $pageUid;
        $this->languageUid = $languageUid;
        $this->workspaceId = $workspaceId;
        $this->recordTable = $recordTable;
        $this->recordUid = $recordUid;
        $this->recordColumns = $recordColumns;
        $this->signature = '' !== $signature ? $signature : $this->createSignature();
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
     * Get the affected record columns.
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

    /**
     * Create signature for this object.
     * 
     * @return string
     */
    protected function createSignature(): string
    {
        $hashService = GeneralUtility::makeInstance(HashService::class);
        return $hashService->hmac(
            implode(
                '',
                [
                    (int)$this->userId,
                    (int)$this->pageUid,
                    (int)$this->languageUid,
                    (int)$this->workspaceId,
                    $this->recordTable,
                    (int)$this->recordUid,
                    implode(',', $this->recordColumns),
                ]
            ),
            __CLASS__
        );
    }

    /**
     * Test if the signature is valid.
     * 
     * Compare the signature of this object with the one generated from the properties. Used
     * for request validation and to ensure that the request is not tampered with.
     * 
     * @return bool
     */
    public function validateSignature(): bool
    {
        $expectedHash = $this->createSignature();
        return hash_equals($expectedHash, $this->signature);
    }

    /**
     * Return as array.
     */
    public function toArray(): array
    {
        return [
            'userId' => $this->getUserId(),
            'pageUid' => $this->getPageUid(),
            'languageUid' => $this->getLanguageUid(),
            'workspaceId' => $this->getWorkspaceId(),
            'recordTable' => $this->getRecordTable(),
            'recordUid' => $this->getRecordUid(),
            'recordColumns' => $this->getRecordColumns(),
            'signature' => $this->getSignature(),
        ];
    }

    /**
     * Return as JSON.
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
