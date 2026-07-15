<?php

declare(strict_types=1);

/*
 * Mindful A11y extension for TYPO3 integrating accessibility tools into the backend.
 * Copyright (C) 2025  Mindful Markup, Felix Spittel
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

namespace MindfulMarkup\MindfulA11y\Controller;

use InvalidArgumentException;
use MindfulMarkup\MindfulA11y\Domain\Model\GenerateAltTextDemand;
use MindfulMarkup\MindfulA11y\Service\AltTextGeneratorService;
use MindfulMarkup\MindfulA11y\Service\PermissionService;
use MindfulMarkup\MindfulA11y\Service\SiteLanguageService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Schema\Capability\TcaSchemaCapability;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;

/**
 * Handles the AJAX endpoint generating alternative text for images.
 *
 * Uses the OpenAI API to generate the alternative text based on the image
 * content. The allowed HTTP method is enforced on the route definition
 * (Configuration/Backend/AjaxRoutes.php).
 */
final readonly class AltTextAjaxController
{
    use JsonErrorResponseTrait;

    public function __construct(
        private AltTextGeneratorService $altTextGeneratorService,
        private SiteLanguageService $siteLanguageService,
        private ResourceFactory $resourceFactory,
        private PermissionService $permissionService,
        private TcaSchemaFactory $tcaSchemaFactory,
    ) {}

    /**
     * Whether the given table ignores the root-level restriction.
     *
     * Schema API replacement for the deprecated BackendUtility root-level
     * restriction helper (Deprecation #106393). The Schema API exists in both
     * TYPO3 v13.2+ and v14, so no version branch is required. Returns false for
     * tables not present in TCA, matching the behaviour of the previous method.
     */
    private function tableIgnoresRootLevelRestriction(string $table): bool
    {
        return $this->tcaSchemaFactory->has($table)
            && $this->tcaSchemaFactory->get($table)
                ->getCapability(TcaSchemaCapability::RestrictionRootLevel)
                ->shallIgnoreRootLevelRestriction();
    }

    /**
     * Generate alternative text for an image.
     *
     * This action handles the AJAX request to generate alternative text for a given image and
     * returns the generated text as a JSON response.
     *
     * @throws InvalidArgumentException If the request parameters are invalid.
     */
    public function generateAction(ServerRequestInterface $request): ResponseInterface
    {
        $requestBody = json_decode((string)$request->getBody(), true);
        $requestBody = is_array($requestBody) ? $requestBody : [];
        $demand = GenerateAltTextDemand::fromRequestData($requestBody);

        if ($demand === null) {
            throw new InvalidArgumentException('Missing or invalid parameters for generating alternative text');
        }

        if (!$demand->validateSignature()) {
            return $this->errorResponse('module.error.invalidSignature', 400);
        }

        $backendUser = $this->getBackendUserAuthentication();

        // Check if user has access to the mindfula11y_accessibility module
        if (!$this->permissionService->checkModuleAccess()) {
            return $this->errorResponse('error.forbidden', 403);
        }

        $userId = $demand->getUserId();
        $pageUid = $demand->getPageUid();
        $languageUid = $demand->getLanguageUid();
        $workspaceId = $demand->getWorkspaceId();
        $recordTable = $demand->getRecordTable();

        // Verify the current backend user matches the demand
        if ($backendUser->user['uid'] !== $userId) {
            return $this->errorResponse('error.invalidUser', 403);
        }

        // Verify the current workspace matches the demand
        if ($backendUser->workspace !== $workspaceId) {
            return $this->errorResponse('error.invalidWorkspace', 403);
        }

        // Check language access
        if (!$this->permissionService->checkLanguageAccess($languageUid)) {
            return $this->errorResponse('error.invalidLanguage', 403);
        }

        // Check if user has access to the page.
        // Root-level tables like sys_file_metadata have ignoreRootLevelRestriction=true in TCA
        // and are not governed by page tree permissions when their records sit at pid=0.
        // Only skip when both conditions are true: the record is at root AND the table allows it.
        if (!(0 === $pageUid && $this->tableIgnoresRootLevelRestriction($recordTable))) {
            $pageInfo = BackendUtility::readPageAccess($pageUid, $backendUser->getPagePermsClause(Permission::PAGE_SHOW));
            if (false === $pageInfo) {
                return $this->errorResponse('error.noPageAccess', 403);
            }
        }

        // Check if user has read access to sys_file
        if (!$this->permissionService->checkTableReadAccess('sys_file')) {
            return $this->errorResponse('error.noFileAccess', 403);
        }

        // Check if user has edit access to the record
        $recordUid = $demand->getRecordUid();
        $recordColumns = $demand->getRecordColumns();
        $recordData = BackendUtility::getRecordWSOL($recordTable, $recordUid);

        // Check if user has edit access to the record.
        // For sys_file_metadata the TYPO3 core permission model (FileMetadataPermissionsAspect)
        // is file-mount based (editMeta), not page-based. checkRecordEditAccess relies on a
        // parent page row which does not exist for root-level records (pid=0), so skip it when
        // the record is at root on a table that explicitly allows it, and enforce editMeta below.
        if (!(0 === $pageUid && $this->tableIgnoresRootLevelRestriction($recordTable))
            && is_array($recordData)
            && !$this->permissionService->checkRecordEditAccess($recordTable, $recordData, $recordColumns)
        ) {
            return $this->errorResponse('error.invalidRecordAccess', 403);
        }

        // For root-level records (e.g. sys_file_metadata) checkRecordEditAccess is skipped above
        // because it cannot resolve a parent page row. Enforce the equivalent guards manually:
        // table write access and non-exclude field access — matching what checkRecordEditAccess
        // would verify via checkTableWriteAccess and checkNonExcludeFields.
        if (0 === $pageUid && $this->tableIgnoresRootLevelRestriction($recordTable)) {
            if (!$this->permissionService->checkTableWriteAccess($recordTable)
                || !$this->permissionService->checkNonExcludeFields($recordTable, $recordColumns)
            ) {
                return $this->errorResponse('error.invalidRecordAccess', 403);
            }
        }

        try {
            $languageCode = $this->siteLanguageService->getLanguageCode($demand->getLanguageUid(), $demand->getPageUid());
        } catch (\Exception) {
            $languageCode = 'en';
        }

        try {
            $file = $this->resourceFactory->getFileObject($demand->getFileUid());
        } catch (FileDoesNotExistException) {
            return $this->errorResponse('error.fileNotFound', 404);
        }

        // For sys_file_metadata the TYPO3 core uses editMeta (writable file mount) as the
        // permission check (see FileMetadataPermissionsAspect). For all other tables, a
        // read file mount check is sufficient.
        $hasFileAccess = ('sys_file_metadata' === $recordTable)
            ? $this->permissionService->checkFileMetaEditAccess($file)
            : $this->permissionService->checkFileReadAccess($file);

        if (!$hasFileAccess) {
            return $this->errorResponse('error.noFileMountAccess', 403);
        }

        $altText = $this->altTextGeneratorService->generate($file, $languageCode);

        if (null === $altText) {
            return $this->errorResponse('altText.generate.error.openAIConnection', 500);
        }

        return new JsonResponse(['altText' => $altText], 201);
    }

    private function getBackendUserAuthentication(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }
}
