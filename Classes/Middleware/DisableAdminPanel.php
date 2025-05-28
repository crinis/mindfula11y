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
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

/**
 * Middleware to disable the admin panel.
 * 
 * Middleware to disable the admin panel for a request if Mindfula11y-Heading-Structure header is set.
 */
class DisableAdminPanel implements MiddlewareInterface
{
    public function __construct(
        protected readonly Context $context,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (
            $this->context->getPropertyFromAspect('backend.user', 'isLoggedIn', false)
            && $request->getHeaderLine('Mindfula11y-Heading-Structure') === '1'
            && ExtensionManagementUtility::isLoaded('adminpanel')
        ) {
            /**
             * Deprecated, but kept for backwards compatibility with TYPO3 12.
             */
            ExtensionManagementUtility::addUserTSConfig('admPanel.hide = 1');
        }

        return $handler->handle($request);
    }
}
