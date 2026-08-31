<?php

declare(strict_types=1);

namespace MindfulMarkup\MindfulA11y\Service;

use MindfulMarkup\MindfulA11y\Enum\InteractiveLabelType;

final readonly class InteractiveLabelRuleProvider
{
    private const string PROJECT_SPECIFIC_RULE = 'potentially_vague_interactive_label';
    private const string PROJECT_SPECIFIC_CATEGORY = 'project_specific';

    /**
     * Every built-in term currently carries this exact WCAG mapping, so
     * project-specific terms added via `additionalVagueLabels` reuse it
     * rather than requiring integrators to author a `wcag` array themselves.
     */
    private const array PROJECT_SPECIFIC_WCAG = [
        ['criterion' => '2.4.6', 'level' => 'AA', 'appliesTo' => ['link', 'button']],
        ['criterion' => '2.4.4', 'level' => 'A', 'appliesTo' => ['link']],
    ];

    /**
     * Loads the full rule set for a locale. Rules are no longer split by
     * element type at file level — the new format carries per-criterion
     * `appliesTo` (['link'], ['button'], or both) inside each rule's `wcag`
     * entries instead, so the same term list works for both element types
     * and InteractiveLabelChecker resolves which criteria apply once it
     * knows the actual element type of the record being checked.
     *
     * `$additionalVagueLabels` and `$ignoredLabels` are the integrator-facing
     * extension points (Page TSconfig `interactiveLabels.additionalVagueLabels`
     * / `.ignoredLabels`, resolved by ModuleSettingsService): additional terms
     * are appended before ignored terms are filtered out, so an integrator can
     * also use `ignoredLabels` to remove one of their own additions.
     *
     * @param string[] $additionalVagueLabels
     * @param string[] $ignoredLabels
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRules(
        string $locale,
        array $additionalVagueLabels = [],
        array $ignoredLabels = [],
    ): array {
        $languageCode = $this->getLanguageCode($locale);
        $file = $this->getRuleFile($languageCode);
        $rules = $file === null ? [] : require $file;
        $rules = is_array($rules) ? $rules : [];

        foreach ($additionalVagueLabels as $term) {
            $term = trim((string)$term);

            if ($term === '') {
                continue;
            }

            $rules[] = [
                'term' => $term,
                'rule' => self::PROJECT_SPECIFIC_RULE,
                'category' => self::PROJECT_SPECIFIC_CATEGORY,
                'severity' => 'minor',
                'wcag' => self::PROJECT_SPECIFIC_WCAG,
            ];
        }

        if ($ignoredLabels === []) {
            return $rules;
        }

        $normalizedIgnored = array_map(
            static fn (string $term): string => mb_strtolower(trim($term)),
            $ignoredLabels,
        );

        return array_values(array_filter(
            $rules,
            static fn (array $rule): bool => !in_array(
                mb_strtolower(trim((string)($rule['term'] ?? ''))),
                $normalizedIgnored,
                true,
            ),
        ));
    }

    private function getLanguageCode(string $locale): string
    {
        $locale = str_replace(
            '_',
            '-',
            trim($locale),
        );

        $parts = explode('-', $locale);

        return strtolower(
            $parts[0] ?? '',
        );
    }

    private function getRuleFile(
        string $languageCode,
    ): ?string {
        $fileName = match ($languageCode) {
            'de' => 'German.php',
            'en' => 'English.php',
            default => null,
        };

        if ($fileName === null) {
            return null;
        }

        $file = dirname(__DIR__, 2)
            . '/Configuration/InteractiveLabels/'
            . $fileName;

        return is_file($file)
            ? $file
            : null;
    }
}
