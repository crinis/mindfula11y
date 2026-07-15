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
use TYPO3\CMS\Core\TypoScript\FrontendTypoScript;

/**
 * Disable admin panel rendering for structure analysis requests.
 */
class StructureAnalysisDisableAdminPanelMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (StructureAnalysisTicket::fromRequest($request) !== null) {
            $frontendTypoScript = $request->getAttribute('frontend.typoscript');
            if ($frontendTypoScript instanceof FrontendTypoScript) {
                $config = $frontendTypoScript->getConfigArray();
                $config['admPanel'] = 0;
                $frontendTypoScript->setConfigArray($config);
                $request = $request->withAttribute('frontend.typoscript', $frontendTypoScript);
            }
        }

        return $handler->handle($request);
    }
}
