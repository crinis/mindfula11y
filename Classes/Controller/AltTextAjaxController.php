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

namespace MindfulMarkup\MindfulA11y\Controller;

use MindfulMarkup\MindfulA11y\Domain\Model\GenerateAltTextDemand;
use MindfulMarkup\MindfulA11y\Enum\AltTextDemandAuthorizationFailure;
use MindfulMarkup\MindfulA11y\Service\AltTextDemandAuthorizationService;
use MindfulMarkup\MindfulA11y\Service\AltTextGeneratorService;
use MindfulMarkup\MindfulA11y\Service\BackendUserProvider;
use MindfulMarkup\MindfulA11y\Service\DemandSignatureService;
use MindfulMarkup\MindfulA11y\Service\OpenAIService;
use MindfulMarkup\MindfulA11y\Service\PermissionService;
use MindfulMarkup\MindfulA11y\Service\SiteLanguageService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * Handles the AJAX endpoint generating alternative text for images.
 *
 * Uses the OpenAI API to generate the alternative text based on the image
 * content. The allowed HTTP method is enforced on the route definition
 * (Configuration/Backend/AjaxRoutes.php).
 */
final readonly class AltTextAjaxController
{
    use JsonErrorResponseTrait;
    use ModuleAccessGuardTrait;
    use DemandSessionGuardTrait;

    public function __construct(
        private AltTextGeneratorService $altTextGeneratorService,
        private DemandSignatureService $demandSignatureService,
        private AltTextDemandAuthorizationService $authorizationService,
        private SiteLanguageService $siteLanguageService,
        private PermissionService $permissionService,
        private BackendUserProvider $backendUserProvider,
        private OpenAIService $openAIService,
    ) {}

    /**
     * Generate alternative text for an image.
     *
     * This action handles the AJAX request to generate alternative text for a given image and
     * returns the generated text as a JSON response.
     */
    public function generateAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($error = $this->requireModuleAccess()) {
            return $error;
        }

        // Re-check the integration at redemption: a demand issued while
        // generation was available must not outlive an administrator disabling
        // it — by the switch or by removing the API key, which would otherwise
        // still upload the image to OpenAI (every other mutable gate is
        // re-checked at redemption, too).
        if (!$this->openAIService->isEnabledAndConfigured()) {
            return $this->errorResponse('altText.generate.error.disabled', 403);
        }

        $demand = GenerateAltTextDemand::fromRequestData($this->parseJsonBody($request));
        if ($demand === null) {
            return $this->errorResponse('error.invalidRequest', 400);
        }

        if (!$this->demandSignatureService->isValid($demand)) {
            return $this->errorResponse('module.error.invalidSignature', 400);
        }

        if ($error = $this->requireDemandSession($demand->getUserId(), $demand->getWorkspaceId(), $demand->getLanguageUid())) {
            return $error;
        }

        $authorization = $this->authorizationService->authorize($demand);
        if ($authorization instanceof AltTextDemandAuthorizationFailure) {
            return match ($authorization) {
                AltTextDemandAuthorizationFailure::NO_PAGE_ACCESS => $this->errorResponse('error.noPageAccess', 403),
                AltTextDemandAuthorizationFailure::NO_FILE_ACCESS => $this->errorResponse('error.noFileAccess', 403),
                AltTextDemandAuthorizationFailure::INVALID_SNAPSHOT => $this->errorResponse('error.invalidRecordAccess', 403),
                AltTextDemandAuthorizationFailure::FILE_NOT_FOUND => $this->errorResponse('error.fileNotFound', 404),
                AltTextDemandAuthorizationFailure::NO_FILE_MOUNT_ACCESS => $this->errorResponse('error.noFileMountAccess', 403),
            };
        }

        try {
            $languageCode = $this->siteLanguageService->getLanguageCode($demand->getLanguageUid(), $demand->getPageUid());
        } catch (\Exception) {
            $languageCode = 'en';
        }

        $generated = $this->altTextGeneratorService->generate($authorization, $languageCode);

        if (null === $generated) {
            return $this->errorResponse('altText.generate.error.openAIConnection', 500);
        }

        // The decorative verdict carries no text on purpose: the clients tell
        // the editor instead of filling the field, and leave marking the
        // reference decorative to the editor.
        if ($generated->decorative) {
            return new JsonResponse(['decorative' => true], 201);
        }

        return new JsonResponse(['altText' => $generated->altText], 201);
    }

}
