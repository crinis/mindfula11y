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

namespace MindfulMarkup\MindfulA11y\Tests\Unit\EventListener;

use MindfulMarkup\MindfulA11y\Domain\Model\StructureAnalysisTicket;
use MindfulMarkup\MindfulA11y\EventListener\IsolateStructureAnalysisCacheEntries;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\CMS\Frontend\ContentObject\Event\BeforeStdWrapContentStoredInCacheEvent;

/**
 * Structure-analysis renders must never write the shared stdWrap.cache entry
 * public renders read, yet must not grow the cache without bound either: the
 * isolated key is derived from the original one, so repeated analyses of the
 * same page reuse one entry per original key.
 */
final class IsolateStructureAnalysisCacheEntriesTest extends TestCase
{
    #[Test]
    public function aPublicRenderKeepsItsCacheEntry(): void
    {
        $event = $this->storeEvent('main_menu_10', withTicket: false);

        (new IsolateStructureAnalysisCacheEntries())($event);

        self::assertSame('main_menu_10', $event->getKey());
        self::assertSame(3600, $event->getLifetime());
    }

    #[Test]
    public function anAnalysisRenderWritesAnIsolatedShortLivedEntry(): void
    {
        $event = $this->storeEvent('main_menu_10', withTicket: true);

        (new IsolateStructureAnalysisCacheEntries())($event);

        self::assertNotSame('main_menu_10', $event->getKey());
        self::assertStringStartsWith('mindfula11y_structure_analysis_', $event->getKey());
        self::assertMatchesRegularExpression('/^[a-zA-Z0-9_%\-&]{1,250}$/', $event->getKey(), 'a valid cache identifier');
        self::assertSame(1, $event->getLifetime());
    }

    #[Test]
    public function theIsolatedKeyIsStablePerOriginalKey(): void
    {
        $first = $this->storeEvent('main_menu_10', withTicket: true);
        $again = $this->storeEvent('main_menu_10', withTicket: true);
        $other = $this->storeEvent('main_menu_11', withTicket: true);
        $listener = new IsolateStructureAnalysisCacheEntries();

        $listener($first);
        $listener($again);
        $listener($other);

        self::assertSame($first->getKey(), $again->getKey(), 'repeated analyses reuse one entry');
        self::assertNotSame($first->getKey(), $other->getKey(), 'distinct original keys stay distinct');
    }

    private function storeEvent(string $key, bool $withTicket): BeforeStdWrapContentStoredInCacheEvent
    {
        $request = new ServerRequest('https://example.com/');
        if ($withTicket) {
            $request = $request->withAttribute(StructureAnalysisTicket::REQUEST_ATTRIBUTE, new StructureAnalysisTicket(
                requestId: str_repeat('a', 32),
                pageId: 10,
                languageId: 0,
                workspaceId: 0,
                pageRecordSnapshot: str_repeat('b', 64),
                backendUserId: 2,
                backendOrigin: 'https://backend.example',
                frontendOrigin: 'https://example.com',
                target: 'https://example.com/',
                expiresAt: time() + 15,
            ));
        }
        $contentObjectRenderer = $this->createMock(ContentObjectRenderer::class);
        $contentObjectRenderer->method('getRequest')->willReturn($request);

        return new BeforeStdWrapContentStoredInCacheEvent(
            content: '<nav>menu</nav>',
            tags: [],
            key: $key,
            lifetime: 3600,
            configuration: [],
            contentObjectRenderer: $contentObjectRenderer,
        );
    }
}
