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

namespace MindfulMarkup\MindfulA11y\Domain\Model;

/**
 * The outcome of one alternative-text generation: a text to fill in, or the
 * model's verdict that the image is purely decorative and should carry none.
 *
 * The verdict is not a text: storing it as alternative text would make screen
 * readers announce the word itself. Whether to mark the reference decorative
 * stays the editor's decision.
 */
final readonly class GeneratedAltText
{
    private function __construct(
        public string $altText,
        public bool $decorative,
    ) {}

    public static function text(string $altText): self
    {
        return new self($altText, false);
    }

    public static function decorative(): self
    {
        return new self('', true);
    }
}
