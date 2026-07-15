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
 */

namespace MindfulMarkup\MindfulA11y\Hooks;

use MindfulMarkup\MindfulA11y\Service\PermissionService;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\SysLog\Action\Database as SystemLogDatabaseAction;
use TYPO3\CMS\Core\SysLog\Error as SystemLogErrorClassification;
use TYPO3\CMS\Core\Utility\MathUtility;

#[Autoconfigure(public: true)]
final class DecorativeFileReferenceDataHandlerGuard
{
    private const FIELD_NAME = 'tx_mindfula11y_decorative';

    /**
     * @var array<string>
     */
    private const PARENT_RELATION_FIELDS = [
        'tablenames',
        'fieldname',
        'uid_foreign',
    ];

    public function __construct(
        private readonly PermissionService $permissionService,
    ) {}

    /**
     * Keep the stored alternative and title empty for decorative references.
     * Explicit empty strings also prevent FAL from falling back to file metadata.
     *
     * @param mixed $incomingFieldArray
     */
    public function processDatamap_preProcessFieldArray(&$incomingFieldArray, string $table, int|string $id, DataHandler $dataHandler): void
    {
        if ($table !== 'sys_file_reference' || !is_array($incomingFieldArray)) {
            return;
        }

        if (array_key_exists(self::FIELD_NAME, $incomingFieldArray)
            && !$this->mayEditParentRelations($incomingFieldArray, $id, $dataHandler)
        ) {
            // Reject only the protected toggle. Invalidating the complete field
            // array would also discard legitimate metadata of new IRRE children.
            unset($incomingFieldArray[self::FIELD_NAME]);
            $dataHandler->log(
                $table,
                MathUtility::canBeInterpretedAsInteger($id) ? (int)$id : 0,
                SystemLogDatabaseAction::UPDATE,
                null,
                SystemLogErrorClassification::USER_ERROR,
                'Attempt to modify the decorative image state without access to its parent record and relation field was blocked',
            );
        }

        // Nothing to enforce unless this save touches the toggle or a field it
        // blanks — skip the stored-state lookup on unrelated reference saves.
        if (!isset($incomingFieldArray[self::FIELD_NAME])
            && !array_key_exists('alternative', $incomingFieldArray)
            && !array_key_exists('title', $incomingFieldArray)
        ) {
            return;
        }

        $isDecorative = isset($incomingFieldArray[self::FIELD_NAME])
            ? (bool)$incomingFieldArray[self::FIELD_NAME]
            : $this->isStoredReferenceDecorative($id);

        if ($isDecorative) {
            $incomingFieldArray['alternative'] = '';
            $incomingFieldArray['title'] = '';
        }
    }

    private function isStoredReferenceDecorative(int|string $id): bool
    {
        if (!MathUtility::canBeInterpretedAsInteger($id)) {
            return false;
        }

        $row = BackendUtility::getRecord('sys_file_reference', (int)$id, self::FIELD_NAME);

        return (bool)($row[self::FIELD_NAME] ?? false);
    }

    /**
     * Require access to both the stored relation and a submitted replacement.
     * Checking only the submitted values would allow an editor to move a
     * restricted reference while changing its decorative state.
     *
     * @param array<string, mixed> $incomingFieldArray
     */
    private function mayEditParentRelations(array $incomingFieldArray, int|string $id, DataHandler $dataHandler): bool
    {
        $storedRelation = null;
        if (MathUtility::canBeInterpretedAsInteger($id)) {
            $storedRelation = BackendUtility::getRecordWSOL(
                'sys_file_reference',
                (int)$id,
                implode(',', self::PARENT_RELATION_FIELDS),
            );
            if (!is_array($storedRelation) || !$this->mayEditParentRelation($storedRelation, $dataHandler)) {
                return false;
            }
        }

        $effectiveRelation = array_replace(
            $storedRelation ?? [],
            array_intersect_key($incomingFieldArray, array_flip(self::PARENT_RELATION_FIELDS)),
        );

        if ($storedRelation === null && !$this->hasCompleteParentRelation($effectiveRelation)) {
            $submittedRelation = $this->findSubmittedParentRelation($id, $dataHandler);
            if ($submittedRelation !== null) {
                $effectiveRelation = array_replace($effectiveRelation, $submittedRelation);
            }
        }

        return $this->mayEditParentRelation($effectiveRelation, $dataHandler);
    }

