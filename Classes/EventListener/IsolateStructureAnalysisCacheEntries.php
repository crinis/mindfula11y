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

namespace MindfulMarkup\MindfulA11y\EventListener;

use MindfulMarkup\MindfulA11y\Domain\Model\StructureAnalysisTicket;
use TYPO3\CMS\Frontend\ContentObject\Event\BeforeStdWrapContentStoredInCacheEvent;

/**
 * Keeps structure-analysis renders out of the shared stdWrap.cache entries.
 *
 * An analysis render is a preview: it may show a hidden page, a workspace
 * draft, a simulated time or a simulated frontend group. Its page cache is
 * disabled (StructureAnalysisDisableCacheMiddleware), which also skips every
 * stdWrap.cache READ — but core writes stdWrap.cache entries regardless of
 * the cache instruction (ContentObjectRenderer::stdWrap_cacheStore(), TYPO3
 * 13 and 14 alike). A menu cached under a shared key would then serve the
 * preview's content to every later visitor until the entry expires.
 *
 * The event cannot cancel the write, so the entry is made unreachable
 * instead: a key no other request can compute, with the shortest lifetime
 * the caching framework accepts (0 would mean "unlimited"). Backends with
 * native expiry drop it by themselves; file and database backends remove it
 * on the next cache garbage collection.
 */
final readonly class IsolateStructureAnalysisCacheEntries
{
    private const KEY_PREFIX = 'mindfula11y_structure_analysis_';
    private const LIFETIME = 1;

    public function __invoke(BeforeStdWrapContentStoredInCacheEvent $event): void
    {
        if (StructureAnalysisTicket::fromRequest($event->getContentObjectRenderer()->getRequest()) === null) {
            return;
        }

        $event->setKey(self::KEY_PREFIX . bin2hex(random_bytes(16)));
        $event->setLifetime(self::LIFETIME);
    }
}
