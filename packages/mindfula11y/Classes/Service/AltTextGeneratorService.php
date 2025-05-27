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

use RuntimeException;
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
     * @return string The generated alternative text or null if the request fails.
     */
    public function generate(FileInterface $file, string $languageCode = 'en'): ?string
    {
        return $this->openAIService->chat([
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
                            'url' => $this->getBase64ImageUrlFromFile($file),
                            'detail' => $this->getChatImageDetail(),
                        ]
                    ]
                ]
            ],
        ]);
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
