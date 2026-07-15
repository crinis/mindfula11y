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

use MindfulMarkup\MindfulA11y\Service\ValidationErrorTitleConfiguration;
use MindfulMarkup\MindfulA11y\Service\ValidationErrorState;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

/**
 * Prefixes the final page title after failed server-side form validation.
 */
final readonly class ValidationErrorTitleMiddleware implements MiddlewareInterface
{
    private const LABEL = 'LLL:EXT:mindfula11y/Resources/Private/Language/Frontend.xlf:validationError.titlePrefix';

    public function __construct(
        private ValidationErrorTitleConfiguration $configuration,
        private ValidationErrorState $validationErrorState,
        private StreamFactoryInterface $streamFactory,
        private LanguageServiceFactory $languageServiceFactory,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->validationErrorState->reset();
        $response = $handler->handle($request);

        // hasErrors() is a cheap in-memory bool and false on virtually every
        // request; check it first so the common path never touches config.
        if (!$this->validationErrorState->hasErrors()
            || !$this->configuration->isEnabled()
            || !$this->isHtmlResponse($response)
        ) {
            return $response;
        }

        $siteLanguage = $request->getAttribute('language');
        if (!$siteLanguage instanceof SiteLanguage) {
            return $response;
        }
        $prefix = $this->languageServiceFactory
            ->createFromSiteLanguage($siteLanguage)
            ->sL(self::LABEL);
        if ($prefix === '') {
            return $response;
        }

        $content = (string)$response->getBody();
        $rewrittenContent = $this->prefixTitle($content, $prefix);
        if ($rewrittenContent === $content) {
            return $response;
        }

        return $response
            ->withoutHeader('Content-Length')
            ->withBody($this->streamFactory->createStream($rewrittenContent));
    }

    private function isHtmlResponse(ResponseInterface $response): bool
    {
        $contentType = strtolower($response->getHeaderLine('Content-Type'));
        return str_starts_with($contentType, 'text/html')
            || str_starts_with($contentType, 'application/xhtml+xml');
    }

    private function prefixTitle(string $content, string $prefix): string
    {
        $rewrittenContent = preg_replace_callback(
            '/(<title\b[^>]*>)(.*?)(<\/title\s*>)/is',
            static function (array $matches) use ($prefix): string {
                $plainTitle = html_entity_decode(strip_tags($matches[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (str_starts_with(trim($plainTitle), $prefix)) {
                    return $matches[0];
                }
                $escapedPrefix = htmlspecialchars($prefix, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
                return $matches[1] . $escapedPrefix . ' ' . $matches[2] . $matches[3];
            },
            $content,
            1
        );

        return is_string($rewrittenContent) ? $rewrittenContent : $content;
    }
}
