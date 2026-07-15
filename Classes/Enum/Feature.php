<?php
declare(strict_types=1);

namespace MindfulMarkup\MindfulA11y\Enum;

/**
 * The features of the accessibility backend module.
 *
 * The backing values are persisted in module data and appear in module URLs.
 * Unknown stored values (e.g. the pre-rename 'general') fall back to
 * OVERVIEW where the enum is resolved, so renames stay backwards-compatible.
 */
enum Feature: string
{
    case OVERVIEW = 'overview';
    case MISSING_ALT_TEXT = 'missingAltText';
    case SCAN = 'scan';
}
