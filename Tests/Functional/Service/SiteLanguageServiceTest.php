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

use MindfulMarkup\MindfulA11y\Service\SiteLanguageService;
use MindfulMarkup\MindfulA11y\Tests\Functional\AbstractAuthorizationTestCase;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;

/**
 * Absolute language bases feed the scanner's crawl glob and the allowlist
 * for page-URL filters. Both must work for a relative site base (`base: /`),
 * where core resolves every language base without scheme and host: the
 * origin then comes from the current backend request — the host the preview
 * URLs are built for — and resolution fails closed without one.
 */
final class SiteLanguageServiceTest extends AbstractAuthorizationTestCase
{
    private function subject(): SiteLanguageService
    {
        return $this->get(SiteLanguageService::class);
    }

    /**
     * The fixture's root page 1 with a relative site base: language 0 at /,
     * language 1 at /fr/.
     */
    private function writeRelativeSiteConfiguration(): void
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
                [
                    'languageId' => 1,
                    'title' => 'French',
                    'enabled' => true,
                    'locale' => 'fr_FR.UTF-8',
                    'base' => '/fr/',
                    'navigationTitle' => 'French',
                    'flag' => 'fr',
                ],
            ],
        ]);
    }

    /**
     * Publish a backend request for https://backend.example:8443 with the
     * normalizedParams attribute core's middleware would provide.
     */
    private function publishBackendRequest(): void
    {
        $request = new ServerRequest(
            'https://backend.example:8443/typo3/module/web/accessibility',
            'GET',
            'php://temp',
            [],
            ['HTTP_HOST' => 'backend.example:8443', 'HTTPS' => 'on', 'REQUEST_URI' => '/typo3/module/web/accessibility'],
        );
        $GLOBALS['TYPO3_REQUEST'] = $request
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
    }

    public function testAbsoluteSiteBaseYieldsAbsoluteLanguageBases(): void
    {
        $this->writeDefaultSiteConfiguration();

        self::assertSame('https://example.com', $this->subject()->getAbsoluteLanguageBase(10, 0));
        self::assertSame('https://example.com/fr', $this->subject()->getAbsoluteLanguageBase(10, 1));
    }

    public function testAbsoluteSiteBaseFiltersUrlsToTheSite(): void
    {
        $this->writeDefaultSiteConfiguration();

        self::assertSame(
            ['https://example.com/editable', 'https://example.com/fr/editable', 'https://example.com'],
            $this->subject()->filterUrlsToSiteBases([
                'https://example.com/editable',
                'https://example.com/fr/editable',
                'https://example.com',
                'https://example.com.evil.test/editable',
                'https://other.example/editable',
            ], 10),
        );
    }

    public function testRelativeSiteBaseResolvesLanguageBasesAgainstTheRequestOrigin(): void
    {
        $this->writeRelativeSiteConfiguration();
        $this->publishBackendRequest();

        self::assertSame('https://backend.example:8443', $this->subject()->getAbsoluteLanguageBase(10, 0));
        self::assertSame('https://backend.example:8443/fr', $this->subject()->getAbsoluteLanguageBase(10, 1));
    }

    public function testRelativeSiteBaseFiltersUrlsToTheRequestOrigin(): void
    {
        $this->writeRelativeSiteConfiguration();
        $this->publishBackendRequest();

        self::assertSame(
            ['https://backend.example:8443/editable', 'https://backend.example:8443/fr/editable'],
            $this->subject()->filterUrlsToSiteBases([
                'https://backend.example:8443/editable',
                'https://backend.example:8443/fr/editable',
                'https://backend.example/editable',
                'https://other.example/editable',
                '/editable',
            ], 10),
        );
    }

    public function testRelativeSiteBaseWithoutRequestOriginFailsClosed(): void
    {
        $this->writeRelativeSiteConfiguration();
        unset($GLOBALS['TYPO3_REQUEST']);

        self::assertNull($this->subject()->getAbsoluteLanguageBase(10, 1));
        self::assertSame([], $this->subject()->filterUrlsToSiteBases(['https://backend.example:8443/editable'], 10));
    }

    public function testRelativeSiteBaseWithRequestLackingNormalizedParamsFailsClosed(): void
    {
        $this->writeRelativeSiteConfiguration();
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest('https://backend.example:8443/typo3/module/web/accessibility'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);

        self::assertNull($this->subject()->getAbsoluteLanguageBase(10, 1));
        self::assertSame([], $this->subject()->filterUrlsToSiteBases(['https://backend.example:8443/editable'], 10));
    }
}
