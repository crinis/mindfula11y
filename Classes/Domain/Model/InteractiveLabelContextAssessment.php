<?php

declare(strict_types=1);

namespace MindfulMarkup\MindfulA11y\Domain\Model;

use MindfulMarkup\MindfulA11y\Enum\InteractiveLabelAssessment;

/**
 * The AI context review's verdict for one interactive-label finding.
 *
 * Never a WCAG conformance decision — advisory only, always presented to the
 * editor as an AI opinion (see InteractiveLabelContextReviewService and the
 * `mindfula11y-interactive-label-finding` element).
 */
final readonly class InteractiveLabelContextAssessment
{
    public function __construct(
        public InteractiveLabelAssessment $assessment,
        public string $reason,
        public ?string $suggestedLabel,
    ) {}
}
