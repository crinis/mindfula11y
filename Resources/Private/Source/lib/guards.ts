/*
 * Mindful A11y extension for TYPO3 integrating accessibility tools into the backend.
 * Copyright (C) 2026  Mindful Markup, Felix Spittel
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 */

/** Small runtime type guards shared by every module validating untrusted wire data. */

export const isObject = (value: unknown): value is Record<string, unknown> =>
    typeof value === 'object' && value !== null;

export const isBoundedString = (value: unknown, maximum = 10_000): value is string =>
    typeof value === 'string' && value.length <= maximum;

export const isStringMap = (value: unknown): boolean =>
    isObject(value) &&
    Object.keys(value).length <= 100 &&
    Object.entries(value).every(([key, label]) => key.length <= 128 && isBoundedString(label, 1_000));
