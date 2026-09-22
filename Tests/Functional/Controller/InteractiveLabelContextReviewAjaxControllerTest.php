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

namespace MindfulMarkup\MindfulA11y\Tests\Functional\Controller;

use MindfulMarkup\MindfulA11y\Controller\InteractiveLabelContextReviewAjaxController;
use MindfulMarkup\MindfulA11y\Tests\Functional\AbstractAuthorizationTestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Authorization coverage of InteractiveLabelContextReviewAjaxController::assessAction().
 *
 * The OpenAI API key is deliberately unconfigured in the functional test
 * instance, exactly like AltTextAjaxControllerTest — so page 600 (TSconfig
 * mod.mindfula11y_accessibility.interactiveLabels.aiReview.enable = 1, see
 * InteractiveLabelAiReviewSupplement.csv) exercises "allowed by every gate,
 * fails only because no key is configured" (500, .notConfigured), which is
 * this suite's positive discriminator for "everything upstream of OpenAI
 * passed" — never a bare "not 4xx".
 *
 * Uses the shared AuthorizationScenario.csv fixture (user 2 full editor,
 * user 3 no module access), plus InteractiveLabelAiReviewSupplement.csv
 * (page 600 enables aiReview, page 601 explicitly disables it — page 601
 * exists because the extension's own shipped default for aiReview.enable
 * is not assumed to be off; a page must say so explicitly to test the
 * "not allowed" gate).
 */
final class InteractiveLabelContextReviewAjaxControllerTest extends AbstractAuthorizationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/InteractiveLabelAiReviewSupplement.csv');
    }

    private function controller(): InteractiveLabelContextReviewAjaxController
    {
        return $this->get(InteractiveLabelContextReviewAjaxController::class);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function payload(int $pageId, array $overrides = []): array
    {
        return [
            'pageId' => $pageId,
            'label' => 'weiter',
            'elementType' => 'button',
            'target' => '',
            'rule' => 'potentially_vague_interactive_label',
            'pageTitle' => 'Test page',
            'surroundingContext' => '',
            'locale' => 'de',
            ...$overrides,
        ];
    }

    private function assess(array $payload): ResponseInterface
    {
        return $this->controller()->assessAction($this->createJsonRequest($payload));
    }

    public function testModuleGateDeniesUserWithoutModuleAccess(): void
    {
        // User 3 (editor_no_module): group carries no groupMods entry.
        $this->logInBackendUser(3);

        $response = $this->assess($this->payload(600));

        $this->assertErrorResponse($response, 403, 'error.forbidden');
    }

    public function testMissingPageIdReturnsInvalidRequest(): void
    {
        $this->logInBackendUser(2);

        $response = $this->assess($this->payload(600, ['pageId' => 0]));

        $this->assertErrorResponse($response, 400, 'error.invalidRequest');
    }

    public function testEmptyLabelReturnsInvalidRequest(): void
    {
        $this->logInBackendUser(2);

        $response = $this->assess($this->payload(600, ['label' => '  ']));

        $this->assertErrorResponse($response, 400, 'error.invalidRequest');
    }

    public function testAiReviewNotEnabledOnPageIsRejected(): void
    {
        // Page 601 explicitly sets aiReview.enable = 0 — deliberately not
        // relying on the extension's shipped default for "off", since that
        // default is a deploy-time setting, not something this test controls.
        $this->logInBackendUser(2);

        $response = $this->assess($this->payload(601));

        $this->assertErrorResponse($response, 403, 'interactiveLabels.aiReview.error.notAllowed');
    }

    public function testAiReviewEnabledButOpenAiUnconfiguredFailsAtGeneration(): void
    {
        // Page 600 enables aiReview via TSconfig — every gate before OpenAI
        // passes, so reaching .notConfigured (not .notAllowed/403/400) is the
        // proof this request cleared module access, request validation, and
        // the TSconfig gate.
        $this->logInBackendUser(2);

        $response = $this->assess($this->payload(600));

        $this->assertErrorResponse($response, 500, 'interactiveLabels.aiReview.error.notConfigured');
    }
}
