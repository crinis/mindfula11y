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

namespace MindfulMarkup\MindfulA11y\Tests\Functional\Service;

use MindfulMarkup\MindfulA11y\Enum\ScanMode;
use MindfulMarkup\MindfulA11y\Service\ModuleSettingsService;
use MindfulMarkup\MindfulA11y\Tests\Functional\AbstractAuthorizationTestCase;

/**
 * The Page TSconfig accessors of ModuleSettingsService that its callers used
 * to spell out themselves: the page-id feature gates (which read a translated
 * page's gate from its default-language page), the ignored file columns (with
 * the legacy path), the page-module hide switch and the file-metadata gate.
 *
 * Fixtures: ScanSupplement page 500 is the fr translation of the scan-disabled
 * page 17; StructureSupplement page 602 is the fr translation of page 600,
 * which disables both structure features. Neither translation carries
 * TSconfig of its own, so its own rootline (under the enabling site root)
 * would read as enabled.
 */
final class ModuleSettingsServiceFeatureGateTest extends AbstractAuthorizationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/ScanSupplement.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/StructureSupplement.csv');
        $this->logInBackendUser(2);
    }

    private function subject(): ModuleSettingsService
    {
        return $this->get(ModuleSettingsService::class);
    }

    public function testAiReviewScanModesDefaultToTheCurrentPageAndFollowPageTsConfig(): void
    {
        $subject = $this->subject();

        // Page 19 enables the AI review without naming modes; page 501 lists two of them.
        self::assertSame([ScanMode::SingleUrl], $subject->getAiAuditScanModes($subject->getConvertedPageTsConfig(19)));
        self::assertSame(
            [ScanMode::SingleUrl, ScanMode::UrlList],
            $subject->getAiAuditScanModes($subject->getConvertedPageTsConfig(501))
        );
    }

    public function testAiReviewScanModesIgnoreUnknownValuesAndKeepTheDefaultWhenNothingIsLeft(): void
    {
        $subject = $this->subject();
        $tsConfig = ['mod' => ['mindfula11y_accessibility' => ['scan' => ['aiAudit' => ['scanModes' => 'crawl, everything']]]]];
        $emptyTsConfig = ['mod' => ['mindfula11y_accessibility' => ['scan' => ['aiAudit' => ['scanModes' => '']]]]];

        self::assertSame([ScanMode::Crawl], $subject->getAiAuditScanModes($tsConfig));
        self::assertSame([ScanMode::SingleUrl], $subject->getAiAuditScanModes($emptyTsConfig));
    }

    public function testScanGateFollowsPageTsConfig(): void
    {
        self::assertTrue($this->subject()->isScanEnabledForPage(10));
        self::assertFalse($this->subject()->isScanEnabledForPage(17));
    }

    public function testScanGateOfATranslatedPageIsReadFromItsDefaultLanguagePage(): void
    {
        self::assertFalse($this->subject()->isScanEnabledForPage(500));
        self::assertTrue($this->subject()->isScanEnabledForPage(30));
    }

    public function testStructureAnalysisGateFollowsPageTsConfig(): void
    {
        self::assertTrue($this->subject()->isStructureAnalysisEnabledForPage(10));
        self::assertFalse($this->subject()->isStructureAnalysisEnabledForPage(600));
    }

    public function testStructureAnalysisGateOfATranslatedPageIsReadFromItsDefaultLanguagePage(): void
    {
        self::assertFalse($this->subject()->isStructureAnalysisEnabledForPage(602));
        self::assertTrue($this->subject()->isStructureAnalysisEnabledForPage(30));
    }

    public function testIgnoredFileColumnsMergeTheCurrentAndTheLegacyPath(): void
    {
        $pageTsConfig = [
            'mod' => [
                'mindfula11y_accessibility' => [
                    'missingAltText' => ['ignoreColumns' => ['tt_content' => 'assets, image']],
                ],
                'mindfula11y_missingalttext' => ['tt_content' => 'media'],
            ],
        ];

        self::assertSame(['assets', 'image', 'media'], $this->subject()->getIgnoredFileColumns('tt_content', $pageTsConfig));
        self::assertSame([], $this->subject()->getIgnoredFileColumns('pages', $pageTsConfig));
    }

    public function testOverviewIsHiddenInPageModuleOnlyWhenConfigured(): void
    {
        self::assertTrue($this->subject()->isOverviewHiddenInPageModule(
            ['mod' => ['web_layout' => ['mindfula11y' => ['hideInfo' => '1']]]]
        ));
        self::assertFalse($this->subject()->isOverviewHiddenInPageModule(
            ['mod' => ['web_layout' => ['mindfula11y' => ['hideInfo' => '0']]]]
        ));
        self::assertFalse($this->subject()->isOverviewHiddenInPageModule([]));
    }

    public function testFileMetadataCountsUnlessTsConfigIgnoresIt(): void
    {
        $ignoring = ['mod' => ['mindfula11y_accessibility' => ['missingAltText' => ['ignoreFileMetadata' => '1']]]];

        self::assertTrue($this->subject()->canConsiderFileMetadataAlternative([]));
        self::assertFalse($this->subject()->canConsiderFileMetadataAlternative($ignoring));
    }
}
