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

namespace MindfulMarkup\MindfulA11y\Hooks;

use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\SysLog\Action\Database as SystemLogDatabaseAction;
use TYPO3\CMS\Core\SysLog\Error as SystemLogErrorClassification;

final class ScanStateDataHandlerGuard
{
    /**
     * @var array<string>
     */
    private const SCAN_STATE_FIELDS = [
        'tx_mindfula11y_scanid',
        'tx_mindfula11y_scanupdated',
    ];

    private static int $internalWriteDepth = 0;

    public static function withInternalWriteScope(callable $callback): mixed
    {
        self::$internalWriteDepth++;
        try {
            return $callback();
        } finally {
            self::$internalWriteDepth--;
        }
    }

    /**
     * @param mixed $incomingFieldArray
     */
    public function processDatamap_preProcessFieldArray(&$incomingFieldArray, string $table, int|string $id, DataHandler $dataHandler): void
    {
        if ($table !== 'pages' || self::$internalWriteDepth > 0 || !is_array($incomingFieldArray)) {
            return;
        }

        $submittedScanFields = array_intersect(self::SCAN_STATE_FIELDS, array_keys($incomingFieldArray));
        if ($submittedScanFields === []) {
            return;
        }

        foreach ($submittedScanFields as $fieldName) {
            unset($incomingFieldArray[$fieldName]);
        }

        $dataHandler->log(
            $table,
            (int)$id,
            SystemLogDatabaseAction::UPDATE,
            null,
            SystemLogErrorClassification::MESSAGE,
            'Attempt to modify internal Mindful A11y scanner state fields was blocked',
            null,
            ['fields' => implode(', ', $submittedScanFields)]
        );
    }
}
