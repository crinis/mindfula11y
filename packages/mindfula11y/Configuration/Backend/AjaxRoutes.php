<?php

declare(strict_types=1);

return [
    'mindfula11y_generatealttext' => [
        'path' => '/mindfula11y/alt-text/generate',
        'target' => \MindfulMarkup\MindfulA11y\Controller\AltTextAjaxController::class . '::generateAction',
    ],
];