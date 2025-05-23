<?php

defined('TYPO3') or die();

$disableAltTextAI = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\Configuration\ExtensionConfiguration::class)->get('mindfula11y', 'disableAltTextAI');
$apiKey = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\Configuration\ExtensionConfiguration::class)->get('mindfula11y', 'openAIApiKey');

if (!$disableAltTextAI && !empty($apiKey)) {
    $GLOBALS['TCA']['sys_file_reference']['columns']['alternative']['config'] = [
        'type' => 'user',
        'renderType' => 'altText',
    ];
}

$GLOBALS['TCA']['sys_file_reference']['ctrl']['hideTable'] = false;