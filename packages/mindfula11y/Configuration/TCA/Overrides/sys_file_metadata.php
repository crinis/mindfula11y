<?php

defined('TYPO3') or die();

$disableAltTextAI = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\Configuration\ExtensionConfiguration::class)->get('mindfula11y', 'disableAltTextAI');
$apiKey = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\Configuration\ExtensionConfiguration::class)->get('mindfula11y', 'openAIApiKey');

if (!$disableAltTextAI && !empty($apiKey)) {
    $GLOBALS['TCA']['sys_file_metadata']['columns']['alternative']['config'] = [
        'type' => 'user',
        'renderType' => 'altText',
    ];
}
