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

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Frontend\Cache\CacheInstruction;

/**
 * Middleware to disable the cache.
 * 
 * Middleware to disable the cache for a request if Mindfula11y-Structure-Analysis header is set.
 */
class DisableCacheMiddleware implements MiddlewareInterface
{
    public function __construct(
        protected readonly Context $context,
    ) {}

    /**
     * Process the request and disable the frontend cache if the Mindfula11y-Structure-Analysis header is set.
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->context->getPropertyFromAspect('backend.user', 'isLoggedIn', false) && $request->hasHeader('Mindfula11y-Structure-Analysis')) {
            $cacheInstruction = $request->getAttribute(
                'frontend.cache.instruction',
                new CacheInstruction()
            );

            $cacheInstruction->disableCache('EXT:mindfula11y: Mindfula11y-Structure-Analysis header set.');
            $request = $request->withAttribute('frontend.cache.instruction', $cacheInstruction);
        }

        return $handler->handle($request);
    }
}
