<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Accessibility Toolkit',
    'description' => 'Find and fix accessibility issues directly in the TYPO3 backend: a remediation module with missing alt-text detection and AI generation (OpenAI), accessibility fields and Fluid ViewHelpers for semantic headings and landmarks, plus optional axe-core page scanning.',
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
