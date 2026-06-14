<?php
declare(strict_types=1);

/*
 * Mindful A11y extension for TYPO3 integrating accessibility tools into the backend.
 * Copyright (C) 2025  Mindful Markup, Felix Spittel
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

namespace MindfulMarkup\MindfulA11y\EventListener;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\TypoScript\IncludeTree\Event\BeforeLoadedUserTsConfigEvent;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

/**
 * Hide the (optional) admin panel during structure-analysis requests so its
 * markup does not pollute the analysed frontend DOM.
 *
 * Replaces the former DisableAdminPanel middleware, whose addUserTSConfig() API
 * was removed in v14 (#105377). BeforeLoadedUserTsConfigEvent behaves
 * identically on v13 and v14 and only fires for an authenticated backend user,
 * so neither a version branch nor an isLoggedIn check is needed.
 *
 * Note: user TSconfig is cached per package-state, so this header-conditional
 * hide is best-effort (the cache is normally first built by a regular login).
 * For a guaranteed clean DOM, fetch the analysed page without the backend
 * session so the admin panel never renders.
 */
final class HideAdminPanelForStructureAnalysis
{
    #[AsEventListener('mindfula11y/hide-admin-panel-for-structure-analysis')]
    public function __invoke(BeforeLoadedUserTsConfigEvent $event): void
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if (
            $request instanceof ServerRequestInterface
            && $request->hasHeader('Mindfula11y-Structure-Analysis')
            && ExtensionManagementUtility::isLoaded('adminpanel')
        ) {
            $event->addTsConfig('admPanel.hide = 1');
        }
    }
}
