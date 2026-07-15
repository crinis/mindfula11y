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

namespace MindfulMarkup\MindfulA11y\Tests\Unit\Domain\Model;

use MindfulMarkup\MindfulA11y\Domain\Model\GenerateAltTextDemand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GenerateAltTextDemandTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = str_repeat('a1b2c3d4', 12);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']);
    }

    private function createDemand(): GenerateAltTextDemand
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
        );
    }

    #[Test]
    public function freshDemandCarriesValidSignature(): void
    {
        self::assertTrue($this->createDemand()->validateSignature());
    }

    #[Test]
    public function demandSurvivesSerializationRoundTrip(): void
    {
        $data = json_decode(json_encode($this->createDemand(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        $demand = GenerateAltTextDemand::fromRequestData($data);

        self::assertNotNull($demand);
        self::assertTrue($demand->validateSignature());
        self::assertSame(3, $demand->getUserId());
        self::assertSame(42, $demand->getPageUid());
        self::assertSame(1, $demand->getLanguageUid());
        self::assertSame(2, $demand->getWorkspaceId());
        self::assertSame('tt_content', $demand->getRecordTable());
        self::assertSame(7, $demand->getRecordUid());
        self::assertSame(9, $demand->getFileUid());
        self::assertSame(['alternative'], $demand->getRecordColumns());
    }

    /** @return iterable<string, array{string, int|string|array<string>}> */
    public static function tamperedFieldProvider(): iterable
    {
        yield 'userId' => ['userId', 4];
        yield 'pageUid' => ['pageUid', 43];
        yield 'languageUid' => ['languageUid', 0];
        yield 'workspaceId' => ['workspaceId', 0];
        yield 'recordTable' => ['recordTable', 'be_users'];
        yield 'recordUid' => ['recordUid', 8];
        yield 'fileUid' => ['fileUid', 10];
        yield 'recordColumns' => ['recordColumns', ['alternative', 'password']];
        yield 'expiresAt (extended)' => ['expiresAt', PHP_INT_MAX - 1];
    }

    /** @param int|string|array<string> $value */
    #[Test]
    #[DataProvider('tamperedFieldProvider')]
    public function tamperedFieldInvalidatesSignature(string $field, int|string|array $value): void
    {
        $data = $this->createDemand()->toArray();
        $data[$field] = $value;
        $demand = GenerateAltTextDemand::fromRequestData($data);

        self::assertNotNull($demand);
        self::assertFalse($demand->validateSignature());
    }

    #[Test]
    public function expiredDemandIsRejectedDespiteValidSignature(): void
    {
        $demand = new GenerateAltTextDemand(
            userId: 3,
            pageUid: 42,
            languageUid: 0,
            workspaceId: 0,
            recordTable: 'tt_content',
            recordUid: 7,
            fileUid: 9,
            recordColumns: ['alternative'],
            expiresAt: time() - 1,
        );

        self::assertFalse($demand->validateSignature());
    }

    #[Test]
    public function expiryBeyondLifetimeWindowIsRejected(): void
    {
        $demand = new GenerateAltTextDemand(
            userId: 3,
            pageUid: 42,
            languageUid: 0,
            workspaceId: 0,
            recordTable: 'tt_content',
            recordUid: 7,
            fileUid: 9,
            recordColumns: ['alternative'],
            expiresAt: time() + GenerateAltTextDemand::LIFETIME + 60,
        );

        self::assertFalse($demand->validateSignature());
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
