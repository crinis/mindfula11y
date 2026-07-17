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

use MindfulMarkup\MindfulA11y\Domain\Model\GenerateAltTextDemand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Value-object shape of the alt-text demand: request-data gate, wire
 * round-trip and expiry defaulting. Signature creation and validation live in
 * DemandSignatureService and are covered by DemandSignatureServiceTest.
 */
final class GenerateAltTextDemandTest extends TestCase
{
    private function createDemand(int $expiresAt = 0, string $signature = ''): GenerateAltTextDemand
    {
        return new GenerateAltTextDemand(
            userId: 3,
            pageUid: 42,
            languageUid: 1,
            workspaceId: 2,
            recordTable: 'tt_content',
            recordUid: 7,
            fileUid: 9,
            recordColumns: ['alternative'],
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
        self::assertLessThanOrEqual($now + GenerateAltTextDemand::LIFETIME, $demand->getExpiresAt());
        self::assertSame(GenerateAltTextDemand::LIFETIME, $demand->maximumLifetime());
    }

    #[Test]
    public function demandSurvivesSerializationRoundTrip(): void
    {
        $data = json_decode(
            json_encode($this->createDemand(expiresAt: 2000000000, signature: 'abc')->toArray(), JSON_THROW_ON_ERROR),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $demand = GenerateAltTextDemand::fromRequestData($data);

        self::assertNotNull($demand);
        self::assertSame(3, $demand->getUserId());
        self::assertSame(42, $demand->getPageUid());
        self::assertSame(1, $demand->getLanguageUid());
        self::assertSame(2, $demand->getWorkspaceId());
        self::assertSame('tt_content', $demand->getRecordTable());
        self::assertSame(7, $demand->getRecordUid());
        self::assertSame(9, $demand->getFileUid());
        self::assertSame(['alternative'], $demand->getRecordColumns());
        self::assertSame(2000000000, $demand->getExpiresAt());
        self::assertSame('abc', $demand->getSignature());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidRequestDataProvider(): iterable
    {
        $valid = [
            'userId' => 3,
            'recordTable' => 'tt_content',
            'recordColumns' => ['alternative'],
            'expiresAt' => 2000000000,
            'signature' => 'abc',
        ];
        yield 'empty payload' => [[]];
        yield 'missing signature' => [array_diff_key($valid, ['signature' => ''])];
        yield 'empty signature' => [['signature' => ''] + $valid];
        yield 'zero userId' => [['userId' => 0] + $valid];
        yield 'missing expiresAt' => [array_diff_key($valid, ['expiresAt' => ''])];
        yield 'non-string recordTable' => [['recordTable' => 42] + $valid];
        yield 'non-array recordColumns' => [['recordColumns' => 'alternative'] + $valid];
    }

    /** @param array<string, mixed> $data */
    #[Test]
    #[DataProvider('invalidRequestDataProvider')]
    public function malformedRequestDataIsRejectedBeforeSignatureValidation(array $data): void
    {
        self::assertNull(GenerateAltTextDemand::fromRequestData($data));
    }
}
