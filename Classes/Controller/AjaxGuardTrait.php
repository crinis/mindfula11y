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

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Shared request plumbing for the extension's AJAX controllers: JSON body
 * parsing, the module-access gate, and pinning a signed demand to the current
 * session.
 *
 * Consumers also use JsonErrorResponseTrait. The module gate additionally
 * requires an injected PermissionService ($permissionService), the demand
 * pinning a BackendUserProvider ($backendUserProvider) — controllers that
 * only parse bodies need neither.
 */
trait AjaxGuardTrait
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
     * Returns a 403 response if the current backend user lacks module access, null otherwise.
     *
     * Module access is the defense-in-depth gate behind the endpoints (the
     * ticket endpoint enforces it inside
     * StructureAnalysisAuthorizationService::authorizePage() instead).
     */
    private function requireModuleAccess(): ?ResponseInterface
    {
        if ($this->permissionService->checkModuleAccess()) {
            return null;
        }
        return $this->errorResponse('error.forbidden', 403);
    }

    /**
     * Verify a signed demand belongs to the current session: same user, same
     * workspace, and access to the demanded language.
     *
     * The redemption-side counterpart of the "signed => authorized at
     * issuance" invariant — a demand is only redeemable in the session scope
     * it was signed for, so the pinning rules must not drift between the
     * demand-redeeming endpoints.
     */
    private function requireDemandSession(int $userId, int $workspaceId, int $languageId): ?ResponseInterface
    {
        $backendUser = $this->backendUserProvider->get();

        if ((int)($backendUser->user['uid'] ?? 0) !== $userId) {
            return $this->errorResponse('error.invalidUser', 403);
        }

        if ($backendUser->workspace !== $workspaceId) {
            return $this->errorResponse('error.invalidWorkspace', 403);
        }

        if (!$this->permissionService->checkLanguageAccess($languageId)) {
            return $this->errorResponse('error.invalidLanguage', 403);
        }

        return null;
    }
}
