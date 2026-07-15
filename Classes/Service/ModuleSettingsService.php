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

use Psr\Http\Message\UriInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Directive;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Mutation;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\MutationCollection;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\MutationMode;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\PolicyRegistry;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\UriValue;
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
        private TypoScriptService $typoScriptService,
        private PolicyRegistry $policyRegistry,
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
     * Check if the user has access to the missing alt text feature.
     *
     * @param array<string, mixed> $pageTsConfig
     */
    public function hasMissingAltTextAccess(array $pageTsConfig): bool
    {
        return $this->permissionService->checkTableReadAccess('sys_file_reference')
            && $this->permissionService->checkNonExcludeFields('sys_file_reference', ['alternative'])
            && !!($pageTsConfig['mod']['mindfula11y_accessibility']['missingAltText']['enable'] ?? false);
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
        return !!($pageTsConfig['mod']['mindfula11y_accessibility']['missingAltText']['ignoreFileMetadata'] ?? false);
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
        return !!($pageTsConfig['mod']['mindfula11y_accessibility']['headingStructure']['enable'] ?? false);
    }

    /**
     * Check if the user has access to the landmark structure feature.
     *
     * @param array<string, mixed> $pageTsConfig
     */
    public function hasLandmarkStructureAccess(array $pageTsConfig): bool
    {
        return !!($pageTsConfig['mod']['mindfula11y_accessibility']['landmarkStructure']['enable'] ?? false);
    }

    /**
     * Permit the structure views to frame the frontend preview.
     *
     * The structure analysis renders the preview in iframes, which a backend
     * Content Security Policy blocks by default. Tying the permission to the
     * feature gates keeps it an invariant of rendering the widget rather than a
     * step each module has to remember.
     *
     * @param array<string, mixed> $pageTsConfig
     */
    public function allowStructureAnalysisFraming(?UriInterface $previewUri, array $pageTsConfig): void
    {
        if (null === $previewUri
            || (!$this->hasHeadingStructureAccess($pageTsConfig) && !$this->hasLandmarkStructureAccess($pageTsConfig))
        ) {
            return;
        }

        // Strip the query before building the CSP source: PreviewUriBuilder adds
        // query parameters for access-restricted pages (ADMCMD_simUser/simTime),
        // but a CSP host-source must not contain a query component — browsers
        // ignore the whole source and block the iframe. Matching ignores the
        // query anyway, so scheme/host/port/path keep the source precise.
        $this->policyRegistry->appendMutationCollection(new MutationCollection(
            new Mutation(MutationMode::Extend, Directive::FrameSrc, UriValue::fromUri($previewUri->withQuery('')))
        ));
    }

    /**
     * Check if the user has access to the accessibility scanner feature.
     *
     * @param array<string, mixed> $pageTsConfig
     */
    public function hasScanAccess(array $pageTsConfig): bool
    {
        return $this->permissionService->checkTableReadAccess('pages')
            && !!($pageTsConfig['mod']['mindfula11y_accessibility']['scan']['enable'] ?? false);
    }

    /**
     * Check if auto-create scan is enabled in TSconfig.
     *
     * @param array<string, mixed> $pageTsConfig
     */
    public function isAutoCreateScanEnabled(array $pageTsConfig): bool
    {
        return !!($pageTsConfig['mod']['mindfula11y_accessibility']['scan']['autoCreate'] ?? true);
    }

    /**
     * Get HTTP Basic Authentication credentials for the scanner from Page TSconfig.
     *
     * Returns an associative array with 'username' and 'password' keys when both are
     * configured, or null when either value is absent. Credentials are read exclusively
     * on the server side and are never forwarded to the frontend.
     *
     * @param array<string, mixed> $pageTsConfig
     * @return array{username: string, password: string}|null
     */
    public function getScanBasicAuth(array $pageTsConfig): ?array
    {
        $username = trim((string)($pageTsConfig['mod']['mindfula11y_accessibility']['scan']['basicAuthUsername'] ?? ''));
        $password = trim((string)($pageTsConfig['mod']['mindfula11y_accessibility']['scan']['basicAuthPassword'] ?? ''));

        if ($username === '' || $password === '') {
            return null;
        }

        return ['username' => $username, 'password' => $password];
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
        return !!($pageTsConfig['mod']['mindfula11y_accessibility']['scan']['aiAudit']['enable'] ?? false);
    }

    /**
     * Check if the AI audit toggle should be pre-selected in the scan module.
     *
     * @param array<string, mixed> $pageTsConfig
     */
    public function isAiAuditDefaultEnabled(array $pageTsConfig): bool
    {
        return !!($pageTsConfig['mod']['mindfula11y_accessibility']['scan']['aiAudit']['default'] ?? false);
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
        $aiAuditConfiguration = $pageTsConfig['mod']['mindfula11y_accessibility']['scan']['aiAudit'] ?? [];
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
