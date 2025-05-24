<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

ExtensionManagementUtility::addTCAcolumns(
    'tt_content',
    [
        'tx_mindfula11y_headinglevel' => [
            'exclude' => true,
            'label' => 'LLL:EXT:mindfulA11y/Resources/Private/Language/Database.xlf:ttContent.columns.mindfulA11y.headingLevel',
            'description' => 'LLL:EXT:mindfulA11y/Resources/Private/Language/Database.xlf:ttContent.columns.mindfulA11y.headingLevel.description',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'default' => 2,
                'items' => [
                    [
                        'label' => 'LLL:EXT:mindfula11y/Resources/Private/Language/Database.xlf:ttContent.columns.mindfulA11y.headingLevel.items.1',
                        'value' => 1,
                    ],
                    [
                        'label' => 'LLL:EXT:mindfula11y/Resources/Private/Language/Database.xlf:ttContent.columns.mindfulA11y.headingLevel.items.2',
                        'value' => 2,
                    ],
                    [
                        'label' => 'LLL:EXT:mindfula11y/Resources/Private/Language/Database.xlf:ttContent.columns.mindfulA11y.headingLevel.items.3',
                        'value' => 3,
                    ],
                    [
                        'label' => 'LLL:EXT:mindfula11y/Resources/Private/Language/Database.xlf:ttContent.columns.mindfulA11y.headingLevel.items.4',
                        'value' => 4,
                    ],
                    [
                        'label' => 'LLL:EXT:mindfula11y/Resources/Private/Language/Database.xlf:ttContent.columns.mindfulA11y.headingLevel.items.5',
                        'value' => 5,
                    ],
                    [
                        'label' => 'LLL:EXT:mindfula11y/Resources/Private/Language/Database.xlf:ttContent.columns.mindfulA11y.headingLevel.items.6',
                        'value' => 6,
                    ],
                    [
                        'label' => 'LLL:EXT:mindfula11y/Resources/Private/Language/Database.xlf:ttContent.columns.mindfulA11y.headingLevel.items.fallbackTag',
                        'value' => -1
                    ]
                ],
            ],
        ],
    ]
);

ExtensionManagementUtility::addFieldsToPalette(
    'tt_content',
    'headers',
    'tx_mindfula11y_headinglevel'
);
