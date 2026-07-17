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

namespace MindfulMarkup\MindfulA11y\Enum;

enum AltTextDemandAuthorizationFailure
{
    case NO_PAGE_ACCESS;
    case NO_FILE_ACCESS;
    case INVALID_SNAPSHOT;
    case FILE_NOT_FOUND;
    case NO_FILE_MOUNT_ACCESS;
}
