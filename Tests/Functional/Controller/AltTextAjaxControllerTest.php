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

use MindfulMarkup\MindfulA11y\Controller\AltTextAjaxController;
use MindfulMarkup\MindfulA11y\Domain\Model\GenerateAltTextDemand;
use MindfulMarkup\MindfulA11y\Service\ModuleLabelService;
use MindfulMarkup\MindfulA11y\Tests\Functional\AbstractAuthorizationTestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Authorization coverage of the alt-text-generation AJAX endpoint
 * (AltTextAjaxController::generateAction).
 *
 * The OpenAI API key is deliberately unconfigured in the functional test
 * instance (no ExtensionConfiguration for 'openAIApiKey' is written by any
 * fixture), so OpenAIService::respond() yields null and the controller answers
 * errorResponse('altText.generate.error.openAIConnection', 500). That 500 is
 * this suite's positive discriminator: a demand that clears every authorization
 * gate does NOT succeed (201), it reaches the generation-failure branch. Every
 * "authorized, ends in upstream failure" assertion below targets that exact
 * outcome (see {@see assertOpenAiFailure()}), never a bare "not 4xx".
 *
 * File-mount enforcement is only active when the storage is permission-aware,
 * which TYPO3's StoragePermissionsAspect applies solely for a backend-typed
 * $GLOBALS['TYPO3_REQUEST'] and a non-admin user. setUp() installs a backend
 * request global and creates the two physical fixture files so
 * ResourceFactory/driver checks resolve against real files.
 *
 * NOTE ON THE REQUEST BUILDER: this suite does not use the base class'
 * createJsonRequest(), whose body stream is opened read-only ('php://temp' with
 * the default mode 'r', which is not writable in this environment). It builds
 * the request from a writable body stream instead (see {@see jsonRequest()});
 * the resulting ServerRequest is byte-for-byte equivalent to what the endpoint
 * receives in production.
 *
 * Uses the shared AuthorizationScenario.csv fixture (users 2 full editor,
 * 3 no module, 4 no tt_content modify, 5 no exclude fields, 6 default-language
 * only, 9 no file mount, 12 file read-only permissions; pages 10 editable,
 * 14 no access, 20 outside every db mount; tt_content 100 editable with assets,
 * 105 record editlock; sys_file 1 inside / 2 outside the only mount;
 * sys_file_metadata 1 -> file 1). No supplementary fixture is required.
 */
