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
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;

/**
 * Functional tests for MindfulMarkup\MindfulA11y\Service\PermissionService.
 *
 * Covers every public authorization check against the shared fixture
 * (Fixtures/AuthorizationScenario.csv, see AbstractAuthorizationTestCase for
 * the user/page/content map). checkFileReadAccess()/checkFileMetaEditAccess()
 * are covered separately in FilePermissionTest.php.
 *
 * Each test method gets a fresh container (FunctionalTestCase::setUp() re-
 * bootstraps per method), so runtime $GLOBALS['TCA'] mutations used below to
 * probe ctrl-level branches never leak between tests - they are still undone
 * in a finally block per house rules.
 */
final class PermissionServiceTest extends AbstractAuthorizationTestCase
{
    private function permissionService(): PermissionService
    {
        return $this->get(PermissionService::class);
    }

    private function record(string $table, int $uid): array
    {
        $record = BackendUtility::getRecord($table, $uid);
        self::assertIsArray($record, $table . ':' . $uid . ' fixture record must exist');

        return $record;
    }

    // -------------------------------------------------------------------
    // checkModuleAccess()
    // -------------------------------------------------------------------

    public function testCheckModuleAccess(): void
    {
        $permissionService = $this->permissionService();

        $this->logInBackendUser(2);
        self::assertTrue($permissionService->checkModuleAccess(), 'editor (user 2) must have module access');

        $this->logInBackendUser(3);
        self::assertFalse($permissionService->checkModuleAccess(), 'user 3 (empty groupMods) must be denied module access');

        $this->logInBackendUser(1);
        self::assertTrue($permissionService->checkModuleAccess(), 'admin must always have module access');
    }

    // -------------------------------------------------------------------
    // checkTableReadAccess()
    // -------------------------------------------------------------------

    public function testCheckTableReadAccessGrantedTable(): void
    {
        $this->logInBackendUser(2);
        self::assertTrue($this->permissionService()->checkTableReadAccess('sys_file'), 'editor has sys_file in tables_select');
    }

    public function testCheckTableReadAccessDeniedWhenTableNotInTablesSelect(): void
    {
        $this->logInBackendUser(2);
        self::assertFalse(
            $this->permissionService()->checkTableReadAccess('sys_category'),
            'editor group never grants sys_category in tables_select'
        );
    }

    public function testCheckTableReadAccessDeniedWhenTableAbsentFromTca(): void
    {
        $this->logInBackendUser(2);
        self::assertFalse(
            $this->permissionService()->checkTableReadAccess('tx_mindfula11y_nonexistent_table'),
            'a table absent from TCA must be denied, even before the admin check'
        );
    }

    public function testCheckTableReadAccessAdminBypassesGrantAndTca(): void
    {
        $this->logInBackendUser(1);
        $permissionService = $this->permissionService();

        self::assertTrue($permissionService->checkTableReadAccess('sys_file'), 'admin: granted table');
        self::assertTrue($permissionService->checkTableReadAccess('sys_category'), 'admin: table not in any tables_select still readable');
        self::assertFalse(
            $permissionService->checkTableReadAccess('tx_mindfula11y_nonexistent_table'),
            'admin cannot read a table that is not defined in TCA at all'
        );
    }

    /**
     * Reading never requires a workspace version to exist: tt_content is
     * workspace-aware (ctrl.versioningWS = true), and an editor with a
     * tables_select grant who is switched into an offline workspace without
     * the live_edit flag must still be able to list/read the table - the
     * workspace overlay handles versioned rows, and only *writes* need the
     * live-edit/versioning distinction (covered by checkTableWriteAccess()).
     */
    public function testCheckTableReadAccessWorkspaceAwareTableReadableInOfflineWorkspace(): void
    {
        // Users 2 and 10 are members of sys_workspace 1 ("Draft"), which has
        // no live_edit flag set (defaults to false in the fixture).
        $this->logInBackendUser(2, 1);
        self::assertTrue(
            $this->permissionService()->checkTableReadAccess('tt_content'),
            'workspace-aware table read access must not depend on the workspace live_edit flag'
        );
    }

