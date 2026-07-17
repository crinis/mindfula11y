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
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 */

namespace MindfulMarkup\MindfulA11y\Pagination;

use TYPO3\CMS\Core\Pagination\PaginatorInterface;

/**
 * Paginator over one already-fetched page of items plus a total count.
 *
 * Core's ArrayPaginator needs the FULL item list and slices it itself — for
 * query-backed lists that would mean fetching (or null-padding) every matching
 * record just to render one page. This paginator takes the current page's
 * items as they came from the query (LIMIT/OFFSET) and derives the page
 * geometry from the separately counted total.
 *
 * Value object: the withers return adjusted copies but cannot re-slice —
 * callers re-query when the page or page size actually changes.
 */
final readonly class SlicePaginator implements PaginatorInterface
{
    private int $currentPageNumber;

    /**
     * @param array<int|string, mixed> $items The current page's items only.
     * @param int $totalAmount Total number of items across all pages.
     * @param int $currentPageNumber Clamped into [1, numberOfPages].
     * @param int $itemsPerPage
     */
    public function __construct(
        private array $items,
        private int $totalAmount,
        int $currentPageNumber,
        private int $itemsPerPage,
    ) {
        $this->currentPageNumber = max(1, min($currentPageNumber, $this->getNumberOfPages()));
    }

    public function withItemsPerPage(int $itemsPerPage): PaginatorInterface
    {
        return new self($this->items, $this->totalAmount, $this->currentPageNumber, $itemsPerPage);
    }

    public function withCurrentPageNumber(int $currentPageNumber): PaginatorInterface
    {
        return new self($this->items, $this->totalAmount, $currentPageNumber, $this->itemsPerPage);
    }

    /** @return array<int|string, mixed> */
    public function getPaginatedItems(): array
    {
        return $this->items;
    }

    public function getNumberOfPages(): int
    {
        return max(1, (int)ceil($this->totalAmount / max(1, $this->itemsPerPage)));
    }

    public function getCurrentPageNumber(): int
    {
        return $this->currentPageNumber;
    }

    public function getKeyOfFirstPaginatedItem(): int
    {
        if ($this->totalAmount === 0) {
            return 0;
        }
        return ($this->currentPageNumber - 1) * $this->itemsPerPage;
    }

    public function getKeyOfLastPaginatedItem(): int
    {
        if ($this->totalAmount === 0) {
            return 0;
        }
        $sliceSize = count($this->items) > 0 ? count($this->items) : $this->itemsPerPage;
        return min($this->getKeyOfFirstPaginatedItem() + $sliceSize, $this->totalAmount) - 1;
    }
}