final class AltTextAjaxControllerTest extends AbstractAuthorizationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // StoragePermissionsAspect only evaluates file-mount boundaries when the
        // current request is a backend request; without this the storage stays
        // permission-blind (evaluatePermissions=false) and every file-mount
        // denial below would false-pass. Set before any storage is created.
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest('https://typo3-testing.local/typo3', 'GET'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);

        // Physical fixture files so the Local driver's existence / getContents()
        // checks resolve. sys_file 1 is inside the only file mount (1:/allowed/),
        // sys_file 2 outside it (/restricted/).
        $fileadmin = $this->instancePath . '/fileadmin';
        GeneralUtility::mkdir_deep($fileadmin . '/allowed');
        GeneralUtility::mkdir_deep($fileadmin . '/restricted');
        file_put_contents($fileadmin . '/allowed/image.jpg', 'fake-jpeg-bytes');
        file_put_contents($fileadmin . '/restricted/secret.jpg', 'fake-jpeg-bytes');
    }

    private function controller(): AltTextAjaxController
    {
        return $this->get(AltTextAjaxController::class);
    }

    /**
     * Build a backend AJAX POST request carrying $payload as a JSON body on a
     * writable stream, with the normalizedParams attribute core middleware
     * would provide.
     *
     * @param array<string, mixed> $payload
     */
    private function jsonRequest(array $payload): ServerRequestInterface
    {
        $body = new Stream('php://temp', 'wb+');
        $body->write(json_encode($payload, JSON_THROW_ON_ERROR));
        $body->rewind();

        $request = new ServerRequest(
            'https://typo3-testing.local/typo3/ajax/mindfula11y',
            'POST',
            $body,
            ['Content-Type' => 'application/json'],
            ['HTTP_HOST' => 'typo3-testing.local', 'HTTPS' => 'on', 'REQUEST_URI' => '/typo3/ajax/mindfula11y'],
        );

        return $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
    }

    /**
     * Build a signed GenerateAltTextDemand and return its wire array. Signing
     * happens in the constructor over the given scope; callers tamper by
     * mutating the returned array afterwards.
     *
     * @param array<string> $recordColumns
     * @return array<string, mixed>
     */
    private function demandPayload(
        int $userId,
        string $recordTable = 'tt_content',
        int $recordUid = 100,
        int $fileUid = 1,
        array $recordColumns = ['assets'],
        int $pageUid = 10,
        int $languageUid = 0,
        int $workspaceId = 0,
        int $expiresAt = 0,
    ): array {
        return (new GenerateAltTextDemand(
            userId: $userId,
            pageUid: $pageUid,
            languageUid: $languageUid,
            workspaceId: $workspaceId,
            recordTable: $recordTable,
            recordUid: $recordUid,
            fileUid: $fileUid,
            recordColumns: $recordColumns,
            expiresAt: $expiresAt,
        ))->toArray();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function generate(array $payload): ResponseInterface
    {
        return $this->controller()->generateAction($this->jsonRequest($payload));
    }

    /**
     * Assert a uniform JSON error response: status code plus the exact
     * localized title JsonErrorResponseTrait::errorResponse() would have built
     * for $expectedLabelKey under the logged-in user's language — resolved via
     * the same LanguageService mechanism the controller uses, so it never drifts
     * from a hardcoded English string.
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

    /**
     * The positive discriminator: authorization fully passed, only OpenAI
     * generation failed (unconfigured key).
     */
    private function assertOpenAiFailure(ResponseInterface $response): void
    {
        $this->assertErrorResponse($response, 500, 'altText.generate.error.openAIConnection');
    }

    // ---------------------------------------------------------------
    // A. Module gate
    // ---------------------------------------------------------------

    public function testModuleGateDeniesUserWithoutModuleAccess(): void
    {
        // User 3 (editor_no_module): group carries no groupMods entry.
        $this->logInBackendUser(3);

        $response = $this->generate($this->demandPayload(3));

        $this->assertErrorResponse($response, 403, 'error.forbidden');
    }

    // ---------------------------------------------------------------
    // B. Malformed body
    // ---------------------------------------------------------------

    public function testEmptyArrayBodyReturnsInvalidRequest(): void
    {
        $this->logInBackendUser(2);

        $response = $this->generate([]);

        $this->assertErrorResponse($response, 400, 'error.invalidRequest');
    }

    public function testNonDemandBodyReturnsInvalidRequest(): void
    {
        $this->logInBackendUser(2);

        $response = $this->generate(['not' => 'a demand']);

        $this->assertErrorResponse($response, 400, 'error.invalidRequest');
    }

    // ---------------------------------------------------------------
    // C. Signature
    // ---------------------------------------------------------------

    public function testTamperedRecordUidReturnsInvalidSignature(): void
    {
        $this->logInBackendUser(2);
        $payload = $this->demandPayload(2);
        // Mutate a signed field after signing without recomputing the HMAC.
        $payload['recordUid'] = 999;

        $response = $this->generate($payload);

        $this->assertErrorResponse($response, 400, 'module.error.invalidSignature');
    }

    public function testExpiredDemandReturnsInvalidSignature(): void
    {
        $this->logInBackendUser(2);
        // Signature is intact (computed over the past expiresAt), but
        // validateSignature()'s "expiresAt > now" check fails first.
        $payload = $this->demandPayload(2, expiresAt: time() - 10);

        $response = $this->generate($payload);

        $this->assertErrorResponse($response, 400, 'module.error.invalidSignature');
    }

    public function testForgedFarFutureExpiryReturnsInvalidSignature(): void
    {
        $this->logInBackendUser(2);
        // Signature is valid over this expiry, but it exceeds the LIFETIME
        // window (expiresAt <= now + LIFETIME fails), so the demand is rejected
        // even before the HMAC comparison — a forged far-future expiry cannot
        // extend a demand's redeemable lifetime.
        $payload = $this->demandPayload(2, expiresAt: time() + 2 * 3600);

        $response = $this->generate($payload);

        $this->assertErrorResponse($response, 400, 'module.error.invalidSignature');
    }

    // ---------------------------------------------------------------
    // D. Session pinning
    // ---------------------------------------------------------------

    public function testUserPinningDeniesDemandRedeemedByAnotherUser(): void
    {
        // Demand signed for user 10, redeemed in user 2's session.
        $this->logInBackendUser(2);

        $response = $this->generate($this->demandPayload(10));

        $this->assertErrorResponse($response, 403, 'error.invalidUser');
    }

    public function testWorkspacePinningDeniesSessionWorkspaceMismatch(): void
    {
        // Session live workspace 0 vs demand workspaceId 1.
        $this->logInBackendUser(2);

        $response = $this->generate($this->demandPayload(2, workspaceId: 1));

        $this->assertErrorResponse($response, 403, 'error.invalidWorkspace');
    }

    public function testWorkspacePinningDeniesWorkspaceSwitchedSession(): void
    {
        // The demand-user matches (2) but the session is switched into
        // workspace 1 while the demand was signed for workspace 0. User 2 is a
        // member of sys_workspace 1.
        $this->logInBackendUser(2, 1);

        $response = $this->generate($this->demandPayload(2, workspaceId: 0));

        $this->assertErrorResponse($response, 403, 'error.invalidWorkspace');
    }

    public function testLanguagePinningDeniesUserWithoutLanguageAccess(): void
    {
        // User 6 (editor_lang_default, be_groups.allowed_languages = "0").
        $this->logInBackendUser(6);

        $response = $this->generate($this->demandPayload(6, languageUid: 1));

        $this->assertErrorResponse($response, 403, 'error.invalidLanguage');
    }

    // ---------------------------------------------------------------
    // E. Page access
    // ---------------------------------------------------------------

    public function testNoPageAccessDeniesPageWithoutPermissions(): void
    {
        // Page 14: perms_user/perms_group/perms_everybody all 0.
        $this->logInBackendUser(2);

        $response = $this->generate($this->demandPayload(2, recordUid: 109, pageUid: 14));

        $this->assertErrorResponse($response, 403, 'error.noPageAccess');
    }

    public function testOutsideWebmountPageDeniesPageAccess(): void
    {
        // SECURITY NOTE (positive): unlike the scan-create endpoint — whose
        // edit-access gate is db-mount-blind — the alt-text endpoint routes its
        // page check through BackendUtility::readPageAccess(), which enforces
        // isInWebMount(). Page 20 is a second site root (pid 0, is_siteroot 1)
        // outside user 2's only db mount (page 1), yet grants PAGE_SHOW via
        // perms_everybody 19. readPageAccess() still returns false because the
        // page is outside the web mount, so the demand is denied. This is the
        // stronger boundary and is asserted here to lock it in.
        $this->logInBackendUser(2);

        $response = $this->generate($this->demandPayload(2, recordUid: 106, pageUid: 20));

        $this->assertErrorResponse($response, 403, 'error.noPageAccess');
    }

    public function testRootPageUidWithNonExemptTableDeniesPageAccess(): void
    {
        // pageUid 0 with tt_content: tt_content does NOT ignore the root-level
        // restriction, so it is not root-level exempt. readPageAccess(0) returns
        // false for a non-admin, so the page gate denies.
        $this->logInBackendUser(2);

        $response = $this->generate($this->demandPayload(2, pageUid: 0));

        $this->assertErrorResponse($response, 403, 'error.noPageAccess');
    }

    // ---------------------------------------------------------------
    // F. Record access
    // ---------------------------------------------------------------

    public function testNoTableModifyDeniesRecordAccess(): void
    {
        // User 4 (no_content_modify): tables_modify excludes tt_content. Page
        // and sys_file gates pass, so the denial is the record-level check.
        $this->logInBackendUser(4);

        $response = $this->generate($this->demandPayload(4, recordUid: 100));

        $this->assertErrorResponse($response, 403, 'error.invalidRecordAccess');
    }

    public function testExcludeFieldNotGrantedDeniesRecordAccess(): void
    {
        // User 5 (no_exclude_fields): non_exclude_fields empty, and
        // tt_content.tx_mindfula11y_headingtype is exclude=true. Table-write,
        // language and CType checks pass; the exclude-field check denies.
        $this->logInBackendUser(5);

        $response = $this->generate($this->demandPayload(5, recordUid: 100, recordColumns: ['tx_mindfula11y_headingtype']));

        $this->assertErrorResponse($response, 403, 'error.invalidRecordAccess');
    }

    public function testRecordEditlockDeniesRecordAccess(): void
    {
        // tt_content 105 carries editlock=1 on an otherwise editable page.
        $this->logInBackendUser(2);

        $response = $this->generate($this->demandPayload(2, recordUid: 105, recordColumns: []));

        $this->assertErrorResponse($response, 403, 'error.invalidRecordAccess');
    }

    public function testNonexistentRecordDeniesRecordAccess(): void
    {
        // Fail-closed: a missing record must reject outright, not fall through.
        $this->logInBackendUser(2);

        $response = $this->generate($this->demandPayload(2, recordUid: 999999));

        $this->assertErrorResponse($response, 403, 'error.invalidRecordAccess');
    }

    // ---------------------------------------------------------------
    // G. File access
    // ---------------------------------------------------------------

    public function testFileOutsideMountDeniesFileAccess(): void
    {
        // sys_file 2 (/restricted/secret.jpg) is outside user 2's only file
        // mount (1:/allowed/). Page and record gates pass; the file-mount
        // boundary denies.
        $this->logInBackendUser(2);

        $response = $this->generate($this->demandPayload(2, recordUid: 100, fileUid: 2));

        $this->assertErrorResponse($response, 403, 'error.noFileMountAccess');
    }

    public function testUserWithoutFileMountsDeniesFileAccess(): void
    {
        // User 9 (no_filemount): group has no file_mountpoints. Page and record
        // gates pass (proved by the distinct label), so the file gate denies.
        $this->logInBackendUser(9);

        $response = $this->generate($this->demandPayload(9, recordUid: 100, fileUid: 1));

        $this->assertErrorResponse($response, 403, 'error.noFileMountAccess');
    }

    public function testNonexistentFileReturnsFileNotFound(): void
    {
        // Reached only after page + record authorization pass (user 2, record
        // 100): getFileObject() throws FileDoesNotExistException -> 404.
        $this->logInBackendUser(2);

        $response = $this->generate($this->demandPayload(2, recordUid: 100, fileUid: 999999));

        $this->assertErrorResponse($response, 404, 'error.fileNotFound');
    }

    // ---------------------------------------------------------------
    // H. sys_file_metadata path (root-level exempt)
    // ---------------------------------------------------------------

    public function testMetadataPathFullyAuthorizedEndsInOpenAiFailure(): void
    {
        // sys_file_metadata 1 -> file 1 at pid 0. sys_file_metadata ignores the
        // root-level restriction, so pageUid 0 is exempt from the page gate; the
        // record boundary is table-write + non-exclude fields, and the file gate
        // is editMeta (writable-mount boundary). User 2 clears all of them, so
        // only generation fails. This is the metadata-path positive baseline.
        $this->logInBackendUser(2);

        $response = $this->generate($this->demandPayload(
            2,
            recordTable: 'sys_file_metadata',
            recordUid: 1,
            fileUid: 1,
            recordColumns: ['alternative'],
            pageUid: 0,
        ));

        $this->assertOpenAiFailure($response);
    }

    public function testMetadataPathFileReadOnlyUserStillAuthorized(): void
    {
        // SECURITY NOTE: user 12 (file_read_only) has file_permissions
        // readFolder,readFile but NOT writeFile. The sys_file_metadata file gate
        // uses ResourceStorage::checkFileActionPermission('editMeta'), which
        // (core, ResourceStorage ~line 659) returns purely on the writable file
        // MOUNT boundary and does NOT consult the user's writeFile capability.
        // User 12's mount 1:/allowed/ is writable, so editMeta passes and the
        // request is authorized (reaches the OpenAI-failure 500) despite the
        // user lacking any file-write permission. Asserting current behaviour:
        // sys_file_metadata alt-text generation is gated by mount writability,
        // not by the writeFile permission bit.
        $this->logInBackendUser(12);

        $response = $this->generate($this->demandPayload(
            12,
            recordTable: 'sys_file_metadata',
            recordUid: 1,
            fileUid: 1,
            recordColumns: ['alternative'],
            pageUid: 0,
        ));

        $this->assertOpenAiFailure($response);
    }

    public function testMetadataPathUserWithoutFileMountsDeniesFileAccess(): void
    {
        // Gate order proof: user 9 (no file mounts) has sys_file_metadata in
        // tables_modify, so the root-level record boundary (table-write +
        // non-exclude fields) passes and execution reaches the editMeta file
        // gate, which denies because the file is in no mount of user 9 —
        // yielding noFileMountAccess (not invalidRecordAccess).
        $this->logInBackendUser(9);

        $response = $this->generate($this->demandPayload(
            9,
            recordTable: 'sys_file_metadata',
            recordUid: 1,
            fileUid: 1,
            recordColumns: ['alternative'],
            pageUid: 0,
        ));

        $this->assertErrorResponse($response, 403, 'error.noFileMountAccess');
    }

    // ---------------------------------------------------------------
    // I. Positive baseline (tt_content path)
    // ---------------------------------------------------------------

    public function testFullyAuthorizedRequestEndsInOpenAiFailure(): void
    {
        // Every gate above is exercised with this same user/page/record/file
        // combination elsewhere in this suite, so this can never pass vacuously:
        // user 2, page 10, record 100, file 1 (in mount), column 'assets',
        // language 0, workspace 0 -> only OpenAI generation fails.
        $this->logInBackendUser(2);

        $response = $this->generate($this->demandPayload(2, recordUid: 100, fileUid: 1, recordColumns: ['assets']));

        $this->assertOpenAiFailure($response);
    }
}
