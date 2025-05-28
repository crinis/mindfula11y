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
 * Class AltTextDemand.
 *
 * This class is used to encapsulate the demand for alternative text generation.
 * It contains properties such as page UID, language UID, file UID, and a signature
 * for request validation.
 */
class AltTextDemand extends AbstractValueObject implements JsonSerializable
{
    /**
     * Page UID we are working on.
     */
    protected int $pageUid = 0;

    /**
     * Language UID we are working in.
     */
    protected int $languageUid = 0;

    /**
     * sys_file UID of the image.
     */
    protected int $fileUid = 0;

    /**
     * Signature of all properties generated using hmac.
     */
    protected string $signature = '';

    /**
     * Constructor.
     */
    public function __construct(int $pageUid, int $languageUid, int $fileUid, string $signature = '')
    {
        $this->pageUid = $pageUid;
        $this->languageUid = $languageUid;
        $this->fileUid = $fileUid;
        $this->signature = '' !== $signature ? $signature : $this->createSignature();
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
     * Get the file UID.
     */
    public function getFileUid(): int
    {
        return $this->fileUid;
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
                    (int)$this->pageUid,
                    (int)$this->fileUid,
                    (int)$this->languageUid,
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
            'pageUid' => $this->getPageUid(),
            'languageUid' => $this->getLanguageUid(),
            'fileUid' => $this->getFileUid(),
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
