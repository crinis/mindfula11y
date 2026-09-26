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
use MindfulMarkup\MindfulA11y\Domain\Repository\AltlessFileReferenceRepository;
use MindfulMarkup\MindfulA11y\Hooks\DecorativeFileReferenceDataHandlerGuard;
use MindfulMarkup\MindfulA11y\Service\AltTextDemandFactory;
use MindfulMarkup\MindfulA11y\Service\BackendUserProvider;
use MindfulMarkup\MindfulA11y\Service\DemandSignatureService;
use MindfulMarkup\MindfulA11y\Service\ModuleLabelService;
use MindfulMarkup\MindfulA11y\Service\ModuleSettingsService;
use MindfulMarkup\MindfulA11y\Service\OpenAIService;
use MindfulMarkup\MindfulA11y\Service\PermissionService;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Http\NormalizedParams;
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
     * Demand signature service instance.
     */
    protected readonly DemandSignatureService $demandSignatureService;

    protected readonly AltTextDemandFactory $altTextDemandFactory;

    /**
     * Supplies the workspace-effective metadata alternative — see its use in
     * render() for why the file's own property is not the same answer.
     */
    protected readonly AltlessFileReferenceRepository $altlessFileReferenceRepository;

    protected readonly BackendUserProvider $backendUserProvider;

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
     * Inject demand signature service.
     */
    public function injectDemandSignatureService(DemandSignatureService $demandSignatureService): void
    {
        $this->demandSignatureService = $demandSignatureService;
    }

    public function injectAltTextDemandFactory(AltTextDemandFactory $altTextDemandFactory): void
    {
        $this->altTextDemandFactory = $altTextDemandFactory;
    }

    public function injectAltlessFileReferenceRepository(AltlessFileReferenceRepository $altlessFileReferenceRepository): void
    {
        $this->altlessFileReferenceRepository = $altlessFileReferenceRepository;
    }

    public function injectBackendUserProvider(BackendUserProvider $backendUserProvider): void
    {
        $this->backendUserProvider = $backendUserProvider;
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

        // Coordinates and the cheap grants first: the record is only fetched
        // for a reference the user could edit at all.
        $record = $recordTableName !== ''
            && $recordColumnName !== ''
            && $this->permissionService->checkTableWriteAccess('sys_file_reference')
            && $this->permissionService->checkNonExcludeFields('sys_file_reference', ['alternative'])
            ? BackendUtility::getRecordWSOL($recordTableName, $recordUid)
            : null;

        if (
            null !== $record
            && $this->permissionService->checkRecordEditAccess($recordTableName, $record, [$recordColumnName])
        ) {
            $editRouteParameters = [
                'edit' => [
                    $recordTableName => [
                        $recordUid => 'edit'
                    ]
                ],
            ];
            // "Close" in the record editor returns to the module view that
            // rendered this link. Relative request URI like core's own modules:
            // an absolute URI can carry http:// behind a TLS-terminating proxy
            // and would then fail sanitizeLocalUrl() against the https:// site
            // URL. Without a request (CLI, bare rendering) or its
            // normalizedParams there is nothing to return to — omitted.
            $normalizedParams = $this->renderingContext->hasAttribute(ServerRequestInterface::class)
                ? $this->renderingContext->getAttribute(ServerRequestInterface::class)->getAttribute('normalizedParams')
                : null;
            if ($normalizedParams instanceof NormalizedParams && $normalizedParams->getRequestUri() !== '') {
                $editRouteParameters['returnUrl'] = $normalizedParams->getRequestUri();
            }
            $this->tag->addAttribute(
                'record-edit-link',
                (string)$this->backendUriBuilder->buildUriFromRoute('record_edit', $editRouteParameters),
            );
            $this->tag->addAttribute('record-edit-link-label', sprintf($this->getLanguageService()->sL(ModuleLabelService::LANGUAGE_FILE . 'altText.editRecord.label'), $recordTableName, $recordUid));
            // Mirrors the DataHandler guard's own condition. The toggle itself
            // always needs its grant; the BLANKED_FIELDS grants are only
            // required to turn decorative ON, because only that direction
            // blanks them. Demanding them unconditionally would hide the
            // control from a user the guard would happily let switch it OFF —
            // stranding a wrongly-decorative image with a permanent alt="".
            $decorativeEditable = $this->permissionService->checkNonExcludeFields(
                'sys_file_reference',
                [DecorativeFileReferenceDataHandlerGuard::FIELD_NAME],
            ) && (
                (bool)$fileReference->getOriginalResource()->getReferenceProperty(
                    DecorativeFileReferenceDataHandlerGuard::FIELD_NAME
                )
                || $this->permissionService->checkNonExcludeFields(
                    'sys_file_reference',
                    DecorativeFileReferenceDataHandlerGuard::BLANKED_FIELDS,
                )
            );
            if ($decorativeEditable) {
                $this->tag->addAttribute('decorative-editable', true);
            }
            if (
                $this->openAIService->isEnabledAndConfigured()
            ) {
                $demand = $this->getGenerateAltTextDemand($fileReference, $record);
                if ($demand !== null) {
                    $this->tag->addAttribute(
                        'generate-alt-text-demand',
                        json_encode($this->demandSignatureService->serialize($demand), JSON_THROW_ON_ERROR)
                    );
                }
            }
        }

        $this->tag->addAttribute('uid', $fileReference->getUid());
        if ((bool)$fileReference->getOriginalResource()->getReferenceProperty(DecorativeFileReferenceDataHandlerGuard::FIELD_NAME)) {
            $this->tag->addAttribute('decorative', true);
        }
        $alternative = $fileReference->getOriginalResource()->getReferenceProperty('alternative');
        if (
            $this->moduleSettingsService->canReadFileReferenceAlternative()
            && is_string($alternative)
            && $alternative !== ''
        ) {
            $this->tag->addAttribute('alternative', $alternative);
        }

        if ($this->moduleSettingsService->canReadFileMetadataAlternative()) {
            // Deliberately NOT the file's own metadata property: FAL resolves it
            // through WorkspaceRestriction, which yields the live row for a file
            // whose metadata was edited in a workspace, and core's overlay
            // listener for FAL metadata runs in the frontend only. Reading it
            // here would advertise live text as inherited even where the listing
            // already counted the reference as missing because the draft
            // cleared or deleted that metadata.
            $fallbackAlternative = $this->altlessFileReferenceRepository->findEffectiveMetaDataAlternative(
                (int)$fileReference->getOriginalResource()->getOriginalFile()->getUid(),
                $this->backendUserProvider->getAuthenticated()?->workspace ?? 0,
                (int)$fileReference->getOriginalResource()->getReferenceProperty('sys_language_uid'),
            );
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
        AltlessFileReference $fileReference,
        array $record,
    ): ?GenerateAltTextDemand {
        [$recordTableName, $recordColumnName, $recordUid] = $this->getRecordCoordinates($fileReference);

        return $this->altTextDemandFactory->create(
            $fileReference->getPid(),
            (int)$fileReference->getOriginalResource()->getReferenceProperty('sys_language_uid'),
            $recordTableName,
            $recordUid,
            $record,
            $fileReference->getOriginalResource()->getOriginalFile()->getUid(),
            $fileReference->getUid(),
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