    public function testCheckTableReadAccessNonWorkspaceAwareTableUnaffectedByOfflineWorkspace(): void
    {
        $this->logInBackendUser(2, 1);
        self::assertTrue(
            $this->permissionService()->checkTableReadAccess('sys_file'),
            'sys_file is not workspace-aware, so offline workspace membership must not affect its read access'
        );
    }

    // -------------------------------------------------------------------
    // checkTableWriteAccess()
    // -------------------------------------------------------------------

    public function testCheckTableWriteAccessGrantedTable(): void
    {
        $this->logInBackendUser(2);
        self::assertTrue($this->permissionService()->checkTableWriteAccess('tt_content'), 'editor has tt_content in tables_modify');
    }

    public function testCheckTableWriteAccessDeniedWhenTableNotInTablesModify(): void
    {
        $this->logInBackendUser(4);
        self::assertFalse(
            $this->permissionService()->checkTableWriteAccess('tt_content'),
            'user 4 group (no_content_modify) never grants tt_content in tables_modify'
        );
    }

    public function testCheckTableWriteAccessDeniedWhenMissingFromTca(): void
    {
        $this->logInBackendUser(2);
        self::assertFalse($this->permissionService()->checkTableWriteAccess('tx_mindfula11y_nonexistent_table'));
    }

    public function testCheckTableWriteAccessDeniedWhenCtrlReadOnlyEvenForAdmin(): void
    {
        $originalReadOnly = $GLOBALS['TCA']['tt_content']['ctrl']['readOnly'] ?? false;
        $GLOBALS['TCA']['tt_content']['ctrl']['readOnly'] = true;

        try {
            $this->logInBackendUser(1);
            self::assertFalse(
                $this->permissionService()->checkTableWriteAccess('tt_content'),
                'ctrl.readOnly must deny write access even for an admin - it is checked before the admin bypass'
            );
        } finally {
            $GLOBALS['TCA']['tt_content']['ctrl']['readOnly'] = $originalReadOnly;
        }
    }

    public function testCheckTableWriteAccessDeniedWhenCtrlAdminOnlyEvenForNonAdminEditor(): void
    {
        // be_users is core's own adminOnly table. The adminOnly branch is
        // checked before the tables_modify check, so this exercises the
        // adminOnly branch specifically - not merely "not in tables_modify"
        // (which is also true here, but not what is being probed).
        $this->logInBackendUser(2);
        self::assertFalse(
            $this->permissionService()->checkTableWriteAccess('be_users'),
            'ctrl.adminOnly must deny write access for a non-admin editor'
        );
    }

    /**
     * A granted table that is NOT workspace-aware, written to while the user
     * is in an offline workspace without the live_edit flag, is denied: such
     * a table has no versioning mechanism to fall back on, so
     * workspaceAllowsLiveEditingInTable() returning false leaves no path to
     * a safe write. sys_file_reference is normally workspace-aware, so its
     * ctrl.versioningWS flag is temporarily cleared (and the schema factory
     * rebuilt so PermissionService's schema-backed workspace check observes
     * it) to construct a granted-but-non-workspace-aware table for the probe.
     */
    public function testCheckTableWriteAccessDeniedForNonWorkspaceAwareGrantedTableInOfflineWorkspace(): void
    {
        $tcaSchemaFactory = $this->get(TcaSchemaFactory::class);
        $originalVersioningWS = $GLOBALS['TCA']['sys_file_reference']['ctrl']['versioningWS'] ?? false;
        $GLOBALS['TCA']['sys_file_reference']['ctrl']['versioningWS'] = false;
        $tcaSchemaFactory->rebuild($GLOBALS['TCA']);

        try {
            $this->logInBackendUser(2, 1);
            self::assertFalse(
                $this->permissionService()->checkTableWriteAccess('sys_file_reference'),
                'a granted but non-workspace-aware table cannot be written to in an offline workspace without live_edit'
            );
        } finally {
            $GLOBALS['TCA']['sys_file_reference']['ctrl']['versioningWS'] = $originalVersioningWS;
            $tcaSchemaFactory->rebuild($GLOBALS['TCA']);
        }
    }

    public function testCheckTableWriteAccessAdminBypassesGrant(): void
    {
        $this->logInBackendUser(1);
        self::assertTrue($this->permissionService()->checkTableWriteAccess('tt_content'));
    }

    // -------------------------------------------------------------------
    // checkNonExcludeFields()
    // -------------------------------------------------------------------

    public function testCheckNonExcludeFieldsGrantedExcludeField(): void
    {
        $this->logInBackendUser(2);
        self::assertTrue(
            $this->permissionService()->checkNonExcludeFields('tt_content', ['tx_mindfula11y_headingtype']),
            'editor group grants tt_content:tx_mindfula11y_headingtype'
        );
    }

    public function testCheckNonExcludeFieldsDeniedExcludeField(): void
    {
        $this->logInBackendUser(5);
        self::assertFalse(
            $this->permissionService()->checkNonExcludeFields('tt_content', ['tx_mindfula11y_headingtype']),
            'user 5 group (no_exclude_fields) grants no non_exclude_fields at all'
        );
    }

    public function testCheckNonExcludeFieldsNonExcludeColumnAlwaysAllowed(): void
    {
        // tt_content.header carries no 'exclude' => true flag in TCA, so it
        // must be accessible regardless of non_exclude_fields grants.
        $this->logInBackendUser(5);
        self::assertTrue($this->permissionService()->checkNonExcludeFields('tt_content', ['header']));
    }

    public function testCheckNonExcludeFieldsSysFileReferenceAlternative(): void
    {
        $permissionService = $this->permissionService();

        $this->logInBackendUser(2);
        self::assertTrue($permissionService->checkNonExcludeFields('sys_file_reference', ['alternative']));

        $this->logInBackendUser(5);
        self::assertFalse($permissionService->checkNonExcludeFields('sys_file_reference', ['alternative']));
    }

    public function testCheckNonExcludeFieldsAdminAlwaysAllowed(): void
    {
        $this->logInBackendUser(1);
        self::assertTrue($this->permissionService()->checkNonExcludeFields('tt_content', ['tx_mindfula11y_headingtype']));
    }

    // -------------------------------------------------------------------
    // checkRecordEditAccess() - tt_content (else-branch)
    // -------------------------------------------------------------------

    public function testCheckRecordEditAccessTtContentBaseline(): void
    {
        $this->logInBackendUser(2);
        self::assertTrue($this->permissionService()->checkRecordEditAccess('tt_content', $this->record('tt_content', 100)));
    }

    public function testCheckRecordEditAccessTtContentDeniedWhenTableWriteDenied(): void
    {
        $this->logInBackendUser(4);
        self::assertFalse(
            $this->permissionService()->checkRecordEditAccess('tt_content', $this->record('tt_content', 100)),
            'user 4 lacks tt_content in tables_modify, so record edit access must be denied up-front'
        );
    }

    public function testCheckRecordEditAccessTtContentLanguageDenied(): void
    {
        $translatedRecord = $this->record('tt_content', 102);

        $this->logInBackendUser(6);
        self::assertFalse(
            $this->permissionService()->checkRecordEditAccess('tt_content', $translatedRecord),
            'user 6 (allowed_languages=0) must be denied on a language-1 record'
        );

        $this->logInBackendUser(11);
        self::assertTrue(
            $this->permissionService()->checkRecordEditAccess('tt_content', $translatedRecord),
            'user 11 (allowed_languages=1) must be allowed on a language-1 record'
        );
    }

    public function testCheckRecordEditAccessTtContentMissingLanguageFieldDenied(): void
    {
        $row = $this->record('tt_content', 100);
        unset($row['sys_language_uid']);

        $this->logInBackendUser(2);
        self::assertFalse(
            $this->permissionService()->checkRecordEditAccess('tt_content', $row),
            'a record row missing the languageField value must be denied, not treated as language 0'
        );
    }

    public function testCheckRecordEditAccessTtContentAuthModeDenied(): void
    {
        $permissionService = $this->permissionService();
        $this->logInBackendUser(7);

        self::assertFalse(
            $permissionService->checkRecordEditAccess('tt_content', $this->record('tt_content', 100)),
            'user 7 (CType text only) must be denied a textmedia (uid 100) record via authMode'
        );
        self::assertTrue(
            $permissionService->checkRecordEditAccess('tt_content', $this->record('tt_content', 101)),
            'user 7 must be allowed a text (uid 101) record'
        );
    }

