<?php

declare(strict_types=1);

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
