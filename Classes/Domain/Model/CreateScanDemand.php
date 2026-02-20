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
 * Class CreateScanDemand.
 *
 * This class is used to encapsulate the demand for creating an accessibility scan.
 * It contains properties such as page UID, language UID, workspace ID, and a signature
 * for request validation.
 */
class CreateScanDemand extends AbstractValueObject implements JsonSerializable
{
    /**
     * Current user ID.
     */
    protected int $userId = 0;

    /**
     * Original page ID (not translated).
     */
    protected int $pageId = 0;

    /**
     * Preview URL for the page.
     */
    protected string $previewUrl = '';

    /**
     * Language ID.
     */
    protected int $languageId = 0;

    /**
     * Current workspace ID.
     */
    protected int $workspaceId = 0;

    /**
     * Signature of the properties generated using hmac.
     */
    protected string $signature = '';

    /**
     * Constructor.
     */
    public function __construct(int $userId, int $pageId, string $previewUrl, int $languageId, int $workspaceId, string $signature = '')
    {
        $this->userId = $userId;
        $this->pageId = $pageId;
        $this->previewUrl = $previewUrl;
        $this->languageId = $languageId;
        $this->workspaceId = $workspaceId;
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
     * Get the original page ID.
     */
    public function getPageId(): int
    {
        return $this->pageId;
    }

    /**
     * Get the preview URL.
     */
    public function getPreviewUrl(): string
    {
        return $this->previewUrl;
    }

    /**
     * Get the language ID.
     */
    public function getLanguageId(): int
    {
        return $this->languageId;
    }

    /**
     * Get the workspace ID.
     */
    public function getWorkspaceId(): int
    {
        return $this->workspaceId;
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
            implode('', [
                (string)$this->userId,
                (string)$this->pageId,
                $this->previewUrl,
                (string)$this->languageId,
                (string)$this->workspaceId
            ]),
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
     * Convert object to array.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'userId' => $this->userId,
            'pageId' => $this->pageId,
            'previewUrl' => $this->previewUrl,
            'languageId' => $this->languageId,
            'workspaceId' => $this->workspaceId,
            'signature' => $this->signature,
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