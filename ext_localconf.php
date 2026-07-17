<?php
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

use MindfulMarkup\MindfulA11y\Form\FieldControl\GenerateAltTextControl;
use MindfulMarkup\MindfulA11y\Form\ValidationErrorDetector;
use MindfulMarkup\MindfulA11y\Hooks\DecorativeFileReferenceDataHandlerGuard;
use MindfulMarkup\MindfulA11y\Hooks\ScanStateDataHandlerGuard;
use MindfulMarkup\MindfulA11y\Service\StructureAnalysisTicketService;

defined('TYPO3') or die();

(static function (): void {
    $cacheHashExclusions = &$GLOBALS['TYPO3_CONF_VARS']['FE']['cacheHash']['excludedParameters'];
    if (!in_array(StructureAnalysisTicketService::TICKET_QUERY_PARAMETER, $cacheHashExclusions, true)) {
        $cacheHashExclusions[] = StructureAnalysisTicketService::TICKET_QUERY_PARAMETER;
    }

    $GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'][1744207980] = [
        'nodeName' => 'mindfula11yGenerateAltText',
        'priority' => 40,
        'class' => GenerateAltTextControl::class,
    ];

    $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][ScanStateDataHandlerGuard::class]
        = ScanStateDataHandlerGuard::class;
    $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][DecorativeFileReferenceDataHandlerGuard::class]
        = DecorativeFileReferenceDataHandlerGuard::class;

    // TYPO3 13 EXT:form hook. TYPO3 14 provides the equivalent PSR-14 event,
    // registered in Services.yaml. Guarding both classes keeps EXT:form optional.
    if (class_exists(\TYPO3\CMS\Form\Domain\Runtime\FormRuntime::class)
        && !class_exists(\TYPO3\CMS\Form\Event\BeforeRenderableIsRenderedEvent::class)
    ) {
        $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['ext/form']['beforeRendering'][ValidationErrorDetector::class]
            = ValidationErrorDetector::class;
    }
})();
