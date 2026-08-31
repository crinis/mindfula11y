<?php

declare(strict_types=1);

namespace MindfulMarkup\MindfulA11y\Service;

use MindfulMarkup\MindfulA11y\Enum\InteractiveLabelType;

final readonly class InteractiveLabelChecker
{
    /**
     * Symbol-only labels are not language-specific (an arrow or "+" reads the
     * same in every locale), so this rule lives here instead of in the
     * per-locale term lists. A value matches when it contains no letters or
     * digits in any script.
     */
    private const SYMBOL_ONLY_RULE = 'symbol_only_label';
    private const SYMBOL_ONLY_WCAG = '2.4.6';
    private const SYMBOL_ONLY_LEVEL = 'AA';

    /**
     * @param array<int, array<string, mixed>> $rules
     *
     * @return array{
     *     type: InteractiveLabelType,
     *     value: string,
     *     rule: string,
     *     category: string,
     *     severity: string,
     *     wcagCriterion: string,
     *     wcagLevel: string,
     *     needsContextReview: bool
     * }|null
     */
    public function check(
        string $value,
        InteractiveLabelType $elementType,
        array $rules,
    ): ?array {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $normalizedValue = $this->normalize($value);

        foreach ($rules as $rule) {
            $term = trim(
                (string)($rule['term'] ?? ''),
            );

            if ($term === '') {
                continue;
            }

            if ($normalizedValue !== $this->normalize($term)) {
                continue;
            }

            $applicableCriteria = $this->resolveApplicableCriteria(
                $rule['wcag'] ?? [],
                $elementType,
            );

            // A term whose every WCAG entry is scoped to the other element
            // type (e.g. a rule that only applies to links) is not a match
            // for this element — keep looking rather than reporting an
            // empty criterion.
            if ($applicableCriteria === []) {
                continue;
            }

            return [
                'type' => $elementType,
                'value' => $value,
                'rule' => (string)($rule['rule'] ?? ''),
                'category' => (string)($rule['category'] ?? ''),
                'severity' => (string)($rule['severity'] ?? ''),
                'wcagCriterion' => implode(
                    ', ',
                    array_column($applicableCriteria, 'criterion'),
                ),
                'wcagLevel' => implode(
                    ', ',
                    array_unique(array_column($applicableCriteria, 'level')),
                ),
                'needsContextReview' => true,
            ];
        }

        if ($this->isSymbolOnly($normalizedValue)) {
            return [
                'type' => $elementType,
                'value' => $value,
                'rule' => self::SYMBOL_ONLY_RULE,
                'category' => 'symbol_only',
                'severity' => 'minor',
                'wcagCriterion' => self::SYMBOL_ONLY_WCAG,
                'wcagLevel' => self::SYMBOL_ONLY_LEVEL,
                'needsContextReview' => true,
            ];
        }

        return null;
    }

    /**
     * Picks the WCAG entries whose appliesTo covers the given element type.
     * A rule's wcag list may mix entries meant for links, for buttons, or
     * for both — only entries matching the actual element (as detected from
     * the record, e.g. via link-target presence) are relevant to report.
     *
     * @param array<int, array<string, mixed>> $wcagEntries
     *
     * @return array<int, array{criterion: string, level: string}>
     */
    private function resolveApplicableCriteria(
        array $wcagEntries,
        InteractiveLabelType $elementType,
    ): array {
        $applicable = [];

        foreach ($wcagEntries as $entry) {
            $appliesTo = $entry['appliesTo'] ?? [];

            if (!is_array($appliesTo) || !in_array($elementType->value, $appliesTo, true)) {
                continue;
            }

            $applicable[] = [
                'criterion' => (string)($entry['criterion'] ?? ''),
                'level' => (string)($entry['level'] ?? ''),
            ];
        }

        return $applicable;
    }

    /**
     * Matches values with no letters or digits in any script — arrows,
     * punctuation-only labels ("→", ">>", "+", "…", "?") — regardless of
     * locale, since symbols carry no language-specific spelling to compare.
     */
    private function isSymbolOnly(string $normalizedValue): bool
    {
        return preg_match('/[\p{L}\p{N}]/u', $normalizedValue) === 0;
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));

        return preg_replace(
            '/\s+/u',
            ' ',
            $value,
        ) ?? $value;
    }
}
