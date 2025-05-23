<?php

declare(strict_types=1);

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
