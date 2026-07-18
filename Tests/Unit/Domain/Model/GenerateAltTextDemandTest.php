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
 * round-trip and verbatim expiry storage (fresh expiries are an issuance
 * concern of AltTextDemandFactory). Signature creation and validation live in
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
            fileReferenceUid: 5,
            fileSnapshot: str_repeat('c', 64),
            recordSnapshot: str_repeat('a', 64),
            fileReferenceSnapshot: str_repeat('b', 64),
            recordColumns: ['alternative'],
            expiresAt: $expiresAt,
            signature: $signature,
        );
    }

    #[Test]
    public function expiryIsStoredVerbatimAndNeverResolvedFresh(): void
    {
        // The VO is deterministic: an unset expiry stays 0 and fails closed at
        // validation — resolving a fresh one is the issuing factory's job.
        $demand = $this->createDemand();

        self::assertSame('', $demand->getSignature());
        self::assertSame(0, $demand->getExpiresAt());
        self::assertSame(2000000000, $this->createDemand(expiresAt: 2000000000)->getExpiresAt());
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
        self::assertSame(5, $demand->getFileReferenceUid());
        self::assertSame(str_repeat('c', 64), $demand->getFileSnapshot());
        self::assertSame(str_repeat('a', 64), $demand->getRecordSnapshot());
        self::assertSame(str_repeat('b', 64), $demand->getFileReferenceSnapshot());
        self::assertSame(['alternative'], $demand->getRecordColumns());
        self::assertSame(2000000000, $demand->getExpiresAt());
        self::assertSame('abc', $demand->getSignature());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidRequestDataProvider(): iterable
    {
        $valid = [
            'userId' => 3,
            'pageUid' => 42,
            'languageUid' => 1,
            'workspaceId' => 2,
            'recordTable' => 'tt_content',
            'recordUid' => 7,
            'fileUid' => 9,
            'fileReferenceUid' => 5,
            'recordColumns' => ['alternative'],
            'recordSnapshot' => str_repeat('a', 64),
            'fileSnapshot' => str_repeat('c', 64),
            'fileReferenceSnapshot' => str_repeat('b', 64),
            'expiresAt' => 2000000000,
            'signature' => 'abc',
        ];
        yield 'empty payload' => [[]];
        yield 'missing signature' => [array_diff_key($valid, ['signature' => ''])];
        yield 'empty signature' => [['signature' => ''] + $valid];
        yield 'zero userId' => [['userId' => 0] + $valid];
        yield 'negative pageUid' => [['pageUid' => -1] + $valid];
        yield 'invalid languageUid below all-languages value' => [['languageUid' => -2] + $valid];
        yield 'negative workspaceId' => [['workspaceId' => -1] + $valid];
        yield 'zero recordUid' => [['recordUid' => 0] + $valid];
        yield 'zero fileUid' => [['fileUid' => 0] + $valid];
        yield 'negative fileReferenceUid' => [['fileReferenceUid' => -1] + $valid];
        yield 'missing expiresAt' => [array_diff_key($valid, ['expiresAt' => ''])];
        yield 'non-string recordTable' => [['recordTable' => 42] + $valid];
        yield 'empty recordTable' => [['recordTable' => ''] + $valid];
        yield 'non-array recordColumns' => [['recordColumns' => 'alternative'] + $valid];
        yield 'empty recordColumns' => [['recordColumns' => []] + $valid];
        yield 'non-list recordColumns' => [['recordColumns' => ['a' => 'alternative']] + $valid];
        yield 'non-string recordColumns entry' => [['recordColumns' => [42]] + $valid];
        yield 'empty recordColumns entry' => [['recordColumns' => ['']] + $valid];
        yield 'invalid record snapshot' => [['recordSnapshot' => 'invalid'] + $valid];
        yield 'invalid file snapshot' => [['fileSnapshot' => 'invalid'] + $valid];
        yield 'invalid reference snapshot' => [['fileReferenceSnapshot' => 'invalid'] + $valid];
    }

    /** @param array<string, mixed> $data */
    #[Test]
    #[DataProvider('invalidRequestDataProvider')]
    public function malformedRequestDataIsRejectedBeforeSignatureValidation(array $data): void
    {
        self::assertNull(GenerateAltTextDemand::fromRequestData($data));
    }
}
