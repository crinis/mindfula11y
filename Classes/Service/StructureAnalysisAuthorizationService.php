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

namespace MindfulMarkup\MindfulA11y\Service;

use MindfulMarkup\MindfulA11y\Domain\Model\StructureAnalysisTicket;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Versioning\VersionState;

/** Applies TYPO3's backend authorization model to structure-analysis capabilities. */
final readonly class StructureAnalysisAuthorizationService
{
    private const MODULE_IDENTIFIER = PermissionService::MODULE_NAME;

    public function __construct(
        private ModuleProvider $moduleProvider,
        private ConnectionPool $connectionPool,
        private ModuleSettingsService $moduleSettingsService,
        private PagePreviewService $pagePreviewService,
        private RecordSnapshotService $recordSnapshotService,
        private StructureAnalysisTicketService $ticketService,
    ) {}

    /**
     * Rebuilds the issuing backend user's current access lists from the database.
     *
     * No backend session is required: the signed ticket identifies the user, and
     * setBeUserByUid() applies TYPO3's disabled/deleted/start/end-time restrictions
     * before fetchGroupData() resolves the user's current groups and permissions.
     */
    public function isTicketHolderAuthorized(StructureAnalysisTicket $ticket): bool
    {
        if ($ticket->backendUserId <= 0) {
            return false;
        }

        try {
            $backendUser = GeneralUtility::makeInstance(BackendUserAuthentication::class);
            $backendUser->setBeUserByUid($ticket->backendUserId);
            if ((int)($backendUser->user['uid'] ?? 0) !== $ticket->backendUserId) {
                return false;
            }
            $backendUser->fetchGroupData();
            // Reconstruct the exact signed workspace without persisting it as
            // the user's new backend preference. Revoked membership therefore
            // invalidates the ticket before any workspace overlay is resolved.
            if (!$backendUser->setTemporaryWorkspace($ticket->workspaceId)) {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }

        $page = $this->authorizePage($backendUser, $ticket->pageId, $ticket->languageId, $ticket->workspaceId);

        return $page !== null && $this->matchesCurrentScope($backendUser, $ticket, $page);
    }

    /**
     * Runs every authorization check for issuing or redeeming a structure
     * analysis on the given page and returns the workspace-overlaid page
     * record when all of them pass — callers reuse it instead of loading the
     * page a second time.
     *
     * @return array<string, mixed>|null
     */
    public function authorizePage(
        BackendUserAuthentication $backendUser,
        int $pageId,
        int $languageId,
        int $workspaceId,
    ): ?array {
        if ((int)($backendUser->user['uid'] ?? 0) <= 0
            || $pageId <= 0
            || $languageId < 0
            || $workspaceId < 0
            || !$backendUser->isUserAllowedToLogin()
            // Requiring the exact current workspace makes a workspace switch or
            // revoked workspace membership invalidate an outstanding ticket.
            || $backendUser->workspace !== $workspaceId
            || (ExtensionManagementUtility::isLoaded('workspaces')
                && $backendUser->checkWorkspace($workspaceId) === false)
            || !$this->moduleProvider->accessGranted(self::MODULE_IDENTIFIER, $backendUser)
            || !$backendUser->checkLanguageAccess($languageId)
        ) {
            return null;
        }

        $page = BackendUtility::getRecord('pages', $pageId);
        if (!is_array($page)) {
            return null;
        }
        BackendUtility::workspaceOL('pages', $page, $workspaceId);
        if (!is_array($page)
            || VersionState::tryFrom((int)($page['t3ver_state'] ?? 0)) === VersionState::DELETE_PLACEHOLDER
            // TYPO3 documents DB-mount containment and the page Show bit as
            // separate requirements. Keep both checks explicit even though
            // current calcPerms() also fails pages outside a web mount.
            || !$backendUser->isInWebMount($page)
            || !$backendUser->doesUserHaveAccess($page, Permission::PAGE_SHOW)
        ) {
            return null;
        }

        if ($languageId !== 0 && !$this->hasPageTranslation($pageId, $languageId, $workspaceId)) {
            return null;
        }

        return $page;
    }

    /**
     * Deliberately NOT delegated to PagePreviewService::getLocalizedPageRecord()
     * despite the similar query: that method derives the workspace from the
     * session's $GLOBALS['BE_USER'], while this service authorizes session-less
     * ticket requests against the ticket's own workspace claim.
     */
    private function hasPageTranslation(int $pageId, int $languageId, int $workspaceId): bool
    {
        $languageField = (string)($GLOBALS['TCA']['pages']['ctrl']['languageField'] ?? 'sys_language_uid');
        $translationParentField = (string)($GLOBALS['TCA']['pages']['ctrl']['transOrigPointerField'] ?? 'l10n_parent');
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $workspaceId));
        $translation = $queryBuilder
            ->select('uid', 'pid', 't3ver_oid', 't3ver_state')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq(
                    $translationParentField,
                    $queryBuilder->createNamedParameter($pageId, Connection::PARAM_INT),
                ),
                $queryBuilder->expr()->eq(
                    $languageField,
                    $queryBuilder->createNamedParameter($languageId, Connection::PARAM_INT),
                ),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();
        if (!is_array($translation)) {
            return false;
        }

        BackendUtility::workspaceOL('pages', $translation, $workspaceId);
        return is_array($translation)
            && VersionState::tryFrom((int)($translation['t3ver_state'] ?? 0)) !== VersionState::DELETE_PLACEHOLDER;
    }

    /**
     * Re-evaluate the mutable issuance-time TSconfig and preview URL scope.
     *
     * @param array<string, mixed> $page
     */
    private function matchesCurrentScope(
        BackendUserAuthentication $backendUser,
        StructureAnalysisTicket $ticket,
        array $page,
    ): bool
    {
        // BackendUtility's TSconfig lookup and PagePreviewService's localized
        // workspace lookup use the current backend user. Ticket redemption has
        // no backend session, so expose the already rebuilt ticket holder only
        // for these lookups and restore the frontend global afterwards.
        $hadBackendUser = array_key_exists('BE_USER', $GLOBALS);
        $previousBackendUser = $GLOBALS['BE_USER'] ?? null;
        $GLOBALS['BE_USER'] = $backendUser;
        try {
            $pageTsConfig = $this->moduleSettingsService->getConvertedPageTsConfig($ticket->pageId);
            if (!$this->moduleSettingsService->hasHeadingStructureAccess($pageTsConfig)
                && !$this->moduleSettingsService->hasLandmarkStructureAccess($pageTsConfig)
            ) {
                return false;
            }

            $previewUrl = $this->pagePreviewService->buildPreviewUrl($page, $ticket->pageId, $ticket->languageId);
            $previewPage = $this->pagePreviewService->getPreviewPageRecord(
                $page,
                $ticket->pageId,
                $ticket->languageId,
            );
            if ($previewUrl === null
                || $previewPage === null
                || !hash_equals(
                    $ticket->pageRecordSnapshot,
                    $this->recordSnapshotService->fingerprint('pages', $previewPage),
                )
            ) {
                return false;
            }

            return hash_equals($ticket->frontendOrigin, $this->ticketService->originFromUrl($previewUrl))
                && hash_equals($ticket->target, $this->ticketService->normalizeTarget($previewUrl));
        } catch (\Throwable) {
            return false;
        } finally {
            if ($hadBackendUser) {
                $GLOBALS['BE_USER'] = $previousBackendUser;
            } else {
                unset($GLOBALS['BE_USER']);
            }
        }
    }
}
