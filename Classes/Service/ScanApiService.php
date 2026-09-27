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

namespace MindfulMarkup\MindfulA11y\Service;

use MindfulMarkup\MindfulA11y\Enum\ScanMode;
use MindfulMarkup\MindfulA11y\Exception\ScanApiRequestException;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Class ScanApiService.
 *
 * This class handles communication with the external accessibility scanner
 * API (mindfulapi >= 0.7, versioned route prefix /v1, errors as RFC 9457
 * application/problem+json).
 */
final readonly class ScanApiService
{
    /**
     * Timeout for HTTP requests to the external scanner API (seconds).
     */
    private const REQUEST_TIMEOUT = 10;

    /** Upper bound on scanner-supplied text forwarded to a backend user. */
    private const MAX_CLIENT_DETAIL_LENGTH = 500;

    /**
     * Versioned route prefix of all business endpoints (the health endpoint is unprefixed).
     */
    private const API_VERSION_PREFIX = '/v1';

    /**
     * Constructor.
     *
     * @param ExtensionSettings $extensionSettings The extension settings.
     * @param RequestFactory $requestFactory The HTTP request factory.
     * @param LoggerInterface $logger The logger instance.
     */
    public function __construct(
        private ExtensionSettings $extensionSettings,
        private RequestFactory $requestFactory,
        private LoggerInterface $logger,
    ) {}

    /**
     * Get the API URL from extension configuration.
     *
     * @return string The API URL.
     */
    private function getApiUrl(): string
    {
        return rtrim($this->extensionSettings->get('scannerApiUrl', ''), '/');
    }

    /**
     * Get the versioned base URL for business endpoints.
     */
    private function getApiBaseUrl(): string
    {
        return $this->getApiUrl() . self::API_VERSION_PREFIX;
    }

    /**
     * Get the API token from extension configuration.
     *
     * @return string The API token.
     */
    private function getApiToken(): string
    {
        return $this->extensionSettings->get('scannerApiToken', '');
    }

    /**
     * Check if the scanner service is configured.
     * Only the API URL is required; the token is optional (the API supports open access).
     *
     * @return bool True if configured, false otherwise.
     */
    public function isConfigured(): bool
    {
        return !empty($this->getApiUrl());
    }

    /**
     * Replace every secret value a request carried with a marker.
     *
     * Upstream text (problem details, raw bodies) may echo a request that
     * carried this installation's API token and, for a scan, the site's Basic
     * Auth credentials — an upstream that reflects its input back in a
     * validation error would otherwise hand those to whoever reads the text.
     * Applied to the editor-facing message and to the log alike: log files are
     * read by more people than the extension configuration.
     *
     * @param array<mixed> $requestSecrets Secret values this request carried, in any shape the caller holds them.
     */
    private function redactSecrets(string $text, array $requestSecrets): string
    {
        $secrets = array_filter(
            [...array_values($requestSecrets), $this->getApiToken()],
            // Non-strings cannot appear verbatim in the text. Length is
            // deliberately no criterion: a short staging password is still a
            // credential, and over-redaction beats leaking it.
            static fn(mixed $secret): bool => is_string($secret) && $secret !== '',
        );
        // strtr() replaces in a single pass, preferring the longest match, so a
        // secret contained in another (e.g. the username inside a derived
        // password) cannot break the longer match, and a short secret cannot
        // match inside an already inserted "[redacted]". The same single-pass
        // property means a text must never be redacted twice.
        if ($secrets === []) {
            return $text;
        }

        return strtr($text, array_fill_keys($secrets, '[redacted]'));
    }

    /**
     * The problem detail as it may be shown to a backend user.
     *
     * The scanner's own explanation is genuinely useful ("AI audit is not
     * enabled on this server."), so it is surfaced rather than replaced by a
     * generic message — redacted (see redactSecrets()), and length-bounded so
     * an error page cannot become a channel for bulk upstream output.
     *
     * @param array<mixed> $requestSecrets Secret values this request carried, in any shape the caller holds them.
     */
    private function toClientSafeDetail(string $detail, array $requestSecrets = []): string
    {
        return mb_strimwidth($this->redactSecrets($detail, $requestSecrets), 0, self::MAX_CLIENT_DETAIL_LENGTH, '…');
    }

    /**
     * Decode the RFC 9457 problem details of an error response.
     *
     * @return array{title: string, detail: string, errors: array} Empty strings/array when the body is not problem+json.
     */
    private function parseProblemDetails(ResponseInterface $response): array
    {
        $problem = ['title' => '', 'detail' => '', 'errors' => []];
        $data = json_decode((string)$response->getBody(), true);
        if (!is_array($data)) {
            return $problem;
        }
        $problem['title'] = is_string($data['title'] ?? null) ? $data['title'] : '';
        $problem['detail'] = is_string($data['detail'] ?? null) ? $data['detail'] : '';
        $problem['errors'] = is_array($data['errors'] ?? null) ? $data['errors'] : [];
        return $problem;
    }

    /**
     * Send an authorized request to a versioned scanner API endpoint.
     *
     * Owns the transport concerns every endpoint shares: the configuration
     * guard, Accept/Authorization headers, timeout and error-suppression
     * defaults, and logging of network-level failures.
     *
     * @param array<string, mixed> $options Request options merged over the defaults; an explicit 'headers' entry wins per header name.
     * @param array<string, mixed> $logContext Context added to every log entry for this request.
     * @return ResponseInterface|null Null when unconfigured or on a network-level failure (both logged).
     */
    private function sendRequest(string $path, string $method, array $options, array $logContext, string $failureMessage): ?ResponseInterface
    {
        if (!$this->isConfigured()) {
            $this->logger->error('Accessibility scanner API is not configured');
            return null;
        }

        $headers = ($options['headers'] ?? []) + ['Accept' => 'application/json'];
        $apiToken = $this->getApiToken();
        if (!empty($apiToken)) {
            $headers['Authorization'] = 'Bearer ' . $apiToken;
        }
        $options['headers'] = $headers;
        $options += [
            'timeout' => self::REQUEST_TIMEOUT,
            'http_errors' => false,
        ];

        try {
            return $this->requestFactory->request($this->getApiBaseUrl() . $path, $method, $options);
        } catch (\Exception $e) {
            $this->logger->error($failureMessage, ['exception' => $e->getMessage()] + $logContext);
            return null;
        }
    }

    /**
     * Log an unexpected API response and surface it to the caller.
     *
     * Every path that answers a failed response with an exception goes through
     * here. The one path that does not throw stands apart deliberately:
     * getScan() degrades a non-200 to null so the module still renders.
     *
     * @param array<string, mixed> $logContext
     * @param array<int|string, mixed> $requestSecrets Credentials this request carried, redacted from the editor-facing detail.
     * @throws ScanApiRequestException Always.
     */
    private function throwProblem(
        ResponseInterface $response,
        string $message,
        array $logContext,
        string $level = 'error',
        array $requestSecrets = [],
    ): never {
        $problem = $this->logProblem($response, $message, $logContext, $level, $requestSecrets);

        throw new ScanApiRequestException(
            $response->getStatusCode(),
            $problem['title'],
            $this->toClientSafeDetail($problem['detail'], $requestSecrets),
        );
    }

    /**
     * Decode, log, and return the problem details of a failed response.
     *
     * Only the logged copy is redacted; the returned details stay raw so the
     * editor-facing message is redacted exactly once (see redactSecrets()).
     *
     * @param array<string, mixed> $logContext
     * @param array<mixed> $requestSecrets Credentials this request carried, redacted from the log.
     * @return array{title: string, detail: string, errors: array}
     */
    private function logProblem(
        ResponseInterface $response,
        string $message,
        array $logContext,
        string $level = 'error',
        array $requestSecrets = [],
    ): array {
        $problem = $this->parseProblemDetails($response);
        $loggedErrors = $problem['errors'];
        array_walk_recursive($loggedErrors, function (mixed &$value) use ($requestSecrets): void {
            if (is_string($value)) {
                $value = $this->redactSecrets($value, $requestSecrets);
            }
        });
        $this->logger->log($level, $message, $logContext + [
            'status' => $response->getStatusCode(),
            'problemTitle' => $this->redactSecrets($problem['title'], $requestSecrets),
            'problemDetail' => $this->redactSecrets($problem['detail'], $requestSecrets),
            'problemErrors' => $loggedErrors,
        ]);

        return $problem;
    }

    /**
     * Decode a JSON response body.
     *
     * @param array<string, mixed> $logContext
     * @param array<mixed> $requestSecrets Credentials this request carried, redacted from the logged body.
     * @return array|null Null when the body is not a JSON object/array (logged).
     */
    private function decodeJsonBody(ResponseInterface $response, array $logContext, array $requestSecrets = []): ?array
    {
        $body = (string)$response->getBody();
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error('Invalid JSON response from scan API', $logContext + [
                'json_error' => json_last_error_msg(),
                // Capped: a misbehaving endpoint may answer with a full HTML
                // error page that must not land in the log verbatim. Redacted
                // before capping, so a cut cannot split a secret past redaction.
                'body' => mb_substr($this->redactSecrets($body, $requestSecrets), 0, 2048),
            ]);
            return null;
        }

        if (!is_array($data)) {
            // Valid JSON, but a scalar or null: nothing a caller can read.
            $this->logger->error('Unexpected JSON response type from scan API', $logContext + [
                'type' => get_debug_type($data),
            ]);
            return null;
        }

        return $data;
    }

    /**
     * Create a new scan for one or more URLs.
     *
     * @param string[] $urls The URLs to scan (for crawl mode: the start URL(s)).
     * @param bool $crawl Whether to use crawl mode.
     * @param array $crawlOptions Optional crawl options (e.g. globs, maxPages) passed through to the API.
     * @param array $scanOptions Optional scan options (e.g. basicAuth credentials) passed through to the API.
     * @param bool $includeAiAudit Whether MindfulAPI should run an AI audit.
     * @param string[]|null $aiAuditSkills Null omits the list (all server-enabled skills); an explicit empty list requests no skills.
     * @return array|null The scan data or null on network/decode failure.
     * @throws ScanApiRequestException When the API rejects the request (e.g. AI audit disabled server-side).
     */
    public function createScan(
        array $urls,
        bool $crawl = false,
        array $crawlOptions = [],
        array $scanOptions = [],
        bool $includeAiAudit = false,
        ?array $aiAuditSkills = null,
    ): ?array {
        if (empty($urls)) {
            $this->logger->error('No URLs provided for scan');
            return null;
        }

        if ($crawl) {
            $requestBody = [
                'mode' => ScanMode::Crawl->value,
                'startUrls' => array_values($urls),
            ];
            if (!empty($crawlOptions)) {
                $requestBody['crawlOptions'] = $crawlOptions;
            }
        } elseif (count($urls) === 1) {
            $requestBody = [
                'mode' => ScanMode::SingleUrl->value,
                'url' => $urls[0],
            ];
        } else {
            $requestBody = [
                'mode' => ScanMode::UrlList->value,
                'urls' => array_values($urls),
            ];
        }
        if (!empty($scanOptions)) {
            $requestBody['scanOptions'] = $scanOptions;
        }
        if ($includeAiAudit) {
            $requestBody['aiAudit'] = $aiAuditSkills === null
                ? new \stdClass()
                : ['skills' => array_values($aiAuditSkills)];
        }

        try {
            // THROW_ON_ERROR: an encode failure (e.g. malformed UTF-8 reaching
            // the pass-through options) must fail cleanly instead of sending a
            // literal `false` as the request body.
            $body = json_encode($requestBody, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logger->error('Failed to encode scan request body', ['exception' => $e->getMessage()]);
            return null;
        }

        $response = $this->sendRequest(
            '/scans',
            'POST',
            [
                'headers' => ['Content-Type' => 'application/json'],
                'body' => $body,
            ],
            [],
            'Exception while creating scan',
        );
        if (null === $response) {
            return null;
        }

        $requestSecrets = (array)($scanOptions['basicAuth'] ?? []);
        if ($response->getStatusCode() !== 201) {
            // This request carried the site's Basic Auth credentials, so they
            // join the redaction set for the editor-facing message and the log.
            $this->throwProblem(
                $response,
                'Failed to create scan',
                [],
                requestSecrets: $requestSecrets,
            );
        }

        return $this->decodeJsonBody($response, [], $requestSecrets);
    }

    /**
     * Request cancellation of a running scan.
     *
     * @param string $scanId The scan ID.
     * @return array|null The updated scan data or null on network/decode failure.
     * @throws ScanApiRequestException When the API rejects the request (409 = scan already terminal).
     */
    public function cancelScan(string $scanId): ?array
    {
        $response = $this->sendRequest(
            '/scans/' . rawurlencode($scanId) . '/cancel',
            'POST',
            [],
            ['scanId' => $scanId],
            'Exception while canceling scan',
        );
        if (null === $response) {
            return null;
        }

        if ($response->getStatusCode() !== 200) {
            $this->throwProblem($response, 'Failed to cancel scan', ['scanId' => $scanId], 'warning');
        }

        return $this->decodeJsonBody($response, ['scanId' => $scanId]);
    }

    /**
     * Fetch a pre-rendered HTML or PDF report for a completed scan.
     *
     * @param string $scanId The scan ID.
     * @param string $format Either 'html' or 'pdf'.
     * @return string|null Raw report body, or null on failure.
     */
    public function getReport(string $scanId, string $format): ?string
    {
        $response = $this->sendRequest(
            '/scans/' . rawurlencode($scanId) . '/reports/' . $format,
            'GET',
            [
                'headers' => ['Accept' => $format === 'pdf' ? 'application/pdf' : 'text/html'],
                // Report rendering (PDF especially) may exceed the default API timeout.
                'timeout' => 30,
            ],
            ['scanId' => $scanId, 'format' => $format],
            'Exception while getting scan report',
        );
        if (null === $response) {
            return null;
        }

        if ($response->getStatusCode() !== 200) {
            $this->logProblem($response, 'Failed to get scan report', ['scanId' => $scanId, 'format' => $format]);
            return null;
        }

        return (string)$response->getBody();
    }

    /**
     * Get scan for a given scan ID.
     *
     * @param string $scanId The scan ID.
     * @param string[] $pageUrls Optional page URL filter.
     * @return array|null The scan or null on network/decode failure.
     * @throws ScanApiRequestException With status 404 when the scanner no longer knows the
     *   scan (retention pruning) — a recoverable state distinct from a failed request.
     */
    public function getScan(string $scanId, array $pageUrls = []): ?array
    {
        $path = '/scans/' . rawurlencode($scanId);
        if (!empty($pageUrls)) {
            $queryParts = [];
            foreach ($pageUrls as $pageUrl) {
                $queryParts[] = 'pageUrls=' . rawurlencode($pageUrl);
            }
            $path .= '?' . implode('&', $queryParts);
        }

        $response = $this->sendRequest($path, 'GET', [], ['scanId' => $scanId], 'Exception while getting scan results');
        if (null === $response) {
            return null;
        }

        // Handle 404 specifically - scan not found, should trigger new scan.
        // Thrown (not null) so the controller can answer 404 instead of the
        // generic 500 for failures — the client recovers by re-creating.
        if ($response->getStatusCode() === 404) {
            $this->throwProblem($response, 'Scan not found, will trigger new scan', ['scanId' => $scanId], 'info');
        }

        if ($response->getStatusCode() !== 200) {
            $this->logProblem($response, 'Failed to get scan results', ['scanId' => $scanId]);
            return null;
        }

        return $this->decodeJsonBody($response, ['scanId' => $scanId]);
    }
}
