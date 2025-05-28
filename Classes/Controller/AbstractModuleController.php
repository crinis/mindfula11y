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

namespace MindfulMarkup\MindfulA11y\Controller;

use InvalidArgumentException;
use MindfulMarkup\MindfulA11y\Service\PermissionService;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\AllowedMethodsTrait;
use TYPO3\CMS\Core\Http\Error\MethodNotAllowedException;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Backend\Module\ModuleData;
use TYPO3\CMS\Backend\Module\ModuleInterface;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\Menu\Menu;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\TypoScript\TypoScriptService;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Abstract class for accessibility module controllers.
 * 
 * This class provides common functionality for all accessibility module controllers in the TYPO3 backend.
 */
abstract class AbstractModuleController
{
    use AllowedMethodsTrait;

    /**
     * The current page ID.
     */
    protected int $pageId = 0;

    /**
     * The selected language UID.
     */
    protected int $languageId = 0;

    /**
     * The page information for the current page.
     */
    protected array|bool $pageInfo = false;

    /**
     * The module template.
     */
    protected ModuleTemplate $moduleTemplate;

    /**
     * The module data.
     */
    protected ?ModuleData $moduleData = null;

    /**
     * Page TSConfig.
     * 
     * @var array<string, mixed>
     */
    protected array $pageTsConfig = [];

    /**
     * The module template factory.
     */
    protected ModuleTemplateFactory $moduleTemplateFactory;

    /**
     * The request object.
     */
    protected ServerRequestInterface $request;

    /**
     * The backend URI builder.
     */
    protected UriBuilder $backendUriBuilder;

    /**
     * The TypoScript service.
     */
    protected TypoScriptService $typoScriptService;

    /**
     * The page renderer.
     */
    protected PageRenderer $pageRenderer;

    /**
     * Module Provider.
     */
    protected ModuleProvider $moduleProvider;

    /**
     * Flash message service.
     */
    protected FlashMessageService $flashMessageService;

    /**
     * Permission service.
     */
    protected PermissionService $permissionService;

    /**
     * Inject the ModuleTemplateFactory.
     */
    public function injectModuleTemplateFactory(ModuleTemplateFactory $moduleTemplateFactory): void
    {
        $this->moduleTemplateFactory = $moduleTemplateFactory;
    }

    /**
     * Inject the backend UriBuilder.
     */
    public function injectBackendUriBuilder(UriBuilder $backendUriBuilder): void
    {
        $this->backendUriBuilder = $backendUriBuilder;
    }

    /**
     * Inject the TypoScriptService.
     */
    public function injectTypoScriptService(TypoScriptService $typoScriptService): void
    {
        $this->typoScriptService = $typoScriptService;
    }

    /**
     * Inject the PageRenderer.
     */
    public function injectPageRenderer(PageRenderer $pageRenderer): void
    {
        $this->pageRenderer = $pageRenderer;
    }

    /**
     * Inject the ModuleProvider.
     */
    public function injectModuleProvider(ModuleProvider $moduleProvider): void
    {
        $this->moduleProvider = $moduleProvider;
    }

    /**
     * Inject the FlashMessageService.
     */
    public function injectFlashMessageService(FlashMessageService $flashMessageService): void
    {
        $this->flashMessageService = $flashMessageService;
    }

    /**
     * Inject the PermissionService.
     */
    public function injectPermissionService(PermissionService $permissionService): void
    {
        $this->permissionService = $permissionService;
    }

    /**
     * Initializes required properties for the module.
     * 
     * @param ServerRequestInterface $request The request object.
     * @param string $moduleTitle The title of the module.
     * 
     * @return bool True if setup was successful, false if permissions are missing or there was another problem.
     * 
     * @throws MethodNotAllowedException If the request method is not allowed.
     * @throws InvalidArgumentException If module data is not set.
     */
    protected function initializeModule(ServerRequestInterface $request, string $moduleTitle): bool
    {
        $this->request = $request;
        $this->assertAllowedHttpMethod($this->request, 'GET');
        $this->moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $this->moduleTemplate->setTitle($moduleTitle);

        $backendUser = $this->getBackendUserAuthentication();
        $this->pageId = (int)($this->request->getParsedBody()['id'] ?? $this->request->getQueryParams()['id'] ?? 0);

        if ($this->pageId === 0) {
            $this->addFlashMessage(
                $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:noPageSelected'),
                $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:noPageSelected.description'),
                ContextualFeedbackSeverity::INFO
            );

            return false;
        }

        $this->pageInfo = BackendUtility::readPageAccess($this->pageId, $backendUser->getPagePermsClause(Permission::PAGE_SHOW));

        /**
         * Check if the user has access to the page. If not, show an error message.
         * Subsequent classes/methods will not check this again.
         */
        if ($this->pageInfo === false) {
            $this->addFlashMessage(
                $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:noPageAccess'),
                $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:noPageAccess.description'),
                ContextualFeedbackSeverity::ERROR
            );

            return false;
        }

        $this->moduleData = $this->request->getAttribute('moduleData', null);
        if (null === $this->moduleData) {
            throw new InvalidArgumentException('Module data is not set.', 1745686754);
        }

        $this->languageId = (int)$this->moduleData->get('languageId', 0);

        /**
         * Check if the user has access to the selected language. If not, show an error message.
         * Subsequent classes/methods will not check this again.
         */
        if (!$backendUser->checkLanguageAccess($this->languageId)) {
            $this->addFlashMessage(
                $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:noLanguageAccess'),
                $this->getLanguageService()->sL('LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:noLanguageAccess.description'),
                ContextualFeedbackSeverity::ERROR
            );

            return false;
        }

        $this->pageTsConfig = $this->typoScriptService->convertTypoScriptArrayToPlainArray($this->getPageTsConfig());

        return true;
    }

