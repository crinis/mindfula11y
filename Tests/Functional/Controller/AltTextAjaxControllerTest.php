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
use MindfulMarkup\MindfulA11y\Service\AltTextFinderService;
use MindfulMarkup\MindfulA11y\Service\DemandSignatureService;
use MindfulMarkup\MindfulA11y\Service\RecordSnapshotService;
use MindfulMarkup\MindfulA11y\Service\ScanCreationService;
use MindfulMarkup\MindfulA11y\Tests\Functional\AbstractAuthorizationTestCase;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Fluid\Core\Rendering\RenderingContextFactory;
use TYPO3Fluid\Fluid\View\TemplateView;

/**
 * Authorization coverage of the alt-text-generation AJAX endpoint
 * (AltTextAjaxController::generateAction).
 *
 * setUp() configures a dummy OpenAI API key (redemption requires one) and an
 * HTTP handler that rejects every outgoing request, so no request ever leaves
 * the test, OpenAIService::respond() yields null and the controller answers
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
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mindfula11y']['openAIApiKey'] = 'sk-functional-test';
        // Short-circuits Guzzle's handler stack: the request fails like an
        // unreachable upstream instead of reaching api.openai.com.
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler']['mindfula11y-offline'] =
            static fn(callable $handler): callable =>
                static fn(RequestInterface $request): PromiseInterface =>
                    Create::rejectionFor(new ConnectException('Offline functional test instance', $request));
    }

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
     * The positive discriminator: authorization fully passed, only OpenAI
     * generation failed (offline HTTP handler).
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

    /**
     * The extension-configuration off-switch is the one mutable gate an
     * already-issued demand could otherwise outlive: controls stop rendering
     * once disableAltTextGeneration is set, but a captured demand stays
     * signature-valid for its full lifetime. Redemption must re-check the
     * switch, like every other gate is re-checked.
     */
    public function testDisabledIntegrationDeniesRedemptionOfAnIssuedDemand(): void
    {
        $this->logInBackendUser(2);
        $payload = $this->demandPayload(2);

        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mindfula11y']['disableAltTextGeneration'] = '1';

        $this->assertErrorResponse($this->generate($payload), 403, 'altText.generate.error.disabled');
    }

    /**
     * Removing the API key is the other way to switch generation off. Without
     * the redemption check a pre-rendered control would still upload the image
     * to OpenAI, only for the unauthenticated request to be rejected there.
     */
    public function testRemovedApiKeyDeniesRedemptionOfAnIssuedDemand(): void
    {
        $this->logInBackendUser(2);
        $payload = $this->demandPayload(2);

        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mindfula11y']['openAIApiKey'] = '';

        $this->assertErrorResponse($this->generate($payload), 403, 'altText.generate.error.disabled');
    }

    /**
     * Control for the two off-switch tests: the same demand with generation
     * available reaches the generation step, so their 403 is the switch.
     */
    public function testAvailableIntegrationRedeemsAnIssuedDemand(): void
    {
        $this->logInBackendUser(2);

        $this->assertOpenAiFailure($this->generate($this->demandPayload(2)));
    }

    /**
     * The wire contract of a successful generation: a text keeps the
     * released `{altText}` body, while the model's decorative verdict is
     * answered as `{decorative: true}` without any text the clients could
     * store as alternative text.
     *
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function modelAnswerProvider(): array
    {
        return [
            'a text' => ['A red bicycle leaning on a wall', ['altText' => 'A red bicycle leaning on a wall']],
            'the decorative verdict' => ['DECORATIVE', ['decorative' => true]],
        ];
    }

    /**
     * @param array<string, mixed> $expectedBody
     */
    #[DataProvider('modelAnswerProvider')]
    public function testGenerationAnswersTheModelsTextOrDecorativeVerdict(string $modelAnswer, array $expectedBody): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler']['mindfula11y-offline'] =
            static fn(callable $handler): callable =>
                static fn(RequestInterface $request): PromiseInterface => Create::promiseFor(new Response(
                    200,
                    ['Content-Type' => 'application/json'],
                    json_encode([
                        'output' => [[
                            'type' => 'message',
                            'content' => [['type' => 'output_text', 'text' => $modelAnswer]],
                        ]],
                    ], JSON_THROW_ON_ERROR),
                ));
        $this->logInBackendUser(2);

        $response = $this->generate($this->demandPayload(2));

        self::assertSame(201, $response->getStatusCode());
        self::assertSame($expectedBody, $this->decodeJsonResponse($response));
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

    // ---------------------------------------------------------------
    // L. "All languages" file references
    // ---------------------------------------------------------------

    /**
     * Listing and redemption apply one language rule
     * (FileReferenceLanguageScope): a reference of the listed language counts
     * whatever its parent stores; one stored for "All languages" (-1) counts
     * under a parent stored for -1 or for the listed language, and always
     * under a parent table without a language field. Every listed reference
     * must therefore redeem the demand the list signs for it, and a demand
     * signed for a language that does not list the reference must not.
     *
     * Covers the realistic mismatches: content switched to "All languages"
     * after images were added keeps its references at language 0, and
     * inconsistent rows (parent 0 / reference 1).
     *
     * @return array<string, array{string, int|null, int, int}>
     */
    public static function listingAgreementProvider(): array
    {
        $cases = [];
        foreach ([-1, 0, 1] as $parentLanguageUid) {
            foreach ([-1, 0, 1] as $referenceLanguageUid) {
                foreach ([0, 1] as $listedLanguageUid) {
                    $cases["parent $parentLanguageUid, reference $referenceLanguageUid, list $listedLanguageUid"]
                        = ['tt_content', $parentLanguageUid, $referenceLanguageUid, $listedLanguageUid];
                }
            }
        }
        foreach ([-1, 0, 1] as $referenceLanguageUid) {
            foreach ([0, 1] as $listedLanguageUid) {
                $cases["parent table without language field, reference $referenceLanguageUid, list $listedLanguageUid"]
                    = ['tx_a11ytest_gallery', null, $referenceLanguageUid, $listedLanguageUid];
            }
        }

        return $cases;
    }

    #[DataProvider('listingAgreementProvider')]
    public function testListedReferenceIsRedeemableAndOnlyWhereListed(
        string $parentTable,
        ?int $parentLanguageUid,
        int $referenceLanguageUid,
        int $listedLanguageUid,
    ): void {
        $column = $parentTable === 'tt_content' ? 'assets' : 'images';
        $connectionPool = $this->getConnectionPool();
        if ($parentTable === 'tt_content') {
            $this->importCSVDataSet(__DIR__ . '/../Fixtures/AllLanguagesReferenceSupplement.csv');
            $connectionPool->getConnectionForTable('tt_content')
                ->update('tt_content', ['sys_language_uid' => $parentLanguageUid], ['uid' => 410]);
            $connectionPool->getConnectionForTable('sys_file_reference')
                ->update('sys_file_reference', ['sys_language_uid' => $referenceLanguageUid], ['uid' => 410]);
        } else {
            // Fixture extension table tx_a11ytest_gallery: a file field, no
            // language field. The full editor's group gets the table.
            $connectionPool->getConnectionForTable('tx_a11ytest_gallery')
                ->insert('tx_a11ytest_gallery', ['uid' => 410, 'pid' => 10, 'title' => 'Gallery', 'images' => 1]);
            $connectionPool->getConnectionForTable('sys_file_reference')->insert('sys_file_reference', [
                'uid' => 410,
                'pid' => 10,
                'uid_local' => 1,
                'uid_foreign' => 410,
                'tablenames' => 'tx_a11ytest_gallery',
                'fieldname' => 'images',
                'sys_language_uid' => $referenceLanguageUid,
                'alternative' => '',
            ]);
            $groups = $connectionPool->getConnectionForTable('be_groups');
            $group = $groups->select(['tables_select', 'tables_modify'], 'be_groups', ['uid' => 1])->fetchAssociative();
            $groups->update('be_groups', [
                'tables_select' => $group['tables_select'] . ',tx_a11ytest_gallery',
                'tables_modify' => $group['tables_modify'] . ',tx_a11ytest_gallery',
            ], ['uid' => 1]);
        }
        $this->logInBackendUser(2);

        $listed = array_values(array_filter(
            $this->get(AltTextFinderService::class)->findAltlessFileReferencePage(
                10,
                0,
                $listedLanguageUid,
                [],
                1,
                100,
                tableName: $parentTable,
            )['items'],
            static fn($reference): bool => (int)$reference->getUid() === 410,
        ));
        // The listing rule itself is pinned too, so the agreement below cannot
        // hold merely because both sides changed together.
        $expectedListed = $referenceLanguageUid === $listedLanguageUid
            || ($referenceLanguageUid === -1
                && ($parentLanguageUid === null || in_array($parentLanguageUid, [$listedLanguageUid, -1], true)));
        self::assertSame($expectedListed, $listed !== [], 'the list shows the reference exactly where it renders');

        if ($listed === []) {
            $this->assertErrorResponse($this->generate($this->demandPayload(
                2,
                recordTable: $parentTable,
                recordUid: 410,
                fileUid: 1,
                fileReferenceUid: 410,
                recordColumns: [$column],
                languageUid: $listedLanguageUid,
            )), 403, 'error.invalidRecordAccess');

            return;
        }

        $context = $this->get(RenderingContextFactory::class)->create();
        $context->getTemplatePaths()->setTemplateSource(
            '<html xmlns:mindfula11y="http://typo3.org/ns/MindfulMarkup/MindfulA11y/ViewHelpers" data-namespace-typo3-fluid="true">'
            . '<mindfula11y:altlessFileReference fileReference="{reference}" languageId="{languageId}" />'
            . '</html>'
        );
        $view = new TemplateView($context);
        $view->assignMultiple(['reference' => $listed[0], 'languageId' => $listedLanguageUid]);
        self::assertSame(
            1,
            preg_match('/generate-alt-text-demand="([^"]+)"/', $view->render(), $match),
            'the list offers generation',
        );
        $payload = json_decode(html_entity_decode($match[1]), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($listedLanguageUid, $payload['languageUid'], 'the demand carries the listed language');

        $this->assertOpenAiFailure($this->generate($payload));
    }

    /**
     * The FormEngine field control edits the reference itself and signs the
     * reference's own language; redemption holds it to exactly that language.
     */
    public function testDirectReferenceDemandIsBoundToTheReferencesOwnLanguage(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/AllLanguagesReferenceSupplement.csv');
        $this->logInBackendUser(2);
        $demand = fn(int $languageUid): array => $this->demandPayload(
            2,
            recordTable: 'sys_file_reference',
            recordUid: 410,
            fileUid: 1,
            fileReferenceUid: 410,
            recordColumns: ['alternative'],
            languageUid: $languageUid,
        );

        $this->assertOpenAiFailure($this->generate($demand(-1)));
        $this->assertErrorResponse($this->generate($demand(1)), 403, 'error.invalidRecordAccess');
    }

    /**
     * A reference of a concrete language is listed in that language only, and
     * its demand redeems in that language only.
     */
    public function testConcreteLanguageReferenceDemandStaysBoundToItsLanguage(): void
    {
        $this->logInBackendUser(2);

        $response = $this->generate($this->demandPayload(2, recordUid: 100, fileUid: 1, languageUid: 1));

        $this->assertErrorResponse($response, 403, 'error.invalidRecordAccess');
    }

    /**
     * What the list renders must be what redemption accepts: the Generate
     * button of a listed "All languages" reference is redeemable, and the
     * text is generated in the listed language — the language whose metadata
     * text the same row advertises as inherited.
     *
     * @return array<string, array{int, int, string}>
     */
    public static function listedAllLanguagesReferenceProvider(): array
    {
        return [
            'all-languages parent in the French list' => [-1, 1, 'fr'],
            'all-languages parent in the default-language list' => [-1, 0, 'en'],
            'default-language parent in the default-language list' => [0, 0, 'en'],
            'French parent in the French list' => [1, 1, 'fr'],
        ];
    }

    #[DataProvider('listedAllLanguagesReferenceProvider')]
    public function testListedAllLanguagesReferenceGeneratesInTheListedLanguage(
        int $parentLanguageUid,
        int $listedLanguageUid,
        string $expectedLanguageCode,
    ): void {
        $this->writeDefaultSiteConfiguration();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/AllLanguagesReferenceSupplement.csv');
        $this->getConnectionPool()->getConnectionForTable('tt_content')->update(
            'tt_content',
            ['sys_language_uid' => $parentLanguageUid],
            ['uid' => 410],
        );
        $this->logInBackendUser(2);

        $listed = array_values(array_filter(
            $this->get(AltTextFinderService::class)->findAltlessFileReferencePage(
                10,
                0,
                $listedLanguageUid,
                [],
                1,
                100,
                tableName: 'tt_content',
            )['items'],
            static fn($reference): bool => (int)$reference->getUid() === 410,
        ));
        self::assertCount(1, $listed, 'fixture guard: the list shows the all-languages reference');

        $context = $this->get(RenderingContextFactory::class)->create();
        $context->getTemplatePaths()->setTemplateSource(
            '<html xmlns:mindfula11y="http://typo3.org/ns/MindfulMarkup/MindfulA11y/ViewHelpers" data-namespace-typo3-fluid="true">'
            . '<mindfula11y:altlessFileReference fileReference="{reference}" languageId="{languageId}" />'
            . '</html>'
        );
        $view = new TemplateView($context);
        $view->assignMultiple(['reference' => $listed[0], 'languageId' => $listedLanguageUid]);
        self::assertSame(
            1,
            preg_match('/generate-alt-text-demand="([^"]+)"/', $view->render(), $match),
            'the list offers generation',
        );
        $payload = json_decode(html_entity_decode($match[1]), true, 512, JSON_THROW_ON_ERROR);

        // Offline like setUp()'s handler, but recording what would be sent.
        // Arrow functions capture by value, so both levels capture explicitly.
        $instructions = [];
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler']['mindfula11y-offline'] =
            static function (callable $handler) use (&$instructions): callable {
                return static function (RequestInterface $request) use (&$instructions): PromiseInterface {
                    $instructions[] = (string)(json_decode((string)$request->getBody(), true)['instructions'] ?? '');

                    return Create::rejectionFor(new ConnectException('Offline functional test instance', $request));
                };
            };

        $this->assertOpenAiFailure($this->generate($payload));
        self::assertCount(1, $instructions, 'redemption reached the OpenAI request');
        self::assertStringContainsString('ISO language code: ' . $expectedLanguageCode . '.', $instructions[0]);
    }

    // ---------------------------------------------------------------
    // M. Page records (pages.media)
    // ---------------------------------------------------------------

    /**
     * A file reference in the media field of a page. Core stores the file
     * references of a page on the page itself — a translation's on its
     * default-language page (TcaInline::addInlineFirstPid(),
     * DataHandler::resolveSortingAndPidForNewRecord()) — so the list signs
     * that page, while the page record's own pid is its parent page.
     * pages:media is an exclude field; the full editor's group gets it.
     */
    private function preparePageMediaReference(int $pageUid, int $languageUid): void
    {
        $this->getConnectionPool()->getConnectionForTable('sys_file_reference')->insert('sys_file_reference', [
            'uid' => 420,
            'pid' => 10,
            'uid_local' => 1,
            'uid_foreign' => $pageUid,
            'tablenames' => 'pages',
            'fieldname' => 'media',
            'sys_language_uid' => $languageUid,
            'alternative' => '',
        ]);
        $this->getConnectionPool()->getConnectionForTable('pages')->update('pages', ['media' => 1], ['uid' => $pageUid]);
        $groups = $this->getConnectionPool()->getConnectionForTable('be_groups');
        $group = $groups->select(['non_exclude_fields'], 'be_groups', ['uid' => 1])->fetchAssociative();
        $groups->update('be_groups', ['non_exclude_fields' => $group['non_exclude_fields'] . ',pages:media'], ['uid' => 1]);
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function pageMediaProvider(): array
    {
        return [
            'default-language page' => [10, 0],
            'translated page' => [30, 1],
        ];
    }

    #[DataProvider('pageMediaProvider')]
    public function testPageMediaReferenceDemandIsRedeemable(int $pageUid, int $languageUid): void
    {
        $this->preparePageMediaReference($pageUid, $languageUid);
        $this->logInBackendUser(2);

        $response = $this->generate($this->demandPayload(
            2,
            recordTable: 'pages',
            recordUid: $pageUid,
            fileUid: 1,
            fileReferenceUid: 420,
            recordColumns: ['media'],
            pageUid: 10,
            languageUid: $languageUid,
        ));

        $this->assertOpenAiFailure($response);
    }

    /**
     * Core rewrites pages.SYS_LASTCHANGED on the first uncached frontend
     * render after a content change — v13
     * TypoScriptFrontendController::setSysLastChanged(), v14
     * RequestHandler::updateSysLastChangedInPageRecord(), both a plain
     * connection update — including the uncached render of the extension's
     * own structure analysis. Nobody edited the page, so a Generate button
     * for one of its images must survive it. A real edit still invalidates.
     */
    public function testPageMediaDemandSurvivesTheFrontendRendersSysLastChangedWrite(): void
    {
        $this->preparePageMediaReference(10, 0);
        $this->logInBackendUser(2);
        $demand = fn(): array => $this->demandPayload(
            2,
            recordTable: 'pages',
            recordUid: 10,
            fileUid: 1,
            fileReferenceUid: 420,
            recordColumns: ['media'],
            pageUid: 10,
        );
        $pages = $this->getConnectionPool()->getConnectionForTable('pages');

        $payload = $demand();
        $pages->update('pages', ['SYS_LASTCHANGED' => time() + 60], ['uid' => 10]);
        $this->assertOpenAiFailure($this->generate($payload));

        $payload = $demand();
        $pages->update('pages', ['title' => 'Edited meanwhile'], ['uid' => 10]);
        $this->assertErrorResponse($this->generate($payload), 403, 'error.invalidRecordAccess');
    }

    /**
     * Creating a scan stores its id on the scanned page through DataHandler
     * (ScanCreationService::storeScanId()), which also writes tstamp and
     * l10n_diffsource — a scan auto-created in another tab is enough. Nobody
     * edited the page, so its Generate buttons must survive; an edit of the
     * page or of its media still invalidates them.
     */
    #[DataProvider('pageMediaProvider')]
    public function testPageMediaDemandSurvivesTheScanBookkeepingWrite(int $pageUid, int $languageUid): void
    {
        // DataHandler synchronizes the page's translations on save and
        // resolves their languages through the site.
        $this->writeDefaultSiteConfiguration();
        $this->preparePageMediaReference($pageUid, $languageUid);
        $backendUser = $this->logInBackendUser(2);
        // A page saved through the backend carries its initialized l10n_state
        // (a translation's per-field synchronization, which editors change
        // and which therefore stays pinned); the fixture row gets it the same
        // way before the demand is issued.
        $this->runDataHandler(['pages' => [$pageUid => ['title' => 'Saved once']]], $backendUser);
        $demand = fn(): array => $this->demandPayload(
            2,
            recordTable: 'pages',
            recordUid: $pageUid,
            fileUid: 1,
            fileReferenceUid: 420,
            recordColumns: ['media'],
            pageUid: 10,
            languageUid: $languageUid,
        );
        $pages = $this->getConnectionPool()->getConnectionForTable('pages');
        $storeScanId = new \ReflectionMethod(ScanCreationService::class, 'storeScanId');

        $payload = $demand();
        $GLOBALS['EXEC_TIME'] += 60;
        self::assertTrue($storeScanId->invoke($this->get(ScanCreationService::class), $pageUid, 'scan-1'));
        self::assertSame('scan-1', $this->fetchRow('pages', $pageUid)['tx_mindfula11y_scanid'], 'fixture guard: the scan id was stored');
        $this->assertOpenAiFailure($this->generate($payload));

        foreach (['title' => 'Edited meanwhile', 'media' => 2] as $column => $value) {
            $payload = $demand();
            $pages->update('pages', [$column => $value], ['uid' => $pageUid]);
            $this->assertErrorResponse($this->generate($payload), 403, 'error.invalidRecordAccess');
        }
    }
}
