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
use TYPO3\CMS\Core\Configuration\SiteWriter;
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
     * The motivating case: a relative site base (`base: /`) is completed from
     * the current backend request's origin; without a request there is no
     * origin, so the crawl must be refused rather than sent unscoped.
     */
    public function testCrawlIsRefusedForARelativeSiteBaseWithoutRequest(): void
    {
        $this->get(SiteWriter::class)->write('main', [
            'rootPageId' => 1,
            'base' => '/',
            'languages' => [
                [
                    'languageId' => 0,
                    'title' => 'English',
                    'enabled' => true,
                    'locale' => 'en_US.UTF-8',
                    'base' => '/',
                    'navigationTitle' => 'English',
                    'flag' => 'us',
                ],
            ],
        ]);
        $this->logInBackendUser(1);
        // logInBackendUser() publishes a request; drop it so no origin exists.
        unset($GLOBALS['TYPO3_REQUEST']);

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

    /**
     * @param array<string, mixed> $pageTsConfig
     */
    private function createMultiPage(
        int $pageId,
        string $previewUrl,
        array $pageTsConfig = [],
        bool $aiAuditRequested = false,
    ): void {
        $page = BackendUtility::getRecord('pages', $pageId);
        self::assertIsArray($page);

        $this->subject()->create(
            new CreateScanDemand(
                userId: 1,
                pageId: $pageId,
                previewUrl: $previewUrl,
                languageId: 0,
                workspaceId: 0,
                pageRecordSnapshot: str_repeat('a', 64),
                pageLevels: 1,
            ),
            $page,
            $pageTsConfig,
            $aiAuditRequested,
            null,
        );
    }

    /**
     * MindfulAPI accepts at most 500 URLs in a url_list and rejects a longer
     * list with a bare validation error. A page tree beyond that is refused
     * here with an actionable message, before the scanner is called — and a
     * tree of exactly 500 pages still goes out.
     */
    public function testPageTreeBeyondTheScannersUrlLimitIsRefused(): void
    {
        $this->writeDefaultSiteConfiguration();
        $this->logInBackendUser(1);
        $this->insertSubpages(10, 499);

        try {
            $this->createMultiPage(10, 'https://example.com/editable');
        } catch (ScanCreationException) {
            // The stubbed scanner is unreachable; only the sent request matters.
        }
        self::assertCount(1, $this->sentRequestOptions, '500 pages are sent');
        $body = json_decode((string)$this->sentRequestOptions[0]['body'], true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(500, $body['urls'] ?? []);

        $this->insertSubpages(10, 1, 499);
        try {
            $this->createMultiPage(10, 'https://example.com/editable');
            self::fail('the scan must be refused');
        } catch (ScanCreationException $exception) {
            self::assertSame('scan.error.tooManyPages', $exception->labelKey);
            self::assertSame(400, $exception->statusCode);
        }
        self::assertCount(1, $this->sentRequestOptions, 'no second request reaches the scanner');
    }

    /** Insert $count public subpages below $parentId, numbered from $offset. */
    private function insertSubpages(int $parentId, int $count, int $offset = 0): void
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('pages');
        for ($index = $offset; $index < $offset + $count; $index++) {
            $connection->insert('pages', [
                'pid' => $parentId,
                'title' => 'Subpage ' . $index,
                'slug' => '/editable/subpage-' . $index,
                'doktype' => 1,
                'perms_everybody' => 19,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function aiReviewTsConfig(string $scanModes): array
    {
        return ['mod' => ['mindfula11y_accessibility' => ['scan' => ['aiAudit' => ['enable' => '1', 'scanModes' => $scanModes]]]]];
    }

    /**
     * MindfulAPI gates the AI review on the mode it receives. A page tree
     * that resolves to one page (page 10 has no subpages) goes out as
     * single_url, so a configuration allowing only url_list must refuse it
     * here — before the scanner answers with a 400.
     */
    public function testAiReviewIsGatedOnTheModeALeafPageTreeIsSentAs(): void
    {
        $this->writeDefaultSiteConfiguration();
        $this->logInBackendUser(1);

        try {
            $this->createMultiPage(10, 'https://example.com/editable', self::aiReviewTsConfig('url_list'), true);
            self::fail('the AI review must be refused');
        } catch (ScanCreationException $exception) {
            self::assertSame('scan.error.aiAuditScanModeNotAllowed', $exception->labelKey);
            self::assertSame(403, $exception->statusCode);
        }
        self::assertSame([], $this->sentRequestOptions, 'no scan request reaches the scanner');
    }

    public function testAiReviewOfACrawlIsRefusedWhenCrawlsAreNotListed(): void
    {
        $this->writeDefaultSiteConfiguration();
        $this->logInBackendUser(1);
        $page = BackendUtility::getRecord('pages', 1);
        self::assertIsArray($page);

        try {
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
                self::aiReviewTsConfig('single_url, url_list'),
                true,
                null,
            );
            self::fail('the AI review must be refused');
        } catch (ScanCreationException $exception) {
            self::assertSame('scan.error.aiAuditScanModeNotAllowed', $exception->labelKey);
        }
        self::assertSame([], $this->sentRequestOptions, 'no crawl request reaches the scanner');
    }

    /**
     * A multi-level scan skips frontend-restricted pages. When the whole
     * subtree is restricted (910 with fe_group + extendToSubpages, its child
     * 911 inherits it), falling back to the start page would scan its login
     * wall — the scan is refused like a single-page scan of that page.
     */
    public function testMultiPageScanOfAFullyRestrictedSubtreeIsRefused(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/PagePreviewSupplement.csv');
        $this->writeDefaultSiteConfiguration();
        $this->logInBackendUser(1);

        try {
            $this->createMultiPage(910, 'https://example.com/members');
            self::fail('the scan must be refused');
        } catch (ScanCreationException $exception) {
            self::assertSame('scan.error.pageRestricted', $exception->labelKey);
            self::assertSame(403, $exception->statusCode);
        }
        self::assertSame([], $this->sentRequestOptions, 'no scan request reaches the scanner');
    }

    /**
     * Anti-vacuous counterpart: an accessible start page whose subtree yields
     * no URL (no site configuration, so no preview URL can be built) keeps
     * falling back to the start page's own preview URL.
     */
    public function testMultiPageScanOfAnAccessibleStartPageFallsBackToItsPreviewUrl(): void
    {
        $this->logInBackendUser(1);

        try {
            $this->createMultiPage(10, 'https://example.com/editable');
        } catch (ScanCreationException) {
            // The stubbed scanner is unreachable; only the sent request matters.
        }

        self::assertCount(1, $this->sentRequestOptions);
        $body = json_decode((string)$this->sentRequestOptions[0]['body'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('https://example.com/editable', $body['url'] ?? null);
    }
}
