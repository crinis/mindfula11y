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

namespace MindfulMarkup\MindfulA11y\Tests\Functional\Middleware;

use MindfulMarkup\MindfulA11y\Controller\StructureAnalysisTicketAjaxController;
use MindfulMarkup\MindfulA11y\Tests\Functional\AbstractAuthorizationTestCase;
use Psr\Http\Message\ResponseInterface;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;

/**
 * End-to-end coverage of the frontend structure-analysis middleware chain:
 * a real ticket issued through ticketAction() is redeemed by a session-less
 * frontend sub-request, running the full stack (authentication middleware →
 * page resolution → response middleware → hardener).
 *
 * The e2e markers: a redeemed ticket yields the inline analysis runner
 * (script#mindfula11y-structure-analysis-runner) plus the hardened
 * CSP/no-store headers; every non-redeemed request must yield the plain
 * public document without any of them. The crypto details (per-field tamper,
 * expiry windows, claim shapes) are pinned in the unit suites — expiry is
 * deliberately NOT re-tested here because a real 15s ticket cannot age
 * inside a test without clock control.
 */
final class StructureAnalysisMiddlewareChainTest extends AbstractAuthorizationTestCase
{
    private const RUNNER_MARKER = 'id="mindfula11y-structure-analysis-runner"';
    private const BACKEND_ORIGIN = 'https://typo3-testing.local';

    protected function setUp(): void
    {
        parent::setUp();
        $this->writeDefaultSiteConfiguration();
        // EXT: path (not __DIR__): the sys_template row created here imports
        // the file inside the test instance, where the extension is linked.
        $this->setUpFrontendRootPage(1, ['setup' => ['EXT:mindfula11y/Tests/Functional/Fixtures/frontend.typoscript']]);
    }

    /**
     * Issue a real analysis URL for the full editor (user 2) through the
     * ticket controller, then drop the backend session: redemption in the
     * browser iframe is a session-less fetch and must work (only) through
     * the signed ticket.
     */
    private function issueAnalysisUrl(int $pageId, int $languageId = 0): string
    {
        $this->logInBackendUser(2);
        $response = $this->get(StructureAnalysisTicketAjaxController::class)->ticketAction(
            $this->createJsonRequest(['pageId' => $pageId, 'languageId' => $languageId])
        );
        self::assertSame(200, $response->getStatusCode(), 'ticket issuance must succeed');
        $url = $this->decodeJsonResponse($response)['url'] ?? null;
        self::assertIsString($url);
        self::assertNotSame('', $url);
        unset($GLOBALS['BE_USER'], $GLOBALS['TYPO3_REQUEST']);

        return $url;
    }

    private function assertNotAnAnalysisDocument(ResponseInterface $response): void
    {
        $body = (string)$response->getBody();
        self::assertStringNotContainsString(self::RUNNER_MARKER, $body, 'no analysis runner may be injected');
        self::assertStringNotContainsString(
            'frame-ancestors ' . self::BACKEND_ORIGIN,
            $response->getHeaderLine('Content-Security-Policy'),
            'the hardened analysis CSP must not be applied'
        );
    }

    public function testPlainRequestRendersPublicPageWithoutAnalysisArtifacts(): void
    {
        $response = $this->executeFrontendSubRequest(new InternalRequest('https://example.com/editable'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Analysis target', (string)$response->getBody());
        $this->assertNotAnAnalysisDocument($response);
    }

    public function testValidTicketYieldsHardenedAnalysisDocument(): void
    {
        $url = $this->issueAnalysisUrl(10);

        $response = $this->executeFrontendSubRequest(new InternalRequest($url));

        self::assertSame(200, $response->getStatusCode());
        $body = (string)$response->getBody();
        self::assertStringContainsString('Analysis target', $body, 'the authorized page body must survive rewriting');
        self::assertStringContainsString(self::RUNNER_MARKER, $body);
        self::assertStringContainsString('data-backend-origin="' . self::BACKEND_ORIGIN . '"', $body);
        $csp = $response->getHeaderLine('Content-Security-Policy');
        self::assertStringContainsString('frame-ancestors ' . self::BACKEND_ORIGIN, $csp);
        self::assertStringContainsString("script-src 'nonce-", $csp);
        self::assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testTamperedTicketFallsBackToPublicRendering(): void
    {
        $url = $this->issueAnalysisUrl(10);
        // Flip the token's last character: the HMAC no longer matches, so the
        // authentication middleware must treat the request as ticketless.
        $tamperedUrl = substr($url, 0, -1) . (str_ends_with($url, 'A') ? 'B' : 'A');

        $response = $this->executeFrontendSubRequest(new InternalRequest($tamperedUrl));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Analysis target', (string)$response->getBody());
        $this->assertNotAnAnalysisDocument($response);
    }

    /**
     * The ticket signs one exact target. Redeeming it on another (public)
     * page must not grant the analysis document there — the target pin in
     * the authentication middleware demotes the request to a plain render.
     */
    public function testTicketIsNotRedeemableOnADifferentTarget(): void
    {
        $url = $this->issueAnalysisUrl(10);
        $otherPageUrl = str_replace('/editable', '/no-translation', $url);
        self::assertNotSame($url, $otherPageUrl);

        $response = $this->executeFrontendSubRequest(new InternalRequest($otherPageUrl));

        $this->assertNotAnAnalysisDocument($response);
    }

    /**
     * Preview visibility e2e: page 15 is hidden — a plain request 404s, a
     * ticketed request renders it (VisibilityAspect includeHiddenPages) as a
     * hardened analysis document. Hidden CONTENT stays excluded; that
     * boundary is pinned in the middleware unit tests.
     */
    public function testHiddenPageIsPreviewableOnlyThroughATicket(): void
    {
        $plainResponse = $this->executeFrontendSubRequest(new InternalRequest('https://example.com/hidden'));
        self::assertSame(404, $plainResponse->getStatusCode(), 'hidden page must not render publicly');

        $url = $this->issueAnalysisUrl(15);
        $ticketedResponse = $this->executeFrontendSubRequest(new InternalRequest($url));

        self::assertSame(200, $ticketedResponse->getStatusCode());
        self::assertStringContainsString(self::RUNNER_MARKER, (string)$ticketedResponse->getBody());
        self::assertStringContainsString(
            'frame-ancestors ' . self::BACKEND_ORIGIN,
            $ticketedResponse->getHeaderLine('Content-Security-Policy')
        );
    }
}
