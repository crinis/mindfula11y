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
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 */

namespace MindfulMarkup\MindfulA11y\Tests\Unit\Service;

use MindfulMarkup\MindfulA11y\Service\InteractiveLabelRuleProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the two integrator-facing extension points of
 * InteractiveLabelRuleProvider::getRules(): `additionalVagueLabels` (Page
 * TSconfig mod.mindfula11y_accessibility.interactiveLabels.additionalVagueLabels)
 * and `ignoredLabels` (…ignoredLabels), both resolved by ModuleSettingsService
 * and threaded through InteractiveLabelFinderService::find().
 */
final class InteractiveLabelRuleProviderTest extends TestCase
{
    private function subject(): InteractiveLabelRuleProvider
    {
        return new InteractiveLabelRuleProvider();
    }

    private function terms(array $rules): array
    {
        return array_column($rules, 'term');
    }

    #[Test]
    public function additionalVagueLabelsAreAppendedAsProjectSpecificRules(): void
    {
        $rules = $this->subject()->getRules('de', ['jetzt', 'hier entlang']);

        self::assertContains('jetzt', $this->terms($rules));
        self::assertContains('hier entlang', $this->terms($rules));

        $added = array_values(array_filter($rules, static fn (array $rule): bool => $rule['term'] === 'jetzt'));
        self::assertCount(1, $added);
        self::assertSame('potentially_vague_interactive_label', $added[0]['rule']);
        self::assertSame('project_specific', $added[0]['category']);
        self::assertNotSame([], $added[0]['wcag'], 'a project-specific term must still carry a WCAG mapping the checker can resolve');
    }

    #[Test]
    public function blankAdditionalTermsAreSkipped(): void
    {
        $rules = $this->subject()->getRules('de', ['', '   ']);

        self::assertSame([], array_filter($this->terms($rules), static fn (string $term): bool => trim($term) === ''));
    }

    #[Test]
    public function ignoredLabelsRemoveBuiltInTerms(): void
    {
        $withoutIgnore = $this->subject()->getRules('de');
        self::assertContains('weiter', $this->terms($withoutIgnore), 'sanity check: "weiter" is a built-in German term');

        $rules = $this->subject()->getRules('de', [], ['weiter']);

        self::assertNotContains('weiter', $this->terms($rules));
    }

    #[Test]
    public function ignoredLabelsAreCaseAndWhitespaceInsensitive(): void
    {
        $rules = $this->subject()->getRules('de', [], [' Weiter ']);

        self::assertNotContains('weiter', $this->terms($rules));
    }

    #[Test]
    public function ignoredLabelsCanRemoveAnAdditionalVagueLabelAgain(): void
    {
        $rules = $this->subject()->getRules('de', ['jetzt'], ['jetzt']);

        self::assertNotContains('jetzt', $this->terms($rules));
    }
}
