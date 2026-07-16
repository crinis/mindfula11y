<?php

declare(strict_types=1);

/*
 * Enables explicit CType allow-listing for the authorization test scenario.
 * Core ships tt_content.CType without authMode; integrators opt in exactly
 * like this, and PermissionService::checkRecordEditAccess() must honor it.
 */
defined('TYPO3') or die();

$GLOBALS['TCA']['tt_content']['columns']['CType']['config']['authMode'] = 'explicitAllow';
