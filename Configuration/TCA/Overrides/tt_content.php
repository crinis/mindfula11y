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
use MindfulMarkup\MindfulA11y\Enum\AriaLandmark;
use MindfulMarkup\MindfulA11y\Enum\HeadingType;
use MindfulMarkup\MindfulA11y\Tca\HeadingTypeItemsProcessor;

defined('TYPO3') or die();

$mindfula11yHeadingTypeItems = array_map(
    static fn(HeadingType $type): array => [
        'label' => $type->getLabelKey(),
        'value' => $type->value,
    ],
    array_filter(
        HeadingType::cases(),
        static fn(HeadingType $type): bool => $type !== HeadingType::DIV,
    ),
);

ExtensionManagementUtility::addTCAcolumns(
    'tt_content',
    [
        'tx_mindfula11y_headingtype' => [
            'exclude' => true,
            'label' => 'LLL:EXT:mindfula11y/Resources/Private/Language/Database.xlf:ttContent.columns.mindfula11y.headingType',
            'description' => 'LLL:EXT:mindfula11y/Resources/Private/Language/Database.xlf:ttContent.columns.mindfula11y.headingType.description',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'default' => HeadingType::H2->value,
                'items' => $mindfula11yHeadingTypeItems,
                'itemsProcFunc' => HeadingTypeItemsProcessor::class . '->addStoredDivItem',
            ],
        ],
        // Intentionally not added to any palette/showitem: only meaningful on container-like
        // CTypes, which this extension does not know about. Integrators add it to their
        // container types (see Documentation/Developers/Index.md) — note the structure
        // module's enrichment also requires the field in the type's showitem.
        'tx_mindfula11y_childheadingtype' => [
            'exclude' => true,
            'label' => 'LLL:EXT:mindfula11y/Resources/Private/Language/Database.xlf:ttContent.columns.mindfula11y.childHeadingType',
            'description' => 'LLL:EXT:mindfula11y/Resources/Private/Language/Database.xlf:ttContent.columns.mindfula11y.childHeadingType.description',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'default' => '',
                'items' => array_merge(
                    [
                        [
                            'label' => 'LLL:EXT:mindfula11y/Resources/Private/Language/Database.xlf:ttContent.columns.mindfula11y.childHeadingType.items.automatic',
                            'value' => '',
                        ],
                    ],
                    $mindfula11yHeadingTypeItems,
                ),
                'itemsProcFunc' => HeadingTypeItemsProcessor::class . '->addStoredDivItem',
            ],
        ],
        'tx_mindfula11y_landmark' => [
            'exclude' => true,
            'label' => 'LLL:EXT:mindfula11y/Resources/Private/Language/Database.xlf:ttContent.columns.mindfula11y.landmark',
            'description' => 'LLL:EXT:mindfula11y/Resources/Private/Language/Database.xlf:ttContent.columns.mindfula11y.landmark.description',
            'onChange' => 'reload',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'default' => AriaLandmark::NONE->value,
                'items' => [
                    ['label' => AriaLandmark::NONE->getLabelKey(), 'value' => AriaLandmark::NONE->value],
                    ['label' => AriaLandmark::REGION->getLabelKey(), 'value' => AriaLandmark::REGION->value],
                    ['label' => AriaLandmark::NAVIGATION->getLabelKey(), 'value' => AriaLandmark::NAVIGATION->value],
                    ['label' => AriaLandmark::COMPLEMENTARY->getLabelKey(), 'value' => AriaLandmark::COMPLEMENTARY->value],
                    ['label' => AriaLandmark::MAIN->getLabelKey(), 'value' => AriaLandmark::MAIN->value],
                    ['label' => AriaLandmark::BANNER->getLabelKey(), 'value' => AriaLandmark::BANNER->value],
                    ['label' => AriaLandmark::CONTENTINFO->getLabelKey(), 'value' => AriaLandmark::CONTENTINFO->value],
                    ['label' => AriaLandmark::SEARCH->getLabelKey(), 'value' => AriaLandmark::SEARCH->value],
                    ['label' => AriaLandmark::FORM->getLabelKey(), 'value' => AriaLandmark::FORM->value],
                ],
            ],
        ],
        'tx_mindfula11y_arialabelledby' => [
            'exclude' => true,
            'label' => 'LLL:EXT:mindfula11y/Resources/Private/Language/Database.xlf:ttContent.columns.mindfula11y.ariaLabelledby',
            'description' => 'LLL:EXT:mindfula11y/Resources/Private/Language/Database.xlf:ttContent.columns.mindfula11y.ariaLabelledby.description',
            'displayCond' => 'FIELD:tx_mindfula11y_landmark:!=:',
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
                'default' => 1,
                'items' => [
                    [
                        'label' => '',
                    ],
                ],
            ],
        ],
        'tx_mindfula11y_arialabel' => [
            'exclude' => true,
            'label' => 'LLL:EXT:mindfula11y/Resources/Private/Language/Database.xlf:ttContent.columns.mindfula11y.ariaLabel',
            'description' => 'LLL:EXT:mindfula11y/Resources/Private/Language/Database.xlf:ttContent.columns.mindfula11y.ariaLabel.description',
            'displayCond' => 'FIELD:tx_mindfula11y_landmark:!=:',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 255,
                'eval' => 'trim',
            ],
        ],
    ]
);

ExtensionManagementUtility::addFieldsToPalette(
    'tt_content',
    'headers',
    'tx_mindfula11y_headingtype',
    'after:header'
);

// Add landmark palette
$GLOBALS['TCA']['tt_content']['palettes']['landmarks'] = [
    'label' => 'LLL:EXT:mindfula11y/Resources/Private/Language/Database.xlf:ttContent.palettes.landmarks',
    'showitem' => 'tx_mindfula11y_landmark, --linebreak--, tx_mindfula11y_arialabelledby, tx_mindfula11y_arialabel'
];

// Add accessibility tab to all content element types
ExtensionManagementUtility::addToAllTCAtypes(
    'tt_content',
    '--div--;LLL:EXT:mindfula11y/Resources/Private/Language/Database.xlf:ttContent.tabs.accessibility, --palette--;LLL:EXT:mindfula11y/Resources/Private/Language/Database.xlf:ttContent.palettes.landmarks;landmarks'
);
