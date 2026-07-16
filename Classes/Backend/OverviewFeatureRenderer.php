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

use Psr\Http\Message\ResponseInterface;

/**
 * Renders the overview feature: the structure analysis plus status cards for
 * missing alternative texts and the accessibility scanner.
 */
final readonly class OverviewFeatureRenderer implements FeatureRendererInterface
{
    public function __construct(
        private OverviewViewStateFactory $viewStateFactory,
    ) {}

    public function render(ModuleContext $context): ResponseInterface
    {
        $context->moduleTemplate->assignMultiple($this->viewStateFactory->build(
            $context->pageId,
            $context->languageId,
            $context->pageInfo,
            $context->localizedPageInfo,
            $context->pageTsConfig,
        ));
        $this->viewStateFactory->registerJavaScriptModules();

        return $context->moduleTemplate->renderResponse('Backend/Overview');
    }
}
