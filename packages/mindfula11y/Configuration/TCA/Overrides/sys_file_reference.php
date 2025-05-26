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

use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

defined('TYPO3') or die();

$disableAltTextAI = GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('mindfula11y', 'disableAltTextAI');
$apiKey = GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('mindfula11y', 'openAIApiKey');

if (!$disableAltTextAI && !empty($apiKey)) {
    $GLOBALS['TCA']['sys_file_reference']['columns']['alternative']['config']['type'] = 'user';
    $GLOBALS['TCA']['sys_file_reference']['columns']['alternative']['config']['renderType'] = 'mindfula11yAltText';
}
