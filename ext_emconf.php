<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Mindful A11y',
    'description' => 'TYPO3 extension with backend tools to help you create accessible content. Includes management of heading structure, finding missing alternative text for images and alternative text generation using ChatGPT. More features to come.',
    'category' => 'module',
    'author' => 'Felix Spittel',
    'author_email' => 'felix@mindfulmarkup.de',
    'author_company' => 'MindfulMarkup',
    'state' => 'alpha',
    'version' => '0.1.1',
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
