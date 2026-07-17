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

use MindfulMarkup\MindfulA11y\Domain\Model\GenerateAltTextDemand;
use MindfulMarkup\MindfulA11y\Enum\AltTextDemandAuthorizationFailure;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/** Validates that an alt-text demand still describes its exact issued target. */
final readonly class AltTextDemandAuthorizationService
{
    public function __construct(
        private ResourceFactory $resourceFactory,
        private PermissionService $permissionService,
        private BackendUserProvider $backendUserProvider,
        private RecordSnapshotService $recordSnapshotService,
    ) {}

    public function authorize(GenerateAltTextDemand $demand): File|AltTextDemandAuthorizationFailure
    {
        $record = BackendUtility::getRecordWSOL($demand->getRecordTable(), $demand->getRecordUid());
        if (!is_array($record)
            || !hash_equals(
                $demand->getRecordSnapshot(),
                $this->recordSnapshotService->fingerprint($demand->getRecordTable(), $record),
            )
            || !$this->recordMatchesDemand($demand, $record)
        ) {
            return AltTextDemandAuthorizationFailure::INVALID_SNAPSHOT;
        }

        if ($demand->getPageUid() !== 0) {
            $page = BackendUtility::readPageAccess(
                $demand->getPageUid(),
                $this->backendUserProvider->get()->getPagePermsClause(Permission::PAGE_SHOW),
            );
            if ($page === false) {
                return AltTextDemandAuthorizationFailure::NO_PAGE_ACCESS;
            }
        }

        $reference = $this->resolveReference($demand, $record);
        if ($reference === false) {
            return AltTextDemandAuthorizationFailure::INVALID_SNAPSHOT;
        }
        if (($reference === null && $demand->getFileReferenceSnapshot() !== '')
            || (is_array($reference)
                && !hash_equals(
                    $demand->getFileReferenceSnapshot(),
                    $this->recordSnapshotService->fingerprint('sys_file_reference', $reference),
                ))
        ) {
            return AltTextDemandAuthorizationFailure::INVALID_SNAPSHOT;
        }

        $fileRecord = BackendUtility::getRecordWSOL('sys_file', $demand->getFileUid());
        if (!is_array($fileRecord)
            || !hash_equals(
                $demand->getFileSnapshot(),
                $this->recordSnapshotService->fingerprint('sys_file', $fileRecord),
            )
        ) {
            return AltTextDemandAuthorizationFailure::INVALID_SNAPSHOT;
        }

        if (!$this->permissionService->checkTableReadAccess('sys_file')) {
            return AltTextDemandAuthorizationFailure::NO_FILE_ACCESS;
        }

        if (!$this->permissionService->checkRecordEditAccess(
            $demand->getRecordTable(),
            $record,
            $demand->getRecordColumns(),
        )) {
            return AltTextDemandAuthorizationFailure::INVALID_SNAPSHOT;
        }

        // Missing-alt-text demands authorize through the foreign content
        // record as well as the exact file-reference row they will later save.
        if (is_array($reference)
            && $demand->getRecordTable() !== 'sys_file_reference'
            && !$this->permissionService->checkRecordEditAccess('sys_file_reference', $reference, ['alternative'])
        ) {
            return AltTextDemandAuthorizationFailure::INVALID_SNAPSHOT;
        }

        try {
            $file = $this->resourceFactory->getFileObject($demand->getFileUid());
        } catch (FileDoesNotExistException) {
            return AltTextDemandAuthorizationFailure::FILE_NOT_FOUND;
        }

        $hasFileAccess = $demand->getRecordTable() === 'sys_file_metadata'
            ? $this->permissionService->checkFileMetaEditAccess($file)
            : $this->permissionService->checkFileReadAccess($file);

        return $hasFileAccess ? $file : AltTextDemandAuthorizationFailure::NO_FILE_MOUNT_ACCESS;
    }

    /** @param array<string, mixed> $record */
    private function recordMatchesDemand(GenerateAltTextDemand $demand, array $record): bool
    {
        if ((int)($record['pid'] ?? -1) !== $demand->getPageUid()
            || $this->recordLanguage($demand->getRecordTable(), $record) !== $demand->getLanguageUid()
        ) {
            return false;
        }

        foreach ($demand->getRecordColumns() as $column) {
            if (!isset($GLOBALS['TCA'][$demand->getRecordTable()]['columns'][$column])) {
                return false;
            }
        }

        if ($demand->getRecordTable() === 'sys_file_metadata') {
            return $demand->getFileReferenceUid() === 0
                && (int)($record['file'] ?? 0) === $demand->getFileUid();
        }

        if ($demand->getRecordTable() === 'sys_file_reference') {
            return $demand->getFileReferenceUid() === $demand->getRecordUid();
        }

        return $demand->getFileReferenceUid() > 0;
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>|false|null The reference, false on mismatch, null for metadata.
     */
    private function resolveReference(GenerateAltTextDemand $demand, array $record): array|false|null
    {
        if ($demand->getRecordTable() === 'sys_file_metadata') {
            return null;
        }

        $reference = $demand->getRecordTable() === 'sys_file_reference'
            && $demand->getRecordUid() === $demand->getFileReferenceUid()
            ? $record
            : BackendUtility::getRecordWSOL('sys_file_reference', $demand->getFileReferenceUid());
        if (!is_array($reference)
            || (int)($reference['pid'] ?? -1) !== $demand->getPageUid()
            || $this->recordLanguage('sys_file_reference', $reference) !== $demand->getLanguageUid()
            || (int)($reference['uid_local'] ?? 0) !== $demand->getFileUid()
        ) {
            return false;
        }

        if ($demand->getRecordTable() !== 'sys_file_reference'
            && ((string)($reference['tablenames'] ?? '') !== $demand->getRecordTable()
                || (int)($reference['uid_foreign'] ?? 0) !== $demand->getRecordUid()
                || [$reference['fieldname'] ?? null] !== $demand->getRecordColumns())
        ) {
            return false;
        }

        return $reference;
    }

    /** @param array<string, mixed> $record */
    private function recordLanguage(string $table, array $record): int
    {
        $languageField = (string)($GLOBALS['TCA'][$table]['ctrl']['languageField'] ?? '');

        return $languageField === '' ? 0 : (int)($record[$languageField] ?? 0);
    }
}
