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

use MindfulMarkup\MindfulA11y\Controller\AltTextAjaxController;
use MindfulMarkup\MindfulA11y\Controller\ScanAjaxController;
use MindfulMarkup\MindfulA11y\Controller\StructureAnalysisEnrichmentAjaxController;
use MindfulMarkup\MindfulA11y\Controller\StructureAnalysisTicketAjaxController;

return [
    'mindfula11y_generatealttext' => [
        'path' => '/mindfula11y/alt-text/generate',
        'target' => AltTextAjaxController::class . '::generateAction',
        'inheritAccessFromModule' => 'mindfula11y_accessibility',
    ],
    'mindfula11y_createscan' => [
        'path' => '/mindfula11y/scan/create',
        'target' => ScanAjaxController::class . '::createAction',
        'inheritAccessFromModule' => 'mindfula11y_accessibility',
    ],
    'mindfula11y_getscan' => [
        'path' => '/mindfula11y/scan/get',
        'target' => ScanAjaxController::class . '::getAction',
        'inheritAccessFromModule' => 'mindfula11y_accessibility',
    ],
    'mindfula11y_cancelscan' => [
        'path' => '/mindfula11y/scan/cancel',
        'target' => ScanAjaxController::class . '::cancelAction',
        'inheritAccessFromModule' => 'mindfula11y_accessibility',
    ],
    'mindfula11y_structure_ticket' => [
        'path' => '/mindfula11y/structure/ticket',
        'target' => StructureAnalysisTicketAjaxController::class . '::ticketAction',
        'methods' => ['POST'],
        'inheritAccessFromModule' => 'mindfula11y_accessibility',
    ],
    'mindfula11y_structure_enrich' => [
        'path' => '/mindfula11y/structure/enrich',
        'target' => StructureAnalysisEnrichmentAjaxController::class . '::enrichAction',
        'methods' => ['POST'],
        'inheritAccessFromModule' => 'mindfula11y_accessibility',
    ],
];
