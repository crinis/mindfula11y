<?php

use TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend;

$GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'][1744207980] = [
    'nodeName' => 'altText',
    'priority' => 40,
    'class' => \MindfulMarkup\MindfulA11y\Form\Element\InputAltElement::class,
];


$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['tx_mindfula11y_cache'] ??= [];
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['tx_mindfula11y_cache']['backend'] ??= TransientMemoryBackend::class;