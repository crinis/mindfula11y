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

use MindfulMarkup\MindfulA11y\Enum\InteractiveLabelType;
use MindfulMarkup\MindfulA11y\Service\BackendUserProvider;
use MindfulMarkup\MindfulA11y\Service\InteractiveLabelAggregator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * Unit tests for InteractiveLabelAggregator::annotate().
 *
 * LanguageService::sL() is stubbed to only resolve the exact LLL: keys the
 * extension actually registers (Accessibility.xlf) and return '' for
 * anything else — the same behavior TYPO3 core's real implementation has for
 * an unknown key. That makes a malformed key (a missing separator, a typo)
 * visible as an empty ruleDescription/ruleTitle instead of passing silently,
 * which is exactly the bug this suite was written to catch: addTranslationKeys()
 * used to build 'findings.rule.description' . $rule without the separating
 * dot, producing e.g. 'findings.rule.descriptionpotentially_vague_interactive_label'
 * — a key that matches no trans-unit — so every ruleDescription/*RuleDescription
 * silently rendered blank.
 */
final class InteractiveLabelAggregatorTest extends TestCase
{
    private const LANGUAGE_FILE = 'LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:';

    /** @var array<string, string> */
    private const REGISTERED_TRANSLATIONS = [
        self::LANGUAGE_FILE . 'findings.rule.title.potentially_vague_interactive_label' => 'Potentially vague label',
        self::LANGUAGE_FILE . 'findings.rule.description.potentially_vague_interactive_label'
            => 'The label may not clearly describe the purpose of the action or link.',
        self::LANGUAGE_FILE . 'findings.rule.title.repeated_generic_label' => 'Repeated label',
        self::LANGUAGE_FILE . 'findings.rule.description.repeated_generic_label'
            => 'The same generic label is used multiple times on this page.',
        self::LANGUAGE_FILE . 'findings.rule.title.generic_label_different_targets' => 'Same generic label with different targets',
        self::LANGUAGE_FILE . 'findings.rule.description.generic_label_different_targets'
            => 'The same generic label is used for different destinations.',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // BackendUserProvider is final (cannot be mocked): give it a real,
        // authenticated-looking backend user via the global it reads.
        $backendUser = new BackendUserAuthentication();
        $backendUser->user = ['uid' => 1];
        $GLOBALS['BE_USER'] = $backendUser;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    private function makeSubject(): InteractiveLabelAggregator
    {
        $languageService = $this->createMock(LanguageService::class);
        $languageService->method('sL')->willReturnCallback(
            static fn(string $key): string => self::REGISTERED_TRANSLATIONS[$key] ?? '',
        );

        $languageServiceFactory = $this->createMock(LanguageServiceFactory::class);
        $languageServiceFactory->method('createFromUserPreferences')->willReturn($languageService);

        return new InteractiveLabelAggregator($languageServiceFactory, new BackendUserProvider());
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function label(array $overrides = []): array
    {
        return [
            'table' => 'tt_content',
            'uid' => 1,
            'field' => 'header',
            'value' => 'weiter',
            'type' => InteractiveLabelType::BUTTON,
            'target' => '',
            'hasSingleIssue' => false,
            ...$overrides,
        ];
    }

    #[Test]
    public function emptyInputProducesNoFindings(): void
    {
        self::assertSame([], $this->makeSubject()->annotate([]));
    }

    #[Test]
    public function aLabelWithoutAnyIssueIsDropped(): void
    {
        $findings = $this->makeSubject()->annotate([$this->label()]);

        self::assertSame([], $findings, 'a completely valid label must not be reported');
    }

    #[Test]
    public function aPrimaryRuleResolvesItsTitleAndDescriptionFromTheDottedKey(): void
    {
        $findings = $this->makeSubject()->annotate([
            $this->label(['rule' => 'potentially_vague_interactive_label', 'hasSingleIssue' => true]),
        ]);

        self::assertCount(1, $findings);
        self::assertSame(
            self::LANGUAGE_FILE . 'findings.rule.description.potentially_vague_interactive_label',
            $findings[0]['ruleDescriptionKey'],
        );
        self::assertSame(
            'The label may not clearly describe the purpose of the action or link.',
            $findings[0]['ruleDescription'],
            'a malformed key would resolve to "" here, exactly like the missing-dot regression did',
        );
        self::assertSame('Potentially vague label', $findings[0]['ruleTitle']);
    }

    #[Test]
    public function twoIdenticalLabelsAreFlaggedAsRepeatedWithAResolvedDescription(): void
    {
        $labels = [
            $this->label(['uid' => 1, 'value' => 'Weiter']),
            $this->label(['uid' => 2, 'value' => ' weiter ']), // normalizes to the same group as uid 1
        ];

        $findings = $this->makeSubject()->annotate($labels);

        self::assertCount(2, $findings, 'both occurrences of the repeated label must be reported');
        foreach ($findings as $finding) {
            self::assertSame('repeated_generic_label', $finding['rule'], 'promoted from the page-wide rule, since neither has a single-label issue');
            self::assertSame(2, $finding['occurrenceCount']);
            self::assertSame(
                'The same generic label is used multiple times on this page.',
                $finding['ruleDescription'],
            );
        }
    }

    #[Test]
    public function repeatedRuleKeysResolveEvenWhenItIsOnlySecondaryToAPrimaryIssue(): void
    {
        // Both occurrences also carry their own single-label issue, so the
        // primary rule stays potentially_vague_interactive_label — the
        // repeatedRule/*Description pair must still resolve correctly as the
        // secondary notice.
        $labels = [
            $this->label(['uid' => 1, 'value' => 'weiter', 'rule' => 'potentially_vague_interactive_label', 'hasSingleIssue' => true]),
            $this->label(['uid' => 2, 'value' => 'weiter', 'rule' => 'potentially_vague_interactive_label', 'hasSingleIssue' => true]),
        ];

        $findings = $this->makeSubject()->annotate($labels);

        self::assertSame('potentially_vague_interactive_label', $findings[0]['rule']);
        self::assertSame(
            self::LANGUAGE_FILE . 'findings.rule.description.repeated_generic_label',
            $findings[0]['repeatedRuleDescriptionKey'],
        );
        self::assertSame(
            'The same generic label is used multiple times on this page.',
            $findings[0]['repeatedRuleDescription'],
        );
    }

    #[Test]
    public function repeatedLabelThresholdIsConfigurable(): void
    {
        $labels = [
            $this->label(['uid' => 1, 'value' => 'weiter']),
            $this->label(['uid' => 2, 'value' => 'weiter']),
        ];

        self::assertSame(
            [],
            $this->makeSubject()->annotate($labels, 3),
            'two occurrences must not be flagged when the configured threshold is 3',
        );

        $findings = $this->makeSubject()->annotate($labels, 2);
        self::assertCount(2, $findings, 'the default threshold of 2 still flags two occurrences');
    }

    #[Test]
    public function sameLabelWithDifferentTargetsIsPromotedOverRepetition(): void
    {
        // Different-targets is documented as the more specific rule and takes
        // precedence over repetition when both apply.
        $labels = [
            $this->label(['uid' => 1, 'value' => 'weiter', 'target' => '/a']),
            $this->label(['uid' => 2, 'value' => 'weiter', 'target' => '/b']),
        ];

        $findings = $this->makeSubject()->annotate($labels);

        self::assertCount(2, $findings);
        foreach ($findings as $finding) {
            self::assertSame('generic_label_different_targets', $finding['rule']);
            self::assertSame(2, $finding['distinctTargetCount']);
            self::assertSame(
                'The same generic label is used for different destinations.',
                $finding['ruleDescription'],
            );
            self::assertSame('2.4.6', $finding['wcagCriterion'], 'buttons use the descriptive-label criterion');
            self::assertSame('AA', $finding['wcagLevel']);
        }
    }
}