    public function testCheckRecordEditAccessTtContentExcludeColumnsParameterDenied(): void
    {
        $this->logInBackendUser(5);
        self::assertFalse(
            $this->permissionService()->checkRecordEditAccess(
                'tt_content',
                $this->record('tt_content', 101),
                ['tx_mindfula11y_headingtype']
            ),
            'user 5 (no_exclude_fields) must be denied when the requested column is an exclude field they lack'
        );
    }

    public function testCheckRecordEditAccessTtContentRecordEditlockDenied(): void
    {
        $this->logInBackendUser(2);
        self::assertFalse(
            $this->permissionService()->checkRecordEditAccess('tt_content', $this->record('tt_content', 105)),
            'uid 105 has editlock=1, must be denied even for the full editor'
        );
    }

    public function testCheckRecordEditAccessTtContentPageEditlockDenied(): void
    {
        $this->logInBackendUser(2);
        self::assertFalse(
            $this->permissionService()->checkRecordEditAccess('tt_content', $this->record('tt_content', 104)),
            'uid 104 sits on page 12, which has editlock=1'
        );
    }

    public function testCheckRecordEditAccessTtContentPagePermissionsDenied(): void
    {
        $permissionService = $this->permissionService();
        $this->logInBackendUser(2);

        self::assertFalse(
            $permissionService->checkRecordEditAccess('tt_content', $this->record('tt_content', 103)),
            'uid 103 sits on page 11 (show-only, perms_everybody=1, no CONTENT_EDIT bit)'
        );
        self::assertFalse(
            $permissionService->checkRecordEditAccess('tt_content', $this->record('tt_content', 109)),
            'uid 109 sits on page 14 (perms_everybody=0)'
        );
    }

    public function testCheckRecordEditAccessTtContentContentEditGrantedWithoutPageEdit(): void
    {
        // Page 13 grants perms_everybody=17 (SHOW+CONTENT_EDIT, no PAGE_EDIT
        // bit). The tt_content branch only requires editContentPermission,
        // so this must be allowed.
        $this->logInBackendUser(2);
        self::assertTrue($this->permissionService()->checkRecordEditAccess('tt_content', $this->record('tt_content', 107)));
    }

    public function testCheckRecordEditAccessTtContentPageEditNotSufficientWithoutContentEdit(): void
    {
        // Page 18 grants perms_everybody=3 (SHOW+PAGE_EDIT, no CONTENT_EDIT
        // bit). The tt_content branch requires editContentPermission
        // specifically, so page-edit alone must not be sufficient.
        $this->logInBackendUser(2);
        self::assertFalse($this->permissionService()->checkRecordEditAccess('tt_content', $this->record('tt_content', 108)));
    }

    public function testCheckRecordEditAccessTtContentNonexistentPageDenied(): void
    {
        $row = $this->record('tt_content', 100);
        $row['pid'] = 99999;

        $this->logInBackendUser(2);
        self::assertFalse($this->permissionService()->checkRecordEditAccess('tt_content', $row));
    }

    public function testCheckRecordEditAccessAllowsLegitimateRootLevelRecord(): void
    {
        $this->logInBackendUser(2);

        self::assertTrue(
            $this->permissionService()->checkRecordEditAccess(
                'sys_file_metadata',
                $this->record('sys_file_metadata', 1),
                ['alternative'],
            ),
        );
    }

    public function testCheckRecordEditAccessDoesNotTreatArbitraryPidZeroRecordAsRootLevel(): void
    {
        $row = $this->record('tt_content', 100);
        $row['pid'] = 0;
        $this->logInBackendUser(2);

        self::assertFalse($this->permissionService()->checkRecordEditAccess('tt_content', $row));
    }

    public function testCheckRecordEditAccessTtContentAdminBypassesEveryDimension(): void
    {
        $permissionService = $this->permissionService();
        $this->logInBackendUser(1);

        foreach ([100, 103, 104, 105, 107, 108, 109] as $uid) {
            self::assertTrue(
                $permissionService->checkRecordEditAccess('tt_content', $this->record('tt_content', $uid)),
                'admin must be allowed on tt_content:' . $uid
            );
        }
    }