    /**
     * New IRRE file references do not submit their relation columns. Instead,
     * the parent file field contains the NEW id and DataHandler writes the
     * relation after inserting the child. Resolve that authoritative parent
     * relation from the submitted data map.
     *
     * @return array{tablenames: string, fieldname: string, uid_foreign: int|string}|null
     */
    private function findSubmittedParentRelation(int|string $referenceId, DataHandler $dataHandler): ?array
    {
        if (MathUtility::canBeInterpretedAsInteger($referenceId)) {
            return null;
        }

        $matches = [];
        foreach ($dataHandler->datamap as $tableName => $records) {
            if (!isset($GLOBALS['TCA'][$tableName]['columns'])) {
                continue;
            }
            foreach ($records as $recordUid => $fields) {
                foreach ($fields as $fieldName => $value) {
                    $configuration = $GLOBALS['TCA'][$tableName]['columns'][$fieldName]['config'] ?? [];
                    if (($configuration['type'] ?? '') !== 'file'
                        && (($configuration['foreign_table'] ?? '') !== 'sys_file_reference'
                            || ($configuration['foreign_field'] ?? '') !== 'uid_foreign')
                    ) {
                        continue;
                    }
                    if (!$this->relationValueContainsReference($value, $referenceId)) {
                        continue;
                    }
                    $matches[] = [
                        'tablenames' => $tableName,
                        'fieldname' => (string)$fieldName,
                        'uid_foreign' => $recordUid,
                    ];
                }
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    private function relationValueContainsReference(mixed $value, string $referenceId): bool
    {
        if (is_array($value)) {
            foreach ($value as $nestedValue) {
                if ($this->relationValueContainsReference($nestedValue, $referenceId)) {
                    return true;
                }
            }
            return false;
        }
        if (!is_string($value) && !is_int($value)) {
            return false;
        }

        foreach (explode(',', (string)$value) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === $referenceId || str_ends_with($candidate, '_' . $referenceId)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, mixed> $relation
     */
    private function hasCompleteParentRelation(array $relation): bool
    {
        foreach (self::PARENT_RELATION_FIELDS as $fieldName) {
            if (!array_key_exists($fieldName, $relation)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<string, mixed> $relation
     */
    private function mayEditParentRelation(array $relation, DataHandler $dataHandler): bool
    {
        $tableName = $relation['tablenames'] ?? null;
        $columnName = $relation['fieldname'] ?? null;
        $recordUid = $relation['uid_foreign'] ?? null;

        if (!is_string($tableName) || $tableName === ''
            || !is_string($columnName) || $columnName === ''
            || (!is_int($recordUid) && !is_string($recordUid))
            || !isset($GLOBALS['TCA'][$tableName]['columns'][$columnName])
        ) {
            return false;
        }

        if (is_string($recordUid) && !ctype_digit($recordUid)) {
            if (($dataHandler->substNEWwithIDs_table[$recordUid] ?? null) !== $tableName) {
                return false;
            }
            $recordUid = $dataHandler->substNEWwithIDs[$recordUid] ?? 0;
        }

        if ((int)$recordUid <= 0) {
            return false;
        }

        $parentRecord = BackendUtility::getRecordWSOL($tableName, (int)$recordUid);

        return is_array($parentRecord)
            && $this->permissionService->checkRecordEditAccess($tableName, $parentRecord, [$columnName]);
    }
}
