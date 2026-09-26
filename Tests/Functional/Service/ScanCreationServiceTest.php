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

namespace MindfulMarkup\MindfulA11y\Tests\Functional\Service;

use MindfulMarkup\MindfulA11y\Domain\Model\CreateScanDemand;
use MindfulMarkup\MindfulA11y\Exception\ScanCreationException;
use MindfulMarkup\MindfulA11y\Service\ExtensionSettings;
use MindfulMarkup\MindfulA11y\Service\ModuleSettingsService;
use MindfulMarkup\MindfulA11y\Service\PagePreviewService;
use MindfulMarkup\MindfulA11y\Service\ScanApiService;
use MindfulMarkup\MindfulA11y\Service\ScanCreationService;
use MindfulMarkup\MindfulA11y\Service\SiteLanguageService;
use MindfulMarkup\MindfulA11y\Tests\Functional\AbstractAuthorizationTestCase;
use Psr\Log\NullLogger;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * A crawl is confined to the selected language's URL space by a glob built
 * from the site configuration. When that base cannot be resolved the crawl
 * must be refused, not started without a glob — which would let the
 * scanner follow links anywhere the start page leads.
 */
final class ScanCreationServiceTest extends AbstractAuthorizationTestCase
{
    /** @var list<array<string, mixed>> */
    private array $sentRequestOptions = [];

    private function subject(): ScanCreationService
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mindfula11y']['scannerApiUrl'] = 'https://scanner.invalid';
        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->method('request')->willReturnCallback(
            function (string $uri, string $method, array $options): never {
                $this->sentRequestOptions[] = $options;
                throw new \RuntimeException('scanner unreachable in tests');
            }
        );

        return new ScanCreationService(
            new ScanApiService(
                new ExtensionSettings($this->get(ExtensionConfiguration::class)),
                $requestFactory,
                new NullLogger(),
            ),
            $this->get(ModuleSettingsService::class),
            $this->get(PagePreviewService::class),
            $this->get(SiteLanguageService::class),
        );
    }

    private function createCrawl(): void
    {
        $page = BackendUtility::getRecord('pages', 1);
        self::assertIsArray($page);

        $this->subject()->create(
            new CreateScanDemand(
                userId: 1,
                pageId: 1,
                previewUrl: 'https://example.com/',
                languageId: 0,
                workspaceId: 0,
                pageRecordSnapshot: str_repeat('a', 64),
                crawl: true,
            ),
            $page,
            [],
            false,
            null,
        );
    }

    public function testCrawlIsRefusedWhenTheLanguageBaseCannotBeResolved(): void
    {
        // No site configuration: the root page's language base is unknown.
        $this->logInBackendUser(1);

        try {
            $this->createCrawl();
            self::fail('the crawl must be refused');
        } catch (ScanCreationException $exception) {
            self::assertSame('scan.error.createFailed', $exception->labelKey);
        }
        self::assertSame([], $this->sentRequestOptions, 'no crawl request reaches the scanner');
    }

    /**
     * Anti-vacuous counterpart: with a resolvable base the request is sent
     * and carries the language glob.
     */
    public function testCrawlCarriesTheLanguageGlobWhenTheBaseResolves(): void
    {
        $this->writeDefaultSiteConfiguration();
        $this->logInBackendUser(1);

        try {
            $this->createCrawl();
        } catch (ScanCreationException) {
            // The stubbed scanner is unreachable; only the sent request matters.
        }

        self::assertCount(1, $this->sentRequestOptions);
        $body = json_decode((string)$this->sentRequestOptions[0]['body'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['https://example.com/**'], $body['crawlOptions']['globs'] ?? null);
    }
}
