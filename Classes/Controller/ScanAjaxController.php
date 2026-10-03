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
use MindfulMarkup\MindfulA11y\Exception\ScanAuthorizationException;
use MindfulMarkup\MindfulA11y\Exception\ScanCreationException;
use MindfulMarkup\MindfulA11y\Service\BackendUserProvider;
use MindfulMarkup\MindfulA11y\Service\DemandSignatureService;
use MindfulMarkup\MindfulA11y\Service\ExistingScanAuthorizationService;
use MindfulMarkup\MindfulA11y\Service\ModuleSettingsService;
use MindfulMarkup\MindfulA11y\Service\PagePreviewService;
use MindfulMarkup\MindfulA11y\Service\PermissionService;
use MindfulMarkup\MindfulA11y\Service\ScanApiService;
use MindfulMarkup\MindfulA11y\Service\ScanCreationService;
use MindfulMarkup\MindfulA11y\Service\ScanDemandFactory;
use MindfulMarkup\MindfulA11y\Service\SiteLanguageService;
use MindfulMarkup\MindfulA11y\Tca\VersionedRecord;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * Handles the AJAX endpoints of the accessibility-scanner feature.
 *
 * Allowed HTTP methods are enforced on the route definitions
 * (Configuration/Backend/AjaxRoutes.php and Routes.php).
 */
