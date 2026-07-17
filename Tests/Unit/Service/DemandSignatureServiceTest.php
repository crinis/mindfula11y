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

namespace MindfulMarkup\MindfulA11y\Tests\Unit\Service;

use MindfulMarkup\MindfulA11y\Domain\Model\CreateScanDemand;
use MindfulMarkup\MindfulA11y\Domain\Model\GenerateAltTextDemand;
use MindfulMarkup\MindfulA11y\Service\DemandSignatureService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Crypto\HashService;

/**
 * The single HMAC authority for the session-bound demands: issuance signs at
 * serialization time, redemption re-derives and compares. The signature bytes
 * are a wire contract with already-rendered demands — the byte-compatibility
 * test below pins payload order and HMAC domain (the demand FQCN).
 */
final class DemandSignatureServiceTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = str_repeat('a1b2c3d4', 12);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']);
    }

    private function demandSignatureService(): DemandSignatureService
    {
        return new DemandSignatureService(new HashService());
    }

    private function createDemand(int $expiresAt = 0): CreateScanDemand
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
        );
    }

    #[Test]
    public function serializedDemandValidatesAfterRoundTrip(): void
    {
        $service = $this->demandSignatureService();
        $data = json_decode(
            json_encode($service->serialize($this->createDemand()), JSON_THROW_ON_ERROR),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $demand = CreateScanDemand::fromRequestData($data);

        self::assertNotNull($demand);
        self::assertTrue($service->isValid($demand));
    }

    #[Test]
    public function signaturePinsTheRenderedWireContract(): void
    {
        // Payload encoding, segment order and the FQCN HMAC domain must never
        // change silently: demands rendered into still-open backend markup
        // validate against them. Changing any of it is a signing-context bump
        // that fails all previously rendered demands closed.
        $demand = $this->createDemand(expiresAt: 2000000000);
        $expected = (new HashService())->hmac(
            json_encode(
                ['3', '42', 'https://example.com/page?type=0', '1', '2', str_repeat('a', 64), '5', '0', '2000000000'],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
            ),
            CreateScanDemand::class
        );

        self::assertSame($expected, $this->demandSignatureService()->serialize($demand)['signature']);
    }

    #[Test]
    public function unsignedDemandDoesNotValidate(): void
    {
        self::assertFalse($this->demandSignatureService()->isValid($this->createDemand()));
    }

    #[Test]
    public function serializeOverridesAStoredSignature(): void
    {
        $service = $this->demandSignatureService();
        $tampered = CreateScanDemand::fromRequestData(
            ['signature' => str_repeat('f', 64)] + $service->serialize($this->createDemand())
        );

        self::assertNotNull($tampered);
        self::assertFalse($service->isValid($tampered));
        self::assertNotSame(str_repeat('f', 64), $service->serialize($tampered)['signature']);
        self::assertTrue($service->isValid(CreateScanDemand::fromRequestData($service->serialize($tampered))));
    }

    /** @return iterable<string, array{string, int|string|bool}> */
    public static function tamperedFieldProvider(): iterable
    {
        yield 'userId' => ['userId', 4];
        yield 'pageId' => ['pageId', 43];
        yield 'previewUrl' => ['previewUrl', 'https://evil.example/page'];
        yield 'languageId' => ['languageId', 0];
        yield 'workspaceId' => ['workspaceId', 0];
        yield 'pageRecordSnapshot' => ['pageRecordSnapshot', str_repeat('b', 64)];
        yield 'pageLevels' => ['pageLevels', 99];
        yield 'crawl' => ['crawl', true];
        yield 'expiresAt (extended)' => ['expiresAt', PHP_INT_MAX - 1];
    }

    #[Test]
    #[DataProvider('tamperedFieldProvider')]
    public function tamperedFieldInvalidatesSignature(string $field, int|string|bool $value): void
    {
        $service = $this->demandSignatureService();
        $data = $service->serialize($this->createDemand());
        $data[$field] = $value;
        $demand = CreateScanDemand::fromRequestData($data);

        self::assertNotNull($demand);
        self::assertFalse($service->isValid($demand));
    }

    #[Test]
    public function expiredDemandIsRejectedDespiteValidSignature(): void
    {
        $service = $this->demandSignatureService();
        // The signature covers the past expiry, so it is formally intact.
        $demand = CreateScanDemand::fromRequestData($service->serialize($this->createDemand(expiresAt: time() - 1)));

        self::assertNotNull($demand);
        self::assertFalse($service->isValid($demand));
    }

    #[Test]
    public function expiryBeyondLifetimeWindowIsRejected(): void
    {
        $service = $this->demandSignatureService();
        $demand = CreateScanDemand::fromRequestData(
            $service->serialize($this->createDemand(expiresAt: time() + CreateScanDemand::LIFETIME + 60))
        );

        self::assertNotNull($demand);
        self::assertFalse($service->isValid($demand));
    }

    /** @return iterable<string, array{string, int|string|array<string>}> */
    public static function tamperedAltTextFieldProvider(): iterable
    {
        yield 'recordTable' => ['recordTable', 'be_users'];
        yield 'recordUid' => ['recordUid', 8];
        yield 'fileUid' => ['fileUid', 10];
        yield 'fileReferenceUid' => ['fileReferenceUid', 6];
        yield 'fileSnapshot' => ['fileSnapshot', str_repeat('e', 64)];
        yield 'recordSnapshot' => ['recordSnapshot', str_repeat('c', 64)];
        yield 'fileReferenceSnapshot' => ['fileReferenceSnapshot', str_repeat('d', 64)];
        yield 'recordColumns' => ['recordColumns', ['alternative', 'password']];
    }

    /** @param int|string|array<string> $value */
    #[Test]
    #[DataProvider('tamperedAltTextFieldProvider')]
    public function tamperedAltTextFieldInvalidatesSignature(string $field, int|string|array $value): void
    {
        $service = $this->demandSignatureService();
        $data = $service->serialize(new GenerateAltTextDemand(
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
        ));
        $data[$field] = $value;
        $demand = GenerateAltTextDemand::fromRequestData($data);

        self::assertNotNull($demand);
        self::assertFalse($service->isValid($demand));
    }

    #[Test]
    public function signatureNeverValidatesAcrossDemandTypes(): void
    {
        // The HMAC domain is the concrete demand class: even a hypothetical
        // identical payload signed for one type must fail for the other.
        $service = $this->demandSignatureService();
        $altTextDemand = new GenerateAltTextDemand(
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
            recordColumns: ['image'],
            expiresAt: 2000000000,
        );

        self::assertNotSame(
            $service->serialize($this->createDemand(expiresAt: 2000000000))['signature'],
            $service->serialize($altTextDemand)['signature']
        );
    }

    #[Test]
    public function structuredColumnScopesCannotCollideThroughDelimiters(): void
    {
        $service = $this->demandSignatureService();
        $create = static fn(array $columns): GenerateAltTextDemand => new GenerateAltTextDemand(
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
            recordColumns: $columns,
            expiresAt: 2000000000,
        );

        self::assertNotSame(
            $service->serialize($create(['assets,media']))['signature'],
            $service->serialize($create(['assets', 'media']))['signature'],
        );
    }
}
