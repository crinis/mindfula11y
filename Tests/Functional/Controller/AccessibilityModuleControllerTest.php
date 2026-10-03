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

namespace MindfulMarkup\MindfulA11y\Tests\Functional\Controller;

use MindfulMarkup\MindfulA11y\Controller\AccessibilityModuleController;
use MindfulMarkup\MindfulA11y\Service\ModuleLabelService;
use MindfulMarkup\MindfulA11y\Tests\Functional\AbstractAuthorizationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Module\ModuleData;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * The module controller's own gates, in order: page read access
 * (readPageAccess = PAGE_SHOW perms clause + webmount, core-side), then
 * language access against the requested language itself — with a redirect to
 * the user's first selectable language as the recovery path, and a 403 when
 * none exists. Module-level access (`access: user` + groupMods) is enforced
 * by core's routing/ModuleProvider before mainAction runs and is covered by
 * the PermissionService/ScenarioSelfCheck suites; these tests target what
 * the action itself must enforce.
 */
final class AccessibilityModuleControllerTest extends AbstractAuthorizationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->writeDefaultSiteConfiguration();
    }

    private function buildModuleRequest(int $pageId, array $moduleProperties = []): ServerRequestInterface
    {
        $module = $this->get(ModuleProvider::class)->getModule('mindfula11y_accessibility');
        self::assertNotNull($module, 'module is registered');

        $request = (new ServerRequest('https://typo3-testing.local/typo3/module/web/mindfula11y?id=' . $pageId, 'GET'))
            ->withQueryParams(['id' => $pageId])
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('module', $module)
            // BackendViewFactory resolves the module template paths from the
            // route's packageName option.
            ->withAttribute('route', new Route('/module/web/mindfula11y', [
                'packageName' => 'mindfulmarkup/mindfula11y',
                'module' => $module,
            ]))
            ->withAttribute('moduleData', new ModuleData('mindfula11y_accessibility', $moduleProperties, [
                'languageId' => 0,
                'feature' => 'overview',
                'pageLevels' => 0,
                'currentPage' => 1,
                'tableName' => '',
            ]));

        try {
            $request = $request->withAttribute('site', $this->get(SiteFinder::class)->getSiteByPageId($pageId ?: 1));
        } catch (\Throwable) {
            // Pages outside a site (or id=0) proceed without the attribute,
            // like core when no site can be resolved.
        }

        return $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
    }

    private function mainAction(ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
    {
        // Core statics (icon/resource publishing) read normalizedParams from
        // the global request — publish the module request like the backend
        // dispatcher would. tearDown() unsets it.
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return $this->get(AccessibilityModuleController::class)->mainAction($request);
    }

    private function moduleLabel(string $key): string
    {
        return $GLOBALS['LANG']->sL(ModuleLabelService::LANGUAGE_FILE . $key);
    }

    public function testMissingAltTextPageScopeDefaultsToCurrentPage(): void
    {
        $module = $this->get(ModuleProvider::class)->getModule('mindfula11y_accessibility');
        self::assertNotNull($module);

        self::assertSame(0, $module->getDefaultModuleData()['pageLevels'] ?? null);
        self::assertTrue($module->getDefaultModuleData()['filterFileMetaData'] ?? false);
        self::assertFalse($module->getDefaultModuleData()['showDecorative'] ?? true);
        self::assertFalse($module->getDefaultModuleData()['showAllReferences'] ?? true);
    }

    public function testPageWithoutShowPermissionRendersForbidden(): void
    {
        $this->logInBackendUser(2);

        $response = $this->mainAction($this->buildModuleRequest(14));

        self::assertSame(403, $response->getStatusCode());
    }

    /**
     * readPageAccess() enforces webmounts internally: page 20 grants
     * perms_everybody 19 but sits outside every db mount.
     */
    public function testPageOutsideWebmountRendersForbidden(): void
    {
        $this->logInBackendUser(2);

        $response = $this->mainAction($this->buildModuleRequest(20));

        self::assertSame(403, $response->getStatusCode());
    }

    public function testPageZeroRendersNoticeWithoutData(): void
    {
        $this->logInBackendUser(2);

        $response = $this->mainAction($this->buildModuleRequest(0));

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * User 11 may only use language 1. Requesting the default language on a
     * page that HAS a language-1 translation must redirect there instead of
     * silently rendering data of a forbidden language.
     */
    public function testDeniedLanguageRedirectsToFirstSelectableLanguage(): void
    {
        $this->logInBackendUser(11);

        $response = $this->mainAction($this->buildModuleRequest(10, ['languageId' => 0]));

        self::assertSame(302, $response->getStatusCode());
        parse_str((string)parse_url($response->getHeaderLine('Location'), PHP_URL_QUERY), $query);
        self::assertSame('1', (string)($query['languageId'] ?? ''), 'redirect targets the first selectable language');
        self::assertSame('10', (string)($query['id'] ?? ''), 'redirect keeps the page');
    }

    /**
     * Same user on page 11, which has no language-1 translation: nothing is
     * selectable, so the module must answer 403 instead of falling back to
     * default-language data the user may not access.
     */
    public function testDeniedLanguageWithoutSelectableAlternativeRendersForbidden(): void
    {
        $this->logInBackendUser(11);

        $response = $this->mainAction($this->buildModuleRequest(11, ['languageId' => 0]));

        self::assertSame(403, $response->getStatusCode());
    }

    /**
     * A negative languageId from a manipulated URL is clamped to 0 before the
     * language check — checkLanguageAccess(-1) would pass for everyone.
     * For the language-restricted user 11 the clamped value 0 is then denied
     * (redirect), never waved through.
     */
    public function testNegativeLanguageIdIsClampedNotWavedThrough(): void
    {
        $this->logInBackendUser(11);

        $response = $this->mainAction($this->buildModuleRequest(10, ['languageId' => -1]));

        self::assertSame(302, $response->getStatusCode());
    }

    /**
     * currentPage is GET-writable: an extreme value saturates the int cast at
     * PHP_INT_MAX, so the offset multiplication would overflow to a float and
     * fatal on the int-typed finder parameter. The renderer must clamp to the
     * actual last page instead.
     */
    public function testExtremeCurrentPageIsClampedNotFatal(): void
    {
        $this->logInBackendUser(2);

        $response = $this->mainAction($this->buildModuleRequest(10, [
            'feature' => 'missingAltText',
            'currentPage' => '99999999999999999999',
        ]));

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * `feature` is GET-writable and BackendModuleValidator persists whatever
     * arrives — `?feature[]=x` stores an array. An unknown or non-string value
     * must fall back to the overview instead of failing with a TypeError.
     *
     * @return array<string, array{mixed}>
     */
    public static function invalidFeatureProvider(): array
    {
        return [
            'array value' => [['x']],
            'unknown string' => ['general'],
        ];
    }

    #[DataProvider('invalidFeatureProvider')]
    public function testInvalidFeatureFallsBackToTheOverview(mixed $feature): void
    {
        $this->logInBackendUser(2);

        $response = $this->mainAction($this->buildModuleRequest(10, ['feature' => $feature]));

        self::assertSame(200, $response->getStatusCode());
        $response->getBody()->rewind();
        self::assertStringContainsString('<mindfula11y-structure', (string)$response->getBody());
    }

    /**
     * A table the user may not read answers with the error notice only: the
     * renderer's doc-header menus (page scope, record type, filter) would
     * otherwise frame a 403 with controls for a view that is not shown.
     */
    public function testUnreadableTableRendersForbiddenWithoutFeatureMenus(): void
    {
        $this->logInBackendUser(2);

        $response = $this->mainAction($this->buildModuleRequest(10, [
            'feature' => 'missingAltText',
            'tableName' => 'be_users',
        ]));

        self::assertSame(403, $response->getStatusCode());
        $response->getBody()->rewind();
        $html = (string)$response->getBody();
        self::assertStringNotContainsString($this->moduleLabel('module.menu.pageLevels'), $html);
        self::assertStringNotContainsString($this->moduleLabel('module.menu.tables'), $html);
        self::assertStringNotContainsString($this->moduleLabel('module.menu.filter'), $html);
    }

    /**
     * The pagination links carry the resolved view state, like the doc-header
     * menu links: a manipulated negative languageId is clamped to 0 for the
     * queries and must not be written back into the next page's link.
     */
    public function testPaginationLinksCarryTheClampedLanguage(): void
    {
        // 101 altless references on content 100 (page 10): two list pages.
        $connection = $this->getConnectionPool()->getConnectionForTable('sys_file_reference');
        for ($uid = 1000; $uid <= 1100; $uid++) {
            $connection->insert('sys_file_reference', [
                'uid' => $uid,
                'pid' => 10,
                'uid_local' => 1,
                'uid_foreign' => 100,
                'tablenames' => 'tt_content',
                'fieldname' => 'assets',
                'sys_language_uid' => 0,
                'alternative' => '',
            ]);
        }
        $this->logInBackendUser(2);

        $response = $this->mainAction($this->buildModuleRequest(10, [
            'feature' => 'missingAltText',
            'languageId' => -1,
        ]));

        self::assertSame(200, $response->getStatusCode());
        $response->getBody()->rewind();
        $html = (string)$response->getBody();
        self::assertStringContainsString('currentPage=2', $html, 'the list paginates');
        self::assertStringNotContainsString('languageId=-1', $html);
    }

    /**
     * File metadata has no "All languages" row: the listing judges an
     * all-languages reference by the metadata of the listed language, so the
     * text the list advertises as inherited must come from that language too.
     * Rendered through the real module, because the template has to hand the
     * listed language to the view helper — in a translation listing the
     * translation's metadata text, never the default language's.
     *
     * Fixture: AllLanguagesReferenceSupplement.csv (tt_content 410 and its
     * reference 410 on page 10, both -1, file 1).
     */
    public function testAllLanguagesReferenceInheritsTheListedLanguagesMetadataText(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/AllLanguagesReferenceSupplement.csv');
        $metadata = $this->getConnectionPool()->getConnectionForTable('sys_file_metadata');
        $metadata->update('sys_file_metadata', ['alternative' => 'Default metadata text'], ['uid' => 1]);
        $metadata->insert('sys_file_metadata', [
            'uid' => 600,
            'pid' => 0,
            'file' => 1,
            'sys_language_uid' => 1,
            'l10n_parent' => 1,
            'alternative' => 'French metadata text',
        ]);
        $this->logInBackendUser(2);

        $html = $this->body($this->mainAction($this->buildModuleRequest(10, [
            'feature' => 'missingAltText',
            'languageId' => 1,
            // Metadata text hides a reference by default; list those, too.
            'filterFileMetaData' => 0,
            'tableName' => 'tt_content',
        ])));

        self::assertSame(
            1,
            preg_match('/<mindfula11y-altless-file-reference[^>]*\suid="410"[^>]*>/', $html, $match),
            'the French list shows the all-languages reference',
        );
        self::assertStringContainsString('fallback-alternative="French metadata text"', $match[0]);
    }

    public function testAuthorizedRequestRendersTheModule(): void
    {
        $this->logInBackendUser(2);

        $response = $this->mainAction($this->buildModuleRequest(10));

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * The doc header shows the selected page (v14 breadcrumb / v13 page path).
     * Without it, TYPO3 v14 renders a breadcrumb of the site root instead.
     */
    public function testDocHeaderShowsTheSelectedPage(): void
    {
        $this->logInBackendUser(2);

        $body = (string)$this->mainAction($this->buildModuleRequest(10))->getBody();

        $docHeaderEnd = strpos($body, 'module-body');
        self::assertNotFalse($docHeaderEnd, 'fixture guard: the module layout rendered');
        self::assertStringContainsString('Editable', substr($body, 0, $docHeaderEnd), 'the page title appears in the doc header');
    }

    /**
     * Renderer-level TSconfig gate through the real dispatch path: page 17
     * disables scans via TSconfig, so requesting the scan feature there must
     * yield the renderer's 403 — deep-linking the feature URL cannot bypass
     * the disabled menu entry.
     */
    public function testScanFeatureOnTsConfigDisabledPageRendersForbidden(): void
    {
        $this->logInBackendUser(2);

        $response = $this->mainAction($this->buildModuleRequest(17, ['feature' => 'scan']));

        self::assertSame(403, $response->getStatusCode());
    }

    /**
     * The scan feature must not offer a single-page scan the create action
     * refuses: a page restricted to frontend user groups (910) renders the
     * pageRestricted notice. Counterparts: a public page (10) and a
     * multi-level scan of the restricted page render the scan view.
     */
    public function testScanFeatureOnFrontendRestrictedPageRendersRestrictedNotice(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/PagePreviewSupplement.csv');
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mindfula11y']['scannerApiUrl'] = 'https://scanner.invalid';
        $this->logInBackendUser(2);
        $restrictedTitle = $this->moduleLabel('scan.error.pageRestricted');
        self::assertNotSame('', $restrictedTitle);

        $restricted = $this->mainAction($this->buildModuleRequest(910, ['feature' => 'scan', 'scanPageLevels' => 0]));
        self::assertSame(200, $restricted->getStatusCode());
        self::assertStringContainsString($restrictedTitle, $this->body($restricted));

        $public = $this->mainAction($this->buildModuleRequest(10, ['feature' => 'scan', 'scanPageLevels' => 0]));
        self::assertStringNotContainsString($restrictedTitle, $this->body($public));
        self::assertStringContainsString('mindfula11y-scan', $this->body($public));

        $multiLevel = $this->mainAction($this->buildModuleRequest(910, ['feature' => 'scan', 'scanPageLevels' => 1]));
        self::assertStringNotContainsString($restrictedTitle, $this->body($multiLevel));
        self::assertStringContainsString('mindfula11y-scan', $this->body($multiLevel));
    }

    private function body(\Psr\Http\Message\ResponseInterface $response): string
    {
        $response->getBody()->rewind();

        return (string)$response->getBody();
    }
}
