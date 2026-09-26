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

namespace MindfulMarkup\MindfulA11y\Controller;

use MindfulMarkup\MindfulA11y\Service\ModuleLabelService;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * The JSON wire format of the extension's AJAX endpoints: uniform localized
 * error bodies, and decoding the request body they answer.
 *
 * Every error is `{"error": {"title": …, "description": …}}` with both texts
 * localized for the requesting backend user — the shape the frontend's
 * `toRequestError()` recognizes and `errorView()` renders verbatim.
 */
trait JsonErrorResponseTrait
{
    /**
     * Decode a JSON request body, treating anything but a JSON object/array
     * (invalid JSON, scalars) as an empty body.
     *
     * @return array<string, mixed>
     */
    private function parseJsonBody(ServerRequestInterface $request): array
    {
        $body = json_decode((string)$request->getBody(), true);

        return is_array($body) ? $body : [];
    }

    /**
     * Builds the uniform localized JSON error response.
     *
     * The title is resolved from the given label key, the description from
     * `<labelKey>.description` — unless an explicit description (e.g. an
     * upstream API's problem detail) overrides it.
     */
    protected function errorResponse(string $labelKey, int $statusCode, ?string $description = null): JsonResponse
    {
        $languageService = $this->getLanguageService();
        return new JsonResponse([
            'error' => [
                'title' => $languageService->sL(ModuleLabelService::LANGUAGE_FILE . $labelKey),
                'description' => $description ?? $languageService->sL(ModuleLabelService::LANGUAGE_FILE . $labelKey . '.description'),
            ],
        ], $statusCode);
    }

    /** Provided by the backend request stack for every (AJAX) route. */
    protected function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
