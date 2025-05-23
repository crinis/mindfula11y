<?php

declare(strict_types=1);

namespace MindfulMarkup\MindfulA11y\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Context\Context;

/**
 * Middleware to disable the cache.
 * 
 * Middleware to disable the cache for a request if Mindfula11y-Heading-Structure header is set.
 */
class DisableCacheMiddleware implements MiddlewareInterface
{
    public function __construct(
        protected readonly Context $context,
    ) {}

    /**
     * Process the request and disable the frontend cache if the Mindfula11y-Heading-Structure header is set.
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->context->getPropertyFromAspect('backend.user', 'isLoggedIn', false) && $request->getHeaderLine('Mindfula11y-Heading-Structure') === '1') {
            $cacheInstruction = $request->getAttribute(
                'frontend.cache.instruction',
            );

            $cacheInstruction->disableCache('EXT:mindfula11y: Mindfula11y-Heading-Structure header set.');
            $request = $request->withAttribute('frontend.cache.instruction', $cacheInstruction);
        }

        return $handler->handle($request);
    }
}
