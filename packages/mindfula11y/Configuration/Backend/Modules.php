<?php

declare(strict_types=1);

return [
    'mindfula11y_accessibility' => [
        'parent' => 'web',
        'position' => ['after' => 'web_list'],
        'access' => 'user',
        'path' => '/module/web/mindfula11y/accessibility',
        'iconIdentifier' => 'tx-mindfula11y-module-accessibility',
        'labels' => 'LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf',
        'routes' => [
            '_default' => [
                'target' => \MindfulMarkup\MindfulA11y\Controller\AccessibilityModuleController::class . '::mainAction',
                'methods' => ['GET'],
            ],
        ],
        'moduleData' => [
            'languageId' => 0,
        ],
    ],
    'mindfula11y_alternativemissingalttext' => [
        'parent' => 'web',
        'access' => 'user',
        'path' => '/module/web/mindfula11y/accessibility/alternative-missing-alt-text',
        'iconIdentifier' => 'tx-mindfula11y-module-missingalttext',
        'labels' => 'LLL:EXT:mindfula11y/Resources/Private/Language/Modules/MissingAltText.xlf',
        'routes' => [
            '_default' => [
                'target' => \MindfulMarkup\MindfulA11y\Controller\AlternativeMissingAltTextModuleController::class . '::mainAction',
                'methods' => ['GET'],
            ],
        ],
        'moduleData' => [
            'languageId' => 0,
            'pageLevels' => 1,
            'tableName' => '',
            'currentPage' => 1,
            'filterFileMetaData' => true,
        ],
    ],
    'mindfula11y_headingstructure' => [
        'parent' => 'web',
        'access' => 'user',
        'path' => '/module/web/mindfula11y/accessibility/heading-structure',
        'iconIdentifier' => 'tx-mindfula11y-module-headingstructure',
        'labels' => 'LLL:EXT:mindfula11y/Resources/Private/Language/Modules/HeadingStructure.xlf',
        'routes' => [
            '_default' => [
                'target' => \MindfulMarkup\MindfulA11y\Controller\HeadingStructureModuleController::class . '::mainAction',
                'methods' => ['GET'],
            ],
        ],
        'moduleData' => [
            'languageId' => 0,
        ],
    ],
];
