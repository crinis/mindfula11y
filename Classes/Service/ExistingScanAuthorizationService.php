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

namespace MindfulMarkup\MindfulA11y\Service;

use MindfulMarkup\MindfulA11y\Exception\ScanAuthorizationException;
use MindfulMarkup\MindfulA11y\Tca\TranslationFields;

/**
 * Authorizes access to a scan through the page record that owns its id.
 *
 * Unlike scan creation, an existing scan has no client-defined URL or tree
 * scope to authenticate with a signed demand. Its stored page association is
 * the authoritative resource scope, so every caller resolves and authorizes
 * that association here against the current backend session.
 */
final readonly class ExistingScanAuthorizationService
{
    public function __construct(
        private ScanStateService $scanStateService,
        private PermissionService $permissionService,
        private ModuleSettingsService $moduleSettingsService,
    ) {}

    /**
     * @return array<string, mixed> The page record carrying the scan id.
     * @throws ScanAuthorizationException
     */
    public function authorizeRead(string $scanId): array
    {
        $pageRecord = $this->scanStateService->getPageRecordByScanId($scanId);
        if ($pageRecord === null) {
            throw new ScanAuthorizationException('scan.error.notFound', 404);
        }

        if (!$this->permissionService->checkPageReadAccess($pageRecord)) {
            throw new ScanAuthorizationException('scan.error.accessDenied', 403);
        }

        // Scans on translated pages store their id on the translation record,
        // but Page TSconfig belongs to the logical default-language page.
        $tsConfigPageUid = TranslationFields::languageId('pages', $pageRecord) > 0
            ? TranslationFields::translationParentUid('pages', $pageRecord)
            : (int)$pageRecord['uid'];
        $pageTsConfig = $this->moduleSettingsService->getConvertedPageTsConfig($tsConfigPageUid);
        if (!$this->moduleSettingsService->hasScanAccess($pageTsConfig)) {
            throw new ScanAuthorizationException('scan.noAccess', 403);
        }

        return $pageRecord;
    }

    /**
     * @return array<string, mixed> The editable page record carrying the scan id.
     * @throws ScanAuthorizationException
     */
    public function authorizeMutation(string $scanId): array
    {
        $pageRecord = $this->authorizeRead($scanId);

        if (!$this->permissionService->checkRecordEditAccess('pages', $pageRecord)) {
            throw new ScanAuthorizationException('error.noPageAccess', 403);
        }

        return $pageRecord;
    }
}
