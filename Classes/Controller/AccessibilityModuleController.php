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
use MindfulMarkup\MindfulA11y\Backend\DocHeaderMenuBuilder;
use MindfulMarkup\MindfulA11y\Backend\MissingAltTextFeatureRenderer;
use MindfulMarkup\MindfulA11y\Backend\ModuleContext;
use MindfulMarkup\MindfulA11y\Backend\ModuleNoticeTrait;
use MindfulMarkup\MindfulA11y\Backend\OverviewFeatureRenderer;
use MindfulMarkup\MindfulA11y\Backend\ScanFeatureRenderer;
use MindfulMarkup\MindfulA11y\Enum\Feature;
use MindfulMarkup\MindfulA11y\Service\ModuleLabelService;
use MindfulMarkup\MindfulA11y\Service\ModuleSettingsService;
use MindfulMarkup\MindfulA11y\Service\PagePreviewService;
use MindfulMarkup\MindfulA11y\Service\PermissionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Domain\Repository\Localization\LocalizationRepository;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\Buttons\DropDownButton;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\AllowedMethodsTrait;
use TYPO3\CMS\Core\Http\Error\MethodNotAllowedException;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Entry point of the accessibility backend module.
 *
 * Resolves and access-checks the requested page, language, and feature into a
 * ModuleContext, adds the feature and language selector menus, and dispatches
 * to the feature's renderer (Classes/Backend/*FeatureRenderer).
 */
