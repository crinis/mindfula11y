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

use MindfulMarkup\MindfulA11y\Exception\ScanApiRequestException;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Class ScanApiService.
 *
 * This class handles communication with the external accessibility scanner
 * API (mindfulapi >= 0.7, versioned route prefix /v1, errors as RFC 9457
 * application/problem+json).
 */
class ScanApiService
{
    /**
     * Timeout for HTTP requests to the external scanner API (seconds).
     */
    protected const REQUEST_TIMEOUT = 10;

    /**
     * Versioned route prefix of all business endpoints (the health endpoint is unprefixed).
     */
    protected const API_VERSION_PREFIX = '/v1';

    /**
     * Constructor.
     *
     * @param ExtensionConfiguration $extensionConfiguration The extension configuration instance.
     * @param RequestFactory $requestFactory The HTTP request factory.
     * @param LoggerInterface $logger The logger instance.
     */
    public function __construct(
        protected readonly ExtensionConfiguration $extensionConfiguration,
        protected readonly RequestFactory $requestFactory,
        protected readonly LoggerInterface $logger,
    ) {}

    /**
     * Get the API URL from extension configuration.
     *
     * @return string The API URL.
     */
    protected function getApiUrl(): string
    {
        return rtrim($this->extensionConfiguration->get('mindfula11y')['scannerApiUrl'] ?? '', '/');
    }

    /**
     * Get the versioned base URL for business endpoints.
     */
    protected function getApiBaseUrl(): string
    {
        return $this->getApiUrl() . self::API_VERSION_PREFIX;
    }

    /**
     * Get the API token from extension configuration.
     *
     * @return string The API token.
     */
    protected function getApiToken(): string
    {
        return $this->extensionConfiguration->get('mindfula11y')['scannerApiToken'] ?? '';
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
     * Check if the external scanner API is reachable via its public,
     * unauthenticated health endpoint. Any HTTP response (a degraded 503
     * included) counts as reachable; only network-level failures (connection
     * refused, DNS failure, timeout) return false.
     *
     * @return bool True if the API responds, false if a connection error occurs.
     */
    public function checkStatus(): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        try {
            $this->requestFactory->request(
                $this->getApiUrl() . '/health',
                'GET',
                [
                    'headers' => ['Accept' => 'application/json'],
                    'timeout' => 5,
                    'http_errors' => false,
                ]
            );

            // Any HTTP response (even 4xx/5xx) means the server is reachable.
            return true;
        } catch (\Exception $e) {
            $this->logger->warning('Accessibility scanner API is not reachable', [
                'exception' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Decode the RFC 9457 problem details of an error response.
     *
     * @return array{title: string, detail: string, errors: array} Empty strings/array when the body is not problem+json.
     */
    protected function parseProblemDetails(ResponseInterface $response): array
    {
        $problem = ['title' => '', 'detail' => '', 'errors' => []];
        try {
            $data = json_decode((string)$response->getBody(), true);
        } catch (\Exception) {
            return $problem;
        }
        if (!is_array($data)) {
            return $problem;
        }
        $problem['title'] = is_string($data['title'] ?? null) ? $data['title'] : '';
        $problem['detail'] = is_string($data['detail'] ?? null) ? $data['detail'] : '';
        $problem['errors'] = is_array($data['errors'] ?? null) ? $data['errors'] : [];
        return $problem;
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
        if (!$this->isConfigured()) {
            $this->logger->error('Accessibility scanner API is not configured');
            return null;
        }

        if (empty($urls)) {
            $this->logger->error('No URLs provided for scan');
            return null;
        }

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
        $apiToken = $this->getApiToken();
        if (!empty($apiToken)) {
            $headers['Authorization'] = 'Bearer ' . $apiToken;
        }

        if ($crawl) {
            $requestBody = [
                'mode' => 'crawl',
                'startUrls' => array_values($urls),
            ];
            if (!empty($crawlOptions)) {
                $requestBody['crawlOptions'] = $crawlOptions;
            }
        } elseif (count($urls) === 1) {
            $requestBody = [
                'mode' => 'single_url',
                'url' => $urls[0],
            ];
        } else {
            $requestBody = [
                'mode' => 'url_list',
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
            $response = $this->requestFactory->request(
                $this->getApiBaseUrl() . '/scans',
                'POST',
                [
                    'headers' => $headers,
                    'body' => json_encode($requestBody),
                    'timeout' => self::REQUEST_TIMEOUT,
                    'http_errors' => false,
                ]
            );
        } catch (\Exception $e) {
            $this->logger->error('Exception while creating scan', [
                'exception' => $e->getMessage(),
            ]);
            return null;
        }

        if ($response->getStatusCode() !== 201) {
            $problem = $this->parseProblemDetails($response);
            $this->logger->error('Failed to create scan', [
                'status' => $response->getStatusCode(),
                'problemTitle' => $problem['title'],
                'problemDetail' => $problem['detail'],
                'problemErrors' => $problem['errors'],
            ]);
            throw new ScanApiRequestException($response->getStatusCode(), $problem['title'], $problem['detail']);
        }

        $body = (string)$response->getBody();
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error('Invalid JSON response from scan API', [
                'json_error' => json_last_error_msg(),
                'body' => $body,
            ]);
            return null;
        }

        return $data;
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
        if (!$this->isConfigured()) {
            $this->logger->error('Accessibility scanner API is not configured');
            return null;
        }

        $headers = ['Accept' => 'application/json'];
        $apiToken = $this->getApiToken();
        if (!empty($apiToken)) {
            $headers['Authorization'] = 'Bearer ' . $apiToken;
        }

        try {
            $response = $this->requestFactory->request(
                $this->getApiBaseUrl() . '/scans/' . rawurlencode($scanId) . '/cancel',
                'POST',
                [
                    'headers' => $headers,
                    'timeout' => self::REQUEST_TIMEOUT,
                    'http_errors' => false,
                ]
            );
        } catch (\Exception $e) {
            $this->logger->error('Exception while canceling scan', [
                'exception' => $e->getMessage(),
                'scanId' => $scanId,
            ]);
            return null;
        }

        if ($response->getStatusCode() !== 200) {
            $problem = $this->parseProblemDetails($response);
            $this->logger->warning('Failed to cancel scan', [
                'status' => $response->getStatusCode(),
                'scanId' => $scanId,
                'problemTitle' => $problem['title'],
                'problemDetail' => $problem['detail'],
            ]);
            throw new ScanApiRequestException($response->getStatusCode(), $problem['title'], $problem['detail']);
        }

        $body = (string)$response->getBody();
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error('Invalid JSON response from scan API', [
                'json_error' => json_last_error_msg(),
                'scanId' => $scanId,
            ]);
            return null;
        }

        return $data;
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
        if (!$this->isConfigured()) {
            $this->logger->error('Accessibility scanner API is not configured');
            return null;
        }

        try {
            $accept = $format === 'pdf' ? 'application/pdf' : 'text/html';
            $headers = ['Accept' => $accept];
            $apiToken = $this->getApiToken();
            if (!empty($apiToken)) {
                $headers['Authorization'] = 'Bearer ' . $apiToken;
            }

            $response = $this->requestFactory->request(
                $this->getApiBaseUrl() . '/scans/' . rawurlencode($scanId) . '/reports/' . $format,
                'GET',
                [
                    'headers' => $headers,
                    'timeout' => 30,
                    'http_errors' => false,
                ]
            );

            if ($response->getStatusCode() !== 200) {
                $problem = $this->parseProblemDetails($response);
                $this->logger->error('Failed to get scan report', [
                    'status' => $response->getStatusCode(),
                    'scanId' => $scanId,
                    'format' => $format,
                    'problemTitle' => $problem['title'],
                    'problemDetail' => $problem['detail'],
                ]);
                return null;
            }

            return $response->getBody()->getContents();
        } catch (\Exception $e) {
            $this->logger->error('Exception while getting scan report', [
                'exception' => $e->getMessage(),
                'scanId' => $scanId,
                'format' => $format,
            ]);
            return null;
        }
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
        if (!$this->isConfigured()) {
            $this->logger->error('Accessibility scanner API is not configured');
            return null;
        }

        $headers = ['Accept' => 'application/json'];
        $apiToken = $this->getApiToken();
        if (!empty($apiToken)) {
            $headers['Authorization'] = 'Bearer ' . $apiToken;
        }

        $url = $this->getApiBaseUrl() . '/scans/' . rawurlencode($scanId);
        if (!empty($pageUrls)) {
            $queryParts = [];
            foreach ($pageUrls as $pageUrl) {
                $queryParts[] = 'pageUrls=' . rawurlencode($pageUrl);
            }
            $url .= '?' . implode('&', $queryParts);
        }

        try {
            $response = $this->requestFactory->request(
                $url,
                'GET',
                [
                    'headers' => $headers,
                    'timeout' => self::REQUEST_TIMEOUT,
                    'http_errors' => false,
                ]
            );
        } catch (\Exception $e) {
            $this->logger->error('Exception while getting scan results', [
                'exception' => $e->getMessage(),
                'scanId' => $scanId,
            ]);
            return null;
        }

        $statusCode = $response->getStatusCode();

        // Handle 404 specifically - scan not found, should trigger new scan.
        // Thrown (not null) so the controller can answer 404 instead of the
        // generic 500 for failures — the client recovers by re-creating.
        if ($statusCode === 404) {
            $this->logger->info('Scan not found, will trigger new scan', [
                'scanId' => $scanId,
            ]);
            $problem = $this->parseProblemDetails($response);
            throw new ScanApiRequestException(404, $problem['title'], $problem['detail']);
        }

        if ($statusCode !== 200) {
            $body = '';
            try {
                $body = $response->getBody()->getContents();
            } catch (\Exception $bodyException) {
                $this->logger->warning('Could not read response body', [
                    'scanId' => $scanId,
                    'status' => $statusCode,
                    'exception' => $bodyException->getMessage(),
                ]);
            }

            $problem = $this->parseProblemDetails($response);
            $this->logger->error('Failed to get scan results', [
                'status' => $statusCode,
                'scanId' => $scanId,
                'body' => $body,
                'problemTitle' => $problem['title'],
                'problemDetail' => $problem['detail'],
            ]);
            return null;
        }

        $body = $response->getBody()->getContents();
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error('Invalid JSON response from API', [
                'scanId' => $scanId,
                'json_error' => json_last_error_msg(),
                'body' => $body,
            ]);
            return null;
        }

        // Return the raw API response as-is
        return $data;
    }
}
