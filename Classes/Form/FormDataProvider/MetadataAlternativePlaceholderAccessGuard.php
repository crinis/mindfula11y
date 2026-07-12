<?php

declare(strict_types=1);

/*
 * Mindful A11y extension for TYPO3 integrating accessibility tools into the backend.
 * Copyright (C) 2026  Mindful Markup, Felix Spittel
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 */

namespace MindfulMarkup\MindfulA11y\Form\FormDataProvider;

use MindfulMarkup\MindfulA11y\Service\GeneralModuleService;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Backend\Form\FormDataProviderInterface;

#[Autoconfigure(public: true)]
final readonly class MetadataAlternativePlaceholderAccessGuard implements FormDataProviderInterface
{
    public function __construct(
        private GeneralModuleService $generalModuleService,
    ) {}

    public function addData(array $result): array
    {
        if (($result['tableName'] ?? '') !== 'sys_file_reference'
            || $this->generalModuleService->canReadFileMetadataAlternative()
        ) {
            return $result;
        }

        unset($result['processedTca']['columns']['alternative']['config']['placeholder']);
        return $result;
    }
}
