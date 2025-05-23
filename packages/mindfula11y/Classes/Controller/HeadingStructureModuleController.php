<?php

declare(strict_types=1);

namespace MindfulMarkup\MindfulA11y\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\PreviewUriBuilder;
use TYPO3\CMS\Core\Http\Error\MethodNotAllowedException;
use TYPO3\CMS\Core\Http\Error\InvalidArgumentException;

/**
 * Class HeadingStructureModuleController.
 *
 * This controller handles the backend module for displaying and managing heading structure.
 */
#[AsController]
class HeadingStructureModuleController extends AbstractModuleController
{
    /**
     * Main action to display the heading structure module.
     * 
     * This method is responsible for rendering the module template and setting up the
     * necessary data for the module to display the heading structure of the selected page.
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
        if (!$this->initializeModule($request, $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/HeadingStructure.xlf:mlang_tabs_tab'))) {
            return $this->moduleTemplate->renderResponse('Backend/Info')->withStatus(403);
        }

        $this->moduleTemplate->getDocHeaderComponent()->getMenuRegistry()->addMenu($this->buildModuleMenu());
        $this->moduleTemplate->getDocHeaderComponent()->getMenuRegistry()->addMenu($this->buildLanguageMenu());

        $this->moduleTemplate->assignMultiple([
            'moduleData' => array_merge($this->moduleData->toArray(), [
                'id' => $this->pageId,
            ]),
            'previewUrl' => (string)PreviewUriBuilder::create($this->pageId)
                ->withLanguage($this->languageId)
                ->buildUri(),
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
        ]);
        $this->pageRenderer->loadJavaScriptModule('@mindfulmarkup/mindfula11y/heading-structure.js');

        return $this->moduleTemplate->renderResponse('Backend/HeadingStructure');
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
            'mindfula11y_headingstructure',
            $params
        );
    }
}
