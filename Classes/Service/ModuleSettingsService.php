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

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\TypoScript\TypoScriptService;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Access gates and editor-facing settings of the accessibility module features.
 *
 * Everything here derives from `mod.mindfula11y_accessibility.*` Page TSconfig
 * combined with the current backend user's table/field permissions. All
 * `has*Access()` gates take the converted Page TSconfig from
 * getConvertedPageTsConfig() so one TSconfig lookup serves many checks.
 */
final readonly class ModuleSettingsService
{
    public function __construct(
        private PermissionService $permissionService,
        private SiteFinder $siteFinder,
        private TypoScriptService $typoScriptService,
    ) {}

    /**
     * Get converted (dot-free) Page TSconfig for the given page.
     *
     * @return array<string, mixed>
     */
    public function getConvertedPageTsConfig(int $pageId): array
    {
        $rawTsConfig = BackendUtility::getPagesTSconfig($pageId);
        return $this->typoScriptService->convertTypoScriptArrayToPlainArray($rawTsConfig);
    }

    /**
     * The module's own Page TSconfig subtree, so a typo in one of the accessors
     * below cannot hide next to seven correct ones. Not quite the only spelling
     * in the extension: AltTextFinderService reads `missingAltText.ignoreColumns`
     * straight from the array, keeping its legacy-path fallback beside it.
     *
     * @param array<string, mixed> $pageTsConfig
     * @return array<string, mixed>
     */
    private function moduleTsConfig(array $pageTsConfig): array
    {
        $moduleTsConfig = $pageTsConfig['mod']['mindfula11y_accessibility'] ?? [];

        return is_array($moduleTsConfig) ? $moduleTsConfig : [];
    }

    /**
     * Check if the user has access to the missing alt text feature.
     *
     * @param array<string, mixed> $pageTsConfig
     */
    public function hasMissingAltTextAccess(array $pageTsConfig): bool
    {
        return $this->canReadFileReferenceAlternative()
            && (bool)($this->moduleTsConfig($pageTsConfig)['missingAltText']['enable'] ?? false);
    }

    /**
     * Check whether the current backend user may read reference-level alternative text.
     */
    public function canReadFileReferenceAlternative(): bool
    {
        return $this->permissionService->checkTableReadAccess('sys_file_reference')
            && $this->permissionService->checkNonExcludeFields('sys_file_reference', ['alternative']);
    }

    /**
     * Check whether file metadata fallback alt text is ignored by the missing alt text feature.
     *
     * By default, file metadata fallback text counts as a valid alternative, matching
     * TYPO3's rendered FileReference behavior. Setting
     * mod.mindfula11y_accessibility.missingAltText.ignoreFileMetadata = 1 opts into
     * requiring alternative text directly on every file reference.
     *
     * @param array<string, mixed> $pageTsConfig
     */
    public function isFileMetadataIgnored(array $pageTsConfig): bool
    {
        return (bool)($this->moduleTsConfig($pageTsConfig)['missingAltText']['ignoreFileMetadata'] ?? false);
    }

    /**
     * Check whether the current backend user may read metadata alternative text.
     */
    public function canReadFileMetadataAlternative(): bool
    {
        return $this->permissionService->checkTableReadAccess('sys_file_metadata')
            && $this->permissionService->checkNonExcludeFields('sys_file_metadata', ['alternative']);
    }

    /**
     * Check if the user has access to the heading structure feature.
     *
     * @param array<string, mixed> $pageTsConfig
     */
    public function hasHeadingStructureAccess(array $pageTsConfig): bool
    {
        return (bool)($this->moduleTsConfig($pageTsConfig)['headingStructure']['enable'] ?? false);
    }

    /**
     * Check if the user has access to the landmark structure feature.
     *
     * @param array<string, mixed> $pageTsConfig
     */
    public function hasLandmarkStructureAccess(array $pageTsConfig): bool
    {
        return (bool)($this->moduleTsConfig($pageTsConfig)['landmarkStructure']['enable'] ?? false);
    }

    /**
     * Check if structure analysis may run on the page at all.
     *
     * One pipeline serves both structure views, so its trust boundaries — ticket
     * issuance, framing, enrichment, redemption — gate on "either view is
     * enabled". Expressed once so a third view cannot reach one of them while
     * another still refuses.
     *
     * @param array<string, mixed> $pageTsConfig
     */
    public function hasStructureAnalysisAccess(array $pageTsConfig): bool
    {
        return $this->hasHeadingStructureAccess($pageTsConfig)
            || $this->hasLandmarkStructureAccess($pageTsConfig);
    }

    /**
     * Check if the user has access to the accessibility scanner feature.
     *
     * @param array<string, mixed> $pageTsConfig
     */
    public function hasScanAccess(array $pageTsConfig): bool
    {
        return $this->permissionService->checkTableReadAccess('pages')
            && (bool)($this->moduleTsConfig($pageTsConfig)['scan']['enable'] ?? false);
    }

    /**
     * Check if auto-create scan is enabled in TSconfig.
     *
     * @param array<string, mixed> $pageTsConfig
     */
    public function isAutoCreateScanEnabled(array $pageTsConfig): bool
    {
        return (bool)($this->moduleTsConfig($pageTsConfig)['scan']['autoCreate'] ?? true);
    }

    /**
     * Get HTTP Basic Authentication credentials for the scanner.
     *
     * Resolved from the site configuration of the page's site (config.yaml key
     * mindfula11y.scan.basicAuth.username / .password), with the Page TSconfig
     * keys released in v0.12.0 as deprecated fallback. The site configuration is
     * authoritative as soon as either key is set there: a partial pair fails
     * closed (no credentials sent, visible as a 401 at the protected host)
     * instead of silently reviving the deprecated TSconfig credentials
     * mid-migration. Credentials are read exclusively on the server side and are
     * never forwarded to the frontend.
     *
     * Deliberately not site settings: core publishes every site setting —
     * defined or not, with %env()% already resolved — as a TypoScript and page
     * TSconfig constant, so anyone allowed to write page TSconfig could print
     * the password (e.g. into a TCEFORM label). A custom root key of config.yaml
     * keeps per-site scope and %env()% support, is never turned into constants,
     * and survives saves in the Sites module, which keeps unknown root keys and
     * protects placeholders.
     *
     * @param array<string, mixed> $pageTsConfig
     * @return array{username: string, password: string}|null
     */
    public function getScanBasicAuth(int $pageId, array $pageTsConfig): ?array
    {
        $siteAuth = $this->getSiteConfigurationScanBasicAuth($pageId);
        if ($siteAuth !== []) {
            return count($siteAuth) === 2 ? $siteAuth : null;
        }

        $username = trim((string)($this->moduleTsConfig($pageTsConfig)['scan']['basicAuthUsername'] ?? ''));
        $password = trim((string)($this->moduleTsConfig($pageTsConfig)['scan']['basicAuthPassword'] ?? ''));

        if ($username === '' || $password === '') {
            return null;
        }

        return ['username' => $username, 'password' => $password];
    }

    /**
     * Read the scanner basic-auth credentials from the site configuration.
     *
     * @return array{username?: string, password?: string} Empty when neither key is set (or the page has no site).
     */
    private function getSiteConfigurationScanBasicAuth(int $pageId): array
    {
        try {
            $configuration = $this->siteFinder->getSiteByPageId($pageId)->getConfiguration();
        } catch (SiteNotFoundException) {
            return [];
        }

        $credentials = $configuration['mindfula11y']['scan']['basicAuth'] ?? [];
        if (!is_array($credentials)) {
            return [];
        }

        $auth = [];
        foreach (['username', 'password'] as $key) {
            $value = $credentials[$key] ?? '';
            if (is_scalar($value) && trim((string)$value) !== '') {
                $auth[$key] = trim((string)$value);
            }
        }

        return $auth;
    }

    /**
     * Check if the user may request an AI audit alongside a scan.
     *
     * The scanner API's agent feature is optional and disabled by default,
     * so the audit toggle is opt-in via Page TSconfig.
     *
     * @param array<string, mixed> $pageTsConfig
     */
    public function hasAiAuditAccess(array $pageTsConfig): bool
    {
        return (bool)($this->moduleTsConfig($pageTsConfig)['scan']['aiAudit']['enable'] ?? false);
    }

    /**
     * Check if the AI audit toggle should be pre-selected in the scan module.
     *
     * @param array<string, mixed> $pageTsConfig
     */
    public function isAiAuditDefaultEnabled(array $pageTsConfig): bool
    {
        return (bool)($this->moduleTsConfig($pageTsConfig)['scan']['aiAudit']['default'] ?? false);
    }

    /**
     * Get the optional MindfulAPI skill selection. A missing setting returns
     * null so the API runs every server-enabled skill; an explicitly empty
     * setting returns [] so the API runs no AI skills.
     *
     * @param array<string, mixed> $pageTsConfig
     * @return string[]|null
     */
    public function getAiAuditSkills(array $pageTsConfig): ?array
    {
        $aiAuditConfiguration = $this->moduleTsConfig($pageTsConfig)['scan']['aiAudit'] ?? [];
        if (!is_array($aiAuditConfiguration) || !array_key_exists('skills', $aiAuditConfiguration)) {
            return null;
        }

        return GeneralUtility::trimExplode(
            ',',
            (string)$aiAuditConfiguration['skills'],
            true
        );
    }
}
