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
 * The event cannot cancel the write, so the entry is moved out of reach
 * instead: under a prefixed key derived from the original one, with the
 * shortest lifetime the caching framework accepts (0 would mean
 * "unlimited"). Public renders only ever compute the integrator's key, and
 * analysis renders never read (the read is gated on caching being allowed,
 * which the analysis disables), so nothing reads the entry. Deriving the key
 * instead of randomizing it bounds the storage: repeated analyses overwrite
 * one entry per original key rather than adding rows to a database or file
 * backend until the next garbage collection.
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

        $event->setKey(self::KEY_PREFIX . hash('xxh128', $event->getKey()));
        $event->setLifetime(self::LIFETIME);
    }
}
