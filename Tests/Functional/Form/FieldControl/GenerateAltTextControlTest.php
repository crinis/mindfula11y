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

namespace MindfulMarkup\MindfulA11y\Tests\Functional\Form\FieldControl;

use MindfulMarkup\MindfulA11y\Form\FieldControl\GenerateAltTextControl;
use MindfulMarkup\MindfulA11y\Tests\Functional\AbstractAuthorizationTestCase;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The generate-alt-text field control is registered in TCA unconditionally;
 * whether it shows up is decided per render from the live extension
 * configuration. So the TCA registration must not depend on the API key
 * (baking it into the TCA cache would need a cache flush after every key
 * change), and render() must yield nothing while generation is disabled or
 * unconfigured.
 *
 * Uses the shared AuthorizationScenario.csv fixture: editor 2, file reference
 * 1 on page 10 pointing at sys_file 1 inside the editor's file mount.
 */
final class GenerateAltTextControlTest extends AbstractAuthorizationTestCase
{
    private function renderForFileReference(): array
    {
        $this->logInBackendUser(2);
        $subject = GeneralUtility::makeInstance(GenerateAltTextControl::class);
        $subject->setData([
            'tableName' => 'sys_file_reference',
            'fieldName' => 'alternative',
            'effectivePid' => 10,
            'databaseRow' => [
                'uid' => 1,
                'uid_local' => [['uid' => 1]],
                'sys_language_uid' => [0],
            ],
            'parameterArray' => [
                'itemFormElName' => 'data[sys_file_reference][1][alternative]',
            ],
        ]);

        return $subject->render();
    }

    public function testFieldControlIsRegisteredWithoutAnApiKey(): void
    {
        // The functional instance has no OpenAI key configured.
        self::assertSame(
            ['renderType' => 'mindfula11yGenerateAltText'],
            $GLOBALS['TCA']['sys_file_reference']['columns']['alternative']['config']['fieldControl']['mindfula11yGenerateAltText'] ?? null
        );
        self::assertSame(
            ['renderType' => 'mindfula11yGenerateAltText'],
            $GLOBALS['TCA']['sys_file_metadata']['columns']['alternative']['config']['fieldControl']['mindfula11yGenerateAltText'] ?? null
        );
    }

    public function testRendersTheButtonWhenGenerationIsConfigured(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mindfula11y']['openAIApiKey'] = 'sk-functional-test';

        $result = $this->renderForFileReference();

        self::assertSame('actions-refresh', $result['iconIdentifier'] ?? null);
        self::assertCount(1, $result['javaScriptModules'] ?? []);
    }

    public function testRendersNothingWithoutAnApiKey(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mindfula11y']['openAIApiKey'] = '';

        self::assertSame([], $this->renderForFileReference());
    }

    public function testRendersNothingWhenGenerationIsDisabled(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mindfula11y']['openAIApiKey'] = 'sk-functional-test';
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mindfula11y']['disableAltTextGeneration'] = '1';

        self::assertSame([], $this->renderForFileReference());
    }
}
