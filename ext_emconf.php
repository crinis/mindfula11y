<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'WFA Accessibility Module',
    'description' => 'Find, fix and improve accessibility in TYPO3 — AI-assisted alt text, semantic headings, ARIA landmarks and inline remediation.',
    'category' => 'module',
    'author' => 'Felix Spittel',
    'author_email' => 'felix@mindfulmarkup.de',
    'author_company' => 'Websites für Alle',
    'state' => 'alpha',
    'version' => '0.4.0',
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
