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

namespace MindfulMarkup\MindfulA11y\Service;

use Psr\Http\Message\UriInterface;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Directive;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Mutation;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\MutationCollection;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\MutationMode;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\PolicyRegistry;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\UriValue;

/**
 * Extends the backend Content Security Policy so structure views may frame
 * the frontend preview.
 *
 * Deliberately a separate service: it mutates the request's CSP (a response
 * side effect), which must not hide inside the otherwise read-only settings
 * and permission gates of ModuleSettingsService.
 */
final readonly class StructureAnalysisFramingService
{
    public function __construct(
        private ModuleSettingsService $moduleSettingsService,
        private PolicyRegistry $policyRegistry,
    ) {}

    /**
     * Permit the structure views to frame the frontend preview.
     *
     * The structure analysis renders the preview in iframes, which a backend
     * Content Security Policy blocks by default. Tying the permission to the
     * feature gates keeps it an invariant of rendering the widget rather than a
     * step each module has to remember.
     *
     * @param array<string, mixed> $pageTsConfig
     */
    public function allowFraming(?UriInterface $previewUri, array $pageTsConfig): void
    {
        if (null === $previewUri
            || (!$this->moduleSettingsService->hasHeadingStructureAccess($pageTsConfig)
                && !$this->moduleSettingsService->hasLandmarkStructureAccess($pageTsConfig))
        ) {
            return;
        }

        // Strip the query before building the CSP source: PreviewUriBuilder adds
        // query parameters for access-restricted pages (ADMCMD_simUser/simTime),
        // but a CSP host-source must not contain a query component — browsers
        // ignore the whole source and block the iframe. Matching ignores the
        // query anyway, so scheme/host/port/path keep the source precise.
        $this->policyRegistry->appendMutationCollection(new MutationCollection(
            new Mutation(MutationMode::Extend, Directive::FrameSrc, UriValue::fromUri($previewUri->withQuery('')))
        ));
    }
}
