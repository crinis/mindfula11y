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
     * Generate a response via the OpenAI Responses API (/v1/responses).
     * 
     * All supported GPT-5 models (gpt-5-nano, gpt-5-mini, gpt-5.1, gpt-5.2) are
     * served exclusively through this endpoint. The `instructions` parameter carries
     * the system prompt; image content items use type `input_image` with a plain
     * string `image_url` and a `detail` level.
     * 
     * @param string $instructions The system instructions for the model.
     * @param array  $messages     Array of message objects, each with `role` and `content`.
     * 
     * @return string|null The generated text or null if the request fails.
     */
    public function respond(string $instructions, array $messages): ?string
    {
        $apiKey = $this->getApiKey();
        $model = $this->getModelName();
        $url = 'https://api.openai.com/v1/responses';
        $headers = [
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type' => 'application/json',
        ];
        $body = [
            'model' => $model,
            'instructions' => $instructions,
            'input' => $messages,
        ];
        $options = [
            'headers' => $headers,
            'body' => json_encode($body),
        ];
        try {
            /** @var ResponseInterface $response */
            $response = $this->requestFactory->request($url, 'POST', $options);
            $responseBody = $response->getBody()->getContents();
        } catch (\Exception $e) {
            return null;
        }

        $data = json_decode($responseBody, true);

        // Traverse output[*] (type==="message") -> content[*] (type==="output_text") -> text
        foreach ($data['output'] ?? [] as $outputItem) {
            if (($outputItem['type'] ?? '') === 'message') {
                foreach ($outputItem['content'] ?? [] as $contentItem) {
                    if (($contentItem['type'] ?? '') === 'output_text') {
                        return $contentItem['text'] ?? null;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Get the configured OpenAI model name.
     * 
     * @return string The OpenAI model name.
     */
    protected function getModelName(): string
    {
        return $this->extensionConfiguration->get('mindfula11y')['openAIChatModel'] ?? 'gpt-5-mini';
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
     * Check if the file extension is supported for vision input.
     * 
     * @param string $extension
     * 
     * @return bool
     */
    public function isFileExtSupported(string $extension): bool
    {
        $supportedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        return in_array($extension, $supportedExtensions, true);
    }

    /**
     * Is OpenAI service enabled and configured.
     * 
     * @return bool True if enabled and configured, false otherwise.
     */
    public function isEnabledAndConfigured(): bool
    {
        return !(bool)$this->extensionConfiguration->get('mindfula11y', 'disableAltTextGeneration') &&
               !empty($this->extensionConfiguration->get('mindfula11y', 'openAIApiKey'));
    }
}