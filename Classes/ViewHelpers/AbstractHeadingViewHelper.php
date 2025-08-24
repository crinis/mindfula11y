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

namespace MindfulMarkup\MindfulA11y\ViewHelpers;

use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractTagBasedViewHelper;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Cache\CacheManager;

/**
 * Abstract base class for heading-related ViewHelpers.
 *
 * Provides shared logic for runtime cache, context, and request handling.
 */
abstract class AbstractHeadingViewHelper extends AbstractTagBasedViewHelper
{
    /**
     * The default heading type if not specified and not found in the record.
     */
    public const DEFAULT_TYPE = 'h2';

    /**
     * Cache Manager instance.
     */
    protected CacheManager $cacheManager;

    /**
     * Runtime cache instance.
     */
    protected FrontendInterface $runtimeCache;

    /**
     * Context object with information about the current request and user.
     */
    protected Context $context;

    /**
     * Inject Cache Manager
     */
    public function injectCacheManager(CacheManager $cacheManager): void
    {
        $this->cacheManager = $cacheManager;
        $this->runtimeCache = $this->cacheManager->getCache('runtime');
    }

    /**
     * Inject the context object.
     *
     * @param Context $context
     */
    public function injectContext(Context $context): void
    {
        $this->context = $context;
    }

    /**
     * Get the current request from the rendering context.
     *
     * @return ServerRequestInterface|null The current request or null if not available.
     */
    protected function getRequest(): ?ServerRequestInterface
    {
        if ($this->renderingContext->hasAttribute(ServerRequestInterface::class)) {
            return $this->renderingContext->getAttribute(ServerRequestInterface::class);
        }
        return null;
    }

    /**
     * Check if this is a structure analysis request and the user is logged in.
     *
     * @return bool True if the Mindfula11y-Structure-Analysis header is set and the user is logged in, false otherwise.
     */
    protected function isStructureAnalysisRequest(): bool
    {
        $request = $this->getRequest();
        return $request !== null && $request->hasHeader('Mindfula11y-Structure-Analysis')
            && $this->context->getPropertyFromAspect('backend.user', 'isLoggedIn', false);
    }
}
