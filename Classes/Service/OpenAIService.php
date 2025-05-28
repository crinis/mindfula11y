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

use TYPO3\CMS\Core\Http\RequestFactory;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use RuntimeException;

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
     * @param RequestFactory $requestFactory
     */
    public function __construct(
        protected readonly ExtensionConfiguration $extensionConfiguration,
        protected readonly RequestFactory $requestFactory,
    ) {}

    /**
     * Chat with OpenAI's GPT-4o model.
     * 
     * @param array $messages The messages to be sent to the OpenAI API.
     * 
     * @return string|null The response message from the OpenAI API or null if the request fails.
     */
    public function chat(array $messages): ?string
    {
        $apiKey = $this->getApiKey();
        $model = $this->getChatModelName();
        $url = 'https://api.openai.com/v1/chat/completions';
        $headers = [
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type' => 'application/json',
        ];
        $body = [
            'model' => $model,
            'messages' => $messages,
        ];
        $options = [
            'headers' => $headers,
            'body' => json_encode($body),
        ];
        /** @var ResponseInterface $response */
        $response = $this->requestFactory->request($url, 'POST', $options);

        try {
            $responseBody = $response->getBody()->getContents();
        } catch (RuntimeException $e) {
            return null;
        }
        
        $data = json_decode($responseBody, true);

        return $data['choices'][0]['message']['content'] ?? null;
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
