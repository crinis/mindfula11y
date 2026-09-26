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

use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Resource\FileInterface;

/**
 * Class AltTextGeneratorService.
 * 
 * This class is responsible for generating alternative text for images.
 */
final readonly class AltTextGeneratorService
{
    /**
     * Largest image this service will encode and send.
     *
     * getContents() loads the whole file into memory and base64 inflates it by
     * a further ~4/3, so an unbounded file turns one authorized generation into
     * a memory-exhaustion risk. OpenAI rejects images past this size anyway, so
     * checking up front replaces a guaranteed round-trip failure with an
     * immediate, logged one.
     */
    private const MAX_IMAGE_BYTES = 20 * 1024 * 1024;

    public function __construct(
        private OpenAIService $openAIService,
        private LoggerInterface $logger,
    ) {}

    /**
     * Generate alternative text for a given image using an OpenAI GPT-5 vision model.
     * 
     * Uses the Responses API (/v1/responses) which is required for all supported models.
     * 
     * @param FileInterface $file The file object representing the image.
     * @param string $languageCode The language code for the generated text (default is 'en').
     * 
     * @return string|null The generated alternative text or null if the request fails.
     */
    public function generate(FileInterface $file, string $languageCode = 'en'): ?string
    {
        try {
            // getSize() is a file access, not a property read: it throws for a
            // deleted file and otherwise asks the storage driver.
            $fileSize = (int)$file->getSize();
            if ($fileSize > self::MAX_IMAGE_BYTES) {
                $this->logger->warning(
                    'Skipped alternative text generation: image exceeds the {limit} byte limit.',
                    ['limit' => self::MAX_IMAGE_BYTES, 'size' => $fileSize, 'file' => $file->getIdentifier()]
                );

                return null;
            }

            $imageUrl = $this->getBase64ImageUrlFromFile($file);
        } catch (\Exception $exception) {
            // The editor is told only that generation failed, so an unreachable
            // storage or a file deleted since authorization must leave a trace
            // for the operator — this is the one failure here they can act on.
            $this->logger->warning(
                'Skipped alternative text generation: image could not be read.',
                ['file' => $file->getIdentifier(), 'exception' => $exception->getMessage()]
            );

            return null;
        }

        return $this->openAIService->respond(
            $this->buildInstructions($languageCode),
            [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'input_image',
                            'image_url' => $imageUrl,
                            'detail' => $this->openAIService->getChatImageDetail(),
                        ],
                    ],
                ],
            ]
        );
    }

    /**
     * Build the system instructions for alt text generation.
     * 
     * @param string $languageCode ISO language code for the output language.
     * 
     * @return string
     */
    private function buildInstructions(string $languageCode): string
    {
        return 'You are an accessibility specialist generating WCAG 2.1 compliant alt text for web images. Respond in the language identified by this ISO language code: ' . $languageCode . '. Follow these rules strictly: (1) Describe the essential meaning and purpose of the image — not a literal catalogue of visual details. (2) Be concise, ideally under 125 characters. (3) Never begin with "image of", "photo of", "picture of", or equivalent phrases — screen readers already announce the element as an image. (4) If the image contains readable text, transcribe it verbatim. (5) If the image is purely decorative and conveys no meaningful information, respond with exactly: DECORATIVE. (6) Respond with only the alt text string — no surrounding quotes, no trailing punctuation, no explanations.';
    }

    /**
     * Get base64 encoded image url from a file.
     * 
     * @param FileInterface $file The file object.
     * 
     * @return string The base64 encoded image url.
     */
    private function getBase64ImageUrlFromFile(FileInterface $file): string
    {
        $contents = base64_encode($file->getContents());
        return 'data:' . $file->getMimeType() . ';base64,' . $contents;
    }
}
