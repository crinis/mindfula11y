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

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

defined('TYPO3') or die();

$extensionConfiguration = GeneralUtility::makeInstance(ExtensionConfiguration::class);

ExtensionManagementUtility::addTCAcolumns(
    'sys_file_reference',
    [
        'tx_mindfula11y_decorative' => [
            'exclude' => true,
            'label' => 'LLL:EXT:mindfula11y/Resources/Private/Language/Database.xlf:sysFileReference.columns.mindfula11y.decorative',
            'description' => 'LLL:EXT:mindfula11y/Resources/Private/Language/Database.xlf:sysFileReference.columns.mindfula11y.decorative.description',
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
                'default' => 0,
            ],
        ],
    ]
);

ExtensionManagementUtility::addFieldsToPalette(
    'sys_file_reference',
    'imageoverlayPalette',
    'tx_mindfula11y_decorative',
    'before:alternative'
);

$decorativeDisplayCondition = 'FIELD:tx_mindfula11y_decorative:!=:1';
foreach (['alternative', 'title'] as $fieldName) {
    $existingDisplayCondition = $GLOBALS['TCA']['sys_file_reference']['columns'][$fieldName]['displayCond'] ?? null;
    $GLOBALS['TCA']['sys_file_reference']['columns'][$fieldName]['displayCond'] = empty($existingDisplayCondition)
        ? $decorativeDisplayCondition
        : ['AND' => [$existingDisplayCondition, $decorativeDisplayCondition]];
}

if (
    !$extensionConfiguration->get('mindfula11y', 'disableAltTextGeneration') &&
    !empty($extensionConfiguration->get('mindfula11y', 'openAIApiKey'))
) {
    $GLOBALS['TCA']['sys_file_reference']['columns']['alternative']['config']['fieldControl']['mindfula11yGenerateAltText'] = [
        'renderType' => 'mindfula11yGenerateAltText',
    ];
}
