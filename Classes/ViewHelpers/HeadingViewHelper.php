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

use MindfulMarkup\MindfulA11y\Service\PermissionService;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractTagBasedViewHelper;

/**
 * Heading ViewHelper to allow editinng heading levels using the heading structure module.
 * 
 * This ViewHelper renders a heading element and adds data attributes with DB information 
 * in case we use the heading structure backend module.
 * 
 * Usage examples:
 *
 * Basic usage with ability to edit heading level from backend module. Outputting the default heading field for a tt_content record:
 * <mindfula11y:heading recordUid="{data.uid}" recordTableName="tt_content" recordColumnName="tx_mindfula11y_headinglevel" level="{data.tx_mindfula11y_headinglevel}" fallbackTag="p">{data.header}</mindfula11y:heading>
 *
 * Specify heading level without way to edit it: Use for dependent headings like child headings.
 * <mindfula11y:heading level="{data.tx_mindfula11y_headinglevel + 1}">{data.header}</mindfula11y:heading>
 */
class HeadingViewHelper extends AbstractTagBasedViewHelper
{
    /**
     * Permission service instance.
     */
    protected readonly PermissionService $permissionService;

    /**
     * Context object with information about the current request and user.
     */
    protected readonly Context $context;

    /**
     * Backend Uri Builder instance.
     */
    protected readonly UriBuilder $backendUriBuilder;

    /**
     * The default heading level if not specified and not found in the record.
     */
    public const DEFAULT_LEVEL = 2;

    /**
     * Inject Context object.
     */
    public function injectContext(Context $context): void
    {
        $this->context = $context;
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
        $this->registerArgument('fallbackTag', 'string', 'The fallback tag to use if level is set to -1. Defaults to p', false, 'p');
        $this->registerArgument('recordTableName', 'string', 'The name of the database table with the heading. Defaults to tt_content', false, 'tt_content');
        $this->registerArgument('recordColumnName', 'string', 'The name of the field to store the heading type. Defaults to tx_mindfula11y_headinglevel.', false, 'tx_mindfula11y_headinglevel');
        $this->registerArgument('recordUid', 'int', 'The UID of the record to edit the heading level.', false, null);
        $this->registerArgument('level', 'int', 'The heading level to use. If not specified, will use the value from the database.', true);
    }

    /**
     * Set the current tag name based on the heading level and fallback tag.
     */
    public function initialize(): void
    {
        parent::initialize();

        if (-1 === $this->arguments['level']) {
            $this->tag->setTagName($this->arguments['fallbackTag']);
        } else {
            $this->tag->setTagName('h' . $this->arguments['level']);
        }
    }

    /**
     * Render the heading tag.
     * 
     * This method checks if the MindfulA11y heading structure module is active and if the user has permission to modify the heading level.
     * If so, it adds data attributes to the tag with information about the record.
     * 
     * @return string The rendered tag HTML.
     */
    public function render(): string
    {
        $request = $this->getRequest();
        if (
            null !== $request
            && $request->hasHeader('Mindfula11y-Structure-Analysis')
            && $this->context->getPropertyFromAspect('backend.user', 'isLoggedIn', false)
            && null !== $this->arguments['recordUid']
            && $this->hasPermissionToModifyHeadingLevel(
                $this->arguments['recordUid'],
                $this->arguments['recordTableName'],
                $this->arguments['recordColumnName']
            )
        ) {
            $this->tag->addAttribute('data-mindfula11y-record-table-name', $this->arguments['recordTableName']);
            $this->tag->addAttribute('data-mindfula11y-record-column-name', $this->arguments['recordColumnName']);
            $this->tag->addAttribute('data-mindfula11y-record-uid', $this->arguments['recordUid']);
            $this->tag->addAttribute('data-mindfula11y-record-edit-link', $this->backendUriBuilder->buildUriFromRoute('record_edit', [
                'edit' => [
                    $this->arguments['recordTableName'] => [
                        $this->arguments['recordUid'] => 'edit',
                    ],
                ],
            ]));
            $this->tag->addAttribute('data-mindfula11y-level', $this->arguments['level']);
            $this->tag->addAttribute('data-mindfula11y-available-levels', json_encode($this->getHeadingLevels(
                $this->arguments['recordTableName'],
                $this->arguments['recordColumnName']
            )));
        }

        $this->tag->setContent($this->renderChildren());
        return $this->tag->render();
    }

    /**
     * Check permissions to modify the heading level.
     * 
     * Checks if the user has permission to modify the heading level of the given record.
     * This has no impact on the actual modification as those permissions are checked by DataHandler on
     * save. We still don't want to show the heading level select box if the user has no
     * permissions to modify the heading level.
     * 
     * @param int $recordUid The UID of the record.
     * @param string $recordTableName The name of the database table with the heading.
     * @param string $recordColumnName The name of the field to store the heading type.
     * 
     * @return bool True if the user has permission, false otherwise.
     */
    protected function hasPermissionToModifyHeadingLevel(
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
     * Get available heading levels from the TCA configuration.
     * 
     * @param string $recordTableName The name of the database table with the heading.
     * @param string $recordColumnName The name of the field to store the heading type.
     * @return array The available heading levels as an associative array.
     */
    protected function getHeadingLevels(string $recordTableName, string $recordColumnName): array
    {
        $headingLevels = [];
        if (isset($GLOBALS['TCA'][$recordTableName]['columns'][$recordColumnName]['config']['items'])) {
            foreach ($GLOBALS['TCA'][$recordTableName]['columns'][$recordColumnName]['config']['items'] as $item) {
                if (-1 === $item['value']) {
                    continue;
                }
                $headingLevels[$item['value']] = LocalizationUtility::translate($item['label']);
            }
        }
        return $headingLevels;
    }

    /**
     * Get the current request from the rendering context.
     * 
     * @return ServerRequestInterface|null The current request or null if not available.
     */
    protected function getRequest(): ?ServerRequestInterface
    {
        if ($this->renderingContext->hasAttribute(ServerRequestInterface::class)) {
            return $this->renderingContext->getAttribute(ServerRequestInterface::class);
        }
        return null;
    }
}
