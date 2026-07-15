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
        } catch (\Throwable) {
            return false;
        }

        return $this->authorizePage($backendUser, $ticket->pageId, $ticket->languageId, $ticket->workspaceId) !== null;
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
}
