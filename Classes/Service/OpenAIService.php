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
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Class OpenAIService.
 * 
 * This class is responsible for interacting with OpenAI's API.
 */
final readonly class OpenAIService
{
    /**
     * Upper bound for one generation request (seconds). Core's HTTP.timeout
     * default is 0 — wait forever — so a stalled upstream would otherwise
     * hang the editor's request; image-input generations routinely take
     * tens of seconds, hence the generous bound.
     */
    private const REQUEST_TIMEOUT = 60;

    /** Upper bound for establishing the connection (seconds). */
    private const CONNECT_TIMEOUT = 10;

    /**
     * Constructor.
     *
     * @param ExtensionSettings $extensionSettings
     * @param RequestFactory $requestFactory
     */
    public function __construct(
        private ExtensionSettings $extensionSettings,
        private RequestFactory $requestFactory,
        private LoggerInterface $logger,
    ) {}

    /**
     * Generate a response via the OpenAI Responses API (/v1/responses).
     * 
     * All supported models (gpt-5.4-mini, gpt-5.4-nano, gpt-5-mini, gpt-5-nano, gpt-5.1, gpt-5.2) are
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
        try {
            $options = [
                'headers' => $headers,
                // THROW_ON_ERROR: an encode failure (e.g. malformed UTF-8 in
                // record-derived content) must fail cleanly instead of
                // sending a literal `false` as the request body.
                'body' => json_encode($body, JSON_THROW_ON_ERROR),
                'timeout' => self::REQUEST_TIMEOUT,
                'connect_timeout' => self::CONNECT_TIMEOUT,
            ];
            /** @var ResponseInterface $response */
            $response = $this->requestFactory->request($url, 'POST', $options);
            $responseBody = $response->getBody()->getContents();
        } catch (\Exception $exception) {
            // A failed generation is a paid API call that produced nothing —
            // leave a trail so support cases are diagnosable.
            $this->logger->warning('OpenAI request failed', [
                'model' => $model,
                'exception' => $exception->getMessage(),
            ]);
            return null;
        }

        $data = json_decode($responseBody, true);

        // Traverse output[*] (type==="message") -> content[*] (type==="output_text") -> text
        foreach ($data['output'] ?? [] as $outputItem) {
            if (($outputItem['type'] ?? '') === 'message') {
                foreach ($outputItem['content'] ?? [] as $contentItem) {
                    if (($contentItem['type'] ?? '') === 'output_text') {
                        $text = $contentItem['text'] ?? null;
                        if (is_string($text)) {
                            return $text;
                        }
                        $this->logger->warning('OpenAI response carried non-string output text', [
                            'model' => $model,
                            'type' => get_debug_type($text),
                        ]);
                        return null;
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
    private function getModelName(): string
    {
        return $this->extensionSettings->get('openAIChatModel', 'gpt-5.4-mini');
    }

    /**
     * Get the OpenAI image detail level (low/high/auto) from extension configuration.
     *
     * @return string The OpenAI image detail level.
     */
    public function getChatImageDetail(): string
    {
        return $this->extensionSettings->get('openAIChatImageDetail', 'auto');
    }

    /**
     * Get OpenAI API key from extension configuration.
     * 
     * @return string The OpenAI API key.
     */
    private function getApiKey(): string
    {
        return $this->extensionSettings->get('openAIApiKey', '');
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
     * Checked both when generation controls are rendered and when a signed
     * demand is redeemed, so disabling the integration — by the switch or by
     * removing the key — takes effect for controls already on screen.
     *
     * @return bool True if enabled and configured, false otherwise.
     */
    public function isEnabledAndConfigured(): bool
    {
        return !(bool)$this->extensionSettings->get('disableAltTextGeneration', false)
            && !empty($this->extensionSettings->get('openAIApiKey', ''));
    }
}