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
 */

namespace MindfulMarkup\MindfulA11y\Tests\Functional\EventListener;

use MindfulMarkup\MindfulA11y\EventListener\AddOverviewToPageModule;
use MindfulMarkup\MindfulA11y\Tests\Functional\AbstractAuthorizationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Controller\Event\ModifyPageLayoutContentEvent;
use TYPO3\CMS\Backend\Module\ModuleData;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * The page-module info box follows the language the page module shows. The
 * page module persists that selection differently per core version: v13
 * stores a single `language` id, v14.3 a `languages` id list whose primary
 * language is the single selected non-default id (PageContext::
 * getPrimaryLanguageId()). Both shapes must reach the card; the language is
 * observable as the structure element's `language-id` attribute.
 */
final class AddOverviewToPageModuleTest extends AbstractAuthorizationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->writeDefaultSiteConfiguration();
    }

    /**
     * @return array<string, array{array<string, mixed>, int}>
     */
    public static function moduleDataProvider(): array
    {
        return [
            'v14 languages list with one translation' => [['languages' => [1]], 1],
            'v14 languages list with default and one translation' => [['languages' => [0, 1]], 1],
            'v14 languages list with default only' => [['languages' => [0]], 0],
            'v14 languages list with several translations' => [['languages' => [1, 2]], 0],
            'v13 single language' => [['language' => 1], 1],
            'v13 all languages view' => [['language' => -1], 0],
        ];
    }

    /**
     * @param array<string, mixed> $moduleProperties
     */
    #[DataProvider('moduleDataProvider')]
    public function testCardFollowsThePageModuleLanguage(array $moduleProperties, int $expectedLanguageId): void
    {
        $this->logInBackendUser(2);
        $request = $this->buildPageModuleRequest(10, $moduleProperties);
        $event = new ModifyPageLayoutContentEvent($request, $this->get(ModuleTemplateFactory::class)->create($request));

        $this->get(AddOverviewToPageModule::class)($event);

        $html = $event->getHeaderContent();
        self::assertStringContainsString('language-id="' . $expectedLanguageId . '"', $html);
    }

    /**
     * @param array<string, mixed> $moduleProperties
     */
    private function buildPageModuleRequest(int $pageId, array $moduleProperties): ServerRequestInterface
    {
        $module = $this->get(ModuleProvider::class)->getModule('web_layout');
        self::assertNotNull($module, 'page module is registered');

        $request = (new ServerRequest('https://typo3-testing.local/typo3/module/web/layout?id=' . $pageId, 'GET'))
            ->withQueryParams(['id' => $pageId])
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('module', $module)
            // ModuleTemplateFactory resolves its template paths from the route.
            ->withAttribute('route', new Route('/module/web/layout', [
                'packageName' => 'typo3/cms-backend',
                'module' => $module,
            ]))
            ->withAttribute('moduleData', new ModuleData('web_layout', $moduleProperties))
            ->withAttribute('site', $this->get(SiteFinder::class)->getSiteByPageId($pageId));
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        // Core statics (icon/resource publishing, URI building) read the
        // global request, as in the backend dispatcher. tearDown() unsets it.
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return $request;
    }
}
