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
use MindfulMarkup\MindfulA11y\Service\ScanStateService;
use MindfulMarkup\MindfulA11y\Tests\Functional\AbstractAuthorizationTestCase;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Http\Uri;

/**
 * The scan card state shared by the overview card and the scan feature:
 * scan-id reuse, the signed create demand and the single-page switches.
 *
 * Fixture: page 10 is editable for the full editor (user 2), who may
 * therefore trigger scans; user 8's group cannot modify pages.
 */
final class OverviewViewStateFactoryScanCardTest extends AbstractAuthorizationTestCase
{
    private const PREVIEW_URL = 'https://example.com/editable';

    /** @var array<string, mixed> */
    private const SCAN_TSCONFIG = ['mod' => ['mindfula11y_accessibility' => ['scan' => ['enable' => '1']]]];

    private function subject(): OverviewViewStateFactory
    {
        return $this->get(OverviewViewStateFactory::class);
    }

    /** @return array<string, mixed> */
    private function page(int $scanUpdated): array
    {
        $this->getConnectionPool()->getConnectionForTable('pages')->update('pages', [
            ScanStateService::FIELD_SCAN_ID => 'stored-scan',
            ScanStateService::FIELD_SCAN_UPDATED => $scanUpdated,
            'SYS_LASTCHANGED' => 1000,
        ], ['uid' => 10]);
        $page = BackendUtility::getRecord('pages', 10);
        self::assertIsArray($page);

        return $page;
    }

    public function testSinglePageScanReusesTheStoredScanAndFiltersByThePreviewUrl(): void
    {
        $this->logInBackendUser(2);

        $state = $this->subject()->buildScanCardState(10, $this->page(2000), null, new Uri(self::PREVIEW_URL), 0, self::SCAN_TSCONFIG);

        self::assertSame('stored-scan', $state['scanId']);
        self::assertSame(0, $state['createScanDemand']['pageLevels'] ?? null);
        self::assertTrue($state['autoCreateScan']);
        self::assertSame([self::PREVIEW_URL], $state['pageUrlFilter']);
    }

    public function testScanOlderThanTheLastPageChangeIsNotReused(): void
    {
        $this->logInBackendUser(2);

        $state = $this->subject()->buildScanCardState(10, $this->page(500), null, new Uri(self::PREVIEW_URL), 0, self::SCAN_TSCONFIG);

        self::assertNull($state['scanId']);
    }

    public function testMultiLevelScanNeitherAutoCreatesNorFilters(): void
    {
        $this->logInBackendUser(2);

        $state = $this->subject()->buildScanCardState(10, $this->page(2000), null, new Uri(self::PREVIEW_URL), 2, self::SCAN_TSCONFIG);

        self::assertSame(2, $state['createScanDemand']['pageLevels'] ?? null);
        self::assertFalse($state['autoCreateScan']);
        self::assertSame([], $state['pageUrlFilter']);
    }

    public function testAutoCreateFollowsPageTsConfig(): void
    {
        $this->logInBackendUser(2);
        $tsConfig = self::SCAN_TSCONFIG;
        $tsConfig['mod']['mindfula11y_accessibility']['scan']['autoCreate'] = '0';

        $state = $this->subject()->buildScanCardState(10, $this->page(2000), null, new Uri(self::PREVIEW_URL), 0, $tsConfig);

        self::assertFalse($state['autoCreateScan']);
    }

    /**
     * A single-page scan of a page a public visitor cannot see (910: fe_group
     * on the page; 911: inherited via extendToSubpages) is refused by
     * ScanAjaxController::createAction() — the card must not offer or
     * auto-create it. A multi-level scan skips restricted pages instead and
     * stays available.
     */
    public function testFrontendRestrictedPageGetsNoSinglePageDemand(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/PagePreviewSupplement.csv');
        $this->logInBackendUser(2);

        foreach ([910, 911] as $pageId) {
            $page = BackendUtility::getRecord('pages', $pageId);
            self::assertIsArray($page);

            $singlePage = $this->subject()->buildScanCardState($pageId, $page, null, new Uri(self::PREVIEW_URL), 0, self::SCAN_TSCONFIG);
            self::assertNull($singlePage['createScanDemand'], 'no single-page demand for page ' . $pageId);
            self::assertFalse($singlePage['autoCreateScan'], 'no auto-create for page ' . $pageId);

            $multiLevel = $this->subject()->buildScanCardState($pageId, $page, null, new Uri(self::PREVIEW_URL), 2, self::SCAN_TSCONFIG);
            self::assertSame(2, $multiLevel['createScanDemand']['pageLevels'] ?? null, 'multi-level demand for page ' . $pageId);
        }
    }

    public function testNoDemandWithoutAPreviewOrWithoutTriggerPermission(): void
    {
        $this->logInBackendUser(2);
        $withoutPreview = $this->subject()->buildScanCardState(10, $this->page(2000), null, null, 0, self::SCAN_TSCONFIG);
        self::assertNull($withoutPreview['createScanDemand']);
        self::assertSame([], $withoutPreview['pageUrlFilter']);

        $this->logInBackendUser(8);
        $withoutPermission = $this->subject()->buildScanCardState(10, $this->page(2000), null, new Uri(self::PREVIEW_URL), 0, self::SCAN_TSCONFIG);
        self::assertNull($withoutPermission['createScanDemand']);
        self::assertSame('stored-scan', $withoutPermission['scanId']);
    }
}
