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

namespace MindfulMarkup\MindfulA11y\Tests\Functional\Upgrades;

use MindfulMarkup\MindfulA11y\Upgrades\HeadingTypeStringMigrationWizard;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The legacy tx_mindfula11y_headinglevel column is no longer part of the
 * extension's schema, so setUp() recreates it the way the old TCA generated
 * it: an INTEGER column defaulting to 0 (= unset, must not migrate).
 */
final class HeadingTypeStringMigrationWizardTest extends FunctionalTestCase
{
    private const OLD_FIELD = 'tx_mindfula11y_headinglevel';
    private const NEW_FIELD = 'tx_mindfula11y_headingtype';

    protected array $testExtensionsToLoad = [
        'mindfulmarkup/mindfula11y',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $connection = $this->connection();
        if (!$connection->createSchemaManager()->introspectTable('tt_content')->hasColumn(self::OLD_FIELD)) {
            $connection->executeStatement(
                'ALTER TABLE tt_content ADD COLUMN ' . self::OLD_FIELD . ' INTEGER DEFAULT 0 NOT NULL'
            );
        }
    }

    public function testMigratesSetLevelsAndSettlesAfterOneRun(): void
    {
        $levels = [1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 5, 6 => 6, 7 => -1, 8 => 0];
        foreach ($levels as $uid => $level) {
            $this->insertContent($uid, $level, null);
        }
        // Already migrated (or set manually since): never overwritten.
        $this->insertContent(9, 3, 'h4');
        // Deleted rows are left alone.
        $this->insertContent(10, 2, null, 1);

        $subject = $this->subject();
        self::assertTrue($subject->updateNecessary());
        self::assertTrue($subject->executeUpdate());

        $expected = [1 => 'h1', 2 => 'h2', 3 => 'h3', 4 => 'h4', 5 => 'h5', 6 => 'h6', 7 => 'p', 8 => null, 9 => 'h4', 10 => null];
        self::assertSame($expected, $this->headingTypes());

        // A second run finds nothing left to do and changes nothing.
        self::assertFalse($subject->updateNecessary());
        self::assertTrue($subject->executeUpdate());
        self::assertSame($expected, $this->headingTypes());
    }

    public function testUnsetLevelIsNotPendingWork(): void
    {
        $this->insertContent(1, 0, null);
        $this->insertContent(2, 0, '');

        self::assertFalse($this->subject()->updateNecessary());
    }

    private function subject(): HeadingTypeStringMigrationWizard
    {
        return GeneralUtility::makeInstance(HeadingTypeStringMigrationWizard::class);
    }

    private function connection(): Connection
    {
        return $this->get(ConnectionPool::class)->getConnectionForTable('tt_content');
    }

    private function insertContent(int $uid, int $level, ?string $headingType, int $deleted = 0): void
    {
        $this->connection()->insert('tt_content', [
            'uid' => $uid,
            'pid' => 1,
            'deleted' => $deleted,
            self::OLD_FIELD => $level,
            self::NEW_FIELD => $headingType,
        ]);
    }

    /**
     * @return array<int, string|null>
     */
    private function headingTypes(): array
    {
        $queryBuilder = $this->connection()->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll();
        $rows = $queryBuilder->select('uid', self::NEW_FIELD)
            ->from('tt_content')
            ->orderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_combine(
            array_map(static fn(array $row): int => (int)$row['uid'], $rows),
            array_map(static fn(array $row): ?string => $row[self::NEW_FIELD], $rows),
        );
    }
}
