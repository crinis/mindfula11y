<?php

declare(strict_types=1);

/*
 * Mindful A11y extension for TYPO3 integrating accessibility tools into the backend.
 * Copyright (C) 2025  Mindful Markup, Felix Spittel
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the General Public License as published by
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

use InvalidArgumentException;
use MindfulMarkup\MindfulA11y\Domain\Model\CreateScanDemand;
use MindfulMarkup\MindfulA11y\Service\ScanApiService;
use MindfulMarkup\MindfulA11y\Service\GeneralModuleService;
use MindfulMarkup\MindfulA11y\Service\PermissionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\AllowedMethodsTrait;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Core\Http\Error\MethodNotAllowedException;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Versioning\VersionState;

/**
 * Class ScanAjaxController.
 *
 * This controller handles AJAX requests for the accessibility scanner feature.
 */
class ScanAjaxController extends ActionController
{
    use AllowedMethodsTrait;

    /**
     * Constructor.
     *
     * @param ScanApiService $scanApiService The accessibility scanner service.
     * @param GeneralModuleService $generalModuleService The general module service.
     * @param PermissionService $permissionService The permission service.
     * @param ConnectionPool $connectionPool The connection pool.
     */
    public function __construct(
        protected readonly ScanApiService $scanApiService,
        protected readonly GeneralModuleService $generalModuleService,
        protected readonly PermissionService $permissionService,
        protected readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * Assert allowed HTTP method for the createScan action.
     *
     * @throws MethodNotAllowedException If the request method is not allowed.
     */
    protected function initializeCreateAction(): void
    {
        $this->assertAllowedHttpMethod($this->request, 'POST');
    }

    /**
     * Assert allowed HTTP method for the getResult action.
     *
     * @throws MethodNotAllowedException If the request method is not allowed.
     */
    protected function initializeGetAction(): void
    {
        $this->assertAllowedHttpMethod($this->request, 'GET');
    }

    /**
     * Create a new accessibility scan for a page.
     *
     * @param ServerRequestInterface $request
     *
     * @return ResponseInterface
     *
     * @throws InvalidArgumentException If the request parameters are invalid.
     * @throws MethodNotAllowedException If the request method is not allowed.
     */
    public function createAction(ServerRequestInterface $request): ResponseInterface
    {
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

        $requestBody = $request->getParsedBody();
        // Basic input validation to avoid TypeError in CreateScanDemand constructor
        $userId = isset($requestBody['userId']) ? (int)$requestBody['userId'] : 0;
        $pageId = isset($requestBody['pageId']) ? (int)$requestBody['pageId'] : 0;
        $previewUrl = $requestBody['previewUrl'] ?? '';
        $languageId = isset($requestBody['languageId']) ? (int)$requestBody['languageId'] : 0;
        $workspaceId = isset($requestBody['workspaceId']) ? (int)$requestBody['workspaceId'] : 0;
        $signature = $requestBody['signature'] ?? '';

        if ($userId <= 0 || $pageId <= 0 || !is_string($signature) || $signature === '' || !is_string($previewUrl) || $previewUrl === '') {
            throw new InvalidArgumentException('Missing or invalid parameters for creating a scan');
        }

        $demand = new CreateScanDemand($userId, $pageId, $previewUrl, $languageId, $workspaceId, $signature);

        if (!$demand->validateSignature()) {
            return $this->jsonResponse(
                json_encode([
                    'error' => [
                        'title' => LocalizationUtility::translate('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:error.invalidSignature'),
                        'description' => LocalizationUtility::translate('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:error.invalidSignature.description'),
                    ]
                ])
            )->withStatus(400);
        }

        $backendUser = $this->getBackendUserAuthentication();
        $userId = $demand->getUserId();
        $pageId = $demand->getPageId();
        $languageId = $demand->getLanguageId();
        $workspaceId = $demand->getWorkspaceId();
        $previewUrl = $demand->getPreviewUrl();

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
        if (!$backendUser->checkLanguageAccess($languageId)) {
            return $this->jsonResponse(
                json_encode([
                    'error' => [
                        'title' => LocalizationUtility::translate('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:error.invalidLanguage'),
                        'description' => LocalizationUtility::translate('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:error.invalidLanguage.description'),
                    ]
                ])
            )->withStatus(403);
        }

        // Check TSConfig access for scan feature
        $pageTsConfig = $this->generalModuleService->getConvertedPageTsConfig($pageId);
        if (!$this->generalModuleService->hasScanAccess($pageTsConfig)) {
            return $this->jsonResponse(
                json_encode([
                    'error' => [
                        'title' => LocalizationUtility::translate('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.noAccess'),
                        'description' => LocalizationUtility::translate('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.noAccess.description'),
                    ]
                ])
            )->withStatus(403);
        }

        // Check if scanner is configured
        if (!$this->scanApiService->isConfigured()) {
            return $this->jsonResponse(
                json_encode([
                    'error' => [
                        'title' => LocalizationUtility::translate('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.error.notConfigured'),
                        'description' => LocalizationUtility::translate('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.error.notConfigured.description'),
                    ]
                ])
            )->withStatus(500);
        }

        $page = BackendUtility::getRecordWSOL('pages', $pageId);

        if (null === $page || VersionState::tryFrom((int)$page['t3ver_state']) === VersionState::DELETE_PLACEHOLDER) {
            return $this->jsonResponse(
                json_encode([
                    'error' => [
                        'title' => LocalizationUtility::translate('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.error.pageNotFound'),
                        'description' => LocalizationUtility::translate('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.error.pageNotFound.description'),
                    ]
                ])
            )->withStatus(404);
        }

        // Verify the current backend user actually has access to the page
        if (!$this->permissionService->checkRecordEditAccess('pages', $page) || !$this->permissionService->checkNonExcludeFields('pages', ['tx_mindfula11y_scanid', 'tx_mindfula11y_scanupdated'])) {
            return $this->jsonResponse(
                json_encode([
                    'error' => [
                        'title' => LocalizationUtility::translate('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:error.invalidPageAccess'),
                        'description' => LocalizationUtility::translate('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:error.invalidPageAccess.description'),
                    ]
                ])
            )->withStatus(403);
        }

        // Create scan
        $scanData = $this->scanApiService->createScan($previewUrl);

        if (null === $scanData) {
            return $this->jsonResponse(
                json_encode([
                    'error' => [
                        'title' => LocalizationUtility::translate('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.error.createFailed'),
                        'description' => LocalizationUtility::translate('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.error.createFailed.description'),
                    ]
                ])
            )->withStatus(500);
        }

        // Store scan ID in database
        if (!$this->storeScanId($page['uid'], (string)$scanData['id'])) {
            return $this->jsonResponse(
                json_encode([
                    'error' => [
                        'title' => LocalizationUtility::translate('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.error.storeFailed'),
                        'description' => LocalizationUtility::translate('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.error.storeFailed.description'),
                    ]
                ])
            )->withStatus(500);
        }

        return $this->jsonResponse(json_encode([
            'scanId' => $scanData['id'],
            'status' => $scanData['status'] ?? 'pending',
        ]))->withStatus(201);
    }

    /**
     * Get scan results by scan ID.
     *
     * @param ServerRequestInterface $request
     *
     * @return ResponseInterface
     *
     * @throws MethodNotAllowedException If the request method is not allowed.
     */
    public function getAction(ServerRequestInterface $request): ResponseInterface
    {
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

        $queryParams = $request->getQueryParams();
        $scanId = $queryParams['scanId'] ?? '';

        if (empty($scanId)) {
            return $this->jsonResponse(
                json_encode([
                    'error' => [
                        'title' => LocalizationUtility::translate('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.error.noScanId'),
                        'description' => LocalizationUtility::translate('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.error.noScanId.description'),
                    ]
                ])
            )->withStatus(404);
        }

        // Get scan
        $scan = $this->scanApiService->get($scanId);

        if (null === $scan) {
            return $this->jsonResponse(
                json_encode([
                    'error' => [
                        'title' => LocalizationUtility::translate('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.error.getFailed'),
                        'description' => LocalizationUtility::translate('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.error.getFailed.description'),
                    ]
                ])
            )->withStatus(500);
        }

        return $this->jsonResponse(json_encode($scan))->withStatus(200);
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

    /**
     * Store scan ID in database using DataHandler.
     *
     * @param int $pageUid The page UID.
     * @param string $scanId The scan ID.
     *
     * @return bool True if the update was successful, false otherwise.
     */
    protected function storeScanId(int $pageUid, string $scanId): bool
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([
            'pages' => [
                $pageUid => [
                    'tx_mindfula11y_scanid' => $scanId,
                    'tx_mindfula11y_scanupdated' => time(),
                ]
            ]
        ], []);

        $dataHandler->process_datamap();

        // Check if there were any errors
        if (!empty($dataHandler->errorLog)) {
            return false;
        }

        return true;
    }

    /**
     * Get scan ID from database.
     *
     * @param int $pageUid The page UID.
     *
     * @return string|null The scan ID or null if not found.
     */
    protected function getScanId(int $pageUid): ?string
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $result = $queryBuilder
            ->select('tx_mindfula11y_scanid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT))
            )
            ->executeQuery()
            ->fetchOne();

        return $result ?: null;
    }
}