    // -------------------------------------------------------------------
    // checkRecordEditAccess() - pages branch
    // -------------------------------------------------------------------

    public function testCheckRecordEditAccessPagesBaselineAllowed(): void
    {
        $this->logInBackendUser(2);
        self::assertTrue(
            $this->permissionService()->checkRecordEditAccess('pages', $this->record('pages', 10)),
            'page 10 grants perms_everybody=19, which includes the PAGE_EDIT bit'
        );
    }

    public function testCheckRecordEditAccessPagesDeniedWithoutEditBit(): void
    {
        $this->logInBackendUser(2);
        self::assertFalse(
            $this->permissionService()->checkRecordEditAccess('pages', $this->record('pages', 13)),
            'page 13 grants perms_everybody=17 (SHOW+CONTENT_EDIT), no PAGE_EDIT bit - the pages branch requires PAGE_EDIT'
        );
    }

    public function testCheckRecordEditAccessPagesEditlockDenied(): void
    {
        $this->logInBackendUser(2);
        self::assertFalse(
            $this->permissionService()->checkRecordEditAccess('pages', $this->record('pages', 12)),
            'page 12 has editlock=1 despite granting perms_everybody=19'
        );
    }

    public function testCheckRecordEditAccessPagesTranslationResolvesPermsViaParent(): void
    {
        $permissionService = $this->permissionService();
        $translationRow = $this->record('pages', 30);

        $this->logInBackendUser(2);
        self::assertTrue(
            $permissionService->checkRecordEditAccess('pages', $translationRow),
            'page 30 (l10n_parent=10) must resolve edit permissions via page 10'
        );

        $this->logInBackendUser(6);
        self::assertFalse(
            $permissionService->checkRecordEditAccess('pages', $translationRow),
            'user 6 (allowed_languages=0) must be denied on the language-1 translation before perms are even resolved'
        );
    }

    public function testCheckRecordEditAccessPagesDeniedWhenPagesNotInTablesModify(): void
    {
        $this->logInBackendUser(8);
        self::assertFalse(
            $this->permissionService()->checkRecordEditAccess('pages', $this->record('pages', 10)),
            'user 8 group (no_pages_modify) never grants pages in tables_modify'
        );
    }

    public function testCheckRecordEditAccessPagesAdminBypassesEveryDimension(): void
    {
        $permissionService = $this->permissionService();
        $this->logInBackendUser(1);

        foreach ([10, 12, 13] as $uid) {
            self::assertTrue($permissionService->checkRecordEditAccess('pages', $this->record('pages', $uid)));
        }
    }

    // -------------------------------------------------------------------
    // checkPageReadAccess()
    // -------------------------------------------------------------------

    public function testCheckPageReadAccessShowBitGranted(): void
    {
        $this->logInBackendUser(2);
        self::assertTrue($this->permissionService()->checkPageReadAccess($this->record('pages', 11)));
    }

    public function testCheckPageReadAccessDeniedWithoutShowBit(): void
    {
        $this->logInBackendUser(2);
        self::assertFalse($this->permissionService()->checkPageReadAccess($this->record('pages', 14)));
    }

    public function testCheckPageReadAccessTranslationResolvesPermsViaParent(): void
    {
        $permissionService = $this->permissionService();
        $translationRow = $this->record('pages', 30);

        $this->logInBackendUser(2);
        self::assertTrue($permissionService->checkPageReadAccess($translationRow));

        $this->logInBackendUser(6);
        self::assertFalse(
            $permissionService->checkPageReadAccess($translationRow),
            'user 6 (allowed_languages=0) must be denied on the language-1 translation'
        );
    }

    public function testCheckPageReadAccessDeniedForForeignWorkspaceVersion(): void
    {
        $row = $this->record('pages', 11);
        $row['t3ver_wsid'] = 1;

        $this->logInBackendUser(2);
        self::assertFalse(
            $this->permissionService()->checkPageReadAccess($row),
            'a record stamped with a workspace id different from the current user workspace (0) must be denied'
        );
    }

