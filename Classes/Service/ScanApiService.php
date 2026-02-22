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

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\RequestFactory;
use Psr\Log\LoggerInterface;

/**
 * Class ScanApiService.
 *
 * This class handles communication with the external accessibility scanner API.
 */
class ScanApiService
{
    /**
     * Timeout for HTTP requests to the external scanner API (seconds).
     */
    protected const REQUEST_TIMEOUT = 10;

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
        return $this->extensionConfiguration->get('mindfula11y')['scannerApiUrl'] ?? '';
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
     * Create a new scan for one or more URLs.
     *
     * @param string[] $urls The URLs to scan (for crawl mode: the start URL(s)).
     * @param bool $crawl Whether to use crawl mode.
     * @param array $crawlOptions Optional crawl options (e.g. globs, maxPages) passed through to the API.
     * @param array $scanOptions Optional scan options (e.g. basicAuth credentials) passed through to the API.
     * @return array|null The scan data or null if failed.
     */
    public function createScan(array $urls, bool $crawl = false, array $crawlOptions = [], array $scanOptions = []): ?array
    {
        if (!$this->isConfigured()) {
            $this->logger->error('Accessibility scanner API is not configured');
            return null;
        }

        if (empty($urls)) {
            $this->logger->error('No URLs provided for scan');
            return null;
        }

        try {
            $headers = [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ];
            $apiToken = $this->getApiToken();
            if (!empty($apiToken)) {
                $headers['Authorization'] = 'Bearer ' . $apiToken;
            }

            if ($crawl) {
                $body = [
                    'mode' => 'crawl',
                    'startUrls' => array_values($urls),
                ];
                if (!empty($crawlOptions)) {
                    $body['crawlOptions'] = $crawlOptions;
                }
            } elseif (count($urls) === 1) {
                $body = [
                    'mode' => 'single_url',
                    'url' => $urls[0],
                ];
            } else {
                $body = [
                    'mode' => 'url_list',
                    'urls' => array_values($urls),
                ];
            }
            if (!empty($scanOptions)) {
                $body['scanOptions'] = $scanOptions;
            }

            $response = $this->requestFactory->request(
                $this->getApiUrl() . '/scans',
                'POST',
                [
                    'headers' => $headers,
                    'body' => json_encode($body),
                    'timeout' => self::REQUEST_TIMEOUT,
                ]
            );

            if ($response->getStatusCode() !== 201) {
                $this->logger->error('Failed to create scan', [
                    'status' => $response->getStatusCode(),
                    'body' => $response->getBody()->getContents(),
                ]);
                return null;
            }

            $data = json_decode($response->getBody()->getContents(), true);
            return $data;
        } catch (\Exception $e) {
            $this->logger->error('Exception while creating scan', [
                'exception' => $e->getMessage(),
            ]);
            return null;
        }
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
                $this->getApiUrl() . '/scans/' . rawurlencode($scanId) . '/reports/' . $format,
                'GET',
                [
                    'headers' => $headers,
                    'timeout' => 30,
                ]
            );

            if ($response->getStatusCode() !== 200) {
                $this->logger->error('Failed to get scan report', [
                    'status' => $response->getStatusCode(),
                    'scanId' => $scanId,
                    'format' => $format,
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
     * @return array|null The scan or null if failed.
     */
    public function getScan(string $scanId, array $pageUrls = []): ?array
    {
        if (!$this->isConfigured()) {
            $this->logger->error('Accessibility scanner API is not configured');
            return null;
        }

        try {
            $headers = ['Accept' => 'application/json'];
            $apiToken = $this->getApiToken();
            if (!empty($apiToken)) {
                $headers['Authorization'] = 'Bearer ' . $apiToken;
            }

            $url = $this->getApiUrl() . '/scans/' . rawurlencode($scanId);
            if (!empty($pageUrls)) {
                $queryParts = [];
                foreach ($pageUrls as $pageUrl) {
                    $queryParts[] = 'pageUrls=' . rawurlencode($pageUrl);
                }
                $url .= '?' . implode('&', $queryParts);
            }

            $response = $this->requestFactory->request(
                $url,
                'GET',
                [
                    'headers' => $headers,
                    'timeout' => self::REQUEST_TIMEOUT,
                ]
            );

            $statusCode = $response->getStatusCode();

            // Handle 404 specifically - scan not found, should trigger new scan
            if ($statusCode === 404) {
                $this->logger->info('Scan not found, will trigger new scan', [
                    'scanId' => $scanId,
                ]);
                return null;
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

                $this->logger->error('Failed to get scan results', [
                    'status' => $statusCode,
                    'scanId' => $scanId,
                    'body' => $body,
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
        } catch (\Exception $e) {
            $this->logger->error('Exception while getting scan results', [
                'exception' => $e->getMessage(),
                'scanId' => $scanId,
            ]);
            return null;
        }
    }
}