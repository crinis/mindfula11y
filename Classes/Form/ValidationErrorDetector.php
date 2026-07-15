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

namespace MindfulMarkup\MindfulA11y\Form;

use MindfulMarkup\MindfulA11y\Service\ValidationErrorTitleConfiguration;
use MindfulMarkup\MindfulA11y\Service\ValidationErrorState;
use TYPO3\CMS\Extbase\Mvc\ExtbaseRequestParameters;

/**
 * Detects EXT:form validation errors without making EXT:form a dependency.
 *
 * TYPO3 13 calls beforeRendering(); TYPO3 14 dispatches the equivalent
 * BeforeRenderableIsRenderedEvent to __invoke(). Both lifecycle points run
 * after the form runtime has populated its request mapping result.
 */
final readonly class ValidationErrorDetector
{
    public function __construct(
        private ValidationErrorTitleConfiguration $configuration,
        private ValidationErrorState $validationErrorState,
    ) {}

    /**
     * TYPO3 14 EXT:form event listener.
     */
    public function __invoke(object $event): void
    {
        $formRuntime = $event->formRuntime ?? null;
        if (is_object($formRuntime)) {
            $this->detect($formRuntime);
        }
    }

    /**
     * TYPO3 13 EXT:form beforeRendering hook.
     */
    public function beforeRendering(object $formRuntime, object $renderable): void
    {
        $this->detect($formRuntime);
    }

    private function detect(object $formRuntime): void
    {
        // Short-circuit on the in-memory flag before reading extension
        // configuration, matching ValidationErrorTitleMiddleware's ordering.
        if ($this->validationErrorState->hasErrors() || !$this->configuration->isEnabled()) {
            return;
        }
        // $formRuntime is typed `object` because EXT:form is optional; guard the
        // one entry point, then rely on core's non-nullable return types.
        if (!method_exists($formRuntime, 'getRequest')) {
            return;
        }

        $extbaseParameters = $formRuntime->getRequest()->getAttribute('extbase');
        if ($extbaseParameters instanceof ExtbaseRequestParameters
            && $extbaseParameters->getOriginalRequestMappingResults()->hasErrors()
        ) {
            $this->validationErrorState->markAsInvalid();
        }
    }
}
