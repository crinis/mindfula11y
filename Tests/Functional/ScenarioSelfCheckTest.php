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

namespace MindfulMarkup\MindfulA11y\Tests\Functional;

use MindfulMarkup\MindfulA11y\Service\PermissionService;
use TYPO3\CMS\Backend\Utility\BackendUtility;

/**
 * Self-check of the shared authorization scenario: one probe per permission
 * dimension the fixture encodes, so a fixture regression fails here with a
 * readable message instead of surfacing as noise across the real suites.
 */
final class ScenarioSelfCheckTest extends AbstractAuthorizationTestCase
{
    private function permissionService(): PermissionService
    {
        return $this->get(PermissionService::class);
    }

    public function testFullEditorPassesAllBaselineChecks(): void
    {
        $this->logInBackendUser(2);
        $permissionService = $this->permissionService();

        self::assertTrue($permissionService->checkModuleAccess(), 'module access');
        self::assertTrue($permissionService->checkTableReadAccess('sys_file'), 'sys_file read');
        self::assertTrue($permissionService->checkTableWriteAccess('tt_content'), 'tt_content write');
        self::assertTrue(
            $permissionService->checkNonExcludeFields('tt_content', ['tx_mindfula11y_headingtype']),
            'exclude field granted'
        );

        $record = BackendUtility::getRecord('tt_content', 100);
        self::assertIsArray($record);
        self::assertTrue(
            $permissionService->checkRecordEditAccess('tt_content', $record),
            'record edit access on editable page'
        );
    }

    public function testEachRestrictedDimensionDeniesItsProbe(): void
    {
        $permissionService = $this->permissionService();
        $editableRecord = BackendUtility::getRecord('tt_content', 100);
        self::assertIsArray($editableRecord);

        $this->logInBackendUser(3);
        self::assertFalse($permissionService->checkModuleAccess(), 'user 3: module access must be denied');

        $this->logInBackendUser(4);
        self::assertFalse(
            $permissionService->checkTableWriteAccess('tt_content'),
            'user 4: tt_content tables_modify must be denied'
        );

        $this->logInBackendUser(5);
        self::assertFalse(
            $permissionService->checkNonExcludeFields('tt_content', ['tx_mindfula11y_headingtype']),
            'user 5: exclude field must be denied'
        );

        $this->logInBackendUser(6);
        $translatedRecord = BackendUtility::getRecord('tt_content', 102);
        self::assertIsArray($translatedRecord);
        self::assertFalse(
            $permissionService->checkRecordEditAccess('tt_content', $translatedRecord),
            'user 6: language 1 record must be denied'
        );

        $this->logInBackendUser(7);
        self::assertFalse(
            $permissionService->checkRecordEditAccess('tt_content', $editableRecord),
            'user 7: CType textmedia must be denied via authMode'
        );

        // Page-level dimensions probed with the full editor.
        $this->logInBackendUser(2);
        foreach ([103 => 'show-only page', 104 => 'page editlock', 105 => 'record editlock', 109 => 'no-access page'] as $uid => $label) {
            $record = BackendUtility::getRecord('tt_content', $uid);
            self::assertIsArray($record, $label);
            self::assertFalse(
                $permissionService->checkRecordEditAccess('tt_content', $record),
                'user 2 must be denied on ' . $label
            );
        }
    }

    public function testAdminBypassesEveryDimension(): void
    {
        $this->logInBackendUser(1);
        $permissionService = $this->permissionService();

        self::assertTrue($permissionService->checkModuleAccess());
        foreach ([100, 103, 104, 105, 109] as $uid) {
            $record = BackendUtility::getRecord('tt_content', $uid);
            self::assertIsArray($record);
            self::assertTrue(
                $permissionService->checkRecordEditAccess('tt_content', $record),
                'admin denied on tt_content:' . $uid
            );
        }
    }
}
