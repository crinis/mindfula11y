<?php

declare(strict_types=1);

namespace MindfulMarkup\MindfulA11y\Service;

use MindfulMarkup\MindfulA11y\Domain\Model\InteractiveLabelContextAssessment;
use MindfulMarkup\MindfulA11y\Enum\InteractiveLabelAssessment;
use Psr\Log\LoggerInterface;

/**
 * Optional AI-assisted second opinion on an already rule-flagged interactive
 * label. Reuses the shared OpenAIService exactly like AltTextGeneratorService
 * does — no new HTTP client, no MindfulAPI involvement.
 *
 * This never runs the rule-based check itself (InteractiveLabelChecker is
 * untouched) and never produces a WCAG conformance verdict — only an advisory
 * assessment the editor reviews before deciding anything. A caller must
 * therefore always present the result as an AI opinion, not as a finding.
 */
final readonly class InteractiveLabelContextReviewService
{
    private const RESPONSE_SCHEMA_NAME = 'interactive_label_context_assessment';

    public function __construct(
        private OpenAIService $openAIService,
        private LoggerInterface $logger,
    ) {}

    /**
     * Assess whether a label is likely understandable given its context, and
     * suggest a better one when it probably is not.
     *
     * Returns null on every failure mode (OpenAI unreachable, malformed JSON,
     * an assessment value outside the enum) — callers treat null exactly like
     * "no AI opinion available", never as a false likely_clear.
     */
    public function assess(
        string $label,
        string $elementType,
        string $target,
        string $rule,
        string $pageTitle,
        string $surroundingContext,
        string $languageCode = 'en',
    ): ?InteractiveLabelContextAssessment {
        $response = $this->openAIService->respond(
            $this->buildInstructions($languageCode),
            [
                [
                    'role' => 'user',
                    'content' => $this->buildContextMessage(
                        $label,
                        $elementType,
                        $target,
                        $rule,
                        $pageTitle,
                        $surroundingContext,
                    ),
                ],
            ],
            [
                'name' => self::RESPONSE_SCHEMA_NAME,
                'schema' => $this->responseSchema(),
            ],
        );

        if ($response === null) {
            // OpenAIService already logged the transport/API failure.
            return null;
        }

        return $this->parseAssessment($response);
    }

    private function buildInstructions(string $languageCode): string
    {
        return 'You are an accessibility specialist giving a second opinion on ONE interactive label '
            . '(a link or button) that a rule-based check has already flagged as potentially vague. '
            . 'Respond in the language identified by this ISO language code: ' . $languageCode . '. '
            . 'Judge only whether the label is likely understandable OUT OF CONTEXT for a screen-reader '
            . 'user, given the surrounding context provided. You are giving an opinion for a human editor '
            . 'to review, never a final accessibility or WCAG conformance decision — do not phrase your '
            . 'reason as a compliance verdict. If you suggest a better label, keep it concise and '
            . 'consistent with the site\'s tone; do not invent a destination or action that was not given '
            . 'to you.';
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function buildContextMessage(
        string $label,
        string $elementType,
        string $target,
        string $rule,
        string $pageTitle,
        string $surroundingContext,
    ): array {
        $lines = [
            'Current label: ' . $label,
            'Element type: ' . $elementType,
            'Target/action: ' . ($target !== '' ? $target : '(none given)'),
            'Rule that flagged this label: ' . $rule,
            'Page title: ' . $pageTitle,
            'Available surrounding context: ' . ($surroundingContext !== '' ? $surroundingContext : '(none given)'),
        ];

        return [
            [
                'type' => 'input_text',
                'text' => implode("\n", $lines),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function responseSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'assessment' => [
                    'type' => 'string',
                    'enum' => array_map(
                        static fn (InteractiveLabelAssessment $case): string => $case->value,
                        InteractiveLabelAssessment::cases(),
                    ),
                ],
                'reason' => ['type' => 'string'],
                'suggestedLabel' => ['type' => ['string', 'null']],
            ],
            'required' => ['assessment', 'reason', 'suggestedLabel'],
            'additionalProperties' => false,
        ];
    }

    /**
     * Defense in depth: even though the request used Structured Outputs
     * (`strict: true`), the assessment value is matched against the enum
     * again here rather than trusted blindly — the same discipline
     * InteractiveLabelRuleProvider applies to its own data.
     */
    private function parseAssessment(string $response): ?InteractiveLabelContextAssessment
    {
        try {
            $decoded = json_decode($response, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $this->logger->warning(
                'AI context review returned unparseable JSON.',
                ['exception' => $exception->getMessage()],
            );

            return null;
        }

        if (!is_array($decoded)) {
            $this->logger->warning('AI context review returned a non-object response.');

            return null;
        }

        $assessment = InteractiveLabelAssessment::tryFrom(
            (string)($decoded['assessment'] ?? ''),
        );

        if ($assessment === null) {
            $this->logger->warning(
                'AI context review returned an assessment value outside the allowed set.',
                ['value' => $decoded['assessment'] ?? null],
            );

            return null;
        }

        $reason = trim((string)($decoded['reason'] ?? ''));
        $suggestedLabel = $decoded['suggestedLabel'] ?? null;
        $suggestedLabel = is_string($suggestedLabel) ? trim($suggestedLabel) : null;

        return new InteractiveLabelContextAssessment(
            $assessment,
            $reason,
            $suggestedLabel === '' ? null : $suggestedLabel,
        );
    }
}
