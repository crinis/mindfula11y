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

namespace MindfulMarkup\MindfulA11y\Form\FieldControl;

use Exception;
use MindfulMarkup\MindfulA11y\Service\AltTextDemandFactory;
use MindfulMarkup\MindfulA11y\Service\DemandSignatureService;
use MindfulMarkup\MindfulA11y\Service\OpenAIService;
use MindfulMarkup\MindfulA11y\Service\PermissionService;
use MindfulMarkup\MindfulA11y\Tca\TranslationFields;
use TYPO3\CMS\Backend\Form\AbstractNode;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\StringUtility;

/**
 * Field control rendering a "Generate alternative text" button next to the
 * alternative-text input of sys_file_reference / sys_file_metadata records.
 *
 * Registered as TCA fieldControl on the columns' default input renderType —
 * core's InputTextElement keeps owning placeholder resolution, null handling
 * and validation; this control only contributes the button and its JS module.
 * Renders nothing (and is skipped by the FieldControl container) when the
 * referenced file is no supported image, the OpenAI integration is not
 * configured, or the user lacks access to the accessibility module.
 */
class GenerateAltTextControl extends AbstractNode
{
    public function __construct(
        protected readonly OpenAIService $openAIService,
        protected readonly ResourceFactory $resourceFactory,
        protected readonly PageRenderer $pageRenderer,
        protected readonly PermissionService $permissionService,
        protected readonly DemandSignatureService $demandSignatureService,
        protected readonly AltTextDemandFactory $altTextDemandFactory,
    ) {
    }

    /**
     * Render the control button or nothing when generation is unavailable.
     *
     * @return array As defined by the FieldControl container.
     */
    public function render(): array
    {
        $table = $this->data['tableName'];
        $file = $this->resolveFile();

        if (
            null === $file
            || !$this->openAIService->isFileExtSupported($file->getExtension())
            || !$this->openAIService->isEnabledAndConfigured()
            || !$this->permissionService->checkModuleAccess()
        ) {
            return [];
        }

        $languageService = $this->getLanguageService();
        $itemName = (string)$this->data['parameterArray']['itemFormElName'];
        $recordUid = (int)$this->data['databaseRow']['uid'];
        $record = BackendUtility::getRecordWSOL($table, $recordUid);
        if (!is_array($record)) {
            return [];
        }
        $generateAltTextDemand = $this->altTextDemandFactory->create(
            (int)$this->data['effectivePid'],
            $this->resolveLanguageUid(),
            $table,
            $recordUid,
            $record,
            $file->getUid(),
            $table === 'sys_file_reference' ? $recordUid : 0,
            [$this->data['fieldName']],
        );
        if ($generateAltTextDemand === null) {
            return [];
        }

        $this->pageRenderer->addInlineLanguageLabelArray([
            'mindfula11y.altText.generate.loading' => $languageService->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:altText.generate.loading'),
            'mindfula11y.altText.generate.success' => $languageService->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:altText.generate.success'),
            'mindfula11y.altText.generate.success.description' => $languageService->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:altText.generate.success.description'),
            'mindfula11y.altText.generate.error.unknown' => $languageService->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:altText.generate.error.unknown'),
            'mindfula11y.altText.generate.error.unknown.description' => $languageService->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:altText.generate.error.unknown.description'),
        ]);

        $id = StringUtility::getUniqueId('mindfula11y-generate-alt-text-');

        return [
            'iconIdentifier' => 'actions-refresh',
            'title' => 'LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:altText.generate.button',
            'linkAttributes' => [
                'id' => $id,
                'data-item-name' => $itemName,
                'aria-label' => $languageService->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:altText.generate.button'),
            ],
            'javaScriptModules' => [
                JavaScriptModuleInstruction::create(
                    '@mindfulmarkup/mindfula11y/service/generate-alt-text-control.js'
                )->instance('#' . $id, $this->demandSignatureService->serialize($generateAltTextDemand)),
            ],
        ];
    }

    /**
     * Resolve the referenced file from the edited record row.
     */
    protected function resolveFile(): ?File
    {
        $row = $this->data['databaseRow'];
        if ('sys_file_reference' === $this->data['tableName']) {
            $fileUid = isset($row['uid_local'][0]['uid']) ? (int)$row['uid_local'][0]['uid'] : null;
        } elseif ('sys_file_metadata' === $this->data['tableName']) {
            $fileUid = isset($row['file'][0]) ? (int)$row['file'][0] : null;
        } else {
            $fileUid = null;
        }

        if (null === $fileUid) {
            return null;
        }

        try {
            return $this->resourceFactory->getFileObject($fileUid);
        } catch (Exception) {
            return null;
        }
    }

    /**
     * Resolve the record language from the table's language field, if any.
     */
    protected function resolveLanguageUid(): int
    {
        $languageField = TranslationFields::languageFieldName($this->data['tableName']);
        if ('' === $languageField) {
            return 0;
        }
        $value = $this->data['databaseRow'][$languageField] ?? 0;

        return (int)((is_array($value) ? ($value[0] ?? 0) : $value) ?? 0);
    }

    protected function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
