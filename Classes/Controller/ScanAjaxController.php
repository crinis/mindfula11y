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
use MindfulMarkup\MindfulA11y\Service\ScanService;
use MindfulMarkup\MindfulA11y\Service\GeneralModuleService;
use MindfulMarkup\MindfulA11y\Service\PermissionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
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
     * @param ScanService $scanService The accessibility scanner service.
     * @param GeneralModuleService $generalModuleService The general module service.
     * @param PermissionService $permissionService The permission service.
     * @param ConnectionPool $connectionPool The connection pool.
     */
    public function __construct(
        protected readonly ScanService $scanService,
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
        $requestBody = $request->getParsedBody();
        $demand = new CreateScanDemand(
            (int)$requestBody['pageUid'],
            $requestBody['signature'],
            $requestBody['previewUrl']
        );

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

        $pageUid = $demand->getPageUid();
        $previewUrl = $demand->getPreviewUrl();

        // Check if scanner is configured
        if (!$this->scanService->isConfigured()) {
            return $this->jsonResponse(
                json_encode([
                    'error' => [
                        'title' => LocalizationUtility::translate('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.error.notConfigured'),
                        'description' => LocalizationUtility::translate('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.error.notConfigured.description'),
                    ]
                ])
            )->withStatus(500);
        }

        // Create scan
        $scanData = $this->scanService->createScan($previewUrl);

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
        if (!$this->storeScanId($pageUid, (string)$scanData['id'])) {
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
        $scan = $this->scanService->get($scanId);

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