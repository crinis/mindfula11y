<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'WFA Accessibility Toolkit',
    'description' => 'Find and fix accessibility issues in TYPO3: viewhelpers for semantic headings and landmarks, missing image alt detection and generation with AI (ChatGPT). A backend module for quick remediation. More features to come.',
    'category' => 'module',
    'author' => 'Felix Spittel',
    'author_email' => 'felix@mindfulmarkup.de',
    'author_company' => 'Websites für Alle',
    'state' => 'alpha',
    'version' => '0.7.0',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-13.4.99',
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
