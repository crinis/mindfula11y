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
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Cache\CacheManager;

/**
 * Scanner basic-auth credentials resolve from the site configuration
 * (config.yaml root key mindfula11y.scan.basicAuth.username/password) with the
 * released Page TSconfig keys as deprecated fallback. The site configuration
 * is authoritative as soon as either key is set there: a partial pair fails
 * closed instead of silently reviving the deprecated TSconfig credentials, so
 * a half-finished migration surfaces as a 401 at the scanned host rather than
 * as stale credentials sent to the wrong place.
 */
final class ModuleSettingsServiceTest extends AbstractAuthorizationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // The functional instance (and its compiled site cache) is shared
        // across the test methods of this class; every test writes its own
        // config.yaml, so drop cached site objects from a previous test.
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
     * Write the scanner credentials into the site's config.yaml.
     *
     * @param array<string, string> $credentials
     */
    private function writeScanBasicAuthSiteConfiguration(array $credentials): void
    {
        $this->writeDefaultSiteConfiguration([
            'mindfula11y' => ['scan' => ['basicAuth' => $credentials]],
        ]);
        $this->get(CacheManager::class)->getCache('core')->flush();
    }

    public function testSiteConfigurationProvidesScanBasicAuth(): void
    {
        $this->writeScanBasicAuthSiteConfiguration(['username' => 'site-user', 'password' => 'site-secret']);

        $result = $this->subject()->getScanBasicAuth(18, []);

        self::assertSame(['username' => 'site-user', 'password' => 'site-secret'], $result);
    }

    public function testSiteConfigurationWinsOverTsConfigCredentials(): void
    {
        $this->writeScanBasicAuthSiteConfiguration(['username' => 'site-user', 'password' => 'site-secret']);

        $result = $this->subject()->getScanBasicAuth(18, $this->scanTsConfig('ts-user', 'ts-secret'));

        self::assertSame(['username' => 'site-user', 'password' => 'site-secret'], $result);
    }

    public function testPartialSiteConfigurationFailsClosedDespiteTsConfigCredentials(): void
    {
        $this->writeScanBasicAuthSiteConfiguration(['username' => 'site-user']);

        $result = $this->subject()->getScanBasicAuth(18, $this->scanTsConfig('ts-user', 'ts-secret'));

        self::assertNull($result);
    }

    public function testEnvPlaceholderResolvesInSiteConfiguration(): void
    {
        // Pins the contract the integrator documentation promises: secrets in
        // config/sites/<id>/config.yaml may be %env()% placeholders.
        putenv('MINDFULA11Y_TEST_BASIC_AUTH_PASSWORD=env-secret');
        try {
            $this->writeScanBasicAuthSiteConfiguration([
                'username' => 'site-user',
                'password' => '%env(MINDFULA11Y_TEST_BASIC_AUTH_PASSWORD)%',
            ]);

            $result = $this->subject()->getScanBasicAuth(18, []);
        } finally {
            putenv('MINDFULA11Y_TEST_BASIC_AUTH_PASSWORD');
        }

        self::assertSame(['username' => 'site-user', 'password' => 'env-secret'], $result);
    }

    /**
     * The reason the credentials live in config.yaml and not in site settings:
     * core publishes every site setting as a page TSconfig (and TypoScript)
     * constant, so anyone allowed to write page TSconfig could print the
     * password, e.g. into a TCEFORM label. Site configuration keys are never
     * turned into constants.
     */
    public function testCredentialsAreNotExposedAsPageTsConfigConstants(): void
    {
        // Core only publishes site settings as constants when the site has at
        // least one defined setting — the fixture set provides one, and its
        // constant is the control that substitution is active in this run.
        $this->writeDefaultSiteConfiguration([
            'dependencies' => ['mindfula11y-test/constants-probe'],
            'mindfula11y' => ['scan' => ['basicAuth' => ['username' => 'site-user', 'password' => 'site-secret']]],
        ]);
        $this->get(CacheManager::class)->getCache('core')->flush();
        $this->getConnectionPool()->getConnectionForTable('pages')->update(
            'pages',
            ['TSconfig' => "TCEFORM.pages.title.label = {\$a11ytest.constantsProbe}\nTCEFORM.pages.subtitle.label = {\$mindfula11y.scan.basicAuth.password}"],
            ['uid' => 1],
        );

        $tceForm = BackendUtility::getPagesTSconfig(18)['TCEFORM.']['pages.'] ?? [];

        self::assertSame('site-secret', $this->subject()->getScanBasicAuth(18, [])['password'] ?? null, 'fixture guard: the credentials are configured');
        self::assertSame('probe-default', $tceForm['title.']['label'] ?? null, 'control: site constants are substituted in this run');
        self::assertSame('{$mindfula11y.scan.basicAuth.password}', $tceForm['subtitle.']['label'] ?? null, 'the password must not be substituted as a constant');
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
