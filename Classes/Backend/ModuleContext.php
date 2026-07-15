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

namespace MindfulMarkup\MindfulA11y\Backend;

use MindfulMarkup\MindfulA11y\Enum\Feature;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Module\ModuleData;
use TYPO3\CMS\Backend\Template\ModuleTemplate;

/**
 * The resolved, access-checked state of one accessibility-module request.
 *
 * Assembled once by AccessibilityModuleController::mainAction() and handed to
 * the feature renderers, so per-request state lives in exactly one place
 * instead of controller properties.
 */
final readonly class ModuleContext
{
    /**
     * @param array<string, mixed> $pageInfo Access-checked default-language page record.
     * @param array<string, mixed>|null $localizedPageInfo Localized page record when the page is translated.
     * @param array<string, mixed> $pageTsConfig Converted Page TSconfig for the page.
     */
    public function __construct(
        public ServerRequestInterface $request,
        public ModuleTemplate $moduleTemplate,
        public ModuleData $moduleData,
        public Feature $feature,
        public int $pageId,
        public int $languageId,
        public array $pageInfo,
        public ?array $localizedPageInfo,
        public array $pageTsConfig,
    ) {}

    /**
     * The record previews are built from: the localized page record when the
     * translation exists, the default-language record otherwise.
     *
     * @return array<string, mixed>
     */
    public function getPreviewPageInfo(): array
    {
        return $this->localizedPageInfo ?: $this->pageInfo;
    }
}
