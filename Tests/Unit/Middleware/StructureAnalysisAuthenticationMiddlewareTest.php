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

namespace MindfulMarkup\MindfulA11y\Tests\Unit\Middleware;

use MindfulMarkup\MindfulA11y\Domain\Model\StructureAnalysisTicket;
use MindfulMarkup\MindfulA11y\Middleware\StructureAnalysisAuthenticationMiddleware;
use MindfulMarkup\MindfulA11y\Middleware\StructureAnalysisResponseHardener;
use MindfulMarkup\MindfulA11y\Service\StructureAnalysisTicketService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\VisibilityAspect;
use TYPO3\CMS\Core\Crypto\HashService;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ResponseFactory;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\StreamFactory;
use TYPO3\CMS\Core\Http\Uri;

final class StructureAnalysisAuthenticationMiddlewareTest extends TestCase
{
    private Context $context;
    private StructureAnalysisAuthenticationMiddleware $subject;

    protected function setUp(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = str_repeat('a1b2c3d4', 12);
        $this->context = new Context();
        $this->subject = new StructureAnalysisAuthenticationMiddleware(
            new StructureAnalysisTicketService(new HashService()),
            $this->context,
            new StructureAnalysisResponseHardener(new ResponseFactory(), new StreamFactory()),
        );
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['SIM_EXEC_TIME'], $GLOBALS['SIM_ACCESS_TIME'], $GLOBALS['TYPO3_CONF_VARS']);
    }

    #[Test]
    public function previewUsesNativeFrontendRecordVisibility(): void
    {
        $this->applyPreviewSimulation(new ServerRequest('https://frontend.example/page'));

        $visibility = $this->context->getAspect('visibility');
        self::assertInstanceOf(VisibilityAspect::class, $visibility);
        self::assertTrue($visibility->includeHiddenPages());
        self::assertFalse($visibility->includeHiddenContent());
        self::assertFalse($visibility->includeDeletedRecords());
        self::assertFalse($visibility->includeScheduledRecords());
    }

    #[Test]
    public function simulatedTimeChangesEvaluationDateWithoutDisablingSchedulingRestrictions(): void
    {
        $timestamp = 1_800_000_000;
        $request = (new ServerRequest('https://frontend.example/page'))
            ->withQueryParams(['ADMCMD_simTime' => (string)$timestamp]);

        $this->applyPreviewSimulation($request);

        $visibility = $this->context->getAspect('visibility');
        self::assertInstanceOf(VisibilityAspect::class, $visibility);
        self::assertFalse($visibility->includeScheduledRecords());
        self::assertSame($timestamp, $this->context->getPropertyFromAspect('date', 'timestamp'));
    }

    /**
     * A validly signed ticket can arrive on a request whose host the ticket
     * service refuses to normalize (an underscore is not a valid hostname).
     * That is "no ticket for this request": the page renders publicly
     * instead of the request failing with an exception.
     */
    #[Test]
    public function ticketOnRequestWithInvalidHostRendersWithoutTicket(): void
    {
        $ticketService = new StructureAnalysisTicketService(new HashService());
        $issued = $ticketService->issueAnalysisUrl(
            'https://frontend.example/page',
            42,
            0,
            0,
            str_repeat('a', 64),
            3,
            'https://backend.example',
        );
        parse_str((string)(new Uri($issued['url']))->getQuery(), $query);
        // Core's Uri constructor rejects such a host, but withHost() does not
        // validate — any PSR-7 request can carry it by the time it arrives.
        $request = new ServerRequest('https://frontend.example/page?' . http_build_query($query));
        $request = $request
            ->withUri($request->getUri()->withHost('front_end.example'))
            ->withQueryParams($query);
        $handler = new class implements RequestHandlerInterface {
            public ?ServerRequestInterface $handledRequest = null;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->handledRequest = $request;
                return new Response();
            }
        };

        $response = $this->subject->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertNotNull($handler->handledRequest);
        self::assertNull($handler->handledRequest->getAttribute(StructureAnalysisTicket::REQUEST_ATTRIBUTE));
        self::assertFalse($this->context->hasAspect('frontend.preview'));
    }

    private function applyPreviewSimulation(ServerRequest $request): void
    {
        $method = new \ReflectionMethod($this->subject, 'applyPreviewSimulation');
        $method->invoke($this->subject, $request);
    }
}
