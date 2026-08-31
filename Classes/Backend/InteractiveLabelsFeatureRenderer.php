<?php

declare(strict_types=1);

namespace MindfulMarkup\MindfulA11y\Backend;

use MindfulMarkup\MindfulA11y\Service\BackendPageLanguageService;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;
use MindfulMarkup\MindfulA11y\Enum\InteractiveLabelType;
use MindfulMarkup\MindfulA11y\Service\InteractiveLabelFinderService;
use MindfulMarkup\MindfulA11y\Service\ModuleSettingsService;
use Psr\Http\Message\ResponseInterface;
use MindfulMarkup\MindfulA11y\Service\InteractiveLabelChecker;
use MindfulMarkup\MindfulA11y\Service\InteractiveLabelRuleProvider;
use MindfulMarkup\MindfulA11y\Service\InteractiveLabelAggregator;
use MindfulMarkup\MindfulA11y\Service\OpenAIService;
use TYPO3\CMS\Core\Page\PageRenderer;

final readonly class InteractiveLabelsFeatureRenderer implements FeatureRendererInterface
{
    public function __construct(
        private InteractiveLabelFinderService $finderService,
        private ModuleSettingsService $moduleSettingsService,
        private InteractiveLabelRuleProvider $ruleProvider,
        private InteractiveLabelChecker $checker,
        private BackendPageLanguageService $backendPageLanguageService,
        private InteractiveLabelAggregator $aggregator,
        private PageRenderer $pageRenderer,
        private OpenAIService $openAIService,
    ) {
    }
    private function resolveLocale(ModuleContext $context): string
    {
        $site = $context->request->getAttribute('site');

        if (!$site instanceof SiteInterface) {
            return 'en';
        }

        $languages = $this->backendPageLanguageService
            ->getSelectableLanguages(
                $site,
                $context->pageId,
            );

        foreach ($languages as $language) {
            if ($language->getLanguageId() !== $context->languageId) {
                continue;
            }

            return $language->getLocale()->getName();
        }

        return 'en';
    }
    public function render(
        ModuleContext $context,
    ): ResponseInterface {
        $pageTsConfig =
            $this->moduleSettingsService->getConvertedPageTsConfig(
                $context->pageId,
            );

        $fieldsConfig =
            $this->moduleSettingsService->getInteractiveLabelFields(
                $pageTsConfig,
            );

        $additionalVagueLabels =
            $this->moduleSettingsService->getAdditionalVagueLabels(
                $pageTsConfig,
            );

        $ignoredLabels =
            $this->moduleSettingsService->getIgnoredLabels(
                $pageTsConfig,
            );

        $repeatedLabelThreshold =
            $this->moduleSettingsService->getRepeatedLabelThreshold(
                $pageTsConfig,
            );

        $locale = $this->resolveLocale($context);

        $labels = [];

        foreach (InteractiveLabelType::cases() as $type) {
            $tableFields = $fieldsConfig[$type->value] ?? [];

            foreach ($tableFields as $table => $fields) {
                $currentLabels = $this->finderService->find(
                    $context->pageId,
                    $context->languageId,
                    $locale,
                    $table,
                    $fields,
                    $type,
                    $additionalVagueLabels,
                    $ignoredLabels,
                );

                $labels = [
                    ...$labels,
                    ...$currentLabels,
                ];
            }
        }

        $findings = $this->aggregator->annotate($labels, $repeatedLabelThreshold);

        $aiReviewAvailable = $this->moduleSettingsService->hasInteractiveLabelAiReviewAccess($pageTsConfig)
            && $this->openAIService->isApiKeyConfigured();

        if ($aiReviewAvailable) {
            $pageTitle = (string)($context->getPreviewPageInfo()['title'] ?? '');

            $findings = array_map(
                fn (array $finding): array => [
                    ...$finding,
                    'pageId' => $context->pageId,
                    'pageTitle' => $pageTitle,
                    'locale' => $locale,
                    'surroundingContext' => $this->buildSurroundingContext(
                        (string)($finding['table'] ?? ''),
                        (string)($finding['field'] ?? ''),
                    ),
                    'aiReviewAvailable' => true,
                ],
                $findings,
            );
        }

        $context->moduleTemplate->assignMultiple([
            'pageTsConfigDebug' => $pageTsConfig,
            'fieldsConfig' => $fieldsConfig,
            'interactiveLabelLocale' => $locale,
            'findings' => $findings,
        ]);

        $this->pageRenderer->loadJavaScriptModule(
            '@mindfulmarkup/mindfula11y/element/notice/notice.js',
        );

        $this->pageRenderer->addCssFile(
            'EXT:mindfula11y/Resources/Public/Css/backend.css',
        );

        $this->pageRenderer->loadJavaScriptModule(
            '@mindfulmarkup/mindfula11y/element/interactive-label-finding/interactive-label-finding.js',
        );

        return $context->moduleTemplate->renderResponse(
            'Backend/InteractiveLabels',
        );
    }

    /**
     * The only "surrounding context" cheaply available at render time: the
     * record type and field the label lives in (e.g. "Text & Media, Button
     * label").
     */
    private function buildSurroundingContext(string $table, string $field): string
    {
        if ($table === '') {
            return '';
        }

        $languageService = $GLOBALS['LANG'];
        $tableTitle = $languageService->sL($GLOBALS['TCA'][$table]['ctrl']['title'] ?? $table);
        $fieldLabel = $languageService->sL($GLOBALS['TCA'][$table]['columns'][$field]['label'] ?? $field);

        return trim($tableTitle . ($fieldLabel !== '' ? ', ' . $fieldLabel : ''));
    }
}
