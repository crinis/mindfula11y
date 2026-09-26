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

/**
 * Keeps alternative and title empty on decorative file references.
 *
 * This is a data-consistency invariant, not an authorization gate: explicit
 * empty strings prevent FAL from falling back to file metadata, so native
 * f:image/f:media render alt="" for a reference declared decorative. WHO may
 * write tx_mindfula11y_decorative is deliberately governed by core's rules
 * for sys_file_reference alone (table rights, the reference's page
 * permissions, the field's exclude grant) — exactly like the adjacent
 * alternative and title columns. A stricter parent-relation requirement for
 * this one field would be bypassable through those equally powerful core
 * fields anyway and only make permission behavior route-dependent.
 *
 * One addition on top of those core rules: ENABLING decorative additionally
 * requires the alternative and title exclude-field grants. The injected empty
 * strings run through DataHandler's own per-field permission filter, which
 * silently drops fields the user may not write — a partially granted editor
 * could otherwise store the toggle while the blanking is discarded, leaving a
 * decorative reference that still renders its stored alternative text.
 */
#[Autoconfigure(public: true)]
final class DecorativeFileReferenceDataHandlerGuard
{
    // A depth left raised by a throwing command only logs a later rejection
    // as a note instead of an error — the rejection never depends on it.
    use DuplicatingCommandScopeTrait;

    /**
     * The decorative toggle column.
     *
     * Public alongside BLANKED_FIELDS: the UI gate has to name the same field
     * this guard enforces, and a second literal would be free to drift from it.
     */
    public const FIELD_NAME = 'tx_mindfula11y_decorative';

    /**
     * The columns enabling decorative blanks.
     *
     * Public because the decision "may this user be offered the toggle?" has to
     * be made in two places that must not drift: this guard, and the UI gate in
     * AltlessFileReferenceViewHelper. Widening the blanking below without
     * widening the gate would offer a toggle whose write DataHandler then
     * partially discards.
     *
     * @var list<string>
     */
    public const BLANKED_FIELDS = ['alternative', 'title'];

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

        // Nothing to enforce unless this save touches the toggle or a field it
        // blanks — skip the stored-state lookup on unrelated reference saves.
        $touchedFields = array_intersect_key(
            $incomingFieldArray,
            array_flip([self::FIELD_NAME, ...self::BLANKED_FIELDS])
        );
        if ($touchedFields === []) {
            return;
        }

        // Resolved once: it costs a workspace-overlaid record lookup and both
        // decisions below need it.
        $storedDecorative = $this->isStoredReferenceDecorative($id);

        // Enabling decorative and blanking alternative/title is ONE atomic
        // change: if DataHandler's exclude-field filter would drop the injected
        // blanks (missing alternative/title grants), reject the toggle instead
        // of storing it half-applied. Only a save that actually TURNS the flag
        // on qualifies — FormEngine resubmits every rendered field, so an
        // unchanged resave of an already-decorative reference carries the flag
        // too, and treating that as an enable would raise a USER_ERROR flash
        // message on every save while the stored value never changed.
        $enablesDecorative = !empty($incomingFieldArray[self::FIELD_NAME]) && !$storedDecorative;

        // A copy or translation of a decorative reference arrives here as a NEW
        // record carrying the flag, so it counts as an enable too — rightly: its
        // empty alternative/title are just as subject to the exclude-field
        // filter, and the nullable columns would fall back to NULL (inheriting
        // the file metadata's text). The editor did not ask for the flag in
        // that case, though, so dropping it is a note, not an error flash.
        if ($enablesDecorative && !$this->userMayWriteFields($dataHandler, self::BLANKED_FIELDS)) {
            unset($incomingFieldArray[self::FIELD_NAME]);
            $isDuplicate = self::isDuplicating() && !MathUtility::canBeInterpretedAsInteger($id);
            $dataHandler->log(
                $table,
                MathUtility::canBeInterpretedAsInteger($id) ? (int)$id : 0,
                SystemLogDatabaseAction::UPDATE,
                0,
                $isDuplicate ? SystemLogErrorClassification::MESSAGE : SystemLogErrorClassification::USER_ERROR,
                $isDuplicate
                    ? 'Decorative flag not carried over to the copied or translated file reference: a decorative reference keeps its alternative and title fields empty, which requires access to both fields.'
                    : 'Decorative flag not saved: marking a file reference decorative also empties its alternative and title fields, which requires access to both fields.'
            );
        }

        // The toggle must also SURVIVE the filter before its consequences are
        // applied. A user holding the alternative/title grants but not the
        // toggle's own passes the check above, yet DataHandler drops the toggle
        // here — leaving the injected blanks to wipe both fields while the flag
        // stays off. Fall back to the stored state in that case, so the write
        // is either applied whole or not at all.
        $incomingDecorativeCounts = array_key_exists(self::FIELD_NAME, $incomingFieldArray)
            && $this->userMayWriteFields($dataHandler, [self::FIELD_NAME]);

        $isDecorative = $incomingDecorativeCounts
            ? (bool)$incomingFieldArray[self::FIELD_NAME]
            : $storedDecorative;

        if ($isDecorative) {
            $discardedFields = [];
            foreach (self::BLANKED_FIELDS as $fieldName) {
                if ((string)($incomingFieldArray[$fieldName] ?? '') !== '') {
                    $discardedFields[] = $fieldName;
                }
                $incomingFieldArray[$fieldName] = '';
            }
            // The save is valid, but submitted text is discarded — leave a
            // note rather than an error flash.
            if ($discardedFields !== []) {
                $dataHandler->log(
                    $table,
                    MathUtility::canBeInterpretedAsInteger($id) ? (int)$id : 0,
                    SystemLogDatabaseAction::UPDATE,
                    0,
                    SystemLogErrorClassification::MESSAGE,
                    'Submitted {fields} emptied: the file reference is decorative, so it keeps these fields empty.',
                    null,
                    ['fields' => implode(', ', $discardedFields)]
                );
            }
        }
    }

    /**
     * Opens the duplication scope that classifies nested NEW-record saves as
     * copies (see DUPLICATING_COMMANDS). Runs before the command executes.
     */
    public function processCmdmap_preProcess(string $command, string $table, mixed $id, mixed $value, DataHandler $dataHandler): void
    {
        self::enterDuplicatingCommand($command);
    }

    public function processCmdmap_postProcess(string $command, string $table, mixed $id, mixed $value, DataHandler $dataHandler): void
    {
        self::leaveDuplicatingCommand($command);
    }

    /**
     * Whether DataHandler's exclude-field filter will let these fields through.
     *
     * The rule is PermissionService's — the module's UI gate has to reach the
     * same verdict, so the two must not be separate implementations. Only the
     * judged user differs: DataHandler::start() may be handed an alternative
     * user object, and the filter this anticipates runs against that one rather
     * than the session user.
     *
     * @param list<string> $fieldNames
     */
    private function userMayWriteFields(DataHandler $dataHandler, array $fieldNames): bool
    {
        return $this->permissionService->checkNonExcludeFields(
            'sys_file_reference',
            $fieldNames,
            $dataHandler->BE_USER,
        );
    }

    private function isStoredReferenceDecorative(int|string $id): bool
    {
        if (!MathUtility::canBeInterpretedAsInteger($id)) {
            return false;
        }

        // Workspace-overlaid: a save addressed at the live uid is written to
        // the workspace version, so the version's decorative state decides.
        $row = BackendUtility::getRecordWSOL('sys_file_reference', (int)$id, self::FIELD_NAME);

        return (bool)($row[self::FIELD_NAME] ?? false);
    }
}
