<?php

declare(strict_types=1);

namespace MindfulMarkup\MindfulA11y\Enum;

/**
 * The only three verdicts the AI context review may return for a label.
 * Enforced twice: as the OpenAI JSON schema's enum (model-side) and again
 * here via tryFrom() (application-side) — InteractiveLabelContextReviewService
 * never trusts the model's output without also matching it against this enum.
 */
enum InteractiveLabelAssessment: string
{
    case LIKELY_CLEAR = 'likely_clear';
    case LIKELY_UNCLEAR = 'likely_unclear';
    case UNCERTAIN = 'uncertain';
}
