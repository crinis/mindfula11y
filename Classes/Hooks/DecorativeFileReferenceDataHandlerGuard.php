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

use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\DataHandling\DataHandler;
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
 */
#[Autoconfigure(public: true)]
final class DecorativeFileReferenceDataHandlerGuard
{
    private const FIELD_NAME = 'tx_mindfula11y_decorative';

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
        if (!array_key_exists(self::FIELD_NAME, $incomingFieldArray)
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

        // Workspace-overlaid: a save addressed at the live uid is written to
        // the workspace version, so the version's decorative state decides.
        $row = BackendUtility::getRecordWSOL('sys_file_reference', (int)$id, self::FIELD_NAME);

        return (bool)($row[self::FIELD_NAME] ?? false);
    }
}
