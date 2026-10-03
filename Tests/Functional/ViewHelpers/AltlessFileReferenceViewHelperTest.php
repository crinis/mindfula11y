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

namespace MindfulMarkup\MindfulA11y\Tests\Functional\ViewHelpers;

use MindfulMarkup\MindfulA11y\Service\AltTextFinderService;
use MindfulMarkup\MindfulA11y\Tests\Functional\AbstractAuthorizationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Fluid\Core\Rendering\RenderingContextFactory;
use TYPO3Fluid\Fluid\View\TemplateView;

/**
 * The rendered reference must agree with the listing that produced it.
 *
 * The module lists a reference as missing alternative text based on the
 * WORKSPACE-effective file metadata, while FAL's own metadata lookup is
 * workspace-restricted rather than workspace-overlaid (core's overlay listener
 * for FAL metadata runs in the frontend only). Rendering the file property
 * directly would therefore advertise live text as an inherited alternative on
 * exactly the references the list just counted as missing.
 */
final class AltlessFileReferenceViewHelperTest extends AbstractAuthorizationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/WorkspaceAltTextSupplement.csv');
    }

    private function setLiveMetadataAlternative(string $alternative): void
    {
        $this->get(ConnectionPool::class)
            ->getConnectionForTable('sys_file_metadata')
            ->update('sys_file_metadata', ['alternative' => $alternative], ['uid' => 1]);
    }

    private function insertMetadataVersion(string $alternative, int $versionState = 0): void
    {
        $this->get(ConnectionPool::class)
            ->getConnectionForTable('sys_file_metadata')
            ->insert('sys_file_metadata', [
                'uid' => 500,
                'pid' => 0,
                'file' => 1,
                'alternative' => $alternative,
                't3ver_oid' => 1,
                't3ver_wsid' => 1,
                't3ver_state' => $versionState,
            ]);
    }

    private function renderFirstListedReference(?ServerRequestInterface $request = null): string
    {
        $references = $this->get(AltTextFinderService::class)
            ->findAltlessFileReferencePage(10, 0, 0, [], 1, 100, tableName: 'tt_content')['items'];
        self::assertNotSame([], $references, 'the listing must surface a reference to render');

        $context = $this->get(RenderingContextFactory::class)->create([], $request);
        $context->getTemplatePaths()->setTemplateSource(
            '<html xmlns:mindfula11y="http://typo3.org/ns/MindfulMarkup/MindfulA11y/ViewHelpers" data-namespace-typo3-fluid="true">'
            . '<mindfula11y:altlessFileReference fileReference="{reference}" />'
            . '</html>'
        );

        $view = new TemplateView($context);
        $view->assign('reference', $references[0]);

        return $view->render();
    }

    #[Test]
    #[DataProvider('emptyingMetadataDraftProvider')]
    public function noFallbackIsAdvertisedWhenTheWorkspaceDraftHasNoMetadataAlternative(
        string $alternative,
        int $versionState,
    ): void {
        $this->setLiveMetadataAlternative('Inherited alternative');
        $this->insertMetadataVersion($alternative, $versionState);
        $this->logInBackendUser(2, 1);

        self::assertStringNotContainsString('fallback-alternative', $this->renderFirstListedReference());
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function emptyingMetadataDraftProvider(): array
    {
        return [
            'draft clears the alternative' => ['', 0],
            'draft deletes the metadata row' => ['Inherited alternative', 2],
        ];
    }

    /**
     * Anti-vacuous counterpart: the attribute IS rendered when the effective
     * metadata really carries text, so the assertions above cannot pass merely
     * because the fallback never appears.
     */
    #[Test]
    public function liveWorkspaceStillAdvertisesTheInheritedAlternative(): void
    {
        // Live metadata text hides references from the default filter, so ask
        // for the ones that already have an alternative to get a row to render.
        $this->setLiveMetadataAlternative('Inherited alternative');
        $this->logInBackendUser(2);

        $references = $this->get(AltTextFinderService::class)->findAltlessFileReferencePage(
            10,
            0,
            0,
            [],
            1,
            100,
            filterFileMetaData: false,
            tableName: 'tt_content',
        )['items'];
        self::assertNotSame([], $references);

        $context = $this->get(RenderingContextFactory::class)->create();
        $context->getTemplatePaths()->setTemplateSource(
            '<html xmlns:mindfula11y="http://typo3.org/ns/MindfulMarkup/MindfulA11y/ViewHelpers" data-namespace-typo3-fluid="true">'
            . '<mindfula11y:altlessFileReference fileReference="{reference}" />'
            . '</html>'
        );
        $view = new TemplateView($context);
        $view->assign('reference', $references[0]);

        self::assertStringContainsString('fallback-alternative="Inherited alternative"', $view->render());
    }

    /**
     * File metadata has no "All languages" row: an all-languages reference
     * inherits the metadata text of the language being listed — the row the
     * listing judged it by — instead of advertising no fallback at all.
     */
    #[Test]
    public function allLanguagesReferenceAdvertisesTheListedLanguagesMetadataText(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/AllLanguagesReferenceSupplement.csv');
        $this->setLiveMetadataAlternative('Inherited alternative');
        $this->logInBackendUser(2);

        $references = $this->get(AltTextFinderService::class)->findAltlessFileReferencePage(
            10,
            0,
            0,
            [],
            1,
            100,
            filterFileMetaData: false,
            tableName: 'tt_content',
        )['items'];
        $allLanguagesReference = array_values(array_filter(
            $references,
            static fn($reference): bool => (int)$reference->getUid() === 410,
        ));
        self::assertCount(1, $allLanguagesReference, 'the listing must surface the all-languages reference');

        $context = $this->get(RenderingContextFactory::class)->create();
        $context->getTemplatePaths()->setTemplateSource(
            '<html xmlns:mindfula11y="http://typo3.org/ns/MindfulMarkup/MindfulA11y/ViewHelpers" data-namespace-typo3-fluid="true">'
            . '<mindfula11y:altlessFileReference fileReference="{reference}" languageId="0" />'
            . '</html>'
        );
        $view = new TemplateView($context);
        $view->assign('reference', $allLanguagesReference[0]);

        self::assertStringContainsString('fallback-alternative="Inherited alternative"', $view->render());
    }

    #[Test]
    public function recordEditLinkReturnsToTheCurrentModuleRequest(): void
    {
        $this->logInBackendUser(2);
        // Core's relative form (normalizedParams' request URI): an absolute URI
        // behind a TLS-terminating proxy would carry http:// and fail
        // sanitizeLocalUrl() against the https:// site URL.
        $request = new ServerRequest(
            'https://backend.example/typo3/module/web/mindfula11y?id=10&feature=missingAltText',
            'GET',
            'php://temp',
            [],
            ['HTTP_HOST' => 'backend.example', 'HTTPS' => 'on', 'REQUEST_URI' => '/typo3/module/web/mindfula11y?id=10&feature=missingAltText'],
        );
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));

        $output = $this->renderFirstListedReference($request);

        self::assertSame(1, preg_match('/record-edit-link="([^"]+)"/', $output, $matches), 'the editor must get an edit link');
        parse_str((string)parse_url(html_entity_decode($matches[1]), PHP_URL_QUERY), $query);
        self::assertSame('/typo3/module/web/mindfula11y?id=10&feature=missingAltText', $query['returnUrl'] ?? null);
    }

    #[Test]
    public function recordEditLinkOmitsReturnUrlWithoutNormalizedParams(): void
    {
        $this->logInBackendUser(2);

        $output = $this->renderFirstListedReference(
            new ServerRequest('https://backend.example/typo3/module/web/mindfula11y?id=10')
        );

        self::assertSame(1, preg_match('/record-edit-link="([^"]+)"/', $output, $matches), 'the editor must get an edit link');
        self::assertStringNotContainsString('returnUrl', html_entity_decode($matches[1]));
    }

    #[Test]
    public function recordEditLinkOmitsReturnUrlWithoutARequest(): void
    {
        $this->logInBackendUser(2);

        $output = $this->renderFirstListedReference();

        self::assertSame(1, preg_match('/record-edit-link="([^"]+)"/', $output, $matches), 'the editor must get an edit link');
        self::assertStringNotContainsString('returnUrl', html_entity_decode($matches[1]));
    }
}
