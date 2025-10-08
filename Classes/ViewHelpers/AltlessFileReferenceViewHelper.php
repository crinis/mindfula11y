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
use MindfulMarkup\MindfulA11y\Domain\Model\GenerateAltTextDemand;
use MindfulMarkup\MindfulA11y\Service\OpenAIService;
use MindfulMarkup\MindfulA11y\Service\PermissionService;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
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
     * OpenAI service instance.
     */
    protected readonly OpenAIService $openAIService;

    /**
     * Backend Uri Builder instance.
     */
    protected readonly UriBuilder $backendUriBuilder;

    /**
     * ExtensionConfiguration instance.
     */
    protected readonly ExtensionConfiguration $extensionConfiguration;

    /**
     * Tag name.
     */
    protected $tagName = 'mindfula11y-altless-file-reference';

    /**
     * Inject permission service.
     */
    public function injectPermissionService(PermissionService $permissionService): void
    {
        $this->permissionService = $permissionService;
    }

    /**
     * Inject OpenAI service.
     */
    public function injectOpenAIService(OpenAIService $openAIService): void
    {
        $this->openAIService = $openAIService;
    }

    /**
     * Inject UriBuilder.
     */
    public function injectBackendUriBuilder(UriBuilder $backendUriBuilder): void
    {
        $this->backendUriBuilder = $backendUriBuilder;
    }

    /**
     * Inject ExtensionConfiguration.
     */
    public function injectAltTextGeneratorService(ExtensionConfiguration $extensionConfiguration): void
    {
        $this->extensionConfiguration = $extensionConfiguration;
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
        $recordColumnName = $fileReference->getOriginalResource()->getReferenceProperty('fieldname');
        $recordUid = $fileReference->getOriginalResource()->getReferenceProperty('uid_foreign');

        $record = BackendUtility::getRecordWSOL($recordTableName, (int)$recordUid);

        if (
            $this->permissionService->checkTableWriteAccess('sys_file_reference')
            && $this->permissionService->checkNonExcludeFields('sys_file_reference', ['alternative'])
            && !empty($recordTableName)
            && !empty($recordColumnName)
            && null !== $record
            && $this->permissionService->checkRecordEditAccess($recordTableName, $record, [$recordColumnName])
        ) {
            $this->tag->addAttribute('recordEditLink', $this->backendUriBuilder->buildUriFromRoute('record_edit', [
                'edit' => [
                    $recordTableName => [
                        $recordUid => 'edit'
                    ]
                ],
            ]));
            $this->tag->addAttribute('recordEditLinkLabel', sprintf($this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:missingAltText.editRecord.label'), $recordTableName, $recordUid));
            if (
                $this->openAIService->isEnabledAndConfigured()
            ) {
                $this->tag->addAttribute(
                    'generateAltTextDemand',
                    json_encode($this->getGenerateAltTextDemand($fileReference))
                );
            }
        }

        $this->tag->addAttribute('uid', $fileReference->getUid());

        if ($this->permissionService->checkTableReadAccess('sys_file_metadata') && $this->permissionService->checkNonExcludeFields('sys_file_metadata', ['alternative'])) {
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
    protected function getGenerateAltTextDemand(
        AltlessFileReference $fileReference
    ): GenerateAltTextDemand {
        $backendUser = $this->getBackendUserAuthentication();
        $recordTableName = $fileReference->getOriginalResource()->getReferenceProperty('tablenames');
        $recordColumnName = $fileReference->getOriginalResource()->getReferenceProperty('fieldname');
        $recordUid = $fileReference->getOriginalResource()->getReferenceProperty('uid_foreign');
        return new GenerateAltTextDemand(
            $backendUser->user['uid'],
            $fileReference->getPid(),
            $fileReference->getOriginalResource()->getReferenceProperty('sys_language_uid'),
            $backendUser->workspace,
            $recordTableName,
            $recordUid,
            [$recordColumnName],
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

    /**
     * Get backend user authentication.
     * 
     * @return \TYPO3\CMS\Core\Authentication\BackendUserAuthentication
     */
    protected function getBackendUserAuthentication(): \TYPO3\CMS\Core\Authentication\BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }
}
