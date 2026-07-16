<?php

declare(strict_types=1);

/*
 * Mindful A11y extension for TYPO3 integrating accessibility tools into the backend.
 * Copyright (C) 2026  Mindful Markup, Felix Spittel
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 */

namespace MindfulMarkup\MindfulA11y\Tests\Functional\Controller;

use MindfulMarkup\MindfulA11y\Controller\ScanAjaxController;
use MindfulMarkup\MindfulA11y\Domain\Model\CreateScanDemand;
use MindfulMarkup\MindfulA11y\Service\ModuleLabelService;
use MindfulMarkup\MindfulA11y\Service\ScanStateService;
use MindfulMarkup\MindfulA11y\Tests\Functional\AbstractAuthorizationTestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;

/**
 * Authorization coverage of the accessibility-scan AJAX endpoints
 * (createAction, getAction, reportAction, cancelAction).
 *
 * The external scanner API is deliberately unconfigured in the functional
 * test instance (no ExtensionConfiguration for 'scannerApiUrl' is written by
 * any fixture) — ScanApiService::isConfigured() is therefore always false.
 * This is the suite's positive discriminator: a demand/request that clears
 * every authorization gate does not succeed (201/200), it reaches the
 * upstream-failure branch of the corresponding service:
 *  - createAction: ScanCreationService::create() throws
 *    ScanCreationException('scan.error.notConfigured', 500) as its very
 *    first statement, before touching page trees or site configuration.
 *  - getAction: ScanApiService::getScan() -> sendRequest() returns null ->
 *    controller answers errorResponse('scan.error.getFailed', 500).
 *  - cancelAction: ScanApiService::cancelScan() -> sendRequest() returns
 *    null -> controller answers errorResponse('scan.error.cancelFailed', 500).
 * Every "authorized, ends in upstream failure" assertion below targets one
 * of those three exact outcomes, never a bare "not 4xx".
 *
 * Uses only the shared AuthorizationScenario.csv fixture (pages 10 editable,
 * 11 show-only, 12 edit-locked, 14 no-access, 15 hidden, 17 scan-disabled via
 * TSconfig, 19 aiAudit-enabled via TSconfig, 20 outside every db mount, 30 =
 * fr translation of 10; users 2 full editor, 3 no module, 6 default-language
 * only, 8 no pages tables_modify, 10 second full editor) — no supplementary
 * fixture is required because every scenario in the coverage matrix maps
 * onto an existing row.
 */
final class ScanAjaxControllerTest extends AbstractAuthorizationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Preview URL / site resolution is exercised by some code paths
        // reached before the scan-API-unconfigured short circuit (e.g.
        // page/language lookups), so the site must exist even though the
        // scan-creation call itself never reaches URL building.
        $this->writeDefaultSiteConfiguration();
    }

    private function controller(): ScanAjaxController
    {
        return $this->get(ScanAjaxController::class);
    }

    /**
     * INFRA NOTE: overrides AbstractAuthorizationTestCase::createJsonRequest().
     * That method opens its body stream from the plain string 'php://temp',
     * which TYPO3\CMS\Core\Http\Stream's constructor opens via
     * fopen($stream, 'r') (read-only is the Stream class's default $mode)
     * whenever $stream is a string rather than a resource — so the base
     * method's own `$request->getBody()->write(...)` call unconditionally
     * throws RuntimeException("Error writing to stream") for every caller,
     * verified with a standalone repro against the exact vendor classes.
     * This affects every suite that calls createJsonRequest() for a POST
     * body, not just this one. Not fixable here (HARD RULE: never edit the
     * base class) — reimplemented byte-for-byte identically except the body
     * is a resource opened read-write, which Stream's constructor accepts
     * as-is (the is_resource() branch ignores $mode entirely).
     *
     * @param array<string, mixed> $payload
     */
    protected function createJsonRequest(array $payload, string $method = 'POST'): ServerRequestInterface
    {
        $request = new ServerRequest(
            'https://typo3-testing.local/typo3/ajax/mindfula11y',
            $method,
            fopen('php://temp', 'rb+'),
            ['Content-Type' => 'application/json'],
            ['HTTP_HOST' => 'typo3-testing.local', 'HTTPS' => 'on', 'REQUEST_URI' => '/typo3/ajax/mindfula11y'],
        );
        $request->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));
        $request->getBody()->rewind();

        return $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
    }

    /**
     * @return array<string, mixed>
     */
    private function signedCreateDemandPayload(
        int $userId,
        int $pageId,
        string $previewUrl = 'https://example.com/editable',
        int $languageId = 0,
        int $workspaceId = 0,
        int $pageLevels = 0,
        bool $crawl = false,
        int $expiresAt = 0,
    ): array {
        return (new CreateScanDemand(
            userId: $userId,
            pageId: $pageId,
            previewUrl: $previewUrl,
            languageId: $languageId,
            workspaceId: $workspaceId,
            pageLevels: $pageLevels,
            crawl: $crawl,
            expiresAt: $expiresAt,
        ))->toArray();
    }

    /**
     * Stamp a scan id onto a page record's scan-state fields, bypassing the
     * authorized create flow (per MECHANICS: runtime UPDATE against the
     * per-test sqlite database).
     */
    private function seedScanId(int $pageUid, string $scanId): void
    {
        $this->getConnectionPool()->getConnectionForTable('pages')->update(
            'pages',
            [
                ScanStateService::FIELD_SCAN_ID => $scanId,
                ScanStateService::FIELD_SCAN_UPDATED => time(),
            ],
            ['uid' => $pageUid],
        );
    }

    /**
     * Assert a uniform JSON error response: status code plus the exact
     * localized title JsonErrorResponseTrait::errorResponse() would have
     * built for $expectedLabelKey under the currently logged-in user's
     * language — the same mechanism the controller itself uses, so this
     * never drifts from a hardcoded English string.
     */
    private function assertErrorResponse(ResponseInterface $response, int $expectedStatus, string $expectedLabelKey): void
    {
        self::assertSame($expectedStatus, $response->getStatusCode(), 'status code for ' . $expectedLabelKey);
        $body = $this->decodeJsonResponse($response);
        self::assertSame(
            $GLOBALS['LANG']->sL(ModuleLabelService::LANGUAGE_FILE . $expectedLabelKey),
            $body['error']['title'] ?? null,
            'error title for ' . $expectedLabelKey
        );
    }

    // ---------------------------------------------------------------
    // createAction
    // ---------------------------------------------------------------

    public function testCreateActionModuleGateDeniesUserWithoutModuleAccess(): void
    {
        $this->logInBackendUser(3);
        $payload = $this->signedCreateDemandPayload(3, 10);

        $response = $this->controller()->createAction($this->createJsonRequest($payload));

        $this->assertErrorResponse($response, 403, 'error.forbidden');
    }

    public function testCreateActionMalformedBodyReturns400(): void
    {
        $this->logInBackendUser(2);

        $response = $this->controller()->createAction($this->createJsonRequest(['not' => 'a demand']));

        $this->assertErrorResponse($response, 400, 'error.invalidRequest');
    }

    public function testCreateActionTamperedSignatureReturns400(): void
    {
        $this->logInBackendUser(2);
        $payload = $this->signedCreateDemandPayload(2, 10);
        // Tamper a signed field after signing without recomputing the HMAC.
        $payload['previewUrl'] = 'https://example.com/tampered';

        $response = $this->controller()->createAction($this->createJsonRequest($payload));

        $this->assertErrorResponse($response, 400, 'module.error.invalidSignature');
    }

    public function testCreateActionExpiredDemandReturns400(): void
    {
        $this->logInBackendUser(2);
        // A demand signed with an already-past expiresAt: the signature
        // itself is intact (computed over that past timestamp), but
        // validateSignature()'s expiry window check fails first — same
        // error label as a tampered signature, single code path.
        $payload = $this->signedCreateDemandPayload(2, 10, expiresAt: time() - 100);

        $response = $this->controller()->createAction($this->createJsonRequest($payload));

        $this->assertErrorResponse($response, 400, 'module.error.invalidSignature');
    }

    public function testCreateActionUserPinningDeniesDemandRedeemedByAnotherUser(): void
    {
        // Demand signed for user 10, redeemed in user 2's session.
        $this->logInBackendUser(2);
        $payload = $this->signedCreateDemandPayload(10, 10);

        $response = $this->controller()->createAction($this->createJsonRequest($payload));

        $this->assertErrorResponse($response, 403, 'error.invalidUser');
    }

    public function testCreateActionWorkspacePinningDeniesSessionWorkspaceMismatch(): void
    {
        // Source 1 of error.invalidWorkspace: AjaxGuardTrait::requireDemandSession()
        // compares the session's live workspace (0) against the demand's
        // workspaceId (1) and rejects the mismatch before the live-workspace
        // -only gate is ever reached.
        $this->logInBackendUser(2);
        $payload = $this->signedCreateDemandPayload(2, 10, workspaceId: 1);

        $response = $this->controller()->createAction($this->createJsonRequest($payload));

        $this->assertErrorResponse($response, 403, 'error.invalidWorkspace');
    }

    public function testCreateActionLiveWorkspaceOnlyGateDeniesMatchingNonZeroWorkspace(): void
    {
        // Source 2 of error.invalidWorkspace: session workspace and demand
        // workspaceId both equal 1, so requireDemandSession()'s pinning
        // check passes — the denial instead comes from createAction()'s own
        // "if ($workspaceId !== 0)" live-workspace-only gate further down.
        // User 2 is a member of sys_workspace 1.
        $this->logInBackendUser(2, 1);
        $payload = $this->signedCreateDemandPayload(2, 10, workspaceId: 1);

        $response = $this->controller()->createAction($this->createJsonRequest($payload));

        $this->assertErrorResponse($response, 403, 'error.invalidWorkspace');
    }

    public function testCreateActionLanguagePinningDeniesUserWithoutLanguageAccess(): void
    {
        // User 6 (editor_lang_default, be_groups.allowed_languages = "0").
        $this->logInBackendUser(6);
        $payload = $this->signedCreateDemandPayload(6, 10, languageId: 1);

        $response = $this->controller()->createAction($this->createJsonRequest($payload));

        $this->assertErrorResponse($response, 403, 'error.invalidLanguage');
    }

    public function testCreateActionTsConfigGateDeniesScanDisabledPage(): void
    {
        // Page 17 overrides mod.mindfula11y_accessibility.scan.enable = 0.
        $this->logInBackendUser(2);
        $payload = $this->signedCreateDemandPayload(2, 17, previewUrl: 'https://example.com/scan-disabled');

        $response = $this->controller()->createAction($this->createJsonRequest($payload));

        $this->assertErrorResponse($response, 403, 'scan.noAccess');
    }

    public function testCreateActionAiAuditGateDeniesWhenNotEnabledOnPage(): void
    {
        // Page 10 does not enable scan.aiAudit in TSconfig.
        $this->logInBackendUser(2);
        $payload = $this->signedCreateDemandPayload(2, 10) + ['aiAudit' => true];

        $response = $this->controller()->createAction($this->createJsonRequest($payload));

        $this->assertErrorResponse($response, 403, 'scan.error.aiAuditNotAllowed');
    }

    public function testCreateActionAiAuditGatePassesWhenEnabledOnPage(): void
    {
        // Page 19 overrides mod.mindfula11y_accessibility.scan.aiAudit.enable = 1:
        // the aiAudit gate must let this through to the scan-API failure
        // branch, NOT deny with scan.error.aiAuditNotAllowed.
        $this->logInBackendUser(2);
        $payload = $this->signedCreateDemandPayload(2, 19, previewUrl: 'https://example.com/ai-audit') + ['aiAudit' => true];

        $response = $this->controller()->createAction($this->createJsonRequest($payload));

        $this->assertErrorResponse($response, 500, 'scan.error.notConfigured');
    }

    public function testCreateActionNonexistentPageIsDeniedByTsConfigGateBeforePageLookup(): void
    {
        // FINDING (not a security gap — fail-closed, more restrictive than
        // expected): the TSconfig scan-access gate runs BEFORE the
        // page-existence check in createAction(). ModuleSettingsService::
        // getConvertedPageTsConfig() resolves Page TSconfig via the page's
        // own rootline (BackendUtility::getPagesTSconfig() ->
        // RootlineUtility); for a uid with no 'pages' row at all, the
        // rootline cannot be resolved and comes back empty, so
        // hasScanAccess() sees no inherited scan.enable and denies with
        // scan.noAccess — the dedicated scan.error.pageNotFound (404) branch
        // further down is never reached for a page id that never existed.
        // That branch IS reachable (see
        // testCreateActionMissingTranslationReturnsPageNotFound below, whose
        // page genuinely exists but the requested translation does not).
        $this->logInBackendUser(2);
        $payload = $this->signedCreateDemandPayload(2, 99999, previewUrl: 'https://example.com/does-not-exist');

        $response = $this->controller()->createAction($this->createJsonRequest($payload));

        $this->assertErrorResponse($response, 403, 'scan.noAccess');
    }

    public function testCreateActionMissingTranslationReturnsPageNotFound(): void
    {
        // Page 11 (show-only) has no language-1 translation in the fixture.
        // The translation lookup happens BEFORE the page-edit-access check,
        // so this 404s regardless of user 2's (lack of) edit rights on page 11.
        $this->logInBackendUser(2);
        $payload = $this->signedCreateDemandPayload(2, 11, previewUrl: 'https://example.com/show-only', languageId: 1);

        $response = $this->controller()->createAction($this->createJsonRequest($payload));

        $this->assertErrorResponse($response, 404, 'scan.error.pageNotFound');
    }

    public function testCreateActionExistingTranslationProceedsPastPageNotFound(): void
    {
        // Page 30 is the language-1 (fr) translation of page 10 — the
        // translation lookup succeeds, so this must NOT 404; it proceeds all
        // the way to the scan-API failure branch.
        $this->logInBackendUser(2);
        $payload = $this->signedCreateDemandPayload(2, 10, previewUrl: 'https://example.com/fr/editable', languageId: 1);

        $response = $this->controller()->createAction($this->createJsonRequest($payload));

        $this->assertErrorResponse($response, 500, 'scan.error.notConfigured');
    }

    public function testCreateActionShowOnlyPageDeniesEditAccess(): void
    {
        // Page 11: perms_user/perms_group carry only PAGE_SHOW.
        $this->logInBackendUser(2);
        $payload = $this->signedCreateDemandPayload(2, 11, previewUrl: 'https://example.com/show-only');

        $response = $this->controller()->createAction($this->createJsonRequest($payload));

        $this->assertErrorResponse($response, 403, 'error.noPageAccess');
    }

    public function testCreateActionEditLockedPageDeniesEditAccess(): void
    {
        // Page 12: full edit perms but pages.editlock = 1.
        $this->logInBackendUser(2);
        $payload = $this->signedCreateDemandPayload(2, 12, previewUrl: 'https://example.com/edit-locked');

        $response = $this->controller()->createAction($this->createJsonRequest($payload));

        $this->assertErrorResponse($response, 403, 'error.noPageAccess');
    }

    public function testCreateActionNoPagesModifyDeniesEditAccess(): void
    {
        // User 8 (editor_no_pages_modify): be_groups.tables_modify excludes 'pages'.
        $this->logInBackendUser(8);
        $payload = $this->signedCreateDemandPayload(8, 10);

        $response = $this->controller()->createAction($this->createJsonRequest($payload));

        $this->assertErrorResponse($response, 403, 'error.noPageAccess');
    }

    public function testCreateActionOutsideWebmountPageDeniesEditAccess(): void
    {
        // FINDING: page 20 is a second site root (pid 0, is_siteroot 1)
        // outside every db mount of user 2's backend group
        // (db_mountpoints = "1" only), with perms 19 (PAGE_SHOW|
        // PAGE_EDIT_PAGE|PAGE_EDIT_CONTENT) and its own TSconfig enabling
        // scan.enable=1 directly (not inherited, since pid 0 has no
        // ancestor). PermissionService::checkRecordEditAccess() does not
        // check db mounts directly, but it delegates permission bits to
        // BackendUserAuthentication::calcPerms(), and TYPO3 core's calcPerms()
        // itself returns Permission::NOTHING whenever the row fails
        // isInWebMount() — so the db-mount boundary IS enforced here, just
        // indirectly via core rather than by an explicit mount check in this
        // extension. No security gap: a user mounted only at page 1 cannot
        // create a scan for a page outside every db mount.
        $this->logInBackendUser(2);
        $payload = $this->signedCreateDemandPayload(2, 20, previewUrl: 'https://example.com/outside');

        $response = $this->controller()->createAction($this->createJsonRequest($payload));

        $this->assertErrorResponse($response, 403, 'error.noPageAccess');
    }

    public function testCreateActionHiddenPageDeniesVisibility(): void
    {
        $this->logInBackendUser(2);
        $payload = $this->signedCreateDemandPayload(2, 15, previewUrl: 'https://example.com/hidden');

        $response = $this->controller()->createAction($this->createJsonRequest($payload));

        $this->assertErrorResponse($response, 403, 'scan.error.pageVisible');
    }

    public function testCreateActionFullyAuthorizedEndsInScanApiFailure(): void
    {
        // Positive baseline: every gate above is exercised with this exact
        // user/page/language combination elsewhere in this suite, so this
        // can never pass vacuously.
        $this->logInBackendUser(2);
        $payload = $this->signedCreateDemandPayload(2, 10);

        $response = $this->controller()->createAction($this->createJsonRequest($payload));

        $this->assertErrorResponse($response, 500, 'scan.error.notConfigured');
    }

    // ---------------------------------------------------------------
    // getAction / reportAction / cancelAction (requireScanPageAccess)
    // ---------------------------------------------------------------

    public function testGetActionModuleGateDeniesUserWithoutModuleAccess(): void
    {
        $this->logInBackendUser(3);

        $response = $this->controller()->getAction($this->createGetRequest(['scanId' => 'irrelevant']));

        $this->assertErrorResponse($response, 403, 'error.forbidden');
    }

    public function testReportActionModuleGateDeniesUserWithoutModuleAccess(): void
    {
        $this->logInBackendUser(3);

        $response = $this->controller()->reportAction($this->createGetRequest(['scanId' => 'irrelevant', 'format' => 'html']));

        $this->assertErrorResponse($response, 403, 'error.forbidden');
    }

    public function testCancelActionModuleGateDeniesUserWithoutModuleAccess(): void
    {
        $this->logInBackendUser(3);

        $response = $this->controller()->cancelAction($this->createJsonRequest(['scanId' => 'irrelevant']));

        $this->assertErrorResponse($response, 403, 'error.forbidden');
    }

    public function testGetActionEmptyScanIdReturnsNoScanId(): void
    {
        $this->logInBackendUser(2);

        $response = $this->controller()->getAction($this->createGetRequest(['scanId' => '']));

        $this->assertErrorResponse($response, 404, 'scan.error.noScanId');
    }

    public function testReportActionEmptyScanIdReturnsNoScanIdBeforeFormatCheck(): void
    {
        $this->logInBackendUser(2);

        // Deliberately also an invalid format: the empty-scanId check runs
        // first, so the response must be noScanId, not reportFormat.
        $response = $this->controller()->reportAction($this->createGetRequest(['scanId' => '', 'format' => 'bogus']));

        $this->assertErrorResponse($response, 404, 'scan.error.noScanId');
    }

    public function testCancelActionEmptyScanIdReturnsNoScanId(): void
    {
        $this->logInBackendUser(2);

        $response = $this->controller()->cancelAction($this->createJsonRequest(['scanId' => '']));

        $this->assertErrorResponse($response, 404, 'scan.error.noScanId');
    }

    public function testReportActionInvalidFormatReturnsReportFormat(): void
    {
        $this->logInBackendUser(2);

        // Format is validated before requireScanPageAccess() runs, so an
        // unseeded/unknown scanId does not change the outcome here.
        $response = $this->controller()->reportAction($this->createGetRequest(['scanId' => 'unknown-scan-id', 'format' => 'bogus']));

        $this->assertErrorResponse($response, 400, 'scan.error.reportFormat');
    }

    public function testGetActionUnknownScanIdReturnsNotFound(): void
    {
        $this->logInBackendUser(2);

        $response = $this->controller()->getAction($this->createGetRequest(['scanId' => 'unknown-scan-id']));

        $this->assertErrorResponse($response, 404, 'scan.error.notFound');
    }

    public function testReportActionUnknownScanIdReturnsNotFound(): void
    {
        $this->logInBackendUser(2);

        $response = $this->controller()->reportAction($this->createGetRequest(['scanId' => 'unknown-scan-id', 'format' => 'html']));

        $this->assertErrorResponse($response, 404, 'scan.error.notFound');
    }

    public function testCancelActionUnknownScanIdReturnsNotFound(): void
    {
        $this->logInBackendUser(2);

        $response = $this->controller()->cancelAction($this->createJsonRequest(['scanId' => 'unknown-scan-id']));

        $this->assertErrorResponse($response, 404, 'scan.error.notFound');
    }

    public function testGetActionPageReadDenialReturnsAccessDenied(): void
    {
        // Page 14: perms_user/perms_group/perms_everybody all 0 — no read access.
        $this->seedScanId(14, 'scan-page-14');
        $this->logInBackendUser(2);

        $response = $this->controller()->getAction($this->createGetRequest(['scanId' => 'scan-page-14']));

        $this->assertErrorResponse($response, 403, 'scan.error.accessDenied');
    }

    public function testGetActionTsConfigGateDeniesScanDisabledPage(): void
    {
        // Page 17: PAGE_SHOW is granted (perms 19), so read access passes;
        // the denial comes from the TSconfig scan.enable = 0 override.
        $this->seedScanId(17, 'scan-page-17');
        $this->logInBackendUser(2);

        $response = $this->controller()->getAction($this->createGetRequest(['scanId' => 'scan-page-17']));

        $this->assertErrorResponse($response, 403, 'scan.noAccess');
    }

    public function testCancelActionShowOnlyPageDeniesEditGateAfterReadPasses(): void
    {
        // Page 11: PAGE_SHOW only — requireScanPageAccess()'s read check
        // passes, so cancelAction()'s own checkRecordEditAccess() call is
        // what denies here.
        $this->seedScanId(11, 'scan-page-11');
        $this->logInBackendUser(2);

        $response = $this->controller()->cancelAction($this->createJsonRequest(['scanId' => 'scan-page-11']));

        $this->assertErrorResponse($response, 403, 'error.noPageAccess');
    }

    public function testGetActionFullyAuthorizedEndsInUpstreamFailure(): void
    {
        $this->seedScanId(10, 'scan-page-10-get');
        $this->logInBackendUser(2);

        $response = $this->controller()->getAction($this->createGetRequest(['scanId' => 'scan-page-10-get']));

        $this->assertErrorResponse($response, 500, 'scan.error.getFailed');
    }

    public function testCancelActionFullyAuthorizedEndsInUpstreamFailure(): void
    {
        $this->seedScanId(10, 'scan-page-10-cancel');
        $this->logInBackendUser(2);

        $response = $this->controller()->cancelAction($this->createJsonRequest(['scanId' => 'scan-page-10-cancel']));

        $this->assertErrorResponse($response, 500, 'scan.error.cancelFailed');
    }
}
