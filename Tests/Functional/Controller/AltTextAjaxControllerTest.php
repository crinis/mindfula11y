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
use MindfulMarkup\MindfulA11y\Service\DemandSignatureService;
use MindfulMarkup\MindfulA11y\Service\ModuleLabelService;
use MindfulMarkup\MindfulA11y\Service\RecordSnapshotService;
use MindfulMarkup\MindfulA11y\Tests\Functional\AbstractAuthorizationTestCase;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;

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
 * $GLOBALS['TYPO3_REQUEST'] and a non-admin user. The base class provides both
 * prerequisites: logInBackendUser() publishes such a request and setUp()
 * creates the physical fixture files ResourceFactory/driver checks resolve
 * against.
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
    private function controller(): AltTextAjaxController
    {
        return $this->get(AltTextAjaxController::class);
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
        ?int $fileReferenceUid = null,
        array $recordColumns = ['assets'],
        int $pageUid = 10,
        int $languageUid = 0,
        int $workspaceId = 0,
        int $expiresAt = 0,
    ): array {
        $fileReferenceUid ??= $recordTable === 'sys_file_metadata' ? 0 : $fileUid;
        $record = BackendUtility::getRecordWSOL($recordTable, $recordUid);
        $fileRecord = BackendUtility::getRecordWSOL('sys_file', $fileUid);
        $reference = $fileReferenceUid > 0
            ? BackendUtility::getRecordWSOL('sys_file_reference', $fileReferenceUid)
            : null;
        $snapshotService = $this->get(RecordSnapshotService::class);
        return $this->get(DemandSignatureService::class)->serialize(new GenerateAltTextDemand(
            userId: $userId,
            pageUid: $pageUid,
            languageUid: $languageUid,
            workspaceId: $workspaceId,
            recordTable: $recordTable,
            recordUid: $recordUid,
            fileUid: $fileUid,
            fileReferenceUid: $fileReferenceUid,
            fileSnapshot: is_array($fileRecord)
                ? $snapshotService->fingerprint('sys_file', $fileRecord)
                : str_repeat('0', 64),
            recordSnapshot: is_array($record)
                ? $snapshotService->fingerprint($recordTable, $record)
                : str_repeat('0', 64),
            fileReferenceSnapshot: is_array($reference)
                ? $snapshotService->fingerprint('sys_file_reference', $reference)
                : '',
            recordColumns: $recordColumns,
            expiresAt: $expiresAt ?: time() + GenerateAltTextDemand::LIFETIME,
        ));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function generate(array $payload): ResponseInterface
    {
        return $this->controller()->generateAction($this->createJsonRequest($payload));
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
        // DemandSignatureService::isValid()'s "expiresAt > now" check fails first.
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

    public function testRootPageUidThatNoLongerMatchesRecordInvalidatesDemand(): void
    {
        // The signed page is part of the snapshot. tt_content 100 still has
        // pid=10, so a demand carrying pageUid=0 is stale before permissions.
        $this->logInBackendUser(2);

        $response = $this->generate($this->demandPayload(2, pageUid: 0));

        $this->assertErrorResponse($response, 403, 'error.invalidRecordAccess');
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

        $response = $this->generate($this->demandPayload(2, recordUid: 105, recordColumns: ['assets']));

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
    // G. Signed snapshot freshness
    // ---------------------------------------------------------------

    public function testMovedRecordInvalidatesDemand(): void
    {
        $this->logInBackendUser(2);
        $payload = $this->demandPayload(2);
        $this->getConnectionPool()->getConnectionForTable('tt_content')->update(
            'tt_content',
            ['pid' => 13],
            ['uid' => 100],
        );

        $response = $this->generate($payload);

        $this->assertErrorResponse($response, 403, 'error.invalidRecordAccess');
    }

    public function testRecordLanguageChangeInvalidatesDemand(): void
    {
        $this->logInBackendUser(2);
        $payload = $this->demandPayload(2);
        $this->getConnectionPool()->getConnectionForTable('tt_content')->update(
            'tt_content',
            ['sys_language_uid' => 1],
            ['uid' => 100],
        );

        $response = $this->generate($payload);

        $this->assertErrorResponse($response, 403, 'error.invalidRecordAccess');
    }

    public function testMainRecordContentChangeInvalidatesDemand(): void
    {
        $this->logInBackendUser(2);
        $payload = $this->demandPayload(2);
        $this->getConnectionPool()->getConnectionForTable('tt_content')->update(
            'tt_content',
            ['header' => 'Changed after demand issuance'],
            ['uid' => 100],
        );

        $response = $this->generate($payload);

        $this->assertErrorResponse($response, 403, 'error.invalidRecordAccess');
    }

    public function testReferenceFileChangeInvalidatesDemand(): void
    {
        $this->logInBackendUser(2);
        $payload = $this->demandPayload(2);
        $this->getConnectionPool()->getConnectionForTable('sys_file_reference')->update(
            'sys_file_reference',
            ['uid_local' => 2],
            ['uid' => 1],
        );

        $response = $this->generate($payload);

        $this->assertErrorResponse($response, 403, 'error.invalidRecordAccess');
    }

    public function testReferenceReattachmentInvalidatesDemand(): void
    {
        $this->logInBackendUser(2);
        $payload = $this->demandPayload(2);
        $this->getConnectionPool()->getConnectionForTable('sys_file_reference')->update(
            'sys_file_reference',
            ['uid_foreign' => 101],
            ['uid' => 1],
        );

        $response = $this->generate($payload);

        $this->assertErrorResponse($response, 403, 'error.invalidRecordAccess');
    }

    public function testReferenceContentChangeInvalidatesDemand(): void
    {
        $this->logInBackendUser(2);
        $payload = $this->demandPayload(2);
        $this->getConnectionPool()->getConnectionForTable('sys_file_reference')->update(
            'sys_file_reference',
            ['alternative' => 'Changed after demand issuance'],
            ['uid' => 1],
        );

        $response = $this->generate($payload);

        $this->assertErrorResponse($response, 403, 'error.invalidRecordAccess');
    }

    // ---------------------------------------------------------------
    // H. File access
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

    public function testFileThatNoLongerMatchesReferenceInvalidatesDemand(): void
    {
        // Reference 1 still points to file 1, so an otherwise intact demand
        // claiming file 999 is stale rather than a free-standing file lookup.
        $this->logInBackendUser(2);

        $response = $this->generate($this->demandPayload(2, recordUid: 100, fileUid: 999999));

        $this->assertErrorResponse($response, 403, 'error.invalidRecordAccess');
    }

    public function testFileRecordChangeInvalidatesDemand(): void
    {
        $this->logInBackendUser(2);
        $payload = $this->demandPayload(2);
        $this->getConnectionPool()->getConnectionForTable('sys_file')->update(
            'sys_file',
            ['name' => 'renamed-after-demand.jpg'],
            ['uid' => 1],
        );

        $response = $this->generate($payload);

        $this->assertErrorResponse($response, 403, 'error.invalidRecordAccess');
    }

    // ---------------------------------------------------------------
    // I. Direct sys_file_reference path
    // ---------------------------------------------------------------

    public function testFileReferencePathFullyAuthorizedEndsInOpenAiFailure(): void
    {
        // FormEngine issues this shape when the edited record itself is the
        // reference. The signed reference UID must identify that exact row.
        $this->logInBackendUser(2);

        $response = $this->generate($this->demandPayload(
            2,
            recordTable: 'sys_file_reference',
            recordUid: 1,
            fileUid: 1,
            fileReferenceUid: 1,
            recordColumns: ['alternative'],
        ));

        $this->assertOpenAiFailure($response);
    }

    public function testFileReferencePathRejectsDifferentSignedReferenceUid(): void
    {
        $this->logInBackendUser(2);

        $response = $this->generate($this->demandPayload(
            2,
            recordTable: 'sys_file_reference',
            recordUid: 1,
            fileUid: 1,
            fileReferenceUid: 2,
            recordColumns: ['alternative'],
        ));

        $this->assertErrorResponse($response, 403, 'error.invalidRecordAccess');
    }

    // ---------------------------------------------------------------
    // J. sys_file_metadata path (root-level exempt)
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
    // K. Positive baseline (tt_content path)
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
