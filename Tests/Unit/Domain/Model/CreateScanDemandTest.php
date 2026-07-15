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

use MindfulMarkup\MindfulA11y\Domain\Model\CreateScanDemand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CreateScanDemandTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = str_repeat('a1b2c3d4', 12);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']);
    }

    private function createDemand(): CreateScanDemand
    {
        return new CreateScanDemand(
            userId: 3,
            pageId: 42,
            previewUrl: 'https://example.com/page?type=0',
            languageId: 1,
            workspaceId: 2,
            pageLevels: 5,
            crawl: false,
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
        $demand = CreateScanDemand::fromRequestData($data);

        self::assertNotNull($demand);
        self::assertTrue($demand->validateSignature());
        self::assertSame(3, $demand->getUserId());
        self::assertSame(42, $demand->getPageId());
        self::assertSame('https://example.com/page?type=0', $demand->getPreviewUrl());
        self::assertSame(1, $demand->getLanguageId());
        self::assertSame(2, $demand->getWorkspaceId());
        self::assertSame(5, $demand->getPageLevels());
        self::assertFalse($demand->getCrawl());
    }

    /** @return iterable<string, array{string, int|string|bool}> */
    public static function tamperedFieldProvider(): iterable
    {
        yield 'userId' => ['userId', 4];
        yield 'pageId' => ['pageId', 43];
        yield 'previewUrl' => ['previewUrl', 'https://evil.example/page'];
        yield 'languageId' => ['languageId', 0];
        yield 'workspaceId' => ['workspaceId', 0];
        yield 'pageLevels' => ['pageLevels', 99];
        yield 'crawl' => ['crawl', true];
        yield 'expiresAt (extended)' => ['expiresAt', PHP_INT_MAX - 1];
    }

    #[Test]
    #[DataProvider('tamperedFieldProvider')]
    public function tamperedFieldInvalidatesSignature(string $field, int|string|bool $value): void
    {
        $data = $this->createDemand()->toArray();
        $data[$field] = $value;
        $demand = CreateScanDemand::fromRequestData($data);

        self::assertNotNull($demand);
        self::assertFalse($demand->validateSignature());
    }

    #[Test]
    public function expiredDemandIsRejectedDespiteValidSignature(): void
    {
        // The signature covers the past expiry, so it is formally intact.
        $demand = new CreateScanDemand(
            userId: 3,
            pageId: 42,
            previewUrl: 'https://example.com/page',
            languageId: 0,
            workspaceId: 0,
            expiresAt: time() - 1,
        );

        self::assertFalse($demand->validateSignature());
    }

    #[Test]
    public function expiryBeyondLifetimeWindowIsRejected(): void
    {
        $demand = new CreateScanDemand(
            userId: 3,
            pageId: 42,
            previewUrl: 'https://example.com/page',
            languageId: 0,
            workspaceId: 0,
            expiresAt: time() + CreateScanDemand::LIFETIME + 60,
        );

        self::assertFalse($demand->validateSignature());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidRequestDataProvider(): iterable
    {
        $valid = [
            'userId' => 3,
            'pageId' => 42,
            'previewUrl' => 'https://example.com/page',
            'expiresAt' => 2000000000,
            'signature' => 'abc',
        ];
        yield 'empty payload' => [[]];
        yield 'missing signature' => [array_diff_key($valid, ['signature' => ''])];
        yield 'empty signature' => [['signature' => ''] + $valid];
        yield 'missing previewUrl' => [array_diff_key($valid, ['previewUrl' => ''])];
        yield 'non-string previewUrl' => [['previewUrl' => 42] + $valid];
        yield 'empty previewUrl' => [['previewUrl' => ''] + $valid];
        yield 'zero userId' => [['userId' => 0] + $valid];
        yield 'zero pageId' => [['pageId' => 0] + $valid];
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
