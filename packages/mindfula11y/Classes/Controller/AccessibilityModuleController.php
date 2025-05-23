<?php

declare(strict_types=1);

namespace MindfulMarkup\MindfulA11y\Controller;

use MindfulMarkup\MindfulA11y\Service\AltTextFinderService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\PreviewUriBuilder;
use TYPO3\CMS\Core\Http\Error\MethodNotAllowedException;
use TYPO3\CMS\Core\Http\Error\InvalidArgumentException;
use TYPO3\CMS\Extbase\Utility\DebuggerUtility;

/**
 * Class AccessibilityModuleController.
 *
 * This controller handles the backend module with various accessibility functions.
 */
#[AsController]
class AccessibilityModuleController extends AbstractModuleController
{
    /**
     * Constructor.
     * 
     * @param AltTextFinderService $altTextFinderService
     */
    public function __construct(
        protected readonly AltTextFinderService $altTextFinderService,
    ) {}

    /**
     * Renders the accessibility backend module.
     * 
     * Renders the accessibility backend module with various feature to check and improve
     * accessibility of the selected page.
     * 
     * @param ServerRequestInterface $request
     * 
     * @return ResponseInterface
     * 
     * @throws MethodNotAllowedException If wrong HTTP method is used.
     * @throws InvalidArgumentException If module data is not set.
     */
    public function mainAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->initializeModule($request, $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:mlang_tabs_tab'))) {
            return $this->moduleTemplate->renderResponse('Backend/Info')->withStatus(403);
        }

        $this->moduleTemplate->getDocHeaderComponent()->getMenuRegistry()->addMenu($this->buildModuleMenu());
        $this->moduleTemplate->getDocHeaderComponent()->getMenuRegistry()->addMenu($this->buildLanguageMenu());

        if (
            $this->permissionService->checkTableWriteAccess('sys_file_reference')
            && $this->getBackendUserAuthentication()->check('non_exclude_fields', 'sys_file_reference:alternative')
        ) {
            $fileReferences = $this->altTextFinderService->getAltlessFileReferences(
                $this->pageId,
                1,
                $this->languageId,
                $this->pageTsConfig,
                0,
                100,
                true
            );
        } else {
            $fileReferences = [];
        }

        $activeModules = $this->getActiveModules();
        $this->moduleTemplate->assignMultiple([
            'moduleData' => array_merge($this->moduleData->toArray(), [
                'id' => $this->pageId,
            ]),
            'fileReferences' => $fileReferences,
            'previewUrl' => (string)PreviewUriBuilder::create($this->pageId)
                ->withLanguage($this->languageId)
                ->buildUri(),
            'enableHeadingStructure' => array_key_exists('mindfula11y_headingstructure', $activeModules),
            'enableMissingAltText' => array_key_exists('mindfula11y_alternativemissingalttext', $activeModules),
        ]);

        $this->pageRenderer->addInlineLanguageLabelArray([
            'mindfula11y.modules.headingStructure.error.loading' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/HeadingStructure.xlf:error.loading'),
            'mindfula11y.modules.headingStructure.error.loading.description' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/HeadingStructure.xlf:error.loading.description'),
            'mindfula11y.modules.headingStructure.error.store' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/HeadingStructure.xlf:error.store'),
            'mindfula11y.modules.headingStructure.error.store.description' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/HeadingStructure.xlf:error.store.description'),
            'mindfula11y.modules.headingStructure.error.skippedLevel' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/HeadingStructure.xlf:error.skippedLevel'),
            'mindfula11y.modules.headingStructure.error.skippedLevel.description' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/HeadingStructure.xlf:error.skippedLevel.description'),
            'mindfula11y.modules.headingStructure.error.missingH1' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/HeadingStructure.xlf:error.missingH1'),
            'mindfula11y.modules.headingStructure.error.missingH1.description' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/HeadingStructure.xlf:error.missingH1.description'),
            'mindfula11y.modules.missingAltText.generate.button' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/MissingAltText.xlf:generate.button'),
            'mindfula11y.modules.missingAltText.generate.loading' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/MissingAltText.xlf:generate.loading'),
            'mindfula11y.modules.missingAltText.generate.success' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/MissingAltText.xlf:generate.success'),
            'mindfula11y.modules.missingAltText.generate.success.description' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/MissingAltText.xlf:generate.success.description'),
            'mindfula11y.modules.missingAltText.generate.error.unknown' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/MissingAltText.xlf:generate.error.unknown'),
            'mindfula11y.modules.missingAltText.generate.error.unknown.description' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/MissingAltText.xlf:generate.error.unknown.description'),
            'mindfula11y.modules.missingAltText.generate.error.openAIConnection' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/MissingAltText.xlf:generate.error.openAIConnection'),
            'mindfula11y.modules.missingAltText.generate.error.openAIConnection.description' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/MissingAltText.xlf:generate.error.openAIConnection.description'),
            'mindfula11y.modules.missingAltText.altLabel' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/MissingAltText.xlf:altLabel'),
            'mindfula11y.modules.missingAltText.altPlaceholder' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/MissingAltText.xlf:altPlaceholder'),
            'mindfula11y.modules.missingAltText.save' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/MissingAltText.xlf:save'),
            'mindfula11y.modules.missingAltText.imagePreview' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/MissingAltText.xlf:imagePreview'),
            'mindfula11y.modules.missingAltText.editRecord' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/MissingAltText.xlf:editRecord.label'),
            'mindfula11y.modules.missingAltText.fallbackAltLabel' => $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/MissingAltText.xlf:fallbackAltLabel'),
        ]);

        $this->pageRenderer->loadJavaScriptModule('@mindfulmarkup/mindfula11y/altless-file-reference.js');
        $this->pageRenderer->loadJavaScriptModule('@mindfulmarkup/mindfula11y/heading-structure.js');

        return $this->moduleTemplate->renderResponse('Backend/Accessibility');
    }

    /**
     * Get the URI for a menu item as a string.
     * 
     * @param array $changedParams The changed parameters.
     * 
     * @return string
     */
    protected function getMenuItemUri(array $changedParams): string
    {
        $params = array_replace([
            'id' => $this->pageId,
            'languageId' => $this->languageId,
        ], $changedParams);

        return (string)$this->backendUriBuilder->buildUriFromRoute(
            'mindfula11y_accessibility',
            $params
        );
    }
}
