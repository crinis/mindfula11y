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

namespace MindfulMarkup\MindfulA11y\Hooks;

/**
 * Tracks whether a DataHandler command that duplicates records is in progress,
 * so a hook can tell a NEW record created as a copy/translation (by the nested
 * DataHandler run of copyRecord() / localize()) from one an editor submitted.
 *
 * The depth is static because core instantiates the hook object once per
 * DataHandler, and the nested copy DataHandler is a different instance than
 * the one running the command; each using class keeps its own counter. Should
 * a command throw between pre- and post-processing, the depth stays raised —
 * each using class documents why that only affects its logging.
 */
trait DuplicatingCommandScopeTrait
{
    /**
     * Commands whose nested DataHandler run creates a duplicate of an existing
     * record (copyRecord() / localize() hand the source row to a fresh
     * DataHandler as a NEW record).
     *
     * @var list<string>
     */
    private const DUPLICATING_COMMANDS = ['copy', 'localize', 'copyToLanguage', 'inlineLocalizeSynchronize'];

    private static int $duplicationDepth = 0;

    /** Call from processCmdmap_preProcess(): opens the scope for a duplicating command. */
    private static function enterDuplicatingCommand(string $command): void
    {
        if (in_array($command, self::DUPLICATING_COMMANDS, true)) {
            self::$duplicationDepth++;
        }
    }

    /** Call from processCmdmap_postProcess(): closes the scope a duplicating command opened. */
    private static function leaveDuplicatingCommand(string $command): void
    {
        if (in_array($command, self::DUPLICATING_COMMANDS, true) && self::$duplicationDepth > 0) {
            self::$duplicationDepth--;
        }
    }

    private static function isDuplicating(): bool
    {
        return self::$duplicationDepth > 0;
    }
}
