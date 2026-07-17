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

use MindfulMarkup\MindfulA11y\Middleware\StructureAnalysisAuthenticationMiddleware;
use MindfulMarkup\MindfulA11y\Middleware\StructureAnalysisResponseHardener;
use MindfulMarkup\MindfulA11y\Service\StructureAnalysisTicketService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\VisibilityAspect;
use TYPO3\CMS\Core\Crypto\HashService;
use TYPO3\CMS\Core\Http\ResponseFactory;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\StreamFactory;

final class StructureAnalysisAuthenticationMiddlewareTest extends TestCase
{
    private Context $context;
    private StructureAnalysisAuthenticationMiddleware $subject;

    protected function setUp(): void
    {
        $this->context = new Context();
        $this->subject = new StructureAnalysisAuthenticationMiddleware(
            new StructureAnalysisTicketService(new HashService()),
            $this->context,
            new StructureAnalysisResponseHardener(new ResponseFactory(), new StreamFactory()),
        );
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['SIM_EXEC_TIME'], $GLOBALS['SIM_ACCESS_TIME']);
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

    private function applyPreviewSimulation(ServerRequest $request): void
    {
        $method = new \ReflectionMethod($this->subject, 'applyPreviewSimulation');
        $method->invoke($this->subject, $request);
    }
}
