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
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 */

namespace MindfulMarkup\MindfulA11y\Upgrades;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Install\Attribute\UpgradeWizard;
use TYPO3\CMS\Install\Updates\DatabaseUpdatedPrerequisite;
use TYPO3\CMS\Install\Updates\UpgradeWizardInterface;
use Doctrine\DBAL\ParameterType;

/**
 * Migration wizard for migrating data from tx_mindfula11y_headinglevel to tx_mindfula11y_headingtype.
 * 
 * This wizard migrates data from the old numeric heading level field (tx_mindfula11y_headinglevel)
 * to the new string-based heading type field (tx_mindfula11y_headingtype), converting numeric 
 * values (1, 2, 3, 4, 5, 6, -1) to HTML tag names (h1, h2, h3, h4, h5, h6, p).
 */
#[UpgradeWizard('mindfulA11yHeadingTypeStringMigration')]
class HeadingTypeStringMigrationWizard implements UpgradeWizardInterface
{
    private const TABLE_NAME = 'tt_content';
    private const OLD_FIELD_NAME = 'tx_mindfula11y_headinglevel';
    private const NEW_FIELD_NAME = 'tx_mindfula11y_headingtype';

    /**
     * Mapping from old integer values to new string values.
     * 
     * @var array<int, string>
     */
    private const VALUE_MAPPING = [
        1 => 'h1',
        2 => 'h2', 
        3 => 'h3',
        4 => 'h4',
        5 => 'h5',
        6 => 'h6',
        -1 => 'p', // Fallback tag becomes paragraph
    ];

    /**
     * Get the upgrade wizard identifier.
     */
    public function getIdentifier(): string
    {
        return 'mindfulA11yHeadingTypeStringMigration';
    }

    /**
     * Get the upgrade wizard title.
     */
    public function getTitle(): string
    {
        return 'Mindful A11y: Migrate heading type data from old to new field';
    }

    /**
     * Get the upgrade wizard description.
     */
    public function getDescription(): string
    {
        return 'Migrates heading type data from numeric values (tx_mindfula11y_headinglevel) to string-based heading types (tx_mindfula11y_headingtype). This migration copies the data to the new field and converts numeric values to appropriate string values (e.g., 1 → h1, 2 → h2, -1 → p).';
    }

    /**
     * Execute the actual upgrade.
     */
    public function executeUpdate(): bool
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable(self::TABLE_NAME);

        // First, get all records that have data in the old field but not in the new field
        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $records = $queryBuilder
            ->select('uid', self::OLD_FIELD_NAME)
            ->from(self::TABLE_NAME)
            ->where(
                $queryBuilder->expr()->and(
                    $queryBuilder->expr()->isNotNull(self::OLD_FIELD_NAME),
                    $queryBuilder->expr()->neq(self::OLD_FIELD_NAME, $queryBuilder->createNamedParameter(''))
                )
            )
            ->executeQuery();

        $updatedCount = 0;
        while ($record = $records->fetchAssociative()) {
            $oldValue = $record[self::OLD_FIELD_NAME];
            $newValue = $this->convertOldValueToNewValue($oldValue);
            
            if ($newValue !== null) {
                // Update the record with the new value
                $updateBuilder = $connection->createQueryBuilder();
                $updateBuilder
                    ->update(self::TABLE_NAME)
                    ->where(
                        $updateBuilder->expr()->eq('uid', $updateBuilder->createNamedParameter($record['uid'], ParameterType::INTEGER))
                    )
                    ->set(self::NEW_FIELD_NAME, $newValue)
                    ->executeStatement();
                    
                $updatedCount++;
            }
        }

        return true;
    }

    /**
     * Convert old numeric value to new string value.
     */
    private function convertOldValueToNewValue($oldValue): ?string
    {
        // Handle both integer and string representations of old values
        if (is_numeric($oldValue)) {
            $intValue = (int)$oldValue;
            return self::VALUE_MAPPING[$intValue] ?? null;
        }
        
        // If it's already a string, check if it's a valid numeric string
        if (is_string($oldValue) && is_numeric($oldValue)) {
            $intValue = (int)$oldValue;
            return self::VALUE_MAPPING[$intValue] ?? null;
        }
        
        // If it's already a valid heading type, keep it
        if (is_string($oldValue) && in_array($oldValue, self::VALUE_MAPPING, true)) {
            return $oldValue;
        }
        
        return null;
    }

    /**
     * Check if this wizard needs to be executed.
     */
    public function updateNecessary(): bool
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable(self::TABLE_NAME);

        // Check if the old field exists
        $schemaManager = $connection->createSchemaManager();
        $tableDetails = $schemaManager->introspectTable(self::TABLE_NAME);
        
        if (!$tableDetails->hasColumn(self::OLD_FIELD_NAME)) {
            // If old field doesn't exist, migration is not necessary
            return false;
        }

        // Check if the new field exists
        if (!$tableDetails->hasColumn(self::NEW_FIELD_NAME)) {
            // If new field doesn't exist, the TCA/schema update needs to run first
            return false;
        }

        // Check if there are any records with data in the old field that need migration
        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        // Count records that have data in the old field but haven't been migrated yet
        $count = $queryBuilder
            ->count('uid')
            ->from(self::TABLE_NAME)
            ->where(
                $queryBuilder->expr()->and(
                    $queryBuilder->expr()->isNotNull(self::OLD_FIELD_NAME),
                    $queryBuilder->expr()->neq(self::OLD_FIELD_NAME, $queryBuilder->createNamedParameter('')),
                    // Only migrate if the new field is empty or null (hasn't been migrated yet)
                    $queryBuilder->expr()->or(
                        $queryBuilder->expr()->isNull(self::NEW_FIELD_NAME),
                        $queryBuilder->expr()->eq(self::NEW_FIELD_NAME, $queryBuilder->createNamedParameter(''))
                    )
                )
            )
            ->executeQuery()
            ->fetchOne();

        return $count > 0;
    }

    /**
     * Get prerequisite classes.
     * 
     * @return array<string>
     */
    public function getPrerequisites(): array
    {
        return [
            DatabaseUpdatedPrerequisite::class,
        ];
    }
}
