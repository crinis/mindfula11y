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

use MindfulMarkup\MindfulA11y\Domain\Model\AltlessFileReference;
use MindfulMarkup\MindfulA11y\Domain\Model\AltTextDemand;
use MindfulMarkup\MindfulA11y\Service\PermissionService;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractTagBasedViewHelper;

/**
 * Class AltlessFileReferenceViewHelper.
 * 
 * Renders an altless-file-reference web component and takes care of permissions
 * required. This is only to be used in the backend.
 */
class AltlessFileReferenceViewHelper extends AbstractTagBasedViewHelper
{
    /**
     * Permission service instance.
     */
    protected readonly PermissionService $permissionService;

    /**
     * Backend Uri Builder instance.
     */
    protected readonly UriBuilder $backendUriBuilder;


    /**
     * Tag name.
     */
    protected $tagName = 'mindfula11y-altless-file-reference';

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
        $this->registerArgument('fileReference', AltlessFileReference::class, 'Altless file reference record to display.', true);
        $this->registerArgument('previewUrl', 'string', 'The URL to the preview of the file reference.', false, '');
        $this->registerArgument('originalUrl', 'string', 'The URL to the original file reference.', false, '');
    }

    /**
     * Render the altless-file-reference web component.
     */
    public function render(): string
    {
        /**
         * @var AltlessFileReference $fileReference
         */
        $fileReference = $this->arguments['fileReference'];

        $recordTableName = $fileReference->getOriginalResource()->getReferenceProperty('tablenames');
        $recordUid = $fileReference->getOriginalResource()->getReferenceProperty('uid_foreign');
        $this->tag->addAttribute('recordEditLink', $this->backendUriBuilder->buildUriFromRoute('record_edit', [
            'edit' => [
                $recordTableName => [
                    $recordUid => 'edit'
                ]
            ],
        ]));
        $this->tag->addAttribute('recordEditLinkLabel', sprintf($this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/MissingAltText.xlf:editRecord.label'), $recordTableName, $recordUid));

        $this->tag->addAttribute('altTextDemand', json_encode($this->getAltTextDemand($fileReference)));
        $this->tag->addAttribute('uid', $fileReference->getUid());

        if ($this->permissionService->checkTableReadAccess('sys_file_metadata')) {
            $this->tag->addAttribute('fallbackAlternative', $fileReference->getOriginalResource()->getOriginalFile()->getProperty('alternative'));
        }

        if (!empty($this->arguments['previewUrl'])) {
            $this->tag->addAttribute('previewUrl', $this->arguments['previewUrl']);
        }
        if (!empty($this->arguments['originalUrl'])) {
            $this->tag->addAttribute('originalUrl', $this->arguments['originalUrl']);
        }

        return $this->tag->render();
    }

    /**
     * Get alt text demand used for generating the alt text.
     */
    protected function getAltTextDemand(
        AltlessFileReference $fileReference
    ): AltTextDemand {
        return new AltTextDemand(
            $fileReference->getPid(),
            $fileReference->getOriginalResource()->getReferenceProperty('sys_language_uid'),
            $fileReference->getOriginalResource()->getReferenceProperty('uid_local'),
        );
    }

    /**
     * Get language service.
     * 
     * @return LanguageService
     */
    protected function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