#[AsController]
final readonly class AccessibilityModuleController
{
    use AllowedMethodsTrait;
    use ModuleNoticeTrait;

    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private UriBuilder $backendUriBuilder,
        private PageRenderer $pageRenderer,
        private FlashMessageService $flashMessageService,
        private PermissionService $permissionService,
        private ModuleSettingsService $moduleSettingsService,
        private PagePreviewService $pagePreviewService,
        private ModuleLabelService $moduleLabelService,
        private DocHeaderMenuBuilder $menuBuilder,
        private OverviewFeatureRenderer $overviewFeatureRenderer,
        private MissingAltTextFeatureRenderer $missingAltTextFeatureRenderer,
        private ScanFeatureRenderer $scanFeatureRenderer,
    ) {}

    /**
     * Renders the accessibility backend module.
     *
     * @throws MethodNotAllowedException If wrong HTTP method is used.
     * @throws InvalidArgumentException If module data is not set.
     */
    public function mainAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->assertAllowedHttpMethod($request, 'GET');
        $languageService = $this->getLanguageService();
        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $moduleTemplate->setTitle($languageService->sL(self::MODULE_LANGUAGE_FILE . 'mlang_tabs_tab'));

        $backendUser = $this->getBackendUserAuthentication();
        $pageId = (int)($request->getParsedBody()['id'] ?? $request->getQueryParams()['id'] ?? 0);

        if (0 === $pageId) {
            return $this->noticeResponse($moduleTemplate, 'module.noPageSelected', ContextualFeedbackSeverity::INFO);
        }

        $pageInfo = BackendUtility::readPageAccess($pageId, $backendUser->getPagePermsClause(Permission::PAGE_SHOW));
        if (false === $pageInfo) {
            return $this->noticeResponse($moduleTemplate, 'error.noPageAccess', ContextualFeedbackSeverity::ERROR, 403);
        }

        $moduleData = $request->getAttribute('moduleData', null);
        if (null === $moduleData) {
            throw new InvalidArgumentException('Module data is not set.', 1745686754);
        }

        $languageId = (int)$moduleData->get('languageId', 0);
        $localizedPageInfo = $this->pagePreviewService->getLocalizedPageRecord($pageId, $languageId);

        $languageToCheck = $localizedPageInfo === null ? 0 : $languageId;
        if (!$this->permissionService->checkLanguageAccess($languageToCheck)) {
            // Try to find the first available language that the user has access to
            $availableLanguageId = $this->getFirstAvailableLanguageId($request, $pageId);
            if (null !== $availableLanguageId) {
                // Redirect to the first available language
                $uri = $this->backendUriBuilder->buildUriFromRoute(
                    'mindfula11y_accessibility',
                    [
                        'id' => $pageId,
                        'languageId' => $availableLanguageId,
                        'feature' => $moduleData->get('feature', Feature::OVERVIEW->value),
                    ]
                );

                return new RedirectResponse($uri, 302);
            }

            // No languages available, show error
            return $this->noticeResponse($moduleTemplate, 'module.noLanguageAccess', ContextualFeedbackSeverity::ERROR, 403);
        }

        $context = new ModuleContext(
            request: $request,
            moduleTemplate: $moduleTemplate,
            moduleData: $moduleData,
            // Unknown persisted values (e.g. the pre-rename 'general') fall back to the overview.
            feature: Feature::tryFrom($moduleData->get('feature', Feature::OVERVIEW->value)) ?? Feature::OVERVIEW,
            pageId: $pageId,
            languageId: $languageId,
            pageInfo: $pageInfo,
            localizedPageInfo: $localizedPageInfo,
            pageTsConfig: $this->moduleSettingsService->getConvertedPageTsConfig($pageId),
        );

        $this->menuBuilder->addDropDown($moduleTemplate, $this->buildFeatureMenu($context), 1);
        $this->menuBuilder->addLanguageSelector($moduleTemplate, $this->buildLanguageMenu($context));
        $this->pageRenderer->addInlineLanguageLabelArray($this->moduleLabelService->getInlineLanguageLabels());

        return match ($context->feature) {
            Feature::OVERVIEW => $this->overviewFeatureRenderer->render($context),
            Feature::MISSING_ALT_TEXT => $this->missingAltTextFeatureRenderer->render($context),
            Feature::SCAN => $this->scanFeatureRenderer->render($context),
        };
    }

    private function buildFeatureMenu(ModuleContext $context): ?DropDownButton
    {
        $languageService = $this->getLanguageService();
        $items = [];
        foreach (Feature::cases() as $feature) {
            $enabled = match ($feature) {
                Feature::OVERVIEW => true,
                Feature::MISSING_ALT_TEXT => $this->moduleSettingsService->hasMissingAltTextAccess($context->pageTsConfig),
                Feature::SCAN => $this->moduleSettingsService->hasScanAccess($context->pageTsConfig),
            };
            if ($enabled) {
                $items[] = [
                    'title' => $languageService->sL(self::MODULE_LANGUAGE_FILE . 'module.menu.features.' . $feature->value),
                    'href' => $this->menuBuilder->buildMenuItemUri($context, ['feature' => $feature->value]),
                    'active' => $context->feature === $feature,
                ];
            }
        }

        return $this->menuBuilder->buildDropDown(
            $languageService->sL(self::MODULE_LANGUAGE_FILE . 'module.menu.features'),
            $items
        );
    }

    /**
     * Build a language menu for the module.
     *
     * Add menu listing all available languages for the user based on the active site
     * configuration for the current page, but only for languages where the page is translated.
     */
    private function buildLanguageMenu(ModuleContext $context): ?DropDownButton
    {
        $allowedLanguages = $this->getAllowedSiteLanguages($context->request, $context->pageId);

        if (empty($allowedLanguages) || 0 === $context->pageId) {
            return null;
        }

        $availableLanguageIds = $this->getAvailableLanguageIds($context->pageId);

        $items = [];
        foreach ($allowedLanguages as $language) {
            // Only show languages where the page is actually translated
            if (0 !== $language->getLanguageId() && !in_array($language->getLanguageId(), $availableLanguageIds, true)) {
                continue;
            }

            $items[] = [
                'title' => $language->getTitle(),
                'href' => $this->menuBuilder->buildMenuItemUri($context, [
                    'languageId' => $language->getLanguageId(),
                ]),
                'active' => $language->getLanguageId() === $context->languageId,
            ];
        }

        return $this->menuBuilder->buildDropDown(
            $this->getLanguageService()->sL(self::MODULE_LANGUAGE_FILE . 'module.menu.language'),
            $items
        );
    }

    /**
     * Get allowed site languages for the backend user.
     *
     * @return list<SiteLanguage>
     */
    private function getAllowedSiteLanguages(ServerRequestInterface $request, int $pageId): array
    {
        $site = $request->getAttribute('site', null);
        if (null === $site) {
            return [];
        }

        return $site->getAvailableLanguages($this->getBackendUserAuthentication(), false, $pageId);
    }

    /**
     * Get available language IDs for the current page (including default language).
     *
     * @return array<int>
     */
    private function getAvailableLanguageIds(int $pageId): array
    {
        $availableLanguageIds = [0]; // Default language is always available

        // LocalizationRepository::getPageTranslations() and the deprecation of
        // the legacy BackendUtility::getExistingPageTranslations() both landed
        // in TYPO3 v14.2 (#108799 / #108810); on v13 and v14.0/v14.1 the legacy
        // method is the only one that exists.
        if (version_compare((new Typo3Version())->getVersion(), '14.2', '>=')) {
            // Pass the backend user's workspace so the result matches the
            // workspace-aware legacy path. getPageTranslations() returns
            // RawRecord[] keyed by language id, so the ids are the array keys.
            $workspaceId = $this->getBackendUserAuthentication()->workspace;
            $repository = GeneralUtility::makeInstance(LocalizationRepository::class);
            foreach (array_keys($repository->getPageTranslations($pageId, [], $workspaceId)) as $languageId) {
                $availableLanguageIds[] = (int)$languageId;
            }
        } else {
            // TYPO3 v13 / v14.0 / v14.1: the legacy API returns page rows.
            $pageTranslations = BackendUtility::getExistingPageTranslations($pageId);
            foreach ($pageTranslations as $pageTranslation) {
                $languageId = $pageTranslation[$GLOBALS['TCA']['pages']['ctrl']['languageField']] ?? null;
                if (null !== $languageId) {
                    $availableLanguageIds[] = (int)$languageId;
                }
            }
        }

        return array_unique($availableLanguageIds);
    }

    /**
     * Get the first available language ID that the user has access to and the page is translated to.
     */
    private function getFirstAvailableLanguageId(ServerRequestInterface $request, int $pageId): ?int
    {
        $allowedLanguages = $this->getAllowedSiteLanguages($request, $pageId);

        if (empty($allowedLanguages)) {
            return null;
        }

        $availableLanguageIds = $this->getAvailableLanguageIds($pageId);

        foreach ($allowedLanguages as $language) {
            $languageId = $language->getLanguageId();
            // User access is already checked by getAllowedSiteLanguages(), just check if page is translated
            if (in_array($languageId, $availableLanguageIds, true)) {
                return $languageId;
            }
        }

        return null;
    }

    private function getBackendUserAuthentication(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }
}
