<?php
declare(strict_types=1);

namespace MindfulMarkup\MindfulA11y\Enum;

/**
 * Enum representing features of the Accessibility Controller.
 */
enum Feature: string
{
    case GENERAL = 'general';
    case MISSING_ALT_TEXT = 'missingAltText';
}
