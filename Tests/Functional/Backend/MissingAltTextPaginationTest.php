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

namespace MindfulMarkup\MindfulA11y\Tests\Functional\Backend;

use MindfulMarkup\MindfulA11y\Enum\Feature;
use MindfulMarkup\MindfulA11y\Pagination\SlicePaginator;
use MindfulMarkup\MindfulA11y\Tests\Functional\AbstractAuthorizationTestCase;
use TYPO3\CMS\Core\Pagination\SimplePagination;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;

/**
 * The pagination partial must link back to the registered accessibility
 * module route and keep the missing-alt-text feature selected: since the
 * per-feature modules were merged into one, a link built for an unregistered
 * route throws during rendering and takes the whole module view down as soon
 * as the list exceeds one page.
 */
final class MissingAltTextPaginationTest extends AbstractAuthorizationTestCase
{
    public function testPaginationLinksTargetTheAccessibilityModuleWithTheFeaturePreserved(): void
    {
        $this->logInBackendUser(2);

        // 250 matches at 100 per page: page 2 of 3 renders both the previous
        // and next link alongside the page-number list.
        $paginator = new SlicePaginator([], 250, 2, 100);
        $view = $this->get(ViewFactoryInterface::class)->create(new ViewFactoryData(
            templatePathAndFilename: GeneralUtility::getFileAbsFileName(
                'EXT:mindfula11y/Resources/Private/Partials/Backend/MissingAltText/Pagination.html'
            ),
        ));
        $view->assignMultiple([
            'pagination' => new SimplePagination($paginator),
            'paginator' => $paginator,
            'moduleData' => [
                'id' => 10,
                'feature' => Feature::MISSING_ALT_TEXT->value,
                'languageId' => 0,
                'pageLevels' => 0,
                'tableName' => '',
                'filterFileMetaData' => true,
                'currentPage' => 2,
            ],
        ]);

        $html = $view->render();

        self::assertStringNotContainsString('mindfula11y_missingalttext', $html);
        self::assertStringContainsString('feature=missingAltText', $html);
        self::assertStringContainsString('id=10', $html);
        self::assertStringContainsString('currentPage=3', $html); // the next-page link
    }
}
