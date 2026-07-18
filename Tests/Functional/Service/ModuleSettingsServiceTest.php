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

use MindfulMarkup\MindfulA11y\Service\ModuleSettingsService;
use MindfulMarkup\MindfulA11y\Tests\Functional\AbstractAuthorizationTestCase;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Configuration\SiteWriter;

/**
 * Scanner basic-auth credentials resolve from site settings
 * (mindfula11y.scan.basicAuth.username/password) with the released Page
 * TSconfig keys as deprecated fallback. Site settings are authoritative as
 * soon as either key is set there: a partial pair fails closed instead of
 * silently reviving the deprecated TSconfig credentials, so a half-finished
 * migration surfaces as a 401 at the scanned host rather than as stale
 * credentials sent to the wrong place.
 */
final class ModuleSettingsServiceTest extends AbstractAuthorizationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // The functional instance (and its compiled-settings cache) is shared
        // across the test methods of this class — drop any settings.yaml a
        // previous test wrote so fallback tests don't inherit credentials.
        @unlink($this->instancePath . '/typo3conf/sites/main/settings.yaml');
        $this->get(CacheManager::class)->getCache('core')->flush();
    }

    private function subject(): ModuleSettingsService
    {
        return $this->get(ModuleSettingsService::class);
    }

    /** @return array<string, mixed> */
    private function scanTsConfig(string $username, string $password): array
    {
        return [
            'mod' => [
                'mindfula11y_accessibility' => [
                    'scan' => [
                        'basicAuthUsername' => $username,
                        'basicAuthPassword' => $password,
                    ],
                ],
            ],
        ];
    }

    /**
     * Write the scanner credentials into the site's settings.yaml.
     *
     * Tree form on purpose: settings without a settings.definitions.yaml entry
     * (deliberate here — the secret must not surface in the site settings
     * editor GUI) are only resolvable as dotted identifiers when written as a
     * nested tree; dotted map keys get their dots escaped by core's
     * ArrayUtility::flattenPlain(). The compiled-settings cache keys on the
     * site's config.yaml only, not on settings.yaml content — flush it so
     * every test resolves its own values.
     *
     * @param array<string, string> $credentials
     */
    private function writeScanBasicAuthSiteSettings(array $credentials): void
    {
        $this->writeDefaultSiteConfiguration();
        $this->get(SiteWriter::class)->writeSettings('main', [
            'mindfula11y' => ['scan' => ['basicAuth' => $credentials]],
        ]);
        $this->get(CacheManager::class)->getCache('core')->flush();
    }

    public function testSiteSettingsProvideScanBasicAuth(): void
    {
        $this->writeScanBasicAuthSiteSettings(['username' => 'site-user', 'password' => 'site-secret']);

        $result = $this->subject()->getScanBasicAuth(18, []);

        self::assertSame(['username' => 'site-user', 'password' => 'site-secret'], $result);
    }

    public function testSiteSettingsWinOverTsConfigCredentials(): void
    {
        $this->writeScanBasicAuthSiteSettings(['username' => 'site-user', 'password' => 'site-secret']);

        $result = $this->subject()->getScanBasicAuth(18, $this->scanTsConfig('ts-user', 'ts-secret'));

        self::assertSame(['username' => 'site-user', 'password' => 'site-secret'], $result);
    }

    public function testPartialSiteSettingsFailClosedDespiteTsConfigCredentials(): void
    {
        $this->writeScanBasicAuthSiteSettings(['username' => 'site-user']);

        $result = $this->subject()->getScanBasicAuth(18, $this->scanTsConfig('ts-user', 'ts-secret'));

        self::assertNull($result);
    }

    public function testEnvPlaceholderResolvesInSiteSettings(): void
    {
        // Pins the contract the integrator documentation promises: secrets in
        // config/sites/<id>/settings.yaml may be %env()% placeholders.
        putenv('MINDFULA11Y_TEST_BASIC_AUTH_PASSWORD=env-secret');
        try {
            $this->writeScanBasicAuthSiteSettings([
                'username' => 'site-user',
                'password' => '%env(MINDFULA11Y_TEST_BASIC_AUTH_PASSWORD)%',
            ]);

            $result = $this->subject()->getScanBasicAuth(18, []);
        } finally {
            putenv('MINDFULA11Y_TEST_BASIC_AUTH_PASSWORD');
        }

        self::assertSame(['username' => 'site-user', 'password' => 'env-secret'], $result);
    }

    public function testTsConfigCredentialsRemainAsDeprecatedFallback(): void
    {
        $this->writeDefaultSiteConfiguration();

        $result = $this->subject()->getScanBasicAuth(18, $this->scanTsConfig('ts-user', 'ts-secret'));

        self::assertSame(['username' => 'ts-user', 'password' => 'ts-secret'], $result);
    }

    public function testPageWithoutSiteFallsBackToTsConfig(): void
    {
        // No site configuration written: SiteFinder cannot resolve page 18.
        $result = $this->subject()->getScanBasicAuth(18, $this->scanTsConfig('ts-user', 'ts-secret'));

        self::assertSame(['username' => 'ts-user', 'password' => 'ts-secret'], $result);
    }

    public function testMissingTsConfigPasswordYieldsNull(): void
    {
        $this->writeDefaultSiteConfiguration();

        $result = $this->subject()->getScanBasicAuth(18, $this->scanTsConfig('ts-user', ''));

        self::assertNull($result);
    }

    public function testNoCredentialsAnywhereYieldsNull(): void
    {
        $this->writeDefaultSiteConfiguration();

        self::assertNull($this->subject()->getScanBasicAuth(18, []));
    }
}
