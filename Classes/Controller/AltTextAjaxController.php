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
use MindfulMarkup\MindfulA11y\Domain\Model\GenerateAltTextDemand;
use MindfulMarkup\MindfulA11y\Service\AltTextGeneratorService;
use MindfulMarkup\MindfulA11y\Service\PermissionService;
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
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
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
     * @param PermissionService $permissionService
     */
    public function __construct(
        protected readonly AltTextGeneratorService $altTextGeneratorService,
        protected HashService $hashService,
        protected readonly SiteLanguageService $siteLanguageService,
        protected readonly ResourceFactory $resourceFactory,
        protected readonly ConnectionPool $connectionPool,
        protected readonly PermissionService $permissionService,
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
        $demand = new GenerateAltTextDemand(
            (int)$requestBody['userId'] ?? 0,
            (int)$requestBody['pageUid'] ?? 0,
            (int)$requestBody['languageUid'] ?? 0,
            (int)$requestBody['workspaceId'] ?? 0,
            $requestBody['recordTable'] ?? '',
            (int)$requestBody['recordUid'] ?? 0,
            $requestBody['recordColumns'] ?? [],
            $requestBody['signature'] ?? '',
        );

        if (!$demand->validateSignature()) {
            return $this->jsonResponse(
                json_encode([
                    'error' => [
                        'title' => LocalizationUtility::translate('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:module.error.invalidSignature'),
                        'description' => LocalizationUtility::translate('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:module.error.invalidSignature.description'),
                    ]
                ])
            )->withStatus(400);
        }

        $backendUser = $this->getBackendUserAuthentication();

        // Check if user has access to the mindfula11y_accessibility module
        if (!$backendUser->check('modules', 'mindfula11y_accessibility')) {
            return $this->jsonResponse(
                json_encode([
                    'error' => [
                        'title' => LocalizationUtility::translate('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:error.forbidden'),
                        'description' => LocalizationUtility::translate('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:error.forbidden.description'),
                    ]
                ])
            )->withStatus(403);
        }

        $userId = $demand->getUserId();
        $pageUid = $demand->getPageUid();
        $languageUid = $demand->getLanguageUid();
        $workspaceId = $demand->getWorkspaceId();

        // Verify the current backend user matches the demand
        if ($backendUser->user['uid'] !== $userId) {
            return $this->jsonResponse(
                json_encode([
                    'error' => [
                        'title' => LocalizationUtility::translate('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:error.invalidUser'),
                        'description' => LocalizationUtility::translate('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:error.invalidUser.description'),
                    ]
                ])
            )->withStatus(403);
        }

        // Verify the current workspace matches the demand
        if ($backendUser->workspace !== $workspaceId) {
            return $this->jsonResponse(
                json_encode([
                    'error' => [
                        'title' => LocalizationUtility::translate('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:error.invalidWorkspace'),
                        'description' => LocalizationUtility::translate('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:error.invalidWorkspace.description'),
                    ]
                ])
            )->withStatus(403);
        }

        // Check language access
        if (!$this->permissionService->checkLanguageAccess($languageUid)) {
            return $this->jsonResponse(
                json_encode([
                    'error' => [
                        'title' => LocalizationUtility::translate('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:error.invalidLanguage'),
                        'description' => LocalizationUtility::translate('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:error.invalidLanguage.description'),
                    ]
                ])
            )->withStatus(403);
        }

        // Check if user has access to the page
        $pageInfo = BackendUtility::readPageAccess($pageUid, $backendUser->getPagePermsClause(Permission::PAGE_SHOW));
        if (false === $pageInfo) {
            return $this->jsonResponse(
                json_encode([
                    'error' => [
                        'title' => LocalizationUtility::translate('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:error.noPageAccess'),
                        'description' => LocalizationUtility::translate('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:error.noPageAccess.description'),
                    ]
                ])
            )->withStatus(403);
        }

        // Check if user has read access to sys_file
        if (!$this->permissionService->checkTableReadAccess('sys_file')) {
            return $this->jsonResponse(
                json_encode([
                    'error' => [
                        'title' => LocalizationUtility::translate('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:error.noFileAccess'),
                        'description' => LocalizationUtility::translate('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:error.noFileAccess.description'),
                    ]
                ])
            )->withStatus(403);
        }

        // Check if user has edit access to the record
        $recordTable = $demand->getRecordTable();
        $recordUid = $demand->getRecordUid();
        $recordColumns = $demand->getRecordColumns();
        $recordData = BackendUtility::getRecordWSOL($recordTable, $recordUid);

        if (!$this->permissionService->checkRecordEditAccess($recordTable, $recordData, $recordColumns)) {
            return $this->jsonResponse(
                json_encode([
                    'error' => [
                        'title' => LocalizationUtility::translate('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:error.invalidRecordAccess'),
                        'description' => LocalizationUtility::translate('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:error.invalidRecordAccess.description'),
                    ]
                ])
            )->withStatus(403);
        }

        $languageCode = $this->siteLanguageService->getLanguageCode($demand->getLanguageUid(), $demand->getPageUid());
        
        if ('sys_file_reference' === $recordTable) {
            $fileUid = (int)$recordData['uid_local'][0]['uid'];
        } elseif ('sys_file_metadata' === $recordTable) {
            $fileUid = (int)$recordData['file'][0];
        }

        $file = $this->resourceFactory->getFileObject($fileUid);

        $altText = $this->altTextGeneratorService->generate($file, $languageCode);

        if (null === $altText) {
            return $this->jsonResponse(
                json_encode([
                    'error' => [
                        'title' => LocalizationUtility::translate('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:altText.generate.error.openAIConnection'),
                        'description' => LocalizationUtility::translate('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:altText.generate.error.openAIConnection.description'),
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
