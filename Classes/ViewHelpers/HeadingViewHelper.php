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

namespace MindfulMarkup\MindfulA11y\ViewHelpers;

use TYPO3\CMS\Core\Database\ConnectionPool;
use MindfulMarkup\MindfulA11y\Service\PermissionService;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

/**
 * Heading ViewHelper to allow editing heading types using the heading structure module.
 *
 * This ViewHelper renders a heading element and adds data attributes with DB information
 * in case we use the heading structure backend module. The `relationId` argument can be used to cache and reference
 * the heading type for use by sibling or descendant headings.
 *
 * Usage examples:
 *
 * Basic usage with ability to edit heading type from backend module. The heading type will be fetched from the database:
 * <mindfula11y:heading recordUid="{data.uid}" recordTableName="tt_content" recordColumnName="tx_mindfula11y_headingtype">{data.header}</mindfula11y:heading>
 *
 * Recommended: Set the heading type directly (saves a database query):
 * <mindfula11y:heading recordUid="{data.uid}" recordTableName="tt_content" recordColumnName="tx_mindfula11y_headingtype" type="{data.tx_mindfula11y_headingtype}">{data.header}</mindfula11y:heading>
 *
 * Specify heading type without way to edit it: Use for dependent headings like child headings.
 * <mindfula11y:heading type="h2">{data.header}</mindfula11y:heading>
 *
 * Example using relationId for referencing in siblings/descendants:
 * <mindfula11y:heading relationId="mainHeading" type="h2">Main heading</mindfula11y:heading>
 * <mindfula11y:heading.sibling siblingId="mainHeading">Sibling at same level</mindfula11y:heading.sibling>
 * <mindfula11y:heading.descendant ancestorId="mainHeading" levels="1">Child heading</mindfula11y:heading.descendant>
 */
class HeadingViewHelper extends AbstractHeadingViewHelper
{
    /**
     * ConnectionPool instance for database access.
     */
    protected ConnectionPool $connectionPool;

    /**
     * Permission service instance.
     */
    protected readonly PermissionService $permissionService;



    /**
     * Backend Uri Builder instance.
     */
    protected readonly UriBuilder $backendUriBuilder;

    /**
     * Inject the ConnectionPool.
     */
    public function injectConnectionPool(ConnectionPool $connectionPool): void
    {
        $this->connectionPool = $connectionPool;
    }

    /**
     * Inject the permission service.
     */
    public function injectPermissionService(PermissionService $permissionService): void
    {
        $this->permissionService = $permissionService;
    }

    /**
     * Inject the UriBuilder.
     */
    public function injectBackendUriBuilder(UriBuilder $backendUriBuilder): void
    {
        $this->backendUriBuilder = $backendUriBuilder;
    }

    /**
     * Initialize the ViewHelper arguments.
     */
    public function initializeArguments(): void
    {
        parent::initializeArguments();
        $this->registerArgument('relationId', 'string', 'The relation identifier for this heading (used for caching and relationships).', false, null);
        $this->registerCommonHeadingArguments();
    }

    /**
     * Set the current tag name based on the heading type.
     */
    public function initialize(): void
    {
        parent::initialize();
        
        if (!empty($this->arguments['type'])) {
            $this->tag->setTagName($this->arguments['type']);
        } else if($this->hasRecordInformation()) {
            $headingType = $this->resolveHeadingType(
                $this->arguments['recordUid'],
                $this->arguments['recordTableName'],
                $this->arguments['recordColumnName']
            );

            if (null !== $headingType) {
                $this->tag->setTagName($headingType->value);
            }
        }

        if (!empty($this->arguments['relationId'])) {
            $this->runtimeCache->set('mindfula11y_heading_type_' . $this->arguments['relationId'], $this->tag->getTagName());
        }
    }

    /**
     * Render the heading tag.
     * 
     * This method checks if the MindfulA11y heading structure module is active and if the user has permission to modify the heading type.
     * If so, it adds data attributes to the tag with information about the record.
     * 
     * @return string The rendered tag HTML.
     */
    public function render(): string
    {
        if (
            $this->isStructureAnalysisRequest()
        ) {
            if (!empty($this->arguments['relationId'])) {
                $this->tag->addAttribute('data-mindfula11y-relation-id', $this->arguments['relationId']);
            }

            if ($this->hasRecordInformation()) {
                $this->tag->addAttribute('data-mindfula11y-record-table-name', $this->arguments['recordTableName']);
                $this->tag->addAttribute('data-mindfula11y-record-column-name', $this->arguments['recordColumnName']);
                $this->tag->addAttribute('data-mindfula11y-record-uid', $this->arguments['recordUid']);
                if ($this->hasPermissionToModifyHeadingType(
                    $this->arguments['recordUid'],
                    $this->arguments['recordTableName'],
                    $this->arguments['recordColumnName']
                )) {
                    $this->tag->addAttribute('data-mindfula11y-record-edit-link', $this->backendUriBuilder->buildUriFromRoute('record_edit', [
                        'edit' => [
                            $this->arguments['recordTableName'] => [
                                $this->arguments['recordUid'] => 'edit',
                            ],
                        ],
                    ]));

                    $this->tag->addAttribute('data-mindfula11y-available-types', json_encode($this->getHeadingTypes(
                        $this->arguments['recordTableName'],
                        $this->arguments['recordColumnName']
                    )));
                }
            }
        }
        $this->tag->setContent($this->renderChildren());
        return $this->tag->render();
    }

    /**
     * Check permissions to modify the heading type.
     * 
     * Checks if the user has permission to modify the heading type of the given record.
     * This has no impact on the actual modification as those permissions are checked by DataHandler on
     * save. We still don't want to show the heading type select box if the user has no
     * permissions to modify the heading type.
     * 
     * @param int $recordUid The UID of the record.
     * @param string $recordTableName The name of the database table with the heading.
     * @param string $recordColumnName The name of the field to store the heading type.
     * 
     * @return bool True if the user has permission, false otherwise.
     */
    protected function hasPermissionToModifyHeadingType(
        int $recordUid,
        string $recordTableName,
        string $recordColumnName
    ): bool {
        $record = BackendUtility::getRecord(
            $recordTableName,
            $recordUid,
        );
        if (null === $record) {
            return false;
        }
        return $this->permissionService->checkRecordEditAccess(
            $recordTableName,
            $record,
            [$recordColumnName],
        );
    }

    /**
     * Get available heading types from the TCA configuration.
     * 
     * @param string $recordTableName The name of the database table with the heading.
     * @param string $recordColumnName The name of the field to store the heading type.
     * @return array The available heading types as an associative array.
     */
    protected function getHeadingTypes(string $recordTableName, string $recordColumnName): array
    {
        $headingTypes = [];
        if (isset($GLOBALS['TCA'][$recordTableName]['columns'][$recordColumnName]['config']['items'])) {
            foreach ($GLOBALS['TCA'][$recordTableName]['columns'][$recordColumnName]['config']['items'] as $item) {
                $headingTypes[$item['value']] = LocalizationUtility::translate($item['label']);
            }
        }
        return $headingTypes;
    }

    // ...existing code...
}
