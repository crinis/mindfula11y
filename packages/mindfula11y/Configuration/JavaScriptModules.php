<?php

return [
    'dependencies' => [
        'backend',
    ],
    'tags' => [
        'backend.form',
    ],
    'imports' => [
        '@mindfulmarkup/mindfula11y/input-alt-element-service.js' => 'EXT:mindfula11y/Resources/Public/JavaScript/input-alt-element-service.js',
        '@mindfulmarkup/mindfula11y/heading-structure.js' => 'EXT:mindfula11y/Resources/Public/JavaScript/heading-structure.js',
        '@mindfulmarkup/mindfula11y/altless-file-reference.js' => 'EXT:mindfula11y/Resources/Public/JavaScript/altless-file-reference.js',
    ],
];
