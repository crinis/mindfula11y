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

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

ExtensionManagementUtility::addTCAcolumns(
    'pages',
    [
        'tx_mindfula11y_scanid' => [
            'exclude' => false,
            'label' => 'LLL:EXT:mindfula11y/Resources/Private/Language/Database.xlf:pages.columns.mindfula11y.scanId',
            'description' => 'LLL:EXT:mindfula11y/Resources/Private/Language/Database.xlf:pages.columns.mindfula11y.scanId.description',
            'config' => [
                'type' => 'input',
                'eval' => 'trim',
                'readOnly' => true,
            ],
        ],
        'tx_mindfula11y_scanupdated' => [
            'exclude' => false,
            'label' => 'LLL:EXT:mindfula11y/Resources/Private/Language/Database.xlf:pages.columns.mindfula11y.scanUpdated',
            'description' => 'LLL:EXT:mindfula11y/Resources/Private/Language/Database.xlf:pages.columns.mindfula11y.scanUpdated.description',
            'config' => [
                'type' => 'datetime',
                'readOnly' => true,
            ],
        ],
    ]
);
