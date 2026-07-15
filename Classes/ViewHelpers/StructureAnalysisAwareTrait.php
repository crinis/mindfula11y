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

namespace MindfulMarkup\MindfulA11y\ViewHelpers;

use MindfulMarkup\MindfulA11y\Domain\Model\StructureAnalysisTicket;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Shared structure-analysis request handling for tag-based ViewHelpers.
 *
 * Requires the consuming class to extend (or otherwise provide) TYPO3Fluid's
 * AbstractTagBasedViewHelper: uses `$this->tag`, `$this->arguments` and
 * `$this->renderingContext` inherited from there.
 */
trait StructureAnalysisAwareTrait
{
    /**
     * Returns the current PSR-7 request from the rendering context, if available.
     *
     * @return ServerRequestInterface|null The current request or null if not available.
     */
    protected function getRequest(): ?ServerRequestInterface
    {
        if ($this->renderingContext->hasAttribute(ServerRequestInterface::class)) {
            return $this->renderingContext->getAttribute(ServerRequestInterface::class);
        }
        return null;
    }

    /**
     * Checks if this request carries a validated structure-analysis capability.
     *
     * @return bool True for an authenticated structure analysis request, false otherwise.
     */
    protected function isStructureAnalysisRequest(): bool
    {
        $request = $this->getRequest();
        return $request !== null && StructureAnalysisTicket::fromRequest($request) !== null;
    }

    /**
     * Check if data to fetch the record information is available.
     *
     * @return bool True if record information is available, false otherwise.
     */
    protected function hasRecordInformation(): bool
    {
        return !empty($this->arguments['recordUid'])
            && !empty($this->arguments['recordTableName'])
            && !empty($this->arguments['recordColumnName']);
    }

    /**
     * Adds the record coordinate data attributes (table name, column name, UID) to the
     * tag, if record information is available. Used by structure-analysis requests to
     * resolve edit links and TCA options for the rendered element.
     *
     * @return void
     */
    protected function addRecordDataAttributes(): void
    {
        if ($this->hasRecordInformation()) {
            $this->tag->addAttribute('data-mindfula11y-record-table-name', $this->arguments['recordTableName']);
            $this->tag->addAttribute('data-mindfula11y-record-column-name', $this->arguments['recordColumnName']);
            $this->tag->addAttribute('data-mindfula11y-record-uid', $this->arguments['recordUid']);
        }
    }
}
