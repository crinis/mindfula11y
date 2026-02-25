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
use MindfulMarkup\MindfulA11y\Service\SiteLanguageService;
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
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Versioning\VersionState;
use TYPO3\CMS\Core\Site\SiteFinder;

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
     * @param SiteLanguageService $siteLanguageService The site language service.
     */
    public function __construct(
        protected readonly ScanApiService $scanApiService,
        protected readonly GeneralModuleService $generalModuleService,
        protected readonly PermissionService $permissionService,
        protected readonly ConnectionPool $connectionPool,
        protected readonly SiteFinder $siteFinder,
        protected readonly SiteLanguageService $siteLanguageService,
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
     * Assert allowed HTTP method for the report action.
     *
     * @throws MethodNotAllowedException If the request method is not allowed.
     */
    protected function initializeReportAction(): void
    {
        $this->assertAllowedHttpMethod($this->request, 'GET');
    }

    /**
     * Stream an HTML or PDF accessibility report for a scan.
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     * @throws MethodNotAllowedException If the request method is not allowed.
     */
    public function reportAction(ServerRequestInterface $request): ResponseInterface
    {
        $backendUser = $this->getBackendUserAuthentication();

        if ($error = $this->requireModuleAccess($backendUser)) {
            return $error;
        }

        $queryParams = $request->getQueryParams();
        $scanId = $queryParams['scanId'] ?? '';
        $format = $queryParams['format'] ?? '';

        if (empty($scanId)) {
            return $this->jsonResponse(json_encode([
                'error' => ['title' => LocalizationUtility::translate('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.error.noScanId')]
            ]))->withStatus(404);
        }

        if (!in_array($format, ['html', 'pdf'], true)) {
            return $this->jsonResponse(json_encode([
                'error' => ['title' => LocalizationUtility::translate('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.error.reportFormat')]
            ]))->withStatus(400);
        }

        $pageRecord = $this->requireScanPageAccess($scanId);
        if ($pageRecord instanceof ResponseInterface) {
            return $pageRecord;
        }

        $body = $this->scanApiService->getReport($scanId, $format);
        if (null === $body) {
            return $this->jsonResponse(json_encode([
                'error' => [
                    'title' => LocalizationUtility::translate('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.error.reportFailed'),
                    'description' => LocalizationUtility::translate('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.error.reportFailed.description'),
                ]
            ]))->withStatus(500);
        }

        $contentType = $format === 'pdf' ? 'application/pdf' : 'text/html; charset=utf-8';
        $disposition = $format === 'pdf' ? 'attachment' : 'inline';
        $filename = 'accessibility-report.' . $format;

        $response = $this->responseFactory->createResponse();
        $response->getBody()->write($body);
        $response = $response
            ->withHeader('Content-Type', $contentType)
            ->withHeader('Content-Disposition', $disposition . '; filename="' . $filename . '"');

        // Prevent scripts in HTML reports from running at the TYPO3 backend origin.
        if ($format === 'html') {
            $response = $response->withHeader(
                'Content-Security-Policy',
                "default-src 'none'; style-src 'unsafe-inline'; img-src data: https:; font-src 'self' data:"
            );
        }

        return $response;
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

        if ($error = $this->requireModuleAccess($backendUser)) {
            return $error;
        }

        $requestBody = json_decode((string)$request->getBody(), true) ?? [];
        // Basic input validation to avoid TypeError in CreateScanDemand constructor
        $userId = isset($requestBody['userId']) ? (int)$requestBody['userId'] : 0;
        $pageId = isset($requestBody['pageId']) ? (int)$requestBody['pageId'] : 0;
        $previewUrl = $requestBody['previewUrl'] ?? '';
        $languageId = isset($requestBody['languageId']) ? (int)$requestBody['languageId'] : 0;
        $workspaceId = isset($requestBody['workspaceId']) ? (int)$requestBody['workspaceId'] : 0;
        $pageLevels = isset($requestBody['pageLevels']) ? (int)$requestBody['pageLevels'] : 0;
        $crawl = (bool)($requestBody['crawl'] ?? false);
        $signature = $requestBody['signature'] ?? '';

        if ($userId <= 0 || $pageId <= 0 || !is_string($signature) || $signature === '' || !is_string($previewUrl) || $previewUrl === '') {
            throw new InvalidArgumentException('Missing or invalid parameters for creating a scan');
        }

        $demand = new CreateScanDemand($userId, $pageId, $previewUrl, $languageId, $workspaceId, $pageLevels, $crawl, $signature);

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
        if (!$this->permissionService->checkLanguageAccess($languageId)) {
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

        if ($languageId > 0) {
            $localizedPage = $this->generalModuleService->getLocalizedPageRecord($pageId, $languageId);
            if ($localizedPage) {
                $page = $localizedPage;
            } else {
                return $this->jsonResponse(
                    json_encode([
                        'error' => [
                            'title' => LocalizationUtility::translate('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.error.pageNotFound'),
                            'description' => LocalizationUtility::translate('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.error.pageNotFound.description'),
                        ]
                    ])
                )->withStatus(404);
            }
        }

        // Verify the current backend user actually has access to the page
        if (!$this->permissionService->checkRecordEditAccess('pages', $page) || !$this->permissionService->checkNonExcludeFields('pages', ['tx_mindfula11y_scanid', 'tx_mindfula11y_scanupdated'])) {
            return $this->jsonResponse(
                json_encode([
                    'error' => [
                        'title' => LocalizationUtility::translate('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:error.noPageAccess'),
                        'description' => LocalizationUtility::translate('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:error.noPageAccess.description'),
                    ]
                ])
            )->withStatus(403);
        }

        // Check if page is visible (not hidden and within start/end time)
        if (!$this->generalModuleService->isPageVisible($page)) {
            return $this->jsonResponse(
                json_encode([
                    'error' => [
                        'title' => LocalizationUtility::translate('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.error.pageVisible'),
                        'description' => LocalizationUtility::translate('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.error.pageVisible.description'),
                    ]
                ])
            )->withStatus(403);
        }

        // Generate scan URLs
        $pageLevels = $demand->getPageLevels();
        $crawl = $demand->getCrawl();
        $scanUrls = [];

        if ($crawl) {
            // For crawl mode the API discovers pages from the start URL - only valid on site root pages
            if (!(bool)($page['is_siteroot'] ?? false)) {
                return $this->jsonResponse(
                    json_encode([
                        'error' => [
                            'title' => LocalizationUtility::translate('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.error.crawlNotRootPage'),
                            'description' => LocalizationUtility::translate('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.error.crawlNotRootPage.description'),
                        ]
                    ])
                )->withStatus(403);
            }
            $scanUrls = [$previewUrl];
        } else {
            if ($pageLevels > 0) {
                $scanUrls = $this->generalModuleService->generatePageUrls($pageId, $languageId, $pageLevels);
            }
            // Always include the current page's preview URL
            if (empty($scanUrls)) {
                $scanUrls = [$previewUrl];
            }
        }

        // Create scan
        $crawlOptions = [];
        if ($crawl) {
            // Restrict the crawl to the selected language's URL space via a glob pattern.
            // The base URL is derived from the TYPO3 site configuration — not user-supplied input.
            $base = $this->siteLanguageService->getAbsoluteLanguageBase($pageId, $languageId);
            if ($base !== null) {
                $crawlOptions['globs'] = [$base . '/**'];
            }
        }

        // Read basic auth credentials from PageTS (server-side only, never from client input).
        $scanOptions = [];
        $basicAuth = $this->generalModuleService->getScanBasicAuth($pageTsConfig);
        if ($basicAuth !== null) {
            $scanOptions['basicAuth'] = $basicAuth;
        }

        $scanData = $this->scanApiService->createScan($scanUrls, $crawl, $crawlOptions, $scanOptions);

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

        if ($error = $this->requireModuleAccess($backendUser)) {
            return $error;
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

        $pageRecord = $this->requireScanPageAccess($scanId);
        if ($pageRecord instanceof ResponseInterface) {
            return $pageRecord;
        }

        // Get scan with optional page URL filter
        $pageUrls = $this->extractPageUrls($request);
        // Sanitize: only allow valid URL strings
        $pageUrls = array_values(array_filter($pageUrls, function ($url) {
            return is_string($url) && filter_var($url, FILTER_VALIDATE_URL) !== false;
        }));

        // Finding 3 fix: restrict pageUrls to the site's own base URLs to prevent
        // unintended filter parameters being forwarded to the external scanner API
        if (!empty($pageUrls)) {
            try {
                $site = $this->siteFinder->getSiteByPageId((int)$pageRecord['uid']);
                $allowedBases = [];
                foreach ($site->getLanguages() as $language) {
                    $base = rtrim((string)$language->getBase(), '/');
                    if ($base !== '') {
                        $allowedBases[] = $base;
                    }
                }
                $siteBase = rtrim((string)$site->getBase(), '/');
                if ($siteBase !== '' && !in_array($siteBase, $allowedBases, true)) {
                    $allowedBases[] = $siteBase;
                }
                $pageUrls = array_values(array_filter($pageUrls, static function (string $url) use ($allowedBases): bool {
                    foreach ($allowedBases as $base) {
                        if (str_starts_with($url, $base . '/') || $url === $base) {
                            return true;
                        }
                    }
                    return false;
                }));
            } catch (\Exception $e) {
                $pageUrls = [];
            }
        }

        $scan = $this->scanApiService->getScan($scanId, $pageUrls);

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
     * Extract page URL filters from the request query.
     *
     * Supports repeated OpenAPI query keys (`pageUrls=https://a&pageUrls=https://b`).
     *
     * @param ServerRequestInterface $request
     * @return string[]
     */
    private function extractPageUrls(ServerRequestInterface $request): array
    {
        $pageUrls = [];
        $queryParams = $request->getQueryParams();

        $value = $queryParams['pageUrls'] ?? null;
        if (is_array($value)) {
            $pageUrls = array_merge($pageUrls, $value);
        } elseif (is_string($value) && $value !== '') {
            $pageUrls[] = $value;
        }

        // Parse raw query manually to preserve repeated pageUrls keys.
        $rawQuery = (string)$request->getUri()->getQuery();
        if ($rawQuery !== '') {
            foreach (explode('&', $rawQuery) as $pair) {
                if ($pair === '') {
                    continue;
                }

                [$rawName, $rawValue] = array_pad(explode('=', $pair, 2), 2, '');
                $name = urldecode($rawName);
                if ($name !== 'pageUrls') {
                    continue;
                }

                $value = urldecode($rawValue);
                if ($value !== '') {
                    $pageUrls[] = $value;
                }
            }
        }

        return array_values(array_unique($pageUrls));
    }

    /**
     * Returns a 403 response if the current backend user lacks module access, null otherwise.
     *
     * @param BackendUserAuthentication $backendUser
     * @return ResponseInterface|null
     */
    private function requireModuleAccess(BackendUserAuthentication $backendUser): ?ResponseInterface
    {
        if ($backendUser->check('modules', 'mindfula11y_accessibility')) {
            return null;
        }
        return $this->jsonResponse(json_encode([
            'error' => [
                'title' => LocalizationUtility::translate('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:error.forbidden'),
                'description' => LocalizationUtility::translate('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:error.forbidden.description'),
            ]
        ]))->withStatus(403);
    }

    /**
     * Verifies that a scan ID maps to a page the current user may access, and that TSConfig
     * allows scan access for that page. Returns the page record on success, or a
     * ResponseInterface error response on failure.
     *
     * @param string $scanId
     * @return array|ResponseInterface Page record array on success, error response on failure.
     */
    private function requireScanPageAccess(string $scanId): array|ResponseInterface
    {
        $pageRecord = $this->generalModuleService->getPageRecordByScanId($scanId);
        if (null === $pageRecord) {
            return $this->jsonResponse(json_encode([
                'error' => [
                    'title' => LocalizationUtility::translate('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.error.notFound'),
                    'description' => LocalizationUtility::translate('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.error.notFound.description'),
                ]
            ]))->withStatus(404);
        }

        if (!$this->permissionService->checkPageReadAccess($pageRecord)) {
            return $this->jsonResponse(json_encode([
                'error' => [
                    'title' => LocalizationUtility::translate('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.error.accessDenied'),
                    'description' => LocalizationUtility::translate('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.error.accessDenied.description'),
                ]
            ]))->withStatus(403);
        }

        $pageTsConfig = $this->generalModuleService->getConvertedPageTsConfig((int)$pageRecord['uid']);
        if (!$this->generalModuleService->hasScanAccess($pageTsConfig)) {
            return $this->jsonResponse(json_encode([
                'error' => [
                    'title' => LocalizationUtility::translate('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.noAccess'),
                    'description' => LocalizationUtility::translate('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:scan.noAccess.description'),
                ]
            ]))->withStatus(403);
        }

        return $pageRecord;
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
