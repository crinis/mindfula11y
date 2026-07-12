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

namespace MindfulMarkup\MindfulA11y\Exception;

/**
 * Class ScanApiRequestException.
 *
 * Thrown when the external scanner API answers a request with an error status.
 * Carries the RFC 9457 problem details of the response so callers can surface
 * the API's explanation (e.g. "AI audit is not enabled on this server.") to
 * the editor instead of a generic failure message.
 */
class ScanApiRequestException extends \RuntimeException
{
    /**
     * @param int $statusCode HTTP status code of the API response.
     * @param string $problemTitle The problem+json "title" member, if any.
     * @param string $problemDetail The problem+json "detail" member, if any.
     */
    public function __construct(
        protected readonly int $statusCode,
        protected readonly string $problemTitle = '',
        protected readonly string $problemDetail = '',
    ) {
        parent::__construct(
            'Scanner API request failed with status ' . $statusCode
                . ('' !== $problemTitle ? ': ' . $problemTitle : ''),
            $statusCode
        );
    }

    /**
     * Get the HTTP status code of the API response.
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Get the problem+json title.
     */
    public function getProblemTitle(): string
    {
        return $this->problemTitle;
    }

    /**
     * Get the problem+json detail.
     */
    public function getProblemDetail(): string
    {
        return $this->problemDetail;
    }
}
