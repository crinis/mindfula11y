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
 */

namespace MindfulMarkup\MindfulA11y\Domain\Model;

use JsonSerializable;
use MindfulMarkup\MindfulA11y\Security\SignedScopePolicy;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Immutable authorization and response scope carried by a signed structure-analysis ticket.
 *
 * Unlike the session-bound demands ({@see CreateScanDemand},
 * {@see GenerateAltTextDemand}), a ticket is the bearer flavor of
 * {@see SignedScopeInterface}: it is redeemed by an iframe GET on the
 * frontend origin that carries no backend cookie, so the signed claims are
 * the only identity and the holder's authorization is re-derived from them on
 * every redemption — hence the very short lifetime, the version pin, and the
 * strict claims validation. Signing and validation live in
 * StructureAnalysisTicketService.
 */
final readonly class StructureAnalysisTicket implements JsonSerializable, SignedScopeInterface
{
    /** Request attribute carrying the validated ticket on a frontend analysis request. */
    public const REQUEST_ATTRIBUTE = 'mindfula11y.structure-analysis';

    public const VERSION = 3;

    /** Short replay window for this stateless, narrowly scoped capability. */
    public const LIFETIME = 15;

    /**
     * Stable HMAC domain — decoupled from PHP class names. The claim-schema
     * version is part of the context, so a schema bump automatically fails
     * previously issued tickets closed.
     */
    public const SIGNING_CONTEXT = 'mindfula11y:ticket:structure-analysis:v' . self::VERSION;

    public function __construct(
        public string $requestId,
        public int $pageId,
        public int $languageId,
        public int $workspaceId,
        public string $pageRecordSnapshot,
        public int $backendUserId,
        public string $backendOrigin,
        public string $frontendOrigin,
        public string $target,
        public int $expiresAt,
    ) {}

    /** The ticket the authentication middleware validated and attached, if any. */
    public static function fromRequest(ServerRequestInterface $request): ?self
    {
        $ticket = $request->getAttribute(self::REQUEST_ATTRIBUTE);
        return $ticket instanceof self ? $ticket : null;
    }

    /**
     * Defense in depth: each trust boundary re-validates scope independently; do not deduplicate.
     *
     * @param array<string, mixed> $claims
     */
    public static function fromClaims(array $claims, int $now): ?self
    {
        if (($claims['version'] ?? null) !== self::VERSION
            || !is_string($claims['requestId'] ?? null)
            || !preg_match('/^[a-f0-9]{32}$/', $claims['requestId'])
            || !is_int($claims['pageId'] ?? null)
            || $claims['pageId'] <= 0
            || !is_int($claims['languageId'] ?? null)
            || $claims['languageId'] < 0
            || !is_int($claims['workspaceId'] ?? null)
            || $claims['workspaceId'] < 0
            || !is_string($claims['pageRecordSnapshot'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/', $claims['pageRecordSnapshot']) !== 1
            || !is_int($claims['backendUserId'] ?? null)
            || $claims['backendUserId'] <= 0
            || !is_string($claims['backendOrigin'] ?? null)
            || !is_string($claims['frontendOrigin'] ?? null)
            || !is_string($claims['target'] ?? null)
            || !is_int($claims['expiresAt'] ?? null)
        ) {
            return null;
        }

        $ticket = new self(
            requestId: $claims['requestId'],
            pageId: $claims['pageId'],
            languageId: $claims['languageId'],
            workspaceId: $claims['workspaceId'],
            pageRecordSnapshot: $claims['pageRecordSnapshot'],
            backendUserId: $claims['backendUserId'],
            backendOrigin: $claims['backendOrigin'],
            frontendOrigin: $claims['frontendOrigin'],
            target: $claims['target'],
            expiresAt: $claims['expiresAt'],
        );

        return SignedScopePolicy::isCurrent($ticket, $now) ? $ticket : null;
    }

    public function isExpired(int $now): bool
    {
        return $this->expiresAt <= $now;
    }

    public function getExpiresAt(): int
    {
        return $this->expiresAt;
    }

    public function maximumLifetime(): int
    {
        return self::LIFETIME;
    }

    /**
     * @return array{
     *   version: int,
     *   requestId: string,
     *   pageId: int,
     *   languageId: int,
     *   workspaceId: int,
     *   pageRecordSnapshot: string,
     *   backendUserId: int,
     *   backendOrigin: string,
     *   frontendOrigin: string,
     *   target: string,
     *   expiresAt: int
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'version' => self::VERSION,
            'requestId' => $this->requestId,
            'pageId' => $this->pageId,
            'languageId' => $this->languageId,
            'workspaceId' => $this->workspaceId,
            'pageRecordSnapshot' => $this->pageRecordSnapshot,
            'backendUserId' => $this->backendUserId,
            'backendOrigin' => $this->backendOrigin,
            'frontendOrigin' => $this->frontendOrigin,
            'target' => $this->target,
            'expiresAt' => $this->expiresAt,
        ];
    }
}
