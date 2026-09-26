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

namespace MindfulMarkup\MindfulA11y\Service;

use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * The extension's instance-wide settings (ext_conf_template.txt).
 *
 * Single read path for every consumer: the whole configuration array is read
 * once per instance, on first access. An unsynced/legacy deployment (where
 * extension:setup has not run) has no configuration at all and
 * ExtensionConfiguration::get() throws — that reads as "every key at its
 * default" here, because these settings are consulted on render paths
 * (module, FormEngine, frontend middleware) that an exception would break.
 */
final readonly class ExtensionSettings
{
    private const EXTENSION_KEY = 'mindfula11y';

    /** @var array<string, mixed> */
    private array $settings;

    public function __construct(
        private ExtensionConfiguration $extensionConfiguration,
    ) {}

    /**
     * The stored value of $key, or $default when it is not set (or the
     * configuration is missing entirely). Values are returned as stored —
     * extension configuration keeps scalars as strings — so callers cast.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    private function all(): array
    {
        if (!isset($this->settings)) {
            try {
                $settings = $this->extensionConfiguration->get(self::EXTENSION_KEY);
            } catch (ExtensionConfigurationExtensionNotConfiguredException) {
                $settings = [];
            }
            $this->settings = is_array($settings) ? $settings : [];
        }

        return $this->settings;
    }
}
