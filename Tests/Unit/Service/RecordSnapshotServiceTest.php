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

use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use MindfulMarkup\MindfulA11y\Service\RecordSnapshotService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * The full-row fingerprint reads the table's column list from the schema
 * manager, which Doctrine does not cache. One alt-text listing issues several
 * fingerprints per row for up to a hundred rows, so the list is read once per
 * table and reused.
 */
final class RecordSnapshotServiceTest extends TestCase
{
    #[Test]
    public function fullRowFingerprintsReadTheSchemaOncePerTable(): void
    {
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->expects(self::once())
            ->method('listTableColumns')
            ->with('tt_content')
            ->willReturn([
                'uid' => new Column('uid', Type::getType(Types::INTEGER)),
                'header' => new Column('header', Type::getType(Types::STRING)),
            ]);
        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schemaManager);
        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->method('getConnectionForTable')->with('tt_content')->willReturn($connection);

        $subject = new RecordSnapshotService($connectionPool);
        $first = $subject->fingerprint('tt_content', ['uid' => 1, 'header' => 'A']);
        $second = $subject->fingerprint('tt_content', ['uid' => 1, 'header' => 'A']);

        self::assertSame($first, $second);
        self::assertNotSame($first, $subject->fingerprint('tt_content', ['uid' => 1, 'header' => 'B']));
        // Same column list as the explicit scope: memoization must not change the digest.
        self::assertSame($first, $subject->fingerprint('tt_content', ['uid' => 1, 'header' => 'A'], ['header', 'uid']));
    }

    /**
     * DBAL keys its column listing by the lowercased (quoted) name, while
     * rows carry the schema's own case: the fingerprint must use the column
     * names, or `CType` would never match the row and always hash as missing.
     */
    #[Test]
    public function fullRowFingerprintsUseTheColumnNamesInTheirSchemaCase(): void
    {
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('listTableColumns')->willReturn([
            'uid' => new Column('uid', Type::getType(Types::INTEGER)),
            'ctype' => new Column('CType', Type::getType(Types::STRING)),
        ]);
        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schemaManager);
        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->method('getConnectionForTable')->willReturn($connection);
        $subject = new RecordSnapshotService($connectionPool);

        self::assertNotSame(
            $subject->fingerprint('tt_content', ['uid' => 1, 'CType' => 'text']),
            $subject->fingerprint('tt_content', ['uid' => 1, 'CType' => 'textmedia']),
        );
        self::assertSame(
            $subject->fingerprint('tt_content', ['uid' => 1, 'CType' => 'text']),
            $subject->fingerprint('tt_content', ['uid' => 1, 'CType' => 'text'], ['CType', 'uid']),
        );
    }
}
