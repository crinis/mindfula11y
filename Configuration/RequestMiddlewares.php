<?php
declare(strict_types=1);

/*
 * Mindful A11y extension for TYPO3 integrating accessibility tools into the backend.
 * Copyright (C) 2025  Mindful Markup, Felix Spittel
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 */

use MindfulMarkup\MindfulA11y\Middleware\DisableAdminPanelMiddleware;
use MindfulMarkup\MindfulA11y\Middleware\DisableCacheMiddleware;
use MindfulMarkup\MindfulA11y\Middleware\AuthenticateStructureAnalysisRequestMiddleware;
use MindfulMarkup\MindfulA11y\Middleware\StructureAnalysisResponseMiddleware;
use MindfulMarkup\MindfulA11y\Middleware\ValidationErrorTitleMiddleware;

return [
    'frontend' => [
        'mindfulmarkup/mindfula11y/authenticate-structure-analysis' => [
            'target' => AuthenticateStructureAnalysisRequestMiddleware::class,
            'after' => [
                'typo3/cms-frontend/site',
                // Core resets the workspace aspect while rejecting stale or
                // invalid backend cookies. Apply the signed scope afterwards.
                'typo3/cms-frontend/backend-user-authentication',
                // The signed ADMCMD_simUser simulation mutates the frontend
                // user object this middleware creates.
                'typo3/cms-frontend/authentication',
            ],
            'before' => [
                // The signed workspace aspect must be in place before the
                // simulator decides whether an offline workspace is previewed.
                'typo3/cms-frontend/preview-simulator',
                'typo3/cms-frontend/page-resolver',
            ],
        ],
        'mindfulmarkup/mindfula11y/disable-cache' => [
            'target' => DisableCacheMiddleware::class,
            'before' => [
                'typo3/cms-frontend/prepare-tsfe-rendering',
            ],
            'after' => [
                'mindfulmarkup/mindfula11y/authenticate-structure-analysis',
            ],
        ],
        'mindfulmarkup/mindfula11y/validation-error-title' => [
            'target' => ValidationErrorTitleMiddleware::class,
            'after' => [
                'typo3/cms-frontend/content-length-headers',
            ],
        ],
        'mindfulmarkup/mindfula11y/structure-analysis-response' => [
            'target' => StructureAnalysisResponseMiddleware::class,
            'after' => [
                'typo3/cms-frontend/page-resolver',
            ],
            'before' => [
                'typo3/cms-frontend/page-argument-validator',
            ],
        ],
        'mindfulmarkup/mindfula11y/disable-admin-panel' => [
            'target' => DisableAdminPanelMiddleware::class,
            'after' => [
                'typo3/cms-frontend/content-length-headers',
            ],
            'before' => [
                'typo3/cms-adminpanel/renderer',
            ],
        ],
    ],
];
