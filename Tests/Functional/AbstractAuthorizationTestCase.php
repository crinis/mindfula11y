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

namespace MindfulMarkup\MindfulA11y\Tests\Functional;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Base class of the authorization test suites.
 *
 * Boots a TYPO3 instance with one shared permission scenario
 * (Fixtures/AuthorizationScenario.csv): a page tree covering every page-level
 * permission dimension, one backend group per user-level permission dimension
 * (each group is the full editor grant minus exactly one permission), and one
 * backend user per group. Tests log in a user whose group removes the
 * dimension under test and assert the specific denial — plus the
 * corresponding allowed baseline with the full editor, so a test can never
 * pass vacuously.
 *
 * Fixture map (see the CSV for the full picture):
 *  - Users: 1 admin, 2 editor (full), 3 no module, 4 no tt_content modify,
 *    5 no exclude fields, 6 default language only, 7 CType text only,
 *    8 no pages modify, 9 no file mount, 10 second full editor,
 *    11 translation language only, 12 file read only permissions.
 *  - Pages: 10 editable, 11 show-only, 12 page editlock, 13 content-edit only,
 *    14 no access, 15 hidden, 16 group-1-only perms, 17 scan disabled by
 *    TSconfig, 18 edit-page-but-not-content, 19 AI audit enabled,
 *    20 outside every db mount, 30 language-1 translation of 10.
 *  - Content on those pages: uid 10x mirrors its page (100/101 editable,
 *    105 record editlock, 102 language 1, …).
 *  - Files: storage 1 with file mount "1:/allowed/"; file 1 inside the mount,
 *    file 2 outside ("/restricted/").
 *  - sys_workspace 1 with users 2 and 10 as members.
 *
 * The shared fixture is append-only for suite authors: never repurpose an
 * existing row — add new rows (or a suite-local supplementary CSV) instead.
 */
abstract class AbstractAuthorizationTestCase extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'mindfulmarkup/mindfula11y',
        __DIR__ . '/Fixtures/Extensions/a11y_test',
    ];

    protected array $coreExtensionsToLoad = ['workspaces'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/AuthorizationScenario.csv');

        // Physical counterparts of the fixture's storage 1 layout. With
        // permission evaluation active (see logInBackendUser) a file mount
        // only registers when its folder exists on disk — without these,
        // every mount-boundary check (including DataHandler's own
        // file-reference gate) would deny for the wrong reason.
        $fileadmin = $this->instancePath . '/fileadmin';
        GeneralUtility::mkdir_deep($fileadmin . '/allowed');
        GeneralUtility::mkdir_deep($fileadmin . '/restricted');
        file_put_contents($fileadmin . '/allowed/image.jpg', 'fake-jpeg-bytes');
        file_put_contents($fileadmin . '/restricted/secret.jpg', 'fake-jpeg-bytes');
    }

    protected function tearDown(): void
    {
        // logInBackendUser() publishes a backend-typed TYPO3_REQUEST; without
        // this reset it would leak into later test classes in the same PHP
        // process and change their storage/DataHandler permission behavior.
        unset($GLOBALS['TYPO3_REQUEST']);
        parent::tearDown();
    }

    /**
     * Log in a backend user from the fixture, optionally switched into a
     * workspace, and initialize $GLOBALS['LANG'] the way the backend request
     * stack would (the AJAX controllers' error responses depend on it).
     */
    protected function logInBackendUser(int $userUid, int $workspaceId = 0): BackendUserAuthentication
    {
        // Publish a backend-typed request BEFORE any ResourceStorage is
        // instantiated: StoragePermissionsAspect only applies user file
        // mounts/permissions to storages when the current request is a
        // backend one — without it every file permission check passes
        // vacuously (evaluatePermissions stays false).
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest('https://typo3-testing.local/typo3', 'GET'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);

        $backendUser = $this->setUpBackendUser($userUid);
        if ($workspaceId !== 0) {
            $backendUser->setWorkspace($workspaceId);
        }
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        return $backendUser;
    }

    /**
     * Build a backend AJAX request carrying a JSON body, with the
     * normalizedParams attribute core middleware would provide.
     *
     * @param array<string, mixed> $payload
     */
    protected function createJsonRequest(array $payload, string $method = 'POST'): ServerRequestInterface
    {
        // A string body target makes core's Stream open it read-only ('r'),
        // so the write() below would throw — hand over a writable resource.
        $body = fopen('php://temp', 'r+');
        $request = new ServerRequest(
            'https://typo3-testing.local/typo3/ajax/mindfula11y',
            $method,
            $body,
            ['Content-Type' => 'application/json'],
            ['HTTP_HOST' => 'typo3-testing.local', 'HTTPS' => 'on', 'REQUEST_URI' => '/typo3/ajax/mindfula11y'],
        );
        $request->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));
        $request->getBody()->rewind();

        return $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
    }

    /**
     * Build a backend AJAX GET request with query parameters.
     *
     * @param array<string, mixed> $queryParams
     */
    protected function createGetRequest(array $queryParams): ServerRequestInterface
    {
        $request = new ServerRequest(
            'https://typo3-testing.local/typo3/ajax/mindfula11y?' . http_build_query($queryParams),
            'GET',
            'php://temp',
            [],
            ['HTTP_HOST' => 'typo3-testing.local', 'HTTPS' => 'on', 'REQUEST_URI' => '/typo3/ajax/mindfula11y'],
        );

        return $request
            ->withQueryParams($queryParams)
            ->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
    }

    /**
     * Write the frontend site configuration for the fixture's root page 1
     * (language 0 "en" at /, language 1 "fr" at /fr/). Needed by suites whose
     * code paths resolve sites or build preview URLs.
     */
    protected function writeDefaultSiteConfiguration(): void
    {
        $this->get(SiteWriter::class)->write('main', [
            'rootPageId' => 1,
            'base' => 'https://example.com/',
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
     * Decode a JSON response body for assertions.
     *
     * @return array<string, mixed>
     */
    protected function decodeJsonResponse(\Psr\Http\Message\ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body, 'Response body is not valid JSON');

        return $body;
    }
}
