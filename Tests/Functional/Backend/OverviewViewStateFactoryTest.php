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

namespace MindfulMarkup\MindfulA11y\Tests\Functional\Backend;

use MindfulMarkup\MindfulA11y\Backend\OverviewViewStateFactory;
use MindfulMarkup\MindfulA11y\Service\ModuleSettingsService;
use MindfulMarkup\MindfulA11y\Tests\Functional\AbstractAuthorizationTestCase;
use TYPO3\CMS\Backend\Utility\BackendUtility;

/**
 * Regression coverage for the invariant OverviewViewStateFactory documents
 * but, until this test, never verified: its interactive-label count/findings
 * must match what InteractiveLabelsFeatureRenderer computes for the same
 * page — otherwise the overview badge and "View details" disagree.
 *
 * This exact regression happened when additionalVagueLabels/ignoredLabels/
 * repeatedLabelThreshold (Page TSconfig, resolved via ModuleSettingsService)
 * were threaded through InteractiveLabelsFeatureRenderer's find()/annotate()
 * calls but not through OverviewViewStateFactory's — the overview kept using
 * the unfiltered built-in rule set while the detail page correctly applied
 * the project's ignoredLabels, so a label ignored on the detail page still
 * showed up (and counted) on the overview.
 *
 * Uses OverviewInteractiveLabelSupplement.csv: page 610 configures
 * interactiveLabels.fields (tt_content.header) and ignoredLabels = weiter;
 * tt_content 600 on that page carries the built-in vague term "weiter".
 */
final class OverviewViewStateFactoryTest extends AbstractAuthorizationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/OverviewInteractiveLabelSupplement.csv');
        // OverviewViewStateFactory::build() resolves the page's site to get
        // the interactive-label locale — needs a real site configuration.
        $this->writeDefaultSiteConfiguration();
    }

    private function buildViewState(int $pageId): array
    {
        $moduleSettingsService = $this->get(ModuleSettingsService::class);
        $pageTsConfig = $moduleSettingsService->getConvertedPageTsConfig($pageId);
        $pageInfo = BackendUtility::getRecordWSOL('pages', $pageId);
        self::assertIsArray($pageInfo);

        return $this->get(OverviewViewStateFactory::class)->build($pageId, 0, $pageInfo, null, $pageTsConfig);
    }

    public function testOverviewRespectsIgnoredLabelsLikeTheDetailPageDoes(): void
    {
        $this->logInBackendUser(2);

        $viewState = $this->buildViewState(610);

        self::assertSame(
            0,
            $viewState['interactiveLabelCount'],
            'the overview must not count a label the project TSconfig explicitly ignores',
        );
        self::assertSame([], $viewState['interactiveLabelFindings']);
    }
}
