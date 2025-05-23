<?php

declare(strict_types=1);

namespace MindfulMarkup\MindfulA11y\Service;

use Exception;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Class AltTextGeneratorService.
 * 
 * This class is responsible for generating alternative text for images.
 */
class AltTextGeneratorService
{
    /**
     * Constructor.
     * 
     * @param OpenAIService $openAIService The OpenAI service instance.
     * @param ExtensionConfiguration $extensionConfiguration The extension configuration instance.
     */
    public function __construct(
        protected readonly OpenAIService $openAIService,
        protected readonly ExtensionConfiguration $extensionConfiguration,
    ) {}

    /**
     * Generate alternative text for a given image using OpenAI's GPT-4o model.
     * 
     * @param FileInterface $file The file object representing the image.
     * @param string $languageCode The language code for the generated text (default is 'en').
     * 
     * @return string The generated alternative text.
     * 
     * @throws Exception If the OpenAI API request fails.
     */
    public function generate(FileInterface $file, string $languageCode = 'en'): string
    {
        $imageUrl = $this->getBase64ImageUrlFromFile($file);

        $altText = $this->openAIService->chat([
            [
                'role' => 'system',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'You are an assistant that generates short and descriptive alternative text for given images in the language of the language code ' . $languageCode . '. The alternative text should precisely describe the content of the image and be suitable for visually impaired users. If you deem a photo to '
                    ]
                ]
            ],
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'image_url',
                        'image_url' => [
                            'url' => $imageUrl,
                            'detail' => $this->getChatImageDetail(),
                        ]
                    ]
                ]
            ],
        ]);

        return $altText;
    }

    /**
     * Get OpenAI chat image detail from extension configuration.
     * 
     * @return string The OpenAI chat image detail.
     */
    protected function getChatImageDetail(): string
    {
        return $this->extensionConfiguration->get('mindfula11y')['openAIChatImageDetail'] ?? 'low';
    }

    /**
     * Get base64 encoded image url from a file.
     * 
     * @param FileInterface $file The file object.
     * 
     * @return string The base64 encoded image url.
     */
    protected function getBase64ImageUrlFromFile(FileInterface $file): string
    {
        $contents = base64_encode($file->getContents());
        return 'data:' . $file->getMimeType() . ';base64,' . $contents;
    }
}
