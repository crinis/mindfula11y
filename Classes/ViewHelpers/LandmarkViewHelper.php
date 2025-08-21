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

use MindfulMarkup\MindfulA11y\Enum\AriaLandmark;
use MindfulMarkup\MindfulA11y\Service\PermissionService;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Form\FormDataCompiler;
use TYPO3\CMS\Backend\Form\FormDataGroup\TcaDatabaseRecord;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractTagBasedViewHelper;

/**
 * Landmark ViewHelper to render semantic landmark elements.
 * 
 * This ViewHelper renders appropriate HTML landmark elements with ARIA attributes
 * and adds data attributes for backend module integration.
 * 
 * Usage examples:
 *
 * Basic usage with database fields:
 * <mindfula11y:landmark recordUid="{data.uid}" role="{data.tx_mindfula11y_landmark}" aria="{label: data.tx_mindfula11y_landmark_label, labelledby: data.tx_mindfula11y_landmark_labelledby}">{data.bodytext}</mindfula11y:landmark>
 *
 * Simple usage without database integration:
 * <mindfula11y:landmark role="main" aria="{label: 'Main content area'}">Main content</mindfula11y:landmark>
 *
 * Navigation landmark example:
 * <mindfula11y:landmark role="navigation" aria="{labelledby: 'nav-heading'}">Navigation content</mindfula11y:landmark>
 *
 * Override the HTML tag while keeping the role:
 * <mindfula11y:landmark role="navigation" tagName="div">Navigation content</mindfula11y:landmark>
 *
 * No landmark (uses div by default):
 * <mindfula11y:landmark>Regular content without landmark semantics</mindfula11y:landmark>
 *
 * No landmark with custom tag:
 * <mindfula11y:landmark tagName="section">Regular content without landmark semantics</mindfula11y:landmark>
 */
class LandmarkViewHelper extends AbstractTagBasedViewHelper
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
        $this->registerArgument('recordUid', 'int', 'The UID of the record', false, null);
        $this->registerArgument('recordTableName', 'string', 'Database table name', false, 'tt_content');
        $this->registerArgument('recordColumnName', 'string', 'Field name for landmark', false, 'tx_mindfula11y_landmark');
        $this->registerArgument('role', 'string', 'The landmark role value', false, "");
        $this->registerArgument('tagName', 'string', 'Override the HTML tag name regardless of the role', false, "");
    }

    /**
     * Set the current tag name and role based on the landmark type.
     */
    public function initialize(): void
    {
        parent::initialize();
        $this->determineElementAndRole();
    }

    /**
     * Render the landmark element.
     * 
     * This method checks if the MindfulA11y landmark structure module is active and if the user has permission to modify the landmark.
     * If so, it adds data attributes to the tag with information about the record.
     * 
     * @return string The rendered tag HTML.
     */
    public function render(): string
    {
        // Add backend module data attributes if needed
        $request = $this->getRequest();
        if (
            null !== $request
            && $request->hasHeader('Mindfula11y-Structure-Analysis')
            && $this->context->getPropertyFromAspect('backend.user', 'isLoggedIn', false)
            && null !== $this->arguments['recordUid']
            && $this->hasPermissionToModifyLandmark(
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
            $this->tag->addAttribute('data-mindfula11y-available-roles', json_encode($this->getAvailableLandmarks($request)));
        }

        $this->tag->setContent($this->renderChildren());
        return $this->tag->render();
    }

    /**
     * Determine the appropriate HTML element and role based on landmark type.
     * Always prefers native HTML elements over role attributes.
     * Uses div tag when no landmark role is defined.
     */
    protected function determineElementAndRole(): void
    {
        $role = $this->arguments['role'];
        $tagNameOverride = $this->arguments['tagName'];

        // Use tag name override if provided, regardless of role
        if (!empty($tagNameOverride)) {
            $this->tag->setTagName($tagNameOverride);
            // Add role attribute if a role is specified
            if (!empty($role)) {
                $this->tag->addAttribute('role', $role);
            }
            return;
        }

        $landmarkType = AriaLandmark::from($role);

        switch ($landmarkType) {
            case AriaLandmark::NAVIGATION:
                $this->tag->setTagName('nav');
                break;

            case AriaLandmark::MAIN:
                $this->tag->setTagName('main');
                break;

            case AriaLandmark::BANNER:
                $this->tag->setTagName('header');
                break;

            case AriaLandmark::CONTENTINFO:
                $this->tag->setTagName('footer');
                break;

            case AriaLandmark::COMPLEMENTARY:
                $this->tag->setTagName('aside');
                break;

            case AriaLandmark::SEARCH:
                $this->tag->setTagName('search');
                break;

            case AriaLandmark::FORM:
                $this->tag->setTagName('form');
                break;

            case AriaLandmark::REGION:
                $this->tag->setTagName('section');
                break;

            case AriaLandmark::NONE:
            default:
                $this->tag->setTagName('div');
                break;
        }
    }

    /**
     * Check permissions to modify the landmark.
     * 
     * Checks if the user has permission to modify the landmark of the given record.
     * This has no impact on the actual modification as those permissions are checked by DataHandler on
     * save. We still don't want to show the landmark select box if the user has no
     * permissions to modify the landmark.
     * 
     * @param int $recordUid The UID of the record.
     * @param string $recordTableName The name of the database table with the landmark.
     * @param string $recordColumnName The name of the field to store the landmark type.
     * 
     * @return bool True if the user has permission, false otherwise.
     */
    protected function hasPermissionToModifyLandmark(
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
     * Get available landmark types from the TCA configuration.
     * Uses TYPO3's FormDataCompiler to apply all processing including TSconfig filtering.
     * 
     * @param ServerRequestInterface $request The current request.
     * @return array The available landmark types as an associative array.
     */
    protected function getAvailableLandmarks(ServerRequestInterface $request): array
    {
        $landmarks = [];
        $recordTableName = $this->arguments['recordTableName'];
        $recordColumnName = $this->arguments['recordColumnName'];
        $recordUid = (int)$this->arguments['recordUid'];

        $formDataCompilerInput = [
            'request' => $request,
            'tableName' => $recordTableName,
            'vanillaUid' => $recordUid,
            'command' => 'edit',
        ];

        $formDataCompiler = GeneralUtility::makeInstance(FormDataCompiler::class);
        $formData = $formDataCompiler->compile($formDataCompilerInput, GeneralUtility::makeInstance(TcaDatabaseRecord::class));

        // Access the processed select items for the field
        if (isset($formData['processedTca']['columns'][$recordColumnName]['config']['items'])) {
            $items = $formData['processedTca']['columns'][$recordColumnName]['config']['items'];

            foreach ($items as $item) {
                // FormDataCompiler already processes labels
                $landmarks[$item['value']] = $item['label'];
            }
        }

        return $landmarks;
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
