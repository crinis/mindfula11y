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

namespace MindfulMarkup\MindfulA11y\Tests\Unit\Pagination;

use MindfulMarkup\MindfulA11y\Pagination\SlicePaginator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Pagination\SimplePagination;

/**
 * Paginator over an already-sliced page of items plus a total count — the
 * values SimplePagination and the template read must match what an
 * ArrayPaginator over the full (null-padded) item list produced before.
 */
final class SlicePaginatorTest extends TestCase
{
    #[Test]
    public function exposesTheSliceAndDerivesPageGeometryFromTheTotal(): void
    {
        // Page 2 of 25 items at 10 per page: items 10-19.
        $paginator = new SlicePaginator(['k', 'l', 'm', 'n', 'o', 'p', 'q', 'r', 's', 't'], 25, 2, 10);

        self::assertSame(['k', 'l', 'm', 'n', 'o', 'p', 'q', 'r', 's', 't'], $paginator->getPaginatedItems());
        self::assertSame(3, $paginator->getNumberOfPages());
        self::assertSame(2, $paginator->getCurrentPageNumber());
        self::assertSame(10, $paginator->getKeyOfFirstPaginatedItem());
        self::assertSame(19, $paginator->getKeyOfLastPaginatedItem());
    }

    #[Test]
    public function lastPartialPageReportsItsRealBounds(): void
    {
        // Page 3 of 25 items at 10 per page: items 20-24.
        $paginator = new SlicePaginator(['u', 'v', 'w', 'x', 'y'], 25, 3, 10);

        self::assertSame(3, $paginator->getNumberOfPages());
        self::assertSame(20, $paginator->getKeyOfFirstPaginatedItem());
        self::assertSame(24, $paginator->getKeyOfLastPaginatedItem());
    }

    #[Test]
    public function emptyResultYieldsOnePageWithNoItems(): void
    {
        $paginator = new SlicePaginator([], 0, 1, 10);

        self::assertSame([], $paginator->getPaginatedItems());
        self::assertSame(1, $paginator->getNumberOfPages());
        self::assertSame(1, $paginator->getCurrentPageNumber());
        self::assertSame(0, $paginator->getKeyOfFirstPaginatedItem());
        self::assertSame(0, $paginator->getKeyOfLastPaginatedItem());
    }

    #[Test]
    public function currentPageNumberIsClampedIntoTheValidRange(): void
    {
        $paginator = new SlicePaginator([], 25, 99, 10);

        self::assertSame(3, $paginator->getCurrentPageNumber());
    }

    #[Test]
    public function withersReturnAdjustedCopies(): void
    {
        $paginator = new SlicePaginator(['a'], 25, 1, 10);

        self::assertSame(2, $paginator->withCurrentPageNumber(2)->getCurrentPageNumber());
        self::assertSame(5, $paginator->withItemsPerPage(5)->getNumberOfPages());
        self::assertSame(1, $paginator->getCurrentPageNumber());
    }

    #[Test]
    public function simplePaginationConsumesIt(): void
    {
        $pagination = new SimplePagination(new SlicePaginator(['k'], 25, 2, 10));

        self::assertSame(1, $pagination->getPreviousPageNumber());
        self::assertSame(3, $pagination->getNextPageNumber());
        self::assertSame([1, 2, 3], $pagination->getAllPageNumbers());
    }
}
