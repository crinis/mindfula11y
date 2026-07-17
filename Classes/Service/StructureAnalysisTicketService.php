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

namespace MindfulMarkup\MindfulA11y\Service;

use MindfulMarkup\MindfulA11y\Domain\Model\StructureAnalysisTicket;
use TYPO3\CMS\Core\Crypto\HashService;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Utility\StringUtility;

/** Creates and validates short-lived, stateless frontend analysis capabilities. */
final readonly class StructureAnalysisTicketService
{
    /** Short replay window for this stateless, narrowly scoped capability. */
    public const LIFETIME = 15;

    /** Query parameter carrying the signed ticket on the frontend preview URL. */
    public const TICKET_QUERY_PARAMETER = 'mindfula11y_structure_ticket';

    private const SIGNING_CONTEXT = self::class . ':v' . StructureAnalysisTicket::VERSION;

    public function __construct(
        private HashService $hashService,
    ) {}

    /**
     * @return array{token: string, requestId: string}
     */
    private function issue(
        string $targetUrl,
        int $pageId,
        int $languageId,
        int $workspaceId,
        string $pageRecordSnapshot,
        int $backendUserId,
        string $backendOrigin,
    ): array {
        // Defense in depth: each trust boundary re-validates scope independently; do not deduplicate.
        if ($pageId <= 0
            || $languageId < 0
            || $workspaceId < 0
            || preg_match('/^[a-f0-9]{64}$/', $pageRecordSnapshot) !== 1
            || $backendUserId <= 0
        ) {
            throw new \InvalidArgumentException('Invalid structure analysis authorization scope.', 1760000004);
        }
        $requestId = bin2hex(random_bytes(16));
        $expiresAt = time() + self::LIFETIME;
        $ticket = new StructureAnalysisTicket(
            requestId: $requestId,
            pageId: $pageId,
            languageId: $languageId,
            workspaceId: $workspaceId,
            pageRecordSnapshot: $pageRecordSnapshot,
            backendUserId: $backendUserId,
            backendOrigin: $this->normalizeOrigin($backendOrigin),
            frontendOrigin: $this->originFromUrl($targetUrl),
            target: $this->normalizeTarget($targetUrl),
            expiresAt: $expiresAt,
        );
        $json = json_encode($ticket, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $payload = StringUtility::base64urlEncode($json);
        $signature = $this->hashService->hmac($payload, self::SIGNING_CONTEXT);

        return [
            'token' => $payload . '.' . $signature,
            'requestId' => $requestId,
        ];
    }

    /**
     * Issues a ticket and appends it to the preview URL as the wire-format query parameter.
     *
     * @return array{url: string, requestId: string}
     */
    public function issueAnalysisUrl(
        string $targetUrl,
        int $pageId,
        int $languageId,
        int $workspaceId,
        string $pageRecordSnapshot,
        int $backendUserId,
        string $backendOrigin,
    ): array {
        $ticket = $this->issue(
            $targetUrl,
            $pageId,
            $languageId,
            $workspaceId,
            $pageRecordSnapshot,
            $backendUserId,
            $backendOrigin,
        );
        $uri = new Uri($targetUrl);
        parse_str($uri->getQuery(), $query);
        $query[self::TICKET_QUERY_PARAMETER] = $ticket['token'];
        $url = (string)$uri->withQuery(http_build_query($query, '', '&', PHP_QUERY_RFC3986));

        return [
            'url' => $url,
            'requestId' => $ticket['requestId'],
        ];
    }

    public function validate(string $token): ?StructureAnalysisTicket
    {
        $now = time();
        [$payload, $signature] = array_pad(explode('.', $token, 2), 2, '');
        if ($payload === '' || $signature === ''
            || !$this->hashService->validateHmac($payload, self::SIGNING_CONTEXT, $signature)
        ) {
            return null;
        }

        $decoded = StringUtility::base64urlDecode($payload, true);
        if ($decoded === false) {
            return null;
        }
        try {
            $claims = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($claims)) {
            return null;
        }
        // The signed expiration is enforced for every redemption and does not
        // depend on mutable server-side state or garbage collection.
        $ticket = StructureAnalysisTicket::fromClaims($claims, $now, self::LIFETIME);
        if ($ticket === null) {
            return null;
        }

        // The HMAC signature already guarantees these claims were produced by issue(),
        // which only ever persists origins after normalizeOrigin() succeeded; re-normalizing
        // and comparing them against themselves here can never fail and is not re-checked.
        return $ticket;
    }

    public function originFromUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException('Structure analysis requires an absolute HTTP(S) preview URL.', 1760000001);
        }
        $host = strtolower((string)$parts['host']);
        $origin = strtolower((string)$parts['scheme']) . '://' . $this->formatHost($host);
        if (isset($parts['port'])) {
            $origin .= ':' . (int)$parts['port'];
        }
        return $this->normalizeOrigin($origin);
    }

    public function normalizeOrigin(string $origin): string
    {
        $parts = parse_url($origin);
        $path = is_array($parts) ? (string)($parts['path'] ?? '') : '';
        $scheme = is_array($parts) ? strtolower((string)($parts['scheme'] ?? '')) : '';
        if (!is_array($parts)
            || !in_array($scheme, ['http', 'https'], true)
            || !isset($parts['host'])
            || !$this->isValidHost((string)$parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ($path !== '' && $path !== '/')
        ) {
            throw new \InvalidArgumentException('Invalid structure analysis origin.', 1760000002);
        }
        $normalized = $scheme . '://' . $this->formatHost(strtolower((string)$parts['host']));
        if (isset($parts['port']) && !$this->isDefaultPort($scheme, (int)$parts['port'])) {
            $normalized .= ':' . (int)$parts['port'];
        }
        return $normalized;
    }

    private function isValidHost(string $host): bool
    {
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        }
        $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        return $ascii !== false
            && filter_var($ascii, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }

    public function normalizeTarget(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            throw new \InvalidArgumentException('Invalid structure analysis target.', 1760000003);
        }
        $path = (string)($parts['path'] ?? '/');
        $path = $path === '' ? '/' : $path;
        $query = [];
        parse_str((string)($parts['query'] ?? ''), $query);
        unset(
            $query[self::TICKET_QUERY_PARAMETER],
        );
        ksort($query);
        $normalizedQuery = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        return $path . ($normalizedQuery === '' ? '' : '?' . $normalizedQuery);
    }

    private function formatHost(string $host): string
    {
        // IPv6 literals are bracketed and never IDN-encoded.
        if (str_contains($host, ':')) {
            return str_starts_with($host, '[') ? $host : '[' . $host . ']';
        }
        // Match the ASCII (punycode) serialization the browser reports in
        // MessageEvent.origin, so unicode-configured site bases still compare
        // equal to the iframe's actual origin.
        $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        return $ascii === false ? $host : $ascii;
    }

    private function isDefaultPort(string $scheme, int $port): bool
    {
        return ($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443);
    }
}
