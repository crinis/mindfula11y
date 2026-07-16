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

use MindfulMarkup\MindfulA11y\Service\ModuleLabelService;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Localized flash messages and the message-only module view.
 *
 * Using classes must provide `$this->flashMessageService`
 * (TYPO3\CMS\Core\Messaging\FlashMessageService).
 */
trait ModuleNoticeTrait
{
    private const MODULE_LANGUAGE_FILE = ModuleLabelService::LANGUAGE_FILE;

    /**
     * Adds a localized flash message: the title from `<labelKey>`, the message
     * body from `<labelKey>.description`.
     */
    protected function addLocalizedFlashMessage(string $labelKey, ContextualFeedbackSeverity $severity): void
    {
        $languageService = $this->getLanguageService();
        $flashMessage = GeneralUtility::makeInstance(
            FlashMessage::class,
            $languageService->sL(self::MODULE_LANGUAGE_FILE . $labelKey . '.description'),
            $languageService->sL(self::MODULE_LANGUAGE_FILE . $labelKey),
            $severity
        );
        $this->flashMessageService->getMessageQueueByIdentifier()->addMessage($flashMessage);
    }

    /**
     * Renders the message-only Info view after adding the given localized
     * flash message — the uniform way a feature reports that it cannot render.
     */
    protected function noticeResponse(
        ModuleTemplate $moduleTemplate,
        string $labelKey,
        ContextualFeedbackSeverity $severity,
        int $statusCode = 200,
    ): ResponseInterface {
        $this->addLocalizedFlashMessage($labelKey, $severity);
        return $moduleTemplate->renderResponse('Backend/Info')->withStatus($statusCode);
    }

    /** Provided by the backend request stack for every module route. */
    protected function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
