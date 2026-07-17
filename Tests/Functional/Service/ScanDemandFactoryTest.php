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

use MindfulMarkup\MindfulA11y\Service\ScanDemandFactory;
use MindfulMarkup\MindfulA11y\Tests\Functional\AbstractAuthorizationTestCase;
use TYPO3\CMS\Backend\Utility\BackendUtility;

/**
 * The demand's language must always be the language of the page record the
 * preview is built from. When the module's selected language has no page
 * translation, the preview falls back to the default-language record — a
 * demand signed with the selected language instead would always fail at
 * redemption (getLocalizedPageRecord finds no translation → pageNotFound,
 * and matchesCurrentSnapshot rejects the record/language mismatch).
 */
final class ScanDemandFactoryTest extends AbstractAuthorizationTestCase
{
    private function subject(): ScanDemandFactory
    {
        return $this->get(ScanDemandFactory::class);
    }

    public function testDemandForUntranslatedPageSignsTheFallbackRecordLanguage(): void
    {
        $this->logInBackendUser(2);
        // Page 18 has no language-1 translation: the caller renders the
        // default-language record as preview fallback and passes it here.
        $pageRecord = BackendUtility::getRecord('pages', 18);
        self::assertIsArray($pageRecord);

        $demand = $this->subject()->create($pageRecord, 18, 'https://example.com/page-only');

        self::assertNotNull($demand);
        self::assertSame(0, $demand->getLanguageId());
    }

    public function testDemandForTranslatedPageSignsTheLocalizedRecordLanguage(): void
    {
        $this->logInBackendUser(2);
        // Page 30 is the language-1 translation of page 10.
        $pageRecord = BackendUtility::getRecord('pages', 30);
        self::assertIsArray($pageRecord);

        $demand = $this->subject()->create($pageRecord, 10, 'https://example.com/fr/editable');

        self::assertNotNull($demand);
        self::assertSame(1, $demand->getLanguageId());
    }
}
