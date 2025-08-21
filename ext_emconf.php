<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Mindful A11y',
    'description' => 'Comprehensive accessibility extension for TYPO3 providing backend tools for content editors and integrators. Features include missing alternative text detection with AI-powered generation via ChatGPT, semantic heading structure management with ViewHelper integration, ARIA landmark configuration for improved navigation, and inline editing capabilities within a unified accessibility backend module.',
    'category' => 'module',
    'author' => 'Felix Spittel',
    'author_email' => 'felix@mindfulmarkup.de',
    'author_company' => 'Mindful Markup',
    'state' => 'alpha',
    'version' => '0.2.1',
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
