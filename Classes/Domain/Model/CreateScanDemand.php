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
 * Immutable, signed authorization scope for creating an accessibility scan.
 *
 * A demand is session-bound, not a credential: it is rendered into
 * authenticated backend markup and redeemed against a session-authenticated
 * AJAX endpoint. The session authenticates; the HMAC guarantees the
 * server-derived scope — above all the previewUrl the external scanner will
 * fetch — was not altered by scripts between render and redemption; the
 * redeeming controller additionally pins the demand to the same user and
 * workspace. Contrast {@see StructureAnalysisTicket}, which must work without
 * any session.
 *
 * @phpstan-type SerializedCreateScanDemand array{
 *   userId: int,
 *   pageId: int,
 *   previewUrl: string,
 *   languageId: int,
 *   workspaceId: int,
 *   pageRecordSnapshot: string,
 *   pageLevels: int,
 *   crawl: bool,
 *   expiresAt: int,
 *   signature: string
 * }
 */
final readonly class CreateScanDemand implements SignedDemandInterface
{
    use SignedDemandTrait;

    /** Scan demands are rendered into the module and may be used after user interaction. */
    public const LIFETIME = 3600;

    /**
     * @param int $userId Current user ID.
     * @param int $pageId Original page ID (not translated).
     * @param string $previewUrl Preview URL for the page.
     * @param int $languageId Language ID.
     * @param int $workspaceId Current workspace ID.
     * @param string $pageRecordSnapshot SHA-256 fingerprint of the complete localized/default page record.
     * @param int $pageLevels Page levels for tree scanning (0 = current page only).
     * @param bool $crawl Whether this demand creates a crawl scan (only valid for site root pages).
     * @param int $expiresAt Unix timestamp after which this demand must not be redeemed.
     * @param string $signature Client-supplied HMAC signature carried for validation; empty on a freshly issued demand.
     */
    public function __construct(
        private int $userId,
        private int $pageId,
        private string $previewUrl,
        private int $languageId,
        private int $workspaceId,
        private string $pageRecordSnapshot,
        private int $pageLevels = 0,
        private bool $crawl = false,
        int $expiresAt = 0,
        string $signature = '',
    ) {
        $this->initializeSignedDemand($expiresAt, $signature);
    }

    /** @param array<string, mixed> $data */
    public static function fromRequestData(array $data): ?self
    {
        $required = self::extractRequiredRequestFields($data);
        $previewUrl = $data['previewUrl'] ?? null;
        $pageRecordSnapshot = $data['pageRecordSnapshot'] ?? null;
        $pageId = (int)($data['pageId'] ?? 0);
        $languageId = (int)($data['languageId'] ?? 0);
        $workspaceId = (int)($data['workspaceId'] ?? 0);
        $pageLevels = (int)($data['pageLevels'] ?? 0);
        if ($required === null
            || $pageId <= 0
            || $languageId < 0
            || $workspaceId < 0
            || $pageLevels < 0
            || !is_string($previewUrl)
            || $previewUrl === ''
            || !is_string($pageRecordSnapshot)
            || preg_match('/^[a-f0-9]{64}$/', $pageRecordSnapshot) !== 1
        ) {
            return null;
        }

        return new self(
            userId: $required['userId'],
            pageId: $pageId,
            previewUrl: $previewUrl,
            languageId: $languageId,
            workspaceId: $workspaceId,
            pageRecordSnapshot: $pageRecordSnapshot,
            pageLevels: $pageLevels,
            crawl: (bool)($data['crawl'] ?? false),
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

    public function getPageRecordSnapshot(): string
    {
        return $this->pageRecordSnapshot;
    }

    /**
     * Get the page levels.
     */
    public function getPageLevels(): int
    {
        return $this->pageLevels;
    }

    /**
     * Get whether this is a crawl scan.
     */
    public function getCrawl(): bool
    {
        return $this->crawl;
    }

    /** @return list<string> */
    public function signedProperties(): array
    {
        return [
            (string)$this->userId,
            (string)$this->pageId,
            $this->previewUrl,
            (string)$this->languageId,
            (string)$this->workspaceId,
            $this->pageRecordSnapshot,
            (string)$this->pageLevels,
            (string)(int)$this->crawl,
            (string)$this->expiresAt,
        ];
    }

    /** @return SerializedCreateScanDemand */
    public function toArray(): array
    {
        return [
            'userId' => $this->userId,
            'pageId' => $this->pageId,
            'previewUrl' => $this->previewUrl,
            'languageId' => $this->languageId,
            'workspaceId' => $this->workspaceId,
            'pageRecordSnapshot' => $this->pageRecordSnapshot,
            'pageLevels' => $this->pageLevels,
            'crawl' => $this->crawl,
            'expiresAt' => $this->expiresAt,
            'signature' => $this->signature,
        ];
    }

}
