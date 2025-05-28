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

namespace MindfulMarkup\MindfulA11y\Domain\Model;

use TYPO3\CMS\Extbase\DomainObject\AbstractValueObject;

/**
 * Class AltlessFileReferenceTable.
 *
 * Contains configuration used to select file references without alt text
 * from a specific table. Mainly used by AltlessFileReferenceRepository.
 */
class AltlessFileReferenceTable extends AbstractValueObject
{
    /**
     * Table name.
     */
    protected string $tableName = '';

    /**
     * File column names.
     * 
     * @var array<string>
     */
    protected array $fileColumnNames = [];

    /**
     * Associative array of authMode column names and their allowed values.
     * 
     * @var array<string, array<string>>
     */
    protected array $authModeColumns = [];

    /**
     * The page IDs to filter table rows by.
     * 
     * @var array<int>
     */
    protected array $pageIds = [];

    /**
     * Constructor.
     * 
     * @param string $tableName The name of the table to filter.
     * @param array<string> $fileColumnNames The names of the file columns.
     * @param array<string, array<string>> $authModeColumns The authMode columns and their allowed values.
     * @param array<int> $pageIds The page IDs to filter by.
     */
    public function __construct(
        string $tableName,
        array $fileColumnNames,
        array $authModeColumns,
        array $pageIds
    ) {
        $this->tableName = $tableName;
        $this->fileColumnNames = $fileColumnNames;
        $this->authModeColumns = $authModeColumns;
        $this->pageIds = $pageIds;
    }

    /**
     * Get the table name.
     */
    public function getTableName(): string
    {
        return $this->tableName;
    }

    /**
     * Get the file column names.
     *
     * @return array<string>
     */
    public function getFileColumnNames(): array
    {
        return $this->fileColumnNames;
    }

    /**
     * Get the authMode columns and their allowed values.
     *
     * @return array<string, array<string>>
     */
    public function getAuthModeColumns(): array
    {
        return $this->authModeColumns;
    }

    /**
     * Get the page IDs to filter by.
     *
     * @return array<int>
     */
    public function getPageIds(): array
    {
        return $this->pageIds;
    }
}
