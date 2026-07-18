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

use MindfulMarkup\MindfulA11y\Domain\Model\GenerateAltTextDemand;
use MindfulMarkup\MindfulA11y\Service\AltTextDemandFactory;
use MindfulMarkup\MindfulA11y\Service\DemandSignatureService;
use MindfulMarkup\MindfulA11y\Tests\Functional\AbstractAuthorizationTestCase;
use TYPO3\CMS\Backend\Utility\BackendUtility;

/**
 * The factory owns the demand's revision-pinning invariant and resolves the
 * fresh expiry at issuance (the demand VO stores expiries verbatim). It does
 * NOT authorize — that deliberately stays with its callers.
 */
final class AltTextDemandFactoryTest extends AbstractAuthorizationTestCase
{
    private function subject(): AltTextDemandFactory
    {
        return $this->get(AltTextDemandFactory::class);
    }

    public function testIssuedDemandCarriesAFreshExpiryAndValidates(): void
    {
        $this->logInBackendUser(2);
        // tt_content 100 on page 10 references sys_file 1 via sys_file_reference 1.
        $record = BackendUtility::getRecordWSOL('tt_content', 100);
        self::assertIsArray($record);

        $before = time();
        $demand = $this->subject()->create(10, 0, 'tt_content', 100, $record, 1, 1, ['assets']);

        self::assertNotNull($demand);
        self::assertSame(2, $demand->getUserId());
        self::assertGreaterThan($before, $demand->getExpiresAt());
        self::assertLessThanOrEqual(time() + GenerateAltTextDemand::LIFETIME, $demand->getExpiresAt());
        // All three revision snapshots are pinned.
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $demand->getFileSnapshot());
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $demand->getRecordSnapshot());
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $demand->getFileReferenceSnapshot());

        $signatureService = $this->get(DemandSignatureService::class);
        $redeemed = GenerateAltTextDemand::fromRequestData($signatureService->serialize($demand));
        self::assertNotNull($redeemed);
        self::assertTrue($signatureService->isValid($redeemed));
    }

    public function testUnsupportedFileTypeFailsClosed(): void
    {
        $this->logInBackendUser(2);
        // sys_file 3 is an SVG — OpenAI vision cannot consume it, so no
        // surface (FormEngine control, module list) may offer generation.
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/UnsupportedFileTypeSupplement.csv');
        $record = BackendUtility::getRecordWSOL('tt_content', 100);
        self::assertIsArray($record);

        self::assertNull($this->subject()->create(10, 0, 'tt_content', 100, $record, 3, 300, ['assets']));
    }

    public function testUnresolvableFileFailsClosed(): void
    {
        $this->logInBackendUser(2);
        $record = BackendUtility::getRecordWSOL('tt_content', 100);
        self::assertIsArray($record);

        self::assertNull($this->subject()->create(10, 0, 'tt_content', 100, $record, 999, 0, ['assets']));
    }

    public function testUnresolvableFileReferenceFailsClosed(): void
    {
        $this->logInBackendUser(2);
        $record = BackendUtility::getRecordWSOL('tt_content', 100);
        self::assertIsArray($record);

        self::assertNull($this->subject()->create(10, 0, 'tt_content', 100, $record, 1, 999, ['assets']));
    }
}
