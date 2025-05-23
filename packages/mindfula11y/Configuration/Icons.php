<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;

return [
    'tx-mindfula11y-module-accessibility' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:mindfula11y/Resources/Public/Icons/AccessibilityModule.svg',
    ],
    'tx-mindfula11y-module-missingalttext' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:mindfula11y/Resources/Public/Icons/MissingAltTextModule.svg',
    ],
    'tx-mindfula11y-module-headingstructure' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:mindfula11y/Resources/Public/Icons/HeadingStructureModule.svg',
    ],
];
