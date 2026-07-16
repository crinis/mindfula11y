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

use MindfulMarkup\MindfulA11y\Controller\StructureAnalysisEnrichmentAjaxController;
use MindfulMarkup\MindfulA11y\Controller\StructureAnalysisTicketAjaxController;
use MindfulMarkup\MindfulA11y\Domain\Model\StructureAnalysisTicket;
use MindfulMarkup\MindfulA11y\Service\StructureAnalysisAuthorizationService;
use MindfulMarkup\MindfulA11y\Tests\Functional\AbstractAuthorizationTestCase;

/**
 * Authorization coverage for structure-analysis ticket issuance
 * (StructureAnalysisTicketAjaxController + StructureAnalysisAuthorizationService)
 * and session-less ticket-holder redemption
 * (StructureAnalysisAuthorizationService::isTicketHolderAuthorized), plus the
 * gates guarding StructureAnalysisEnrichmentAjaxController.
 *
 * Imports a suite-local supplementary fixture on top of the shared scenario:
 *  - page 600 "No Structure Access": inside the webmount, full perms, but its
 *    own Page TSconfig disables both headingStructure and landmarkStructure
 *    (overriding root's enable = 1) — exercises the issuance-time TSconfig gate.
 *  - page 601 "No Translation": inside the webmount, full perms, inherits
 *    root's TSconfig (both features enabled), but has no sys_language_uid = 1
 *    counterpart — exercises the page-translation-existence gate independently
 *    of language access.
 */
final class StructureAnalysisAuthorizationTest extends AbstractAuthorizationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/StructureSupplement.csv');
        $this->writeDefaultSiteConfiguration();
    }

    private function ticketController(): StructureAnalysisTicketAjaxController
    {
        return $this->get(StructureAnalysisTicketAjaxController::class);
    }

    private function enrichmentController(): StructureAnalysisEnrichmentAjaxController
    {
        return $this->get(StructureAnalysisEnrichmentAjaxController::class);
    }

    private function authorizationService(): StructureAnalysisAuthorizationService
    {
        return $this->get(StructureAnalysisAuthorizationService::class);
    }

    private function buildTicket(
        int $backendUserId,
        int $pageId = 10,
        int $languageId = 0,
        int $workspaceId = 0,
    ): StructureAnalysisTicket {
        return new StructureAnalysisTicket(
            requestId: bin2hex(random_bytes(16)),
            pageId: $pageId,
            languageId: $languageId,
            workspaceId: $workspaceId,
            backendUserId: $backendUserId,
            backendOrigin: 'https://typo3-testing.local',
            frontendOrigin: 'https://example.com',
            target: '/editable',
            expiresAt: time() + 15,
        );
    }

    // -----------------------------------------------------------------
    // ticketAction: positive baseline (A)
    // -----------------------------------------------------------------

    public function testAllowedBaselineIssuesTicketForDefaultLanguage(): void
    {
        $this->logInBackendUser(2);
        $response = $this->ticketController()->ticketAction(
            $this->createJsonRequest(['pageId' => 10, 'languageId' => 0])
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeJsonResponse($response);
        self::assertArrayHasKey('url', $body);
        self::assertArrayHasKey('requestId', $body);
        self::assertIsString($body['url']);
        self::assertNotSame('', $body['url']);
    }

    public function testAllowedBaselineIssuesTicketForTranslatedLanguage(): void
    {
        $this->logInBackendUser(2);
        $response = $this->ticketController()->ticketAction(
            $this->createJsonRequest(['pageId' => 10, 'languageId' => 1])
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeJsonResponse($response);
        self::assertArrayHasKey('url', $body);
        self::assertArrayHasKey('requestId', $body);
    }

    // -----------------------------------------------------------------
    // ticketAction: module gate (B)
    // -----------------------------------------------------------------

    public function testModuleAccessDeniedForUserWithoutModule(): void
    {
        $this->logInBackendUser(3);
        $response = $this->ticketController()->ticketAction(
            $this->createJsonRequest(['pageId' => 10, 'languageId' => 0])
        );

        self::assertSame(403, $response->getStatusCode());
    }

    // -----------------------------------------------------------------
    // ticketAction: page permissions (C)
    // -----------------------------------------------------------------

    public function testPagePermissionDeniedForNoAccessPage(): void
    {
        $this->logInBackendUser(2);
        $response = $this->ticketController()->ticketAction(
            $this->createJsonRequest(['pageId' => 14, 'languageId' => 0])
        );

        self::assertSame(403, $response->getStatusCode());
    }

    /**
     * Page 11 grants PAGE_SHOW (only) via perms_everybody = 1. authorizePage()
     * requires nothing stronger than PAGE_SHOW for structure-analysis
     * (read-only), and the page inherits root's headingStructure/
     * landmarkStructure enable = 1 and builds a valid preview URL, so the real
     * outcome is an issued ticket — unlike StructureAnalysisEnrichmentAjaxController's
     * per-record edit checks, which do require PAGE_EDIT_CONTENT (see the
     * enrichAction tests below, which deny writes on this same page).
     */
    public function testShowOnlyPageIsAuthorizedForTicketIssuance(): void
    {
        $this->logInBackendUser(2);
        $response = $this->ticketController()->ticketAction(
            $this->createJsonRequest(['pageId' => 11, 'languageId' => 0])
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeJsonResponse($response);
        self::assertArrayHasKey('url', $body);
    }

    // -----------------------------------------------------------------
    // ticketAction: webmount containment (D)
    // -----------------------------------------------------------------

    /**
     * Page 20 is a second page-tree root (pid = 0, is_siteroot = 1) with wide
     * open perms_everybody = 19 — PAGE_SHOW would pass on permission bits
     * alone. It is denied because it is outside every db_mountpoints entry of
     * the user's group (group 1 only mounts page 1), and authorizePage()
     * checks isInWebMount() explicitly and independently of calcPerms().
     */
    public function testWebmountDeniedForPageOutsideMount(): void
    {
        $this->logInBackendUser(2);
        $response = $this->ticketController()->ticketAction(
            $this->createJsonRequest(['pageId' => 20, 'languageId' => 0])
        );

        self::assertSame(403, $response->getStatusCode());
    }

    // -----------------------------------------------------------------
    // ticketAction: language access and translation existence (E)
    // -----------------------------------------------------------------

    public function testLanguageAccessDeniedForDefaultLanguageOnlyUser(): void
    {
        $this->logInBackendUser(6);
        $response = $this->ticketController()->ticketAction(
            $this->createJsonRequest(['pageId' => 10, 'languageId' => 1])
        );

        self::assertSame(403, $response->getStatusCode());
    }

    /**
     * Page 601 (supplementary fixture) has no sys_language_uid = 1
     * counterpart. The full editor is allowed every language
     * (allowed_languages is empty for group 1), so this isolates
     * hasPageTranslation() failing on its own, independent of
     * checkLanguageAccess().
     */
    public function testLanguageDeniedForPageWithoutTranslation(): void
    {
        $this->logInBackendUser(2);
        $response = $this->ticketController()->ticketAction(
            $this->createJsonRequest(['pageId' => 601, 'languageId' => 1])
        );

        self::assertSame(403, $response->getStatusCode());
    }

    // -----------------------------------------------------------------
    // ticketAction: TSconfig issuance gate (F)
    // -----------------------------------------------------------------

    /**
     * Page 600 (supplementary fixture) overrides both
     * mod.mindfula11y_accessibility.headingStructure.enable and
     * .landmarkStructure.enable to 0, so isStructureAnalysisEnabled() denies
     * issuance even though authorizePage() itself would succeed (full perms,
     * inside the mount).
     */
    public function testTsConfigGateDeniesIssuanceWhenBothStructureFeaturesDisabled(): void
    {
        $this->logInBackendUser(2);
        $response = $this->ticketController()->ticketAction(
            $this->createJsonRequest(['pageId' => 600, 'languageId' => 0])
        );

        self::assertSame(403, $response->getStatusCode());
    }

    // -----------------------------------------------------------------
    // ticketAction: invalid input (G)
    // -----------------------------------------------------------------

    public function testInvalidPageIdIsDenied(): void
    {
        $this->logInBackendUser(2);
        $response = $this->ticketController()->ticketAction(
            $this->createJsonRequest(['pageId' => 0, 'languageId' => 0])
        );

        self::assertSame(403, $response->getStatusCode());
    }

    public function testNegativeLanguageIdIsDenied(): void
    {
        $this->logInBackendUser(2);
        $response = $this->ticketController()->ticketAction(
            $this->createJsonRequest(['pageId' => 10, 'languageId' => -1])
        );

        self::assertSame(403, $response->getStatusCode());
    }

    // -----------------------------------------------------------------
    // ticketAction / authorizePage: workspace (H)
    // -----------------------------------------------------------------

    /**
     * User 2 is a member of sys_workspace 1. Logging into that workspace
     * (which AbstractAuthorizationTestCase does via setWorkspace()) succeeds,
     * page 10 carries no workspace version, and every other gate already
     * passes for this user/page pair — so the real outcome is a normally
     * issued ticket from within the offline workspace.
     */
    public function testWorkspaceMemberIsAuthorizedInNonLiveWorkspace(): void
    {
        $this->logInBackendUser(2, 1);
        $response = $this->ticketController()->ticketAction(
            $this->createJsonRequest(['pageId' => 10, 'languageId' => 0])
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeJsonResponse($response);
        self::assertArrayHasKey('url', $body);
    }

    /**
     * User 6 is not a member of sys_workspace 1. BackendUserAuthentication::
     * setWorkspace(1) (called by logInBackendUser()) silently falls back to
     * the user's default workspace (0) when setTemporaryWorkspace()'s own
     * checkWorkspace() call fails — so logging in "into" workspace 1 through
     * the normal session path can never actually reproduce a mismatched
     * session. To exercise authorizePage()'s own
     * `$backendUser->checkWorkspace($workspaceId) === false` branch directly,
     * the public $workspace property is forced to 1 after login (simulating
     * a signed ticket claiming a workspace the caller's live session isn't
     * in), then authorizePage() is called directly with workspaceId = 1.
     */
    public function testWorkspaceNonMemberIsDeniedByCheckWorkspace(): void
    {
        $backendUser = $this->logInBackendUser(6);
        $backendUser->workspace = 1;

        $page = $this->authorizationService()->authorizePage($backendUser, 10, 0, 1);

        self::assertNull($page);
    }

    // -----------------------------------------------------------------
    // isTicketHolderAuthorized: session-less ticket redemption (I, J, K, L)
    // -----------------------------------------------------------------

    public function testTicketHolderIsAuthorizedForValidTicket(): void
    {
        $ticket = $this->buildTicket(backendUserId: 2, pageId: 10, languageId: 0, workspaceId: 0);

        self::assertTrue($this->authorizationService()->isTicketHolderAuthorized($ticket));
    }

    /**
     * setBeUserByUid() applies TYPO3's enable-fields restrictions (disabled/
     * deleted/start/end-time), so a user disabled after the ticket was issued
     * cannot redeem it even within the ticket's short lifetime.
     */
    public function testTicketHolderIsDeniedForDisabledUser(): void
    {
        $ticket = $this->buildTicket(backendUserId: 2);
        $this->getConnectionPool()->getConnectionForTable('be_users')
            ->update('be_users', ['disable' => 1], ['uid' => 2]);

        self::assertFalse($this->authorizationService()->isTicketHolderAuthorized($ticket));
    }

    public function testTicketHolderIsDeniedForDeletedUser(): void
    {
        $ticket = $this->buildTicket(backendUserId: 2);
        $this->getConnectionPool()->getConnectionForTable('be_users')
            ->update('be_users', ['deleted' => 1], ['uid' => 2]);

        self::assertFalse($this->authorizationService()->isTicketHolderAuthorized($ticket));
    }

    public function testTicketHolderIsDeniedForNonexistentUser(): void
    {
        $ticket = $this->buildTicket(backendUserId: 999999);

        self::assertFalse($this->authorizationService()->isTicketHolderAuthorized($ticket));
    }

    public function testTicketHolderIsDeniedForZeroBackendUserId(): void
    {
        $ticket = new StructureAnalysisTicket(
            requestId: bin2hex(random_bytes(16)),
            pageId: 10,
            languageId: 0,
            workspaceId: 0,
            backendUserId: 0,
            backendOrigin: 'https://typo3-testing.local',
            frontendOrigin: 'https://example.com',
            target: '/editable',
            expiresAt: time() + 15,
        );

        self::assertFalse($this->authorizationService()->isTicketHolderAuthorized($ticket));
    }

    /**
     * isTicketHolderAuthorized() rebuilds the backend user (and its group
     * data) from scratch on every call — no cached session — so a module
     * grant revoked between issuance and redemption is honored immediately.
     */
    public function testTicketHolderIsDeniedWhenModuleAccessRevoked(): void
    {
        $ticket = $this->buildTicket(backendUserId: 2);
        $this->getConnectionPool()->getConnectionForTable('be_groups')
            ->update('be_groups', ['groupMods' => ''], ['uid' => 1]);

        self::assertFalse($this->authorizationService()->isTicketHolderAuthorized($ticket));
    }

    // -----------------------------------------------------------------
    // StructureAnalysisEnrichmentAjaxController::enrichAction
    // -----------------------------------------------------------------

    public function testEnrichActionDeniesUserWithoutModuleAccess(): void
    {
        $this->logInBackendUser(3);
        $response = $this->enrichmentController()->enrichAction($this->createJsonRequest(['records' => [
            ['tableName' => 'tt_content', 'columnName' => 'tx_mindfula11y_headingtype', 'uid' => 101],
        ]]));

        self::assertSame(403, $response->getStatusCode());
    }

    public function testEnrichActionPositiveBaselineReturnsMetadataForEditableRecord(): void
    {
        $this->logInBackendUser(2);
        $response = $this->enrichmentController()->enrichAction($this->createJsonRequest(['records' => [
            ['tableName' => 'tt_content', 'columnName' => 'tx_mindfula11y_headingtype', 'uid' => 101],
        ]]));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeJsonResponse($response);
        self::assertCount(1, $body['records']);
        self::assertSame('tt_content', $body['records'][0]['tableName']);
        self::assertSame('tx_mindfula11y_headingtype', $body['records'][0]['columnName']);
        self::assertSame(101, $body['records'][0]['uid']);
    }

    /**
     * Record-level gate: checkRecordEditAccess() requires PAGE_EDIT_CONTENT
     * on the record's page. Content 103 sits on page 11, whose
     * perms_everybody = 1 grants PAGE_SHOW only. The module gate already
     * passed (full editor), so this isolates the per-record permission
     * check: it silently filters the record out of the response rather than
     * failing the whole request.
     */
    public function testEnrichActionFiltersOutRecordWithoutEditContentPermission(): void
    {
        $this->logInBackendUser(2);
        $response = $this->enrichmentController()->enrichAction($this->createJsonRequest(['records' => [
            ['tableName' => 'tt_content', 'columnName' => 'tx_mindfula11y_headingtype', 'uid' => 103],
        ]]));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeJsonResponse($response);
        self::assertSame([], $body['records']);
    }

    /**
     * Record-level gate: checkRecordEditAccess() also checks
     * checkLanguageAccess() for the record's own sys_language_uid. Content
     * 102 is language 1 on page 10 (otherwise fully editable); user 6 is
     * restricted to language 0 (allowed_languages = "0"). The module gate
     * passes for this user, isolating the language check.
     */
    public function testEnrichActionFiltersOutRecordInDisallowedLanguage(): void
    {
        $this->logInBackendUser(6);
        $response = $this->enrichmentController()->enrichAction($this->createJsonRequest(['records' => [
            ['tableName' => 'tt_content', 'columnName' => 'tx_mindfula11y_headingtype', 'uid' => 102],
        ]]));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeJsonResponse($response);
        self::assertSame([], $body['records']);
    }
}
