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

namespace MindfulMarkup\MindfulA11y\Tests\Unit\Service;

use MindfulMarkup\MindfulA11y\Enum\InteractiveLabelAssessment;
use MindfulMarkup\MindfulA11y\Service\InteractiveLabelContextReviewService;
use MindfulMarkup\MindfulA11y\Service\OpenAIService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\Stream;

/**
 * Exercises InteractiveLabelContextReviewService against a real OpenAIService
 * whose HTTP transport is stubbed (RequestFactory mock), the same technique
 * OpenAIServiceTest uses — this avoids mocking OpenAIService itself (final)
 * and, more importantly, proves the two services parse/validate correctly
 * together end to end, not just in isolation.
 *
 * The service must never trust the model's output blindly: an assessment
 * value outside the three-member enum, unparseable JSON, or a non-object
 * response must all yield null, exactly like a transport failure — a caller
 * cannot tell "OpenAI is down" apart from "OpenAI answered something we
 * refuse to trust", and must not need to.
 */
final class InteractiveLabelContextReviewServiceTest extends TestCase
{
    private function responsesApiBody(string $outputText): string
    {
        return json_encode([
            'output' => [
                [
                    'type' => 'message',
                    'content' => [
                        ['type' => 'output_text', 'text' => $outputText],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    private function service(string $rawModelOutput): InteractiveLabelContextReviewService
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->with('mindfula11y')->willReturn(['openAIApiKey' => 'sk-test']);

        $response = new Response(new Stream('php://memory', 'rw'));
        $response->getBody()->write($this->responsesApiBody($rawModelOutput));
        $response->getBody()->rewind();

        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->method('request')->willReturn($response);

        $openAIService = new OpenAIService($extensionConfiguration, $requestFactory, $this->createMock(LoggerInterface::class));

        return new InteractiveLabelContextReviewService($openAIService, $this->createMock(LoggerInterface::class));
    }

    private function assess(InteractiveLabelContextReviewService $service)
    {
        return $service->assess('weiter', 'button', '', 'potentially_vague_interactive_label', 'Test page', '', 'de');
    }

    #[Test]
    public function validResponseIsParsedIntoTheAssessmentDto(): void
    {
        $service = $this->service(json_encode([
            'assessment' => 'likely_unclear',
            'reason' => 'The label does not describe the destination.',
            'suggestedLabel' => 'Informationen zur Bewerbung',
        ], JSON_THROW_ON_ERROR));

        $result = $this->assess($service);

        self::assertNotNull($result);
        self::assertSame(InteractiveLabelAssessment::LIKELY_UNCLEAR, $result->assessment);
        self::assertSame('The label does not describe the destination.', $result->reason);
        self::assertSame('Informationen zur Bewerbung', $result->suggestedLabel);
    }

    #[Test]
    public function nullSuggestedLabelIsPreservedForALikelyClearAssessment(): void
    {
        $service = $this->service(json_encode([
            'assessment' => 'likely_clear',
            'reason' => 'The label matches the visible destination.',
            'suggestedLabel' => null,
        ], JSON_THROW_ON_ERROR));

        $result = $this->assess($service);

        self::assertNotNull($result);
        self::assertSame(InteractiveLabelAssessment::LIKELY_CLEAR, $result->assessment);
        self::assertNull($result->suggestedLabel);
    }

    #[Test]
    public function anAssessmentValueOutsideTheEnumIsRejected(): void
    {
        // Structured Outputs should never let this happen, but the service
        // must not trust that guarantee blindly.
        $service = $this->service(json_encode([
            'assessment' => 'definitely_a_wcag_violation',
            'reason' => 'irrelevant',
            'suggestedLabel' => null,
        ], JSON_THROW_ON_ERROR));

        self::assertNull($this->assess($service));
    }

    #[Test]
    public function unparseableJsonIsRejected(): void
    {
        $service = $this->service('not json at all');

        self::assertNull($this->assess($service));
    }

    #[Test]
    public function emptySuggestedLabelStringIsNormalizedToNull(): void
    {
        $service = $this->service(json_encode([
            'assessment' => 'uncertain',
            'reason' => 'Not enough context.',
            'suggestedLabel' => '   ',
        ], JSON_THROW_ON_ERROR));

        $result = $this->assess($service);

        self::assertNotNull($result);
        self::assertNull($result->suggestedLabel);
    }
}
