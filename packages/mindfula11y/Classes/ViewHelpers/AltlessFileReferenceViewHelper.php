<?php

declare(strict_types=1);

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
