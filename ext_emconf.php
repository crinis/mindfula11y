<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Accessibility Toolkit',
    'description' => 'Find and fix accessibility issues in TYPO3: missing-alt detection and AI alt-text generation, decorative-image controls, heading/landmark checks, axe-core scans with optional AI audits, semantic Fluid ViewHelpers, and accessible form-validation feedback.',
    'category' => 'module',
    'author' => 'Felix Spittel',
    'author_email' => 'felix@mindfulmarkup.de',
    'author_company' => 'Mindful Markup',
    'state' => 'beta',
    'version' => '0.12.0',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-14.3.99',
            'php' => '8.2.0-8.4.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
    'autoload' => [
        'psr-4' => [
            'MindfulMarkup\\MindfulA11y\\' => 'Classes/'
        ]
    ],
];
