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

namespace MindfulMarkup\MindfulA11y\Controller;

use Exception;
use InvalidArgumentException;
use MindfulMarkup\MindfulA11y\Domain\Model\AltTextDemand;
use MindfulMarkup\MindfulA11y\Service\AltTextGeneratorService;
use MindfulMarkup\MindfulA11y\Service\SiteLanguageService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Crypto\HashService;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\AllowedMethodsTrait;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Core\Http\Error\MethodNotAllowedException;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;
use TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException;
use TYPO3\CMS\Core\Resource\ResourceFactory;

/**
 * Class AltTextAjaxController.
 * 
 * This controller handles AJAX requests for generating and storing alternative text for images.
 * It uses the OpenAI API to generate the alternative text based on the image content.
 */
class AltTextAjaxController extends ActionController
{
    use AllowedMethodsTrait;

    /**
     * Constructor.
     * 
     * @param AltTextGeneratorService $altTextGeneratorService
     * @param HashService $hashService
     * @param SiteLanguageService $siteLanguageService
     * @param ResourceFactory $resourceFactory
     * @param ConnectionPool $connectionPool
     */
    public function __construct(
        protected readonly AltTextGeneratorService $altTextGeneratorService,
        protected HashService $hashService,
        protected readonly SiteLanguageService $siteLanguageService,
        protected readonly ResourceFactory $resourceFactory,
        protected readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * Assert allowed HTTP method for the generate action.
     * 
     * @throws MethodNotAllowedException If the request method is not allowed.
     */
    protected function initializeGenerateAction(): void
    {
        $this->assertAllowedHttpMethod($this->request, 'POST');
    }

    /**
     * Generate alternative text for an image.
     * 
     * This action handles the AJAX request to generate alternative text for a given image and
     * returns the generated text as a JSON response.
     * 
     * @param ServerRequestInterface $request
     * 
     * @return ResponseInterface
     * 
     * @throws InvalidArgumentException If the request parameters are invalid.
     * @throws InvalidArgumentException If the file table is invalid.
     * @throws MethodNotAllowedException If the request method is not allowed.
     * @throws FileDoesNotExistException If the file does not exist.
     */
    public function generateAction(ServerRequestInterface $request): ResponseInterface
    {
        $requestBody = $request->getParsedBody();
        $demand = new AltTextDemand(
            (int)$requestBody['pageUid'] ?? 0,
            (int)$requestBody['languageUid'] ?? 0,
            (int)$requestBody['fileUid'] ?? 0,
            $requestBody['signature'] ?? '',
        );

        if (!$demand->validateSignature()) {
            throw new InvalidArgumentException(
                'Invalid request parameters.',
                1744477154
            );
        }

        $languageCode = $this->siteLanguageService->getLanguageCode($demand->getLanguageUid(), $demand->getPageUid());
        $file = $this->resourceFactory->getFileObject($demand->getFileUid());

        try {
            $altText = $this->altTextGeneratorService->generate($file, $languageCode);
        } catch (Exception $e) {
            return $this->jsonResponse(
                json_encode([
                    'error' => [
                        'title' => LocalizationUtility::translate('LLL:EXT:mindfula11y/Resources/Private/Language/GenerateAltText.xlf:error.openAIConnection'),
                        'description' => LocalizationUtility::translate('LLL:EXT:mindfula11y/Resources/Private/Language/GenerateAltText.xlf:error.openAIConnection.description'),
                    ]
                ])
            )->withStatus(500);
        }

        return $this->jsonResponse(json_encode(['altText' => $altText]))->withStatus(201);
    }

    /**
     * Get backend user authentication.
     * 
     * @return BackendUserAuthentication
     */
    protected function getBackendUserAuthentication(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }
}