final readonly class ScanAjaxController
{
    use JsonErrorResponseTrait;
    use ModuleAccessGuardTrait;
    use DemandSessionGuardTrait;

    public function __construct(
        private ScanApiService $scanApiService,
        private DemandSignatureService $demandSignatureService,
        private ModuleSettingsService $moduleSettingsService,
        private PagePreviewService $pagePreviewService,
        private ExistingScanAuthorizationService $existingScanAuthorizationService,
        private PermissionService $permissionService,
        private SiteLanguageService $siteLanguageService,
        private ScanCreationService $scanCreationService,
        private ScanDemandFactory $scanDemandFactory,
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

        if (!is_string($scanId) || $scanId === '') {
            return $this->errorResponse('scan.error.noScanId', 404);
        }

        if (!in_array($format, ['html', 'pdf'], true)) {
            return $this->errorResponse('scan.error.reportFormat', 400);
        }

        $pageRecord = $this->requireScanReadAccess($scanId);
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
            ->withHeader('Content-Disposition', $disposition . '; filename="' . $filename . '"')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            // The report URL carries the route token and scanId — subresource
            // requests from inside the report must not leak it via Referer.
            ->withHeader('Referrer-Policy', 'no-referrer')
            // Privileged content: keep the report out of any shared cache,
            // matching StructureAnalysisResponseHardener's discipline.
            ->withHeader('Cache-Control', 'private, no-store');

        // Prevent scripts in HTML reports from running at the TYPO3 backend
        // origin. form-action/base-uri/frame-ancestors do NOT fall back to
        // default-src, so they are pinned explicitly; the report is only ever
        // opened as a top-level navigation, never framed.
        if ($format === 'html') {
            $response = $response->withHeader(
                'Content-Security-Policy',
                "default-src 'none'; style-src 'unsafe-inline'; img-src data: https:; font-src 'self' data:; form-action 'none'; base-uri 'none'; frame-ancestors 'none'"
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

        if (!$this->demandSignatureService->isValid($demand)) {
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

        // Looked up before the TSconfig gate: a page id without a record has
        // no rootline to inherit scan.enable from, and would otherwise be
        // reported as "scanning disabled" instead of "not found".
        $page = BackendUtility::getRecordWSOL('pages', $pageId);

        if (null === $page || VersionedRecord::isDeletePlaceholder($page)) {
            return $this->errorResponse('scan.error.pageNotFound', 404);
        }

        if (!$this->moduleSettingsService->isScanEnabledForPage($pageId)) {
            return $this->errorResponse('scan.noAccess', 403);
        }
        $pageTsConfig = $this->moduleSettingsService->getConvertedPageTsConfig($pageId);

        // The AI audit is opt-in via Page TSconfig. Each scan mode beyond the
        // current page (a page tree, a crawl) is a separate opt-in there too —
        // the review runs per scanned page, so those modes multiply its cost —
        // but that gate needs the resolved URLs: ScanCreationService applies it
        // to the mode the request is actually sent with, before calling the
        // API. MindfulAPI owns skill selection and applies its server-side
        // whitelist, and enforces its own allowed scan modes.
        $aiAuditSkills = null;
        if ($aiAuditRequested) {
            if (!$this->moduleSettingsService->hasAiAuditAccess($pageTsConfig)) {
                return $this->errorResponse('scan.error.aiAuditNotAllowed', 403);
            }
            $aiAuditSkills = $this->moduleSettingsService->getAiAuditSkills($pageTsConfig);
        }

        if ($languageId > 0) {
            $localizedPage = $this->pagePreviewService->getLocalizedPageRecord($pageId, $languageId);
            if ($localizedPage) {
                $page = $localizedPage;
            } else {
                return $this->errorResponse('scan.error.pageNotFound', 404);
            }
        }

        // Recheck current read access at redemption. The demand was only issued
        // from an authorized module view, but its lifetime must not let
        // a later PAGE_SHOW revocation survive until the next reload.
        if (!$this->permissionService->checkPageReadAccess($page)) {
            return $this->errorResponse('error.noPageAccess', 403);
        }

        // Verify the current backend user may mutate the page scan state.
        if (!$this->permissionService->checkRecordEditAccess('pages', $page)) {
            return $this->errorResponse('error.noPageAccess', 403);
        }

        // Check if page is visible (not hidden and within start/end time)
        if (!$this->pagePreviewService->isPageVisible($page)) {
            return $this->errorResponse('scan.error.pageVisible', 403);
        }

        // The scanner fetches pages as a public visitor. Multi-page scans skip
        // pages restricted to frontend user groups (directly or inherited via
        // extendToSubpages) in PagePreviewService::generatePageUrls(); a
        // single-page scan refuses such a page instead of scanning its login wall.
        if (!$demand->getCrawl()
            && $demand->getPageLevels() === 0
            && !$this->pagePreviewService->isPageFrontendAccessible($page)
        ) {
            return $this->errorResponse('scan.error.pageRestricted', 403);
        }

        // The HMAC authenticates the issued preview URL and page/language
        // coordinates; this comparison makes it a snapshot. A move, slug/site
        // change or translation replacement invalidates the outstanding demand.
        if (!$this->scanDemandFactory->matchesCurrentSnapshot($demand, $page)) {
            return $this->errorResponse('module.error.invalidSignature', 400);
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

        if (!is_string($scanId) || $scanId === '') {
            return $this->errorResponse('scan.error.noScanId', 404);
        }

        $pageRecord = $this->requireScanReadAccess($scanId);
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
        } catch (ScanApiRequestException $exception) {
            // Rate limited: passed on with the scanner's Retry-After, so the
            // polling scan view backs off instead of hammering the limit that
            // every editor of this installation shares.
            if ($exception->getStatusCode() === 429) {
                $response = $this->errorResponse('scan.error.rateLimited', 429);
                $retryAfter = $exception->getRetryAfter();

                return $retryAfter === null ? $response : $response->withHeader('Retry-After', (string)$retryAfter);
            }
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

        $pageRecord = $this->requireScanMutationAccess($scanId);
        if ($pageRecord instanceof ResponseInterface) {
            return $pageRecord;
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
     * The backend client sends them as an indexed array (`pageUrls[0]=…`); a
     * single scalar value is accepted as well.
     *
     * @return string[]
     */
    private function extractPageUrls(ServerRequestInterface $request): array
    {
        $value = $request->getQueryParams()['pageUrls'] ?? null;
        $values = is_array($value) ? $value : [$value];
        $pageUrls = array_filter($values, static fn(mixed $pageUrl): bool => is_string($pageUrl) && $pageUrl !== '');

        return array_values(array_unique($pageUrls));
    }

    /**
     * Map the existing-scan authorization service's read check to the AJAX
     * layer's uniform localized response.
     *
     * @return array<string, mixed>|ResponseInterface Page record array on success, error response on failure.
     */
    private function requireScanReadAccess(string $scanId): array|ResponseInterface
    {
        try {
            return $this->existingScanAuthorizationService->authorizeRead($scanId);
        } catch (ScanAuthorizationException $exception) {
            return $this->errorResponse($exception->labelKey, $exception->statusCode);
        }
    }

    /**
     * Map the existing-scan authorization service's mutation check (read
     * access plus page edit access) to the AJAX layer's uniform localized
     * response.
     *
     * @return array<string, mixed>|ResponseInterface Page record array on success, error response on failure.
     */
    private function requireScanMutationAccess(string $scanId): array|ResponseInterface
    {
        try {
            return $this->existingScanAuthorizationService->authorizeMutation($scanId);
        } catch (ScanAuthorizationException $exception) {
            return $this->errorResponse($exception->labelKey, $exception->statusCode);
        }
    }
}
