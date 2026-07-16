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

namespace MindfulMarkup\MindfulA11y\Tests\Functional\Service;

use MindfulMarkup\MindfulA11y\Service\PermissionService;
use MindfulMarkup\MindfulA11y\Tests\Functional\AbstractAuthorizationTestCase;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;

/**
 * Functional tests for PermissionService::checkFileReadAccess() and
 * ::checkFileMetaEditAccess(), covering FAL file mount authorization.
 *
 * Both methods are thin wrappers around
 * TYPO3\CMS\Core\Resource\ResourceStorage::checkFileActionPermission(), whose
 * behaviour is only fully assembled at runtime by
 * TYPO3\CMS\Core\Resource\Security\StoragePermissionsAspect (injects the
 * logged-in user's file mounts/permissions into non-fallback storages) - so
 * these checks can only be verified functionally, not with a unit test.
 *
 * IMPORTANT: ResourceFactory/StorageRepository cache storage instances for
 * the lifetime of the container, and storage permission injection happens
 * once, at first use, based on whoever is $GLOBALS['BE_USER'] at that moment.
 * Every test method here therefore logs in exactly one user and must not
 * switch users mid-test - each test method gets a fresh container (see
 * AbstractAuthorizationTestCase / FunctionalTestCase::setUp()), so this is
 * safe as long as no test mixes users.
 *
 * Fixture files (from the shared AuthorizationScenario.csv):
 *  - sys_file 1 "/allowed/image.jpg" on storage 1, inside file mount 1 ("1:/allowed/").
 *  - sys_file 2 "/restricted/secret.jpg" on storage 1, outside every file mount.
 *  - Users: 1 admin; 2 editor (mount 1, full read/write file_permissions);
 *    9 editor_no_filemount (no file mounts, full file_permissions);
 *    12 editor_file_read_only (mount 1, file_permissions readFolder,readFile only).
 *
 * Supplementary fixture (FilePermissionSupplement.csv, uids >= 300):
 *  - sys_file 300 "/fallback-file.jpg" on storage 0 (the built-in fallback
 *    storage covering the public web root) - for fallback-storage parity.
 *  - sys_filemounts 300 = "1:/allowed/" with read_only = 1, be_groups 300 /
 *    be_users 300 (editor_readonly_mount) granting that mount plus full
 *    readFolder/readFile/writeFile file_permissions - for read-only file
 *    mount coverage (sys_filemounts.read_only exists as a TCA/DB column on
 *    this TYPO3 version).
 */
