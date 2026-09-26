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
            ->willReturn(['uid' => null, 'header' => null]);
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
}
