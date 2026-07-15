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

namespace MindfulMarkup\MindfulA11y\Backend;

use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\Buttons\DropDown\DropDownRadio;
use TYPO3\CMS\Backend\Template\Components\Buttons\DropDownButton;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Builds and places the accessibility module's doc-header selector menus.
 *
 * Owns only the version-dependent DropDownButton mechanics; the menu *items*
 * (features, languages, tables, page levels) are assembled by the controller
 * and the feature renderers, which know their context.
 */
final readonly class DocHeaderMenuBuilder
{
    public function __construct(
        private UriBuilder $backendUriBuilder,
    ) {}

    /**
     * Build a doc header dropdown button from a list of single-select items.
     *
     * The selector menus (feature, language, page levels, table) are rendered as
     * button bar DropDownButtons rather than registered MenuRegistry menus: TYPO3
     * v14's DocHeaderComponent only renders the *first* registered MenuRegistry
     * menu (it calls reset($menus)), so registering several would silently drop
     * all but one. DropDownButtons in the button bar render on both v13 and v14.
     *
     * TYPO3 v14 renders the active item via setShowActiveLabelText(), which keeps
     * the category label visually hidden and announced to screen readers. TYPO3 v13
     * gets the previous dual-compatible label fallback, "<category>: <active item>".
     * Returns null when there are fewer than two items, matching the old behaviour
     * of hiding menus that offer no real choice.
     *
     * @param string $categoryLabel Already-localised category label (e.g. "Language").
     * @param array<int, array{title: string, href: string, active: bool}> $items
     */
    public function buildDropDown(string $categoryLabel, array $items): ?DropDownButton
    {
        if (count($items) < 2) {
            return null;
        }

        $button = $this->createDropDownButton()
            ->setLabel($categoryLabel)
            ->setShowLabelText(true);
        if ($this->isTypo3VersionAtLeast('14.0')) {
            $button->setShowActiveLabelText(true);
        }

        $activeTitle = null;
        foreach ($items as $item) {
            /** @var DropDownRadio $radio */
            $radio = GeneralUtility::makeInstance(DropDownRadio::class)
                ->setLabel($item['title'])
                ->setHref($item['href'])
                ->setActive($item['active']);
            $button->addItem($radio);
            if ($item['active']) {
                $activeTitle = $item['title'];
            }
        }

        if (!$this->isTypo3VersionAtLeast('14.0')) {
            $button->setLabel($activeTitle !== null ? $categoryLabel . ': ' . $activeTitle : $categoryLabel);
        }

        return $button;
    }

    /**
     * Add a selector dropdown to the module doc header button bar (left position).
     *
     * Null buttons (fewer than two items) are skipped so the button bar never
     * receives an invalid button.
     */
    public function addDropDown(ModuleTemplate $moduleTemplate, ?DropDownButton $button, int $group): void
    {
        if ($button === null) {
            return;
        }
        $moduleTemplate->getDocHeaderComponent()->getButtonBar()->addButton(
            $button,
            ButtonBar::BUTTON_POSITION_LEFT,
            $group
        );
    }

    /**
     * Add the language selector using TYPO3 v14's dedicated DocHeader slot.
     *
     * TYPO3 v13 does not have that slot, so it keeps the previous button-bar
     * placement.
     */
    public function addLanguageSelector(ModuleTemplate $moduleTemplate, ?DropDownButton $button): void
    {
        if ($button === null) {
            return;
        }
        if ($this->isTypo3VersionAtLeast('14.0')) {
            $moduleTemplate->getDocHeaderComponent()->setLanguageSelector($button);
            return;
        }
        $this->addDropDown($moduleTemplate, $button, 2);
    }

    /**
     * Create a backend dropdown button.
     *
     * Uses GeneralUtility::makeInstance() directly instead of
     * ButtonBar::makeDropDownButton(), deprecated on TYPO3 v14 (#107823);
     * equivalent and dual-compat (v13 + v14).
     */
    public function createDropDownButton(): DropDownButton
    {
        return GeneralUtility::makeInstance(DropDownButton::class);
    }

    /**
     * Build the URI of a menu item: the module route with the context's page,
     * language, and feature, overridden by the given parameters.
     *
     * @param array<string, mixed> $additionalParams
     */
    public function buildMenuItemUri(ModuleContext $context, array $additionalParams): string
    {
        $params = array_replace([
            'id' => $context->pageId,
            'languageId' => $context->languageId,
            'feature' => $context->feature->value,
        ], $additionalParams);

        return (string)$this->backendUriBuilder->buildUriFromRoute('mindfula11y_accessibility', $params);
    }

    private function isTypo3VersionAtLeast(string $version): bool
    {
        return version_compare((new Typo3Version())->getVersion(), $version, '>=');
    }
}