final class FilePermissionTest extends AbstractAuthorizationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/FilePermissionSupplement.csv');

        // Physical files backing the DB rows: FAL's LocalDriver checks
        // real filesystem existence/readability (checkFileActionPermission()
        // Check 5/6), so the sys_file rows alone are not enough.
        // The instance directory is reused across test methods of this test
        // case (only the DB is re-imported per test), so guard against the
        // directories already existing from a previous test method.
        $fileadmin = $this->instancePath . '/fileadmin';
        if (!is_dir($fileadmin . '/allowed')) {
            mkdir($fileadmin . '/allowed', 0777, true);
        }
        if (!is_dir($fileadmin . '/restricted')) {
            mkdir($fileadmin . '/restricted', 0777, true);
        }
        file_put_contents($fileadmin . '/allowed/image.jpg', 'dummy-jpg-content');
        file_put_contents($fileadmin . '/restricted/secret.jpg', 'dummy-jpg-content');

        // Storage 0 (the fallback storage) covers the instance's public web
        // root directly (Environment::getPublicPath()), not fileadmin/.
        file_put_contents($this->instancePath . '/fallback-file.jpg', 'dummy-jpg-content');
    }

    private function permissionService(): PermissionService
    {
        return $this->get(PermissionService::class);
    }

    private function file(int $uid): File
    {
        return $this->get(ResourceFactory::class)->getFileObject($uid);
    }

    public function testAdminBypassesFileMountAndFilePermissionChecks(): void
    {
        $this->logInBackendUser(1);
        $permissionService = $this->permissionService();

        self::assertTrue($permissionService->checkFileReadAccess($this->file(1)), 'admin read: inside mount');
        self::assertTrue($permissionService->checkFileReadAccess($this->file(2)), 'admin read: outside every mount');
        self::assertTrue($permissionService->checkFileMetaEditAccess($this->file(1)), 'admin editMeta: inside mount');
        self::assertTrue($permissionService->checkFileMetaEditAccess($this->file(2)), 'admin editMeta: outside every mount');
    }

    public function testEditorWithFullFileMountAndPermissionsIsBoundedByTheMount(): void
    {
        $this->logInBackendUser(2);
        $permissionService = $this->permissionService();

        self::assertTrue($permissionService->checkFileReadAccess($this->file(1)), 'editor read: inside mount 1');
        self::assertFalse($permissionService->checkFileReadAccess($this->file(2)), 'editor read: outside every mount');
        self::assertTrue($permissionService->checkFileMetaEditAccess($this->file(1)), 'editor editMeta: inside writable mount 1');
        self::assertFalse($permissionService->checkFileMetaEditAccess($this->file(2)), 'editor editMeta: outside every mount');
    }

    public function testEditorWithoutAnyFileMountIsDeniedEverywhere(): void
    {
        $this->logInBackendUser(9);
        $permissionService = $this->permissionService();

        // Allowed baseline for this exact file is established by
        // testEditorWithFullFileMountAndPermissionsIsBoundedByTheMount()
        // above (user 2 on the same sys_file 1) - so this can never pass
        // vacuously.
        self::assertFalse($permissionService->checkFileReadAccess($this->file(1)), 'no-filemount editor read: denied everywhere');
        self::assertFalse($permissionService->checkFileMetaEditAccess($this->file(1)), 'no-filemount editor editMeta: denied everywhere');
    }

    public function testEditorWithReadOnlyFilePermissionsCanStillEditMetaOfAWritableMountFile(): void
    {
        $this->logInBackendUser(12);
        $permissionService = $this->permissionService();

        self::assertTrue($permissionService->checkFileReadAccess($this->file(1)), 'read-only-permissions editor read: inside mount 1');

        // SECURITY NOTE (core parity, not a mindfula11y gap): user 12's
        // be_groups.file_permissions is "readFolder,readFile" only - it does
        // NOT include writeFile. Yet checkFileMetaEditAccess() is TRUE here.
        // This mirrors TYPO3 core exactly: ResourceStorage::
        // checkFileActionPermission()'s "editMeta" branch (Check 1) returns
        // as soon as the file is within a *writable file mount boundary* -
        // it never calls checkUserActionPermission() and so never consults
        // the file_permissions tokens at all. Core's own
        // FileMetadataPermissionsAspect (which gates the sys_file_metadata
        // table in the backend record list/edit forms) applies the very same
        // rule. So editing a file's title/alternative text is authorized by
        // mount-write-ability alone, independent of the readFile/writeFile
        // tokens. PermissionService::checkFileMetaEditAccess() faithfully
        // delegates to this core behaviour and cannot restrict it further
        // without diverging from core.
        self::assertTrue($permissionService->checkFileMetaEditAccess($this->file(1)), 'read-only-permissions editor editMeta: writable mount boundary alone decides');
    }

    public function testFallbackStorageFileIsReadableAndMetaEditableRegardlessOfFileMounts(): void
    {
        // SECURITY NOTE (core parity, not a mindfula11y gap): user 9 has NO
        // file mounts at all (see testEditorWithoutAnyFileMountIsDeniedEverywhere()
        // above, denied on storage 1). Yet both checks are TRUE here because
        // the file lives on storage 0, the built-in "Fallback Storage"
        // covering the public web root. TYPO3\CMS\Core\Resource\Security\
        // StoragePermissionsAspect deliberately skips permission injection
        // for it (`!$storage->isFallbackStorage()`), so
        // ResourceStorage::$evaluatePermissions stays false and every
        // boundary/permission check inside checkFileActionPermission() short
        // -circuits to true. This is the exact behaviour documented in
        // PermissionService::checkFileReadAccess()'s docblock and matches
        // core's own FileMetadataPermissionsAspect - not a weakened gate
        // introduced by this extension.
        $this->logInBackendUser(9);
        $permissionService = $this->permissionService();

        self::assertTrue($permissionService->checkFileReadAccess($this->file(300)), 'fallback storage read: no mount evaluation at all');
        self::assertTrue($permissionService->checkFileMetaEditAccess($this->file(300)), 'fallback storage editMeta: no mount evaluation at all');
    }

    public function testReadOnlyFileMountAllowsReadButDeniesMetaEdit(): void
    {
        $this->logInBackendUser(300);
        $permissionService = $this->permissionService();

        // Same physical file as the writable-mount baseline in
        // testEditorWithFullFileMountAndPermissionsIsBoundedByTheMount()
        // (user 2, editMeta TRUE there) - only the mount's read_only flag
        // differs, isolating exactly that boundary.
        self::assertTrue($permissionService->checkFileReadAccess($this->file(1)), 'read-only mount read: read does not require a writable mount');
        self::assertFalse($permissionService->checkFileMetaEditAccess($this->file(1)), 'read-only mount editMeta: editMeta requires a writable mount boundary');
    }
}
