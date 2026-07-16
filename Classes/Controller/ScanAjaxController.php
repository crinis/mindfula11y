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

use MindfulMarkup\MindfulA11y\Domain\Model\CreateScanDemand;
use MindfulMarkup\MindfulA11y\Exception\ScanApiRequestException;
use MindfulMarkup\MindfulA11y\Exception\ScanCreationException;
use MindfulMarkup\MindfulA11y\Service\ScanApiService;
use MindfulMarkup\MindfulA11y\Service\BackendUserProvider;
use MindfulMarkup\MindfulA11y\Service\ModuleSettingsService;
use MindfulMarkup\MindfulA11y\Service\PagePreviewService;
use MindfulMarkup\MindfulA11y\Service\PermissionService;
use MindfulMarkup\MindfulA11y\Service\ScanCreationService;
use MindfulMarkup\MindfulA11y\Service\ScanStateService;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Versioning\VersionState;
use MindfulMarkup\MindfulA11y\Service\SiteLanguageService;

/**
 * Handles the AJAX endpoints of the accessibility-scanner feature.
 *
 * Allowed HTTP methods are enforced on the route definitions
 * (Configuration/Backend/AjaxRoutes.php and Routes.php).
 */
final readonly class ScanAjaxController
{
    use JsonErrorResponseTrait;
    use AjaxGuardTrait;

    public function __construct(
        private ScanApiService $scanApiService,
        private ModuleSettingsService $moduleSettingsService,
        private PagePreviewService $pagePreviewService,
        private ScanStateService $scanStateService,
        private PermissionService $permissionService,
        private SiteLanguageService $siteLanguageService,
        private ScanCreationService $scanCreationService,
        private ResponseFactoryInterface $responseFactory,
        private BackendUserProvider $backendUserProvider,
    ) {}

    /**
     * Stream an HTML or PDF accessibility report for a scan.
     */
    public function reportAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($error = $this->requireModuleAccess()) {
            return $error;
        }

        $queryParams = $request->getQueryParams();
        $scanId = $queryParams['scanId'] ?? '';
        $format = $queryParams['format'] ?? '';

        if (empty($scanId)) {
            return $this->errorResponse('scan.error.noScanId', 404);
        }

        if (!in_array($format, ['html', 'pdf'], true)) {
            return $this->errorResponse('scan.error.reportFormat', 400);
        }

        $pageRecord = $this->requireScanPageAccess($scanId);
        if ($pageRecord instanceof ResponseInterface) {
            return $pageRecord;
        }

        $body = $this->scanApiService->getReport($scanId, $format);
        if (null === $body) {
            return $this->errorResponse('scan.error.reportFailed', 500);
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
     * Create a new accessibility scan for a page.
     *
     */
    public function createAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($error = $this->requireModuleAccess()) {
            return $error;
        }

        $requestBody = $this->parseJsonBody($request);
        $demand = CreateScanDemand::fromRequestData($requestBody);
        // Editor choice riding alongside the signed demand fields: authorization
        // happens via Page TSconfig below, so it needs no HMAC coverage.
        $aiAuditRequested = (bool)($requestBody['aiAudit'] ?? false);

        if ($demand === null) {
            return $this->errorResponse('error.invalidRequest', 400);
        }

        if (!$demand->validateSignature()) {
            return $this->errorResponse('module.error.invalidSignature', 400);
        }

        $pageId = $demand->getPageId();
        $languageId = $demand->getLanguageId();
        $workspaceId = $demand->getWorkspaceId();

        if ($error = $this->requireDemandSession($demand->getUserId(), $workspaceId, $languageId)) {
            return $error;
        }

        // Scans are also a live-workspace feature: the external scanner cannot
        // fetch workspace previews, and storing the scan id must not version
        // the page record.
        if ($workspaceId !== 0) {
            return $this->errorResponse('error.invalidWorkspace', 403);
        }

        // Check TSConfig access for scan feature
        $pageTsConfig = $this->moduleSettingsService->getConvertedPageTsConfig($pageId);
        if (!$this->moduleSettingsService->hasScanAccess($pageTsConfig)) {
            return $this->errorResponse('scan.noAccess', 403);
        }

        // The AI audit is opt-in via Page TSconfig. MindfulAPI owns skill
        // selection and applies its server-side whitelist.
        $aiAuditSkills = null;
        if ($aiAuditRequested) {
            if (!$this->moduleSettingsService->hasAiAuditAccess($pageTsConfig)) {
                return $this->errorResponse('scan.error.aiAuditNotAllowed', 403);
            }
            $aiAuditSkills = $this->moduleSettingsService->getAiAuditSkills($pageTsConfig);
        }

        $page = BackendUtility::getRecordWSOL('pages', $pageId);

        if (null === $page || VersionState::tryFrom((int)$page['t3ver_state']) === VersionState::DELETE_PLACEHOLDER) {
            return $this->errorResponse('scan.error.pageNotFound', 404);
        }

        if ($languageId > 0) {
            $localizedPage = $this->pagePreviewService->getLocalizedPageRecord($pageId, $languageId);
            if ($localizedPage) {
                $page = $localizedPage;
            } else {
                return $this->errorResponse('scan.error.pageNotFound', 404);
            }
        }

        // Verify the current backend user actually has access to the page
        if (!$this->permissionService->checkRecordEditAccess('pages', $page)) {
            return $this->errorResponse('error.noPageAccess', 403);
        }

        // Check if page is visible (not hidden and within start/end time)
        if (!$this->pagePreviewService->isPageVisible($page)) {
            return $this->errorResponse('scan.error.pageVisible', 403);
        }

        try {
            $result = $this->scanCreationService->create($demand, $page, $pageTsConfig, $aiAuditRequested, $aiAuditSkills);
        } catch (ScanCreationException $exception) {
            return $this->errorResponse($exception->labelKey, $exception->statusCode, $exception->description);
        }

        return new JsonResponse($result, 201);
    }

    /**
     * Get scan results by scan ID.
     */
    public function getAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($error = $this->requireModuleAccess()) {
            return $error;
        }

        $queryParams = $request->getQueryParams();
        $scanId = $queryParams['scanId'] ?? '';

        if (empty($scanId)) {
            return $this->errorResponse('scan.error.noScanId', 404);
        }

        $pageRecord = $this->requireScanPageAccess($scanId);
        if ($pageRecord instanceof ResponseInterface) {
            return $pageRecord;
        }

        // Get scan with optional page URL filter
        $pageUrls = $this->extractPageUrls($request);
        // Sanitize: only allow valid URL strings
        $pageUrls = array_values(array_filter($pageUrls, fn(string $url): bool => filter_var($url, FILTER_VALIDATE_URL) !== false));

        // Only forward filters within this site's configured language bases.
        $pageUrls = $this->siteLanguageService->filterUrlsToSiteBases($pageUrls, (int)$pageRecord['uid']);

        try {
            $scan = $this->scanApiService->getScan($scanId, $pageUrls);
        } catch (ScanApiRequestException) {
            // The scanner no longer knows the id (retention pruning). 404 is
            // the client's signal to forget the stored id and re-create the
            // scan — a 500 would strand it on the loading-error view.
            return $this->errorResponse('scan.error.notFound', 404);
        }

        if (null === $scan) {
            return $this->errorResponse('scan.error.getFailed', 500);
        }

        return new JsonResponse($scan, 200);
    }

    /**
     * Cancel a running scan.
     *
     * Requires the same page edit access as creating a scan, since canceling
     * mutates the scan state.
     */
    public function cancelAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($error = $this->requireModuleAccess()) {
            return $error;
        }

        $requestBody = $this->parseJsonBody($request);
        $scanId = $requestBody['scanId'] ?? '';

        if (!is_string($scanId) || $scanId === '') {
            return $this->errorResponse('scan.error.noScanId', 404);
        }

        $pageRecord = $this->requireScanPageAccess($scanId);
        if ($pageRecord instanceof ResponseInterface) {
            return $pageRecord;
        }

        if (!$this->permissionService->checkRecordEditAccess('pages', $pageRecord)) {
            return $this->errorResponse('error.noPageAccess', 403);
        }

        try {
            $scanData = $this->scanApiService->cancelScan($scanId);
        } catch (ScanApiRequestException $exception) {
            // 409 = scan already terminal; the client resolves it by reloading.
            $status = $exception->getStatusCode() === 409 ? 409 : 500;
            $description = $exception->getProblemDetail() !== '' ? $exception->getProblemDetail() : null;
            return $this->errorResponse('scan.error.cancelFailed', $status, $description);
        }

        if (null === $scanData) {
            return $this->errorResponse('scan.error.cancelFailed', 500);
        }

        return new JsonResponse([
            'status' => $scanData['status'] ?? 'canceled',
        ], 200);
    }

    /**
     * Extract page URL filters from the request query.
     *
     * Supports repeated OpenAPI query keys (`pageUrls=https://a&pageUrls=https://b`).
     *
     * @return string[]
     */
    private function extractPageUrls(ServerRequestInterface $request): array
    {
        $pageUrls = [];
        $queryParams = $request->getQueryParams();

        $value = $queryParams['pageUrls'] ?? null;
        if (is_array($value)) {
            foreach ($value as $pageUrl) {
                if (is_string($pageUrl) && $pageUrl !== '') {
                    $pageUrls[] = $pageUrl;
                }
            }
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
     * Verifies that a scan ID maps to a page the current user may access, and that TSConfig
     * allows scan access for that page. Returns the page record on success, or a
     * ResponseInterface error response on failure.
     *
     * @return array<string, mixed>|ResponseInterface Page record array on success, error response on failure.
     */
    private function requireScanPageAccess(string $scanId): array|ResponseInterface
    {
        $pageRecord = $this->scanStateService->getPageRecordByScanId($scanId);
        if (null === $pageRecord) {
            return $this->errorResponse('scan.error.notFound', 404);
        }

        if (!$this->permissionService->checkPageReadAccess($pageRecord)) {
            return $this->errorResponse('scan.error.accessDenied', 403);
        }

        $pageTsConfig = $this->moduleSettingsService->getConvertedPageTsConfig((int)$pageRecord['uid']);
        if (!$this->moduleSettingsService->hasScanAccess($pageTsConfig)) {
            return $this->errorResponse('scan.noAccess', 403);
        }

        return $pageRecord;
    }

}
