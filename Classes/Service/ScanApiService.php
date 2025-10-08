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
     *
     * @return bool True if configured, false otherwise.
     */
    public function isConfigured(): bool
    {
        return !empty($this->getApiUrl()) && !empty($this->getApiToken());
    }

    /**
     * Create a new scan for a given URL.
     *
     * @param string $url The URL to scan.
     * @return array|null The scan data or null if failed.
     */
    public function createScan(string $url): ?array
    {
        if (!$this->isConfigured()) {
            $this->logger->error('Accessibility scanner API is not configured');
            return null;
        }

        try {
            $response = $this->requestFactory->request(
                $this->getApiUrl() . '/scans',
                'POST',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->getApiToken(),
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                    ],
                    'body' => json_encode([
                        'url' => $url,
                        'language' => 'en',
                        'scannerType' => 'axe',
                    ]),
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
     * Get scan for a given scan ID.
     *
     * @param string $scanId The scan ID.
     * @return array|null The scan or null if failed.
     */
    public function get(string $scanId): ?array
    {
        if (!$this->isConfigured()) {
            $this->logger->error('Accessibility scanner API is not configured');
            return null;
        }

        try {
            $response = $this->requestFactory->request(
                $this->getApiUrl() . '/scans/' . $scanId,
                'GET',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->getApiToken(),
                        'Accept' => 'application/json',
                    ],
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