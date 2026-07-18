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

use MindfulMarkup\MindfulA11y\Domain\Model\CreateScanDemand;
use MindfulMarkup\MindfulA11y\Service\DemandSignatureService;
use MindfulMarkup\MindfulA11y\Service\ScanDemandFactory;
use MindfulMarkup\MindfulA11y\Tests\Functional\AbstractAuthorizationTestCase;
use TYPO3\CMS\Backend\Routing\PreviewUriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The demand's language must always be the language of the page record the
 * preview is built from. When the module's selected language has no page
 * translation, the preview falls back to the default-language record — a
 * demand signed with the selected language instead would always fail at
 * redemption (getLocalizedPageRecord finds no translation → pageNotFound,
 * and matchesCurrentSnapshot rejects the record/language mismatch).
 */
final class ScanDemandFactoryTest extends AbstractAuthorizationTestCase
{
    private function subject(): ScanDemandFactory
    {
        return $this->get(ScanDemandFactory::class);
    }

    public function testDemandForUntranslatedPageSignsTheFallbackRecordLanguage(): void
    {
        $this->logInBackendUser(2);
        // Page 18 has no language-1 translation: the caller renders the
        // default-language record as preview fallback and passes it here.
        $pageRecord = BackendUtility::getRecord('pages', 18);
        self::assertIsArray($pageRecord);

        $demand = $this->subject()->create($pageRecord, 18, 'https://example.com/page-only');

        self::assertNotNull($demand);
        self::assertSame(0, $demand->getLanguageId());
    }

    public function testDemandForTranslatedPageSignsTheLocalizedRecordLanguage(): void
    {
        $this->logInBackendUser(2);
        // Page 30 is the language-1 translation of page 10.
        $pageRecord = BackendUtility::getRecord('pages', 30);
        self::assertIsArray($pageRecord);

        $demand = $this->subject()->create($pageRecord, 10, 'https://example.com/fr/editable');

        self::assertNotNull($demand);
        self::assertSame(1, $demand->getLanguageId());
    }

    public function testIssuedDemandCarriesAFreshExpiryAndValidates(): void
    {
        $this->logInBackendUser(2);
        $pageRecord = BackendUtility::getRecord('pages', 18);
        self::assertIsArray($pageRecord);

        // The demand VO stores expiries verbatim, so the factory must resolve
        // a fresh one at issuance — a forgotten expiry fails closed here.
        $before = time();
        $demand = $this->subject()->create($pageRecord, 18, 'https://example.com/page-only');

        self::assertNotNull($demand);
        self::assertGreaterThan($before, $demand->getExpiresAt());
        self::assertLessThanOrEqual(time() + CreateScanDemand::LIFETIME, $demand->getExpiresAt());

        $signatureService = $this->get(DemandSignatureService::class);
        $redeemed = CreateScanDemand::fromRequestData($signatureService->serialize($demand));
        self::assertNotNull($redeemed);
        self::assertTrue($signatureService->isValid($redeemed));
    }

    /** @return array{demand: CreateScanDemand, pageUid: int} */
    private function issueDemandForPage(int $pageUid): array
    {
        $this->logInBackendUser(2);
        $this->writeDefaultSiteConfiguration();
        $pageRecord = BackendUtility::getRecord('pages', $pageUid);
        self::assertIsArray($pageRecord);
        // Redemption compares the demand's URL against a freshly built preview
        // URI, so the signed URL must come from the same builder.
        $previewUrl = (string)PreviewUriBuilder::create($pageRecord)->buildUri();
        self::assertNotSame('', $previewUrl);
        $demand = $this->subject()->create($pageRecord, $pageUid, $previewUrl);
        self::assertNotNull($demand);

        return ['demand' => $demand, 'pageUid' => $pageUid];
    }

    /**
     * The scan flow shares the pages-row race of the structure tickets: core
     * frontend rendering updates SYS_LASTCHANGED (and a previous scan cycle
     * may rewrite the bookkeeping fields) between module render and the
     * create-scan POST. Bookkeeping columns are no authorization input, so
     * the signed demand must survive them.
     */
    public function testDemandRemainsValidAfterScanBookkeepingWrite(): void
    {
        ['demand' => $demand, 'pageUid' => $pageUid] = $this->issueDemandForPage(18);

        $this->getConnectionPool()->getConnectionForTable('pages')->update(
            'pages',
            [
                'tx_mindfula11y_scanid' => '186',
                'tx_mindfula11y_scanupdated' => time(),
                'tstamp' => time(),
                'SYS_LASTCHANGED' => time(),
            ],
            ['uid' => $pageUid],
        );
        GeneralUtility::makeInstance(CacheManager::class)->getCache('runtime')->flush();
        $currentRecord = BackendUtility::getRecord('pages', $pageUid);
        self::assertIsArray($currentRecord);

        self::assertTrue($this->subject()->matchesCurrentSnapshot($demand, $currentRecord));
    }

    public function testDemandIsRejectedWhenEditlockIsSetAfterIssuance(): void
    {
        ['demand' => $demand, 'pageUid' => $pageUid] = $this->issueDemandForPage(18);

        $this->getConnectionPool()->getConnectionForTable('pages')->update(
            'pages',
            ['editlock' => 1],
            ['uid' => $pageUid],
        );
        GeneralUtility::makeInstance(CacheManager::class)->getCache('runtime')->flush();
        $currentRecord = BackendUtility::getRecord('pages', $pageUid);
        self::assertIsArray($currentRecord);

        self::assertFalse($this->subject()->matchesCurrentSnapshot($demand, $currentRecord));
    }
}
