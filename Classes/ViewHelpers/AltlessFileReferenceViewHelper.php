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
use MindfulMarkup\MindfulA11y\Service\BackendUserProvider;
use MindfulMarkup\MindfulA11y\Service\DemandSignatureService;
use MindfulMarkup\MindfulA11y\Service\OpenAIService;
use MindfulMarkup\MindfulA11y\Service\PermissionService;
use MindfulMarkup\MindfulA11y\Service\ModuleSettingsService;
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

    protected readonly ModuleSettingsService $moduleSettingsService;

    /**
     * OpenAI service instance.
     */
    protected readonly OpenAIService $openAIService;

    /**
     * Backend Uri Builder instance.
     */
    protected readonly UriBuilder $backendUriBuilder;

    /**
     * Backend user provider instance.
     */
    protected readonly BackendUserProvider $backendUserProvider;

    /**
     * Demand signature service instance.
     */
    protected readonly DemandSignatureService $demandSignatureService;

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

    public function injectModuleSettingsService(ModuleSettingsService $moduleSettingsService): void
    {
        $this->moduleSettingsService = $moduleSettingsService;
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
     * Inject backend user provider.
     */
    public function injectBackendUserProvider(BackendUserProvider $backendUserProvider): void
    {
        $this->backendUserProvider = $backendUserProvider;
    }

    /**
     * Inject demand signature service.
     */
    public function injectDemandSignatureService(DemandSignatureService $demandSignatureService): void
    {
        $this->demandSignatureService = $demandSignatureService;
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

        // Custom elements must not be self-closed — HTML has no self-closing
        // syntax for them, so `<tag />` would leave the element open.
        $this->tag->forceClosingTag(true);

        [$recordTableName, $recordColumnName, $recordUid] = $this->getRecordCoordinates($fileReference);

        $record = BackendUtility::getRecordWSOL($recordTableName, (int)$recordUid);

        if (
            $this->permissionService->checkTableWriteAccess('sys_file_reference')
            && $this->permissionService->checkNonExcludeFields('sys_file_reference', ['alternative'])
            && !empty($recordTableName)
            && !empty($recordColumnName)
            && null !== $record
            && $this->permissionService->checkRecordEditAccess($recordTableName, $record, [$recordColumnName])
        ) {
            $this->tag->addAttribute('record-edit-link', $this->backendUriBuilder->buildUriFromRoute('record_edit', [
                'edit' => [
                    $recordTableName => [
                        $recordUid => 'edit'
                    ]
                ],
            ]));
            $this->tag->addAttribute('record-edit-link-label', sprintf($this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:altText.editRecord.label'), $recordTableName, $recordUid));
            if ($this->permissionService->checkNonExcludeFields('sys_file_reference', ['tx_mindfula11y_decorative'])) {
                $this->tag->addAttribute('decorative-editable', true);
            }
            if (
                $this->openAIService->isEnabledAndConfigured()
            ) {
                $this->tag->addAttribute(
                    'generate-alt-text-demand',
                    json_encode($this->demandSignatureService->serialize($this->getGenerateAltTextDemand($fileReference)))
                );
            }
        }

        $this->tag->addAttribute('uid', $fileReference->getUid());

        if ($this->moduleSettingsService->canReadFileMetadataAlternative()) {
            $fallbackAlternative = $fileReference->getOriginalResource()->getOriginalFile()->getProperty('alternative');
            if (is_string($fallbackAlternative) && '' !== $fallbackAlternative) {
                $this->tag->addAttribute('fallback-alternative', $fallbackAlternative);
            }
        }

        if (!empty($this->arguments['previewUrl'])) {
            $this->tag->addAttribute('preview-url', $this->arguments['previewUrl']);
        }
        if (!empty($this->arguments['originalUrl'])) {
            $this->tag->addAttribute('original-url', $this->arguments['originalUrl']);
        }

        return $this->tag->render();
    }

    /**
     * The record coordinates (table, column, uid) the file reference points at.
     *
     * @return array{0: string, 1: string, 2: int}
     */
    protected function getRecordCoordinates(AltlessFileReference $fileReference): array
    {
        $reference = $fileReference->getOriginalResource();

        // Cast at the boundary: reference properties may arrive string-typed
        // from the driver, and GenerateAltTextDemand declares int under
        // strict_types.
        return [
            (string)$reference->getReferenceProperty('tablenames'),
            (string)$reference->getReferenceProperty('fieldname'),
            (int)$reference->getReferenceProperty('uid_foreign'),
        ];
    }

    /**
     * Get alt text demand used for generating the alt text.
     */
    protected function getGenerateAltTextDemand(
        AltlessFileReference $fileReference
    ): GenerateAltTextDemand {
        $backendUser = $this->backendUserProvider->get();
        [$recordTableName, $recordColumnName, $recordUid] = $this->getRecordCoordinates($fileReference);
        $fileUid = $fileReference->getOriginalResource()->getOriginalFile()->getUid();
        return new GenerateAltTextDemand(
            (int)$backendUser->user['uid'],
            $fileReference->getPid(),
            (int)$fileReference->getOriginalResource()->getReferenceProperty('sys_language_uid'),
            $backendUser->workspace,
            $recordTableName,
            $recordUid,
            $fileUid,
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
}
