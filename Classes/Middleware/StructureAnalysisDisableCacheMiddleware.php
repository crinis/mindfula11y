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

namespace MindfulMarkup\MindfulA11y\Middleware;

use MindfulMarkup\MindfulA11y\Domain\Model\StructureAnalysisTicket;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Frontend\Cache\CacheInstruction;

/**
 * Disable frontend cache for structure analysis requests.
 */
final readonly class StructureAnalysisDisableCacheMiddleware implements MiddlewareInterface
{
    /**
     * Process the request and disable frontend cache for an authenticated structure analysis.
     *
     * The signed ticket is the authorization; it is validated before this
     * middleware runs and deliberately recreates no backend user session, so
     * the request carries no `backend.user` aspect to test here.
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (StructureAnalysisTicket::fromRequest($request) === null) {
            return $handler->handle($request);
        }

        $cacheInstruction = $request->getAttribute('frontend.cache.instruction', new CacheInstruction());
        $cacheInstruction->disableCache('EXT:mindfula11y: structure analysis request.');

        return $handler->handle($request->withAttribute('frontend.cache.instruction', $cacheInstruction));
    }
}
