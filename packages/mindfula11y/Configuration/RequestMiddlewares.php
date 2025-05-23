<?php

return [
    'frontend' => [
        'mindfulmarkup/mindfula11y/disable-cache' => [
            'target' => \MindfulMarkup\MindfulA11y\Middleware\DisableCacheMiddleware::class,
            'after' => [
                'typo3/cms-frontend/tsfe',
            ],
        ],
        'mindfulmarkup/mindfula11y/disable-admin-panel' => [
            'target' => \MindfulMarkup\MindfulA11y\Middleware\DisableAdminPanel::class,
            'before' => [
                'typo3/cms-frontend/site',
            ],
        ],
    ],
];
