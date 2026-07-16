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

namespace MindfulMarkup\MindfulA11y\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * The security invariants every ticketed structure-analysis response must
 * carry, shared between the response middleware (which applies them on the
 * regular rendering path) and the authentication middleware (which fails
 * closed when an inner short-circuit bypassed the response middleware).
 */
final readonly class StructureAnalysisResponseHardener
{
    public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
    ) {}

    /** Applies the invariants shared by every privileged analysis response. */
    public function harden(ResponseInterface $response, string $contentSecurityPolicy): ResponseInterface
    {
        return $response
            ->withoutHeader('Content-Length')
            ->withoutHeader('Content-Security-Policy')
            ->withoutHeader('Content-Security-Policy-Report-Only')
            ->withoutHeader('X-Frame-Options')
            ->withHeader('Content-Security-Policy', $contentSecurityPolicy)
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    /**
     * A blank, analyzable HTML document with the given status.
     *
     * NOT hardened by itself: callers on the ticketed rendering path inject
     * the runner and harden() the final response before returning it.
     */
    public function createMinimalHtmlResponse(int $statusCode): ResponseInterface
    {
        return $this->responseFactory
            ->createResponse($statusCode)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($this->streamFactory->createStream('<!doctype html><html><body></body></html>'));
    }

    /**
     * A hardened, script-free error document. Used when a ticketed request
     * cannot be answered with an analyzable document (missing runner bundle,
     * or a response that never passed the response middleware).
     */
    public function createNonScriptedErrorResponse(string $backendOrigin, int $statusCode = 500): ResponseInterface
    {
        $contentSecurityPolicy = "default-src 'none'; script-src 'none'; object-src 'none'; frame-src 'none'; form-action 'none'; base-uri 'none'; frame-ancestors " . $backendOrigin;

        return $this->harden($this->createMinimalHtmlResponse($statusCode), $contentSecurityPolicy);
    }

    /**
     * Whether a response went through harden(): every hardened response pins
     * frame-ancestors to the ticket's backend origin and forbids caching.
     */
    public function isHardened(ResponseInterface $response, string $backendOrigin): bool
    {
        return str_contains($response->getHeaderLine('Content-Security-Policy'), 'frame-ancestors ' . $backendOrigin)
            && str_contains($response->getHeaderLine('Cache-Control'), 'no-store');
    }
}
