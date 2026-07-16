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

use MindfulMarkup\MindfulA11y\Service\PermissionService;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Verifies the functional test harness itself: the extension installs into a
 * fresh TYPO3 instance and its services are container-resolvable.
 */
final class InfrastructureSmokeTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['mindfulmarkup/mindfula11y'];

    public function testExtensionServicesAreWired(): void
    {
        self::assertInstanceOf(PermissionService::class, $this->get(PermissionService::class));
    }
}
