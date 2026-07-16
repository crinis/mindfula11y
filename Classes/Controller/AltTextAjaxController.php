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

use MindfulMarkup\MindfulA11y\Domain\Model\GenerateAltTextDemand;
use MindfulMarkup\MindfulA11y\Service\AltTextGeneratorService;
use MindfulMarkup\MindfulA11y\Service\BackendUserProvider;
use MindfulMarkup\MindfulA11y\Service\PermissionService;
use MindfulMarkup\MindfulA11y\Service\SiteLanguageService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
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
    use AjaxGuardTrait;

    public function __construct(
        private AltTextGeneratorService $altTextGeneratorService,
        private SiteLanguageService $siteLanguageService,
        private ResourceFactory $resourceFactory,
        private PermissionService $permissionService,
        private TcaSchemaFactory $tcaSchemaFactory,
        private BackendUserProvider $backendUserProvider,
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
     */
    public function generateAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($error = $this->requireModuleAccess()) {
            return $error;
        }

        $demand = GenerateAltTextDemand::fromRequestData($this->parseJsonBody($request));
        if ($demand === null) {
            return $this->errorResponse('error.invalidRequest', 400);
        }

        if (!$demand->validateSignature()) {
            return $this->errorResponse('module.error.invalidSignature', 400);
        }

        if ($error = $this->requireDemandSession($demand->getUserId(), $demand->getWorkspaceId(), $demand->getLanguageUid())) {
            return $error;
        }

        if ($error = $this->authorizeDemand($demand)) {
            return $error;
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

    /**
     * Verify the current user may edit the record the demand targets.
     *
     * Returns null when authorized, or the error response to send.
     */
    private function authorizeDemand(GenerateAltTextDemand $demand): ?ResponseInterface
    {
        $pageUid = $demand->getPageUid();
        $recordTable = $demand->getRecordTable();
        $recordColumns = $demand->getRecordColumns();

        // Root-level tables like sys_file_metadata have ignoreRootLevelRestriction=true in TCA
        // and are not governed by page tree permissions when their records sit at pid=0.
        // Only exempt when both conditions are true: the record is at root AND the table allows it.
        $isRootLevelExempt = 0 === $pageUid && $this->tableIgnoresRootLevelRestriction($recordTable);

        if (!$isRootLevelExempt) {
            $pageInfo = BackendUtility::readPageAccess(
                $pageUid,
                $this->backendUserProvider->get()->getPagePermsClause(Permission::PAGE_SHOW)
            );
            if (false === $pageInfo) {
                return $this->errorResponse('error.noPageAccess', 403);
            }
        }

        // Check if user has read access to sys_file
        if (!$this->permissionService->checkTableReadAccess('sys_file')) {
            return $this->errorResponse('error.noFileAccess', 403);
        }

        $recordData = BackendUtility::getRecordWSOL($recordTable, $demand->getRecordUid());

        // Fail closed: a missing (e.g. meanwhile deleted) record must reject the
        // request outright — skipping the record-level checks would fail open.
        if (!is_array($recordData)) {
            return $this->errorResponse('error.invalidRecordAccess', 403);
        }

        if ($isRootLevelExempt) {
            // For sys_file_metadata the TYPO3 core permission model (FileMetadataPermissionsAspect)
            // is file-mount based (editMeta), not page-based. checkRecordEditAccess relies on a
            // parent page row which does not exist for root-level records (pid=0), so enforce the
            // equivalent guards manually — table write access and non-exclude field access —
            // matching what checkRecordEditAccess would verify; editMeta is enforced by the caller.
            if (!$this->permissionService->checkTableWriteAccess($recordTable)
                || !$this->permissionService->checkNonExcludeFields($recordTable, $recordColumns)
            ) {
                return $this->errorResponse('error.invalidRecordAccess', 403);
            }
        } elseif (!$this->permissionService->checkRecordEditAccess($recordTable, $recordData, $recordColumns)) {
            return $this->errorResponse('error.invalidRecordAccess', 403);
        }

        return null;
    }
}
