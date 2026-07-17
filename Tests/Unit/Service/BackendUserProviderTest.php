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

namespace MindfulMarkup\MindfulA11y\Tests\Unit\Service;

use MindfulMarkup\MindfulA11y\Service\BackendUserProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

final class BackendUserProviderTest extends TestCase
{
    private BackendUserProvider $subject;
    private bool $backendUserWasSet;
    private mixed $previousBackendUser;

    protected function setUp(): void
    {
        $this->subject = new BackendUserProvider();
        $this->backendUserWasSet = array_key_exists('BE_USER', $GLOBALS);
        $this->previousBackendUser = $GLOBALS['BE_USER'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->backendUserWasSet) {
            $GLOBALS['BE_USER'] = $this->previousBackendUser;
        } else {
            unset($GLOBALS['BE_USER']);
        }
    }

    #[Test]
    public function authenticatedLookupRejectsMissingBackendUser(): void
    {
        unset($GLOBALS['BE_USER']);

        self::assertNull($this->subject->getAuthenticated());
    }

    #[Test]
    public function authenticatedLookupRejectsInitializedButAnonymousBackendUser(): void
    {
        $GLOBALS['BE_USER'] = new BackendUserAuthentication();

        self::assertNull($this->subject->getAuthenticated());
    }

    #[Test]
    public function requiredLookupRejectsInitializedButAnonymousBackendUser(): void
    {
        $GLOBALS['BE_USER'] = new BackendUserAuthentication();

        $this->expectException(\LogicException::class);
        $this->subject->get();
    }

    #[Test]
    public function bothLookupsReturnAuthenticatedBackendUser(): void
    {
        $backendUser = new BackendUserAuthentication();
        $backendUser->user = ['uid' => 42];
        $GLOBALS['BE_USER'] = $backendUser;

        self::assertSame($backendUser, $this->subject->getAuthenticated());
        self::assertSame($backendUser, $this->subject->get());
    }
}