    /**
     * Build a language menu for the module.
     * 
     * Add menu listing all available languages for the user based on the active site
     * configuration for the current page. This menu must be manually added by subclasses as
     * it might need additional parameters.
     * 
     * @return Menu|null The language menu or null if no languages are available.
     */
    protected function buildLanguageMenu(): ?Menu
    {
        $allowedLanguages = $this->getAllowedSiteLanguages();

        if (empty($allowedLanguages)) {
            return null;
        }

        $menu = $this->moduleTemplate->getDocHeaderComponent()->getMenuRegistry()->makeMenu();
        $menu->setIdentifier('MindfulA11yAccessibilityLanguage')
            ->setLabel(
                'LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:menu.language'
            );

        foreach ($allowedLanguages as $language) {
            $menuItem = $menu->makeMenuItem()->setTitle(
                $language->getTitle()
            )->setHref(
                $this->getMenuItemUri(
                    [
                        'languageId' => $language->getLanguageId(),
                    ]
                )
            )->setActive($language->getLanguageId() === $this->languageId);
            $menu->addMenuItem($menuItem);
        }

        return $menu;
    }

    /**
     * Build module menu for users to select a submodule.
     * 
     * Add menu listing all available accessibility modules for the user. This menu must be
     * manually added by subclasses as it might need additional parameters.
     * 
     * @return Menu
     */
    protected function buildModuleMenu(): Menu
    {
        $menu = $this->moduleTemplate->getDocHeaderComponent()->getMenuRegistry()->makeMenu();
        $menu->setIdentifier('MindfulA11yAccessibilityModule')
            ->setLabel(
                'LLL:EXT:mindfula11y/Resources/Private/Language/Modules/Accessibility.xlf:menu.module'
            );

        $activeModule = $this->request->getAttribute('module', null);

        foreach ($this->getActiveModules() as $module) {
            $menuItem = $menu->makeMenuItem()->setTitle(
                $this->getLanguageService()->sL($module->getTitle())
            )->setHref(
                (string)$this->backendUriBuilder->buildUriFromRoute(
                    $module->getIdentifier(),
                    [
                        'id' => $this->pageId,
                        'languageId' => $this->languageId,
                    ]
                )
            )->setActive($module->getIdentifier() === (null === $activeModule ? null : $activeModule->getIdentifier()));
            $menu->addMenuItem($menuItem);
        }

        return $menu;
    }

    /**
     * Get the URI for a menu item as a string.
     * 
     * @param array $changedParams The changed parameters.
     * 
     * @return string
     */
    protected abstract function getMenuItemUri(array $changedParams): string;

    /**
     * Get allowed site languages for the backend user.
     * 
     * @return array
     */
    protected function getAllowedSiteLanguages(): array
    {
        $site = $this->request->getAttribute('site');
        if (null === $site) {
            return [];
        }

        return $site->getAvailableLanguages($this->getBackendUserAuthentication(), false, $this->pageId);
    }

    /**
     * Get all active accessibility modules.
     * 
     * @return array<ModuleInterface>
     */
    protected function getActiveModules(): array
    {
        $modules = [];
        $backendUser = $this->getBackendUserAuthentication();

        $accessibilityModule = $this->moduleProvider->getModule('mindfula11y_accessibility', $backendUser);
        if ($accessibilityModule !== null) {
            $modules['mindfula11y_accessibility'] = $accessibilityModule;
        }

        $missingAltTextModule = $this->moduleProvider->getModule('mindfula11y_missingalttext', $backendUser);
        $isMissingAltTextEnabled = $this->pageTsConfig['mod']['mindfula11y_missingalttext']['enable'] ?? false;

        if (
            $missingAltTextModule !== null &&
            $isMissingAltTextEnabled
        ) {
            $modules['mindfula11y_missingalttext'] = $missingAltTextModule;
        }

        $headingStructureModule = $this->moduleProvider->getModule('mindfula11y_headingstructure', $backendUser);
        $isHeadingStructureEnabled = $this->pageTsConfig['mod']['mindfula11y_headingstructure']['enable'] ?? false;

        if (
            $headingStructureModule !== null &&
            $isHeadingStructureEnabled
        ) {
            $modules['mindfula11y_headingstructure'] = $headingStructureModule;
        }

        return $modules;
    }

    /**
     * Add a flash message.
     * 
     * @param string $title The title of the message.
     * @param string $message The message content.
     * @param ContextualFeedbackSeverity $severity The severity of the message.
     */
    protected function addFlashMessage(string $title, string $message, ContextualFeedbackSeverity $severity): void
    {
        $flashMessage = GeneralUtility::makeInstance(
            FlashMessage::class,
            $message,
            $title,
            $severity
        );

        $this->flashMessageService->getMessageQueueByIdentifier()->addMessage($flashMessage);
    }

    /**
     * Get backend user authentication.
     * 
     * @return BackendUserAuthentication
     */
    protected function getBackendUserAuthentication(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }

    /**
     * Get page tsconfig.
     * 
     * @return array
     */
    protected function getPageTsConfig(): array
    {
        return BackendUtility::getPagesTSconfig($this->pageId) ?? [];
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