    public function testCheckPageReadAccessDeniedForDeletePlaceholder(): void
    {
        $row = $this->record('pages', 11);
        $row['t3ver_state'] = 2; // VersionState::DELETE_PLACEHOLDER

        $this->logInBackendUser(2);
        self::assertFalse($this->permissionService()->checkPageReadAccess($row));
    }

    /**
     * Page 20 is a second website root (is_siteroot=1, pid=0) outside the
     * editor's db_mountpoints ("1"), but grants perms_everybody=19 (includes
     * PAGE_SHOW). checkPageReadAccess() itself never inspects webmounts -
     * but calcPerms() does, transitively, via isInWebMount(): a page whose
     * rootline never crosses one of the user's webmounts computes to
     * Permission::NOTHING regardless of its own perms_* columns. So the
     * absence of an explicit webmount check in checkPageReadAccess() is not
     * a gap in practice - core's calcPerms() already closes it. Documented
     * here (per the review brief) rather than silently assumed.
     */
    public function testCheckPageReadAccessOutsideWebmountDeniedViaCalcPerms(): void
    {
        $this->logInBackendUser(2);
        self::assertFalse(
            $this->permissionService()->checkPageReadAccess($this->record('pages', 20)),
            'page 20 is outside every db_mountpoint; calcPerms() must zero out its otherwise-granting perms_everybody'
        );
    }

    public function testCheckPageReadAccessAdminBypassesEveryDimension(): void
    {
        $permissionService = $this->permissionService();
        $this->logInBackendUser(1);

        foreach ([11, 14, 20] as $uid) {
            self::assertTrue($permissionService->checkPageReadAccess($this->record('pages', $uid)));
        }
    }

    // -------------------------------------------------------------------
    // checkLanguageAccess()
    // -------------------------------------------------------------------

    public function testCheckLanguageAccessEmptyAllowedLanguagesGrantsAll(): void
    {
        $this->logInBackendUser(2);
        $permissionService = $this->permissionService();

        self::assertTrue($permissionService->checkLanguageAccess(0));
        self::assertTrue($permissionService->checkLanguageAccess(1));
    }

    public function testCheckLanguageAccessDefaultLanguageOnly(): void
    {
        $this->logInBackendUser(6);
        $permissionService = $this->permissionService();

        self::assertTrue($permissionService->checkLanguageAccess(0));
        self::assertFalse($permissionService->checkLanguageAccess(1));
    }

    public function testCheckLanguageAccessTranslationLanguageOnly(): void
    {
        $this->logInBackendUser(11);
        $permissionService = $this->permissionService();

        self::assertFalse($permissionService->checkLanguageAccess(0));
        self::assertTrue($permissionService->checkLanguageAccess(1));
    }

    public function testCheckLanguageAccessAdminAlwaysGranted(): void
    {
        $this->logInBackendUser(1);
        $permissionService = $this->permissionService();

        self::assertTrue($permissionService->checkLanguageAccess(0));
        self::assertTrue($permissionService->checkLanguageAccess(1));
    }

    // -------------------------------------------------------------------
    // getAllowedAuthModeValues()
    // -------------------------------------------------------------------

    public function testGetAllowedAuthModeValuesRestrictedGroup(): void
    {
        $this->logInBackendUser(7);
        self::assertSame(
            ['CType' => ['text']],
            $this->permissionService()->getAllowedAuthModeValues('tt_content'),
            'user 7 group (ctype_text_only) only grants tt_content:CType:text'
        );
    }

    public function testGetAllowedAuthModeValuesFullEditor(): void
    {
        $this->logInBackendUser(2);
        $allowedValues = $this->permissionService()->getAllowedAuthModeValues('tt_content');

        self::assertArrayHasKey('CType', $allowedValues);
        self::assertContains('text', $allowedValues['CType']);
        self::assertContains('textmedia', $allowedValues['CType']);
        self::assertCount(2, $allowedValues['CType'], 'the editor group only grants exactly text and textmedia');
    }

    public function testGetAllowedAuthModeValuesMissingTable(): void
    {
        $this->logInBackendUser(2);
        self::assertSame([], $this->permissionService()->getAllowedAuthModeValues('tx_mindfula11y_nonexistent_table'));
    }
}
