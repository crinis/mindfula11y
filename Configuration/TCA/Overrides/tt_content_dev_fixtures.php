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

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

/*
 * DEVELOPMENT-ONLY fixture — export-ignored and excluded from TER packaging
 * (.gitattributes / .github/ExcludeFromPackaging.php), never part of a release.
 *
 * A minimal container content element for the MindfulA11yDev set's
 * "Container Heading Lab": it exposes tx_mindfula11y_childheadingtype in its
 * showitem (required for FormEngine AND the heading-structure module's
 * enrichment) and mirrors exactly what integrators configure for their own
 * container CTypes. Rendering lives in Configuration/Sets/MindfulA11yDev.
 */
ExtensionManagementUtility::addTcaSelectItem(
    'tt_content',
    'CType',
    [
        'label' => 'Container (Mindful A11y dev fixture)',
        'value' => 'mindfula11ydev_container',
        'group' => 'default',
    ],
);

$GLOBALS['TCA']['tt_content']['types']['mindfula11ydev_container'] = [
    'showitem' => '
        --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:general,
        --palette--;;general,
        --palette--;;headers,
        tx_mindfula11y_childheadingtype,
        bodytext,
    ',
    'columnsOverrides' => [
        'bodytext' => [
            'config' => [
                'enableRichtext' => true,
            ],
        ],
    ],
];
