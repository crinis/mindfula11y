<?php
declare(strict_types=1);

/*
 * Mindful A11y extension for TYPO3 integrating accessibility tools into the backend.
 * Copyright (C) 2025  Mindful Markup, Felix Spittel
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 */

namespace MindfulMarkup\MindfulA11y\Tests\Unit\Domain\Model;

use MindfulMarkup\MindfulA11y\Domain\Model\CreateScanDemand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Value-object shape of the scan demand: request-data gate, wire round-trip
 * and expiry defaulting. Signature creation and validation live in
 * DemandSignatureService and are covered by DemandSignatureServiceTest.
 */
final class CreateScanDemandTest extends TestCase
{
    private function createDemand(int $expiresAt = 0, string $signature = ''): CreateScanDemand
    {
        return new CreateScanDemand(
            userId: 3,
            pageId: 42,
            previewUrl: 'https://example.com/page?type=0',
            languageId: 1,
            workspaceId: 2,
            pageRecordSnapshot: str_repeat('a', 64),
            pageLevels: 5,
            crawl: false,
            expiresAt: $expiresAt,
            signature: $signature,
        );
    }

    #[Test]
    public function freshDemandCarriesNoSignatureAndALifetimeExpiry(): void
    {
        $now = time();
        $demand = $this->createDemand();

        self::assertSame('', $demand->getSignature());
        self::assertGreaterThan($now, $demand->getExpiresAt());
        self::assertLessThanOrEqual($now + CreateScanDemand::LIFETIME, $demand->getExpiresAt());
        self::assertSame(CreateScanDemand::LIFETIME, $demand->maximumLifetime());
    }

    #[Test]
    public function demandSurvivesSerializationRoundTrip(): void
    {
        $data = json_decode(
            json_encode($this->createDemand(expiresAt: 2000000000, signature: 'abc')->toArray(), JSON_THROW_ON_ERROR),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $demand = CreateScanDemand::fromRequestData($data);

        self::assertNotNull($demand);
        self::assertSame(3, $demand->getUserId());
        self::assertSame(42, $demand->getPageId());
        self::assertSame('https://example.com/page?type=0', $demand->getPreviewUrl());
        self::assertSame(1, $demand->getLanguageId());
        self::assertSame(2, $demand->getWorkspaceId());
        self::assertSame(str_repeat('a', 64), $demand->getPageRecordSnapshot());
        self::assertSame(5, $demand->getPageLevels());
        self::assertFalse($demand->getCrawl());
        self::assertSame(2000000000, $demand->getExpiresAt());
        self::assertSame('abc', $demand->getSignature());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidRequestDataProvider(): iterable
    {
        $valid = [
            'userId' => 3,
            'pageId' => 42,
            'previewUrl' => 'https://example.com/page',
            'pageRecordSnapshot' => str_repeat('a', 64),
            'expiresAt' => 2000000000,
            'signature' => 'abc',
        ];
        yield 'empty payload' => [[]];
        yield 'missing signature' => [array_diff_key($valid, ['signature' => ''])];
        yield 'empty signature' => [['signature' => ''] + $valid];
        yield 'missing previewUrl' => [array_diff_key($valid, ['previewUrl' => ''])];
        yield 'non-string previewUrl' => [['previewUrl' => 42] + $valid];
        yield 'empty previewUrl' => [['previewUrl' => ''] + $valid];
        yield 'invalid page snapshot' => [['pageRecordSnapshot' => 'invalid'] + $valid];
        yield 'zero userId' => [['userId' => 0] + $valid];
        yield 'zero pageId' => [['pageId' => 0] + $valid];
        yield 'negative languageId' => [['languageId' => -1] + $valid];
        yield 'negative workspaceId' => [['workspaceId' => -1] + $valid];
        yield 'negative pageLevels' => [['pageLevels' => -1] + $valid];
        yield 'missing expiresAt' => [array_diff_key($valid, ['expiresAt' => ''])];
    }

    /** @param array<string, mixed> $data */
    #[Test]
    #[DataProvider('invalidRequestDataProvider')]
    public function malformedRequestDataIsRejectedBeforeSignatureValidation(array $data): void
    {
        self::assertNull(CreateScanDemand::fromRequestData($data));
    }
}
