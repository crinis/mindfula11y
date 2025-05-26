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

namespace MindfulMarkup\MindfulA11y\Service;

use OpenAI;
use OpenAI\Client;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Class OpenAIService.
 * 
 * This class is responsible for interacting with OpenAI's API.
 */
class OpenAIService
{
    /**
     * Constructor.
     * 
     * @param ExtensionConfiguration $extensionConfiguration
     */
    public function __construct(
        protected readonly ExtensionConfiguration $extensionConfiguration,
    ) {}

    /**
     * Chat with OpenAI's GPT-4o model.
     * 
     * @param array $messages The messages to be sent to the OpenAI API.
     * 
     * @return string The response message from the OpenAI API.
     * 
     * @throws Exception If the OpenAI API request fails.
     */
    public function chat(array $messages): string
    {
        $client = $this->getClient();

        $response = $client->chat()->create([
            'model' => $this->getChatModelName(),
            'messages' => $messages
        ]);

        return $response['choices'][0]['message']['content'] ?? '';
    }

    /**
     * Get OpenAI API client.
     * 
     * @return Client The OpenAI API client.
     */
    protected function getClient(): Client
    {
        $apiKey = $this->getApiKey();
        return OpenAI::client($apiKey);
    }

    /**
     * Get OpenAI chat model name.
     * 
     * @return string The OpenAI chat model name.
     */
    protected function getChatModelName(): string
    {
        return $this->extensionConfiguration->get('mindfula11y')['openAIChatModel'] ?? 'gpt-4o-mini';
    }

    /**
     * Get OpenAI API key from extension configuration.
     * 
     * @return string The OpenAI API key.
     */
    protected function getApiKey(): string
    {
        return $this->extensionConfiguration->get('mindfula11y')['openAIApiKey'] ?? '';
    }

    /**
     * Check OpenAI chat file ext support.
     * 
     * @param string $extension
     * 
     * @return bool
     */
    public function isChatFileExtSupported(string $extension): bool
    {
        $supportedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        return in_array($extension, $supportedExtensions, true);
    }
}
