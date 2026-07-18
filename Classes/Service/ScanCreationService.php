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

namespace MindfulMarkup\MindfulA11y\Service;

use MindfulMarkup\MindfulA11y\Domain\Model\CreateScanDemand;
use MindfulMarkup\MindfulA11y\Exception\ScanApiRequestException;
use MindfulMarkup\MindfulA11y\Exception\ScanCreationException;
use MindfulMarkup\MindfulA11y\Hooks\ScanStateDataHandlerGuard;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Creates accessibility scans from validated, authorized demands.
 *
 * Owns everything between authorization and the JSON response: assembling
 * the URL list and scanner options, calling the scan API, and persisting the
 * returned scan id on the page record. Callers authorize first — this
 * service assumes the demand's signature, user, workspace, language, page
 * access, and TSconfig gates have been verified.
 */
final readonly class ScanCreationService
{
    public function __construct(
        private ScanApiService $scanApiService,
        private ModuleSettingsService $moduleSettingsService,
        private PagePreviewService $pagePreviewService,
        private SiteLanguageService $siteLanguageService,
    ) {}

    /**
     * @param array<string, mixed> $page Workspace-overlaid (and, for translations, localized) page record.
     * @param array<string, mixed> $pageTsConfig Converted Page TSconfig of the demand's page.
     * @param list<string>|null $aiAuditSkills Skill selection when an AI audit was requested and allowed.
     * @return array{scanId: string, status: string}
     * @throws ScanCreationException Carries the label key, HTTP status, and optional description for the error response.
     */
    public function create(
        CreateScanDemand $demand,
        array $page,
        array $pageTsConfig,
        bool $aiAuditRequested,
        ?array $aiAuditSkills,
    ): array {
        if (!$this->scanApiService->isConfigured()) {
            throw new ScanCreationException('scan.error.notConfigured', 500);
        }

        $scanUrls = $this->buildScanUrls($demand, $page);

        $crawlOptions = [];
        if ($demand->getCrawl()) {
            // Restrict the crawl to the selected language's URL space via a glob pattern.
            // The base URL is derived from the TYPO3 site configuration — not user-supplied input.
            $base = $this->siteLanguageService->getAbsoluteLanguageBase($demand->getPageId(), $demand->getLanguageId());
            if ($base !== null) {
                $crawlOptions['globs'] = [$base . '/**'];
            }
        }

        // Read basic auth credentials from site settings / PageTS (server-side only,
        // never from client input).
        $scanOptions = [];
        $basicAuth = $this->moduleSettingsService->getScanBasicAuth($demand->getPageId(), $pageTsConfig);
        if ($basicAuth !== null) {
            $scanOptions['basicAuth'] = $basicAuth;
        }

        try {
            $scanData = $this->scanApiService->createScan(
                $scanUrls,
                $demand->getCrawl(),
                $crawlOptions,
                $scanOptions,
                $aiAuditRequested,
                $aiAuditSkills,
            );
        } catch (ScanApiRequestException $exception) {
            // Surface the API's own explanation (e.g. "AI audit is not enabled
            // on this server.") so editors see an actionable message.
            $status = $exception->getStatusCode() >= 400 && $exception->getStatusCode() < 500 ? 400 : 500;
            $description = $exception->getProblemDetail() !== '' ? $exception->getProblemDetail() : null;
            throw new ScanCreationException('scan.error.createFailed', $status, $description);
        }

        if (null === $scanData || !isset($scanData['id'])) {
            throw new ScanCreationException('scan.error.createFailed', 500);
        }

        $scanId = (string)$scanData['id'];
        if (!$this->storeScanId((int)$page['uid'], $scanId)) {
            throw new ScanCreationException('scan.error.storeFailed', 500);
        }

        return [
            'scanId' => $scanId,
            'status' => (string)($scanData['status'] ?? 'pending'),
        ];
    }

    /**
     * @param array<string, mixed> $page
     * @return list<string>
     * @throws ScanCreationException
     */
    private function buildScanUrls(CreateScanDemand $demand, array $page): array
    {
        if ($demand->getCrawl()) {
            // For crawl mode the API discovers pages from the start URL - only valid on site root pages
            if (!(bool)($page['is_siteroot'] ?? false)) {
                throw new ScanCreationException('scan.error.crawlNotRootPage', 403);
            }
            return [$demand->getPreviewUrl()];
        }

        $scanUrls = [];
        if ($demand->getPageLevels() > 0) {
            $scanUrls = $this->pagePreviewService->generatePageUrls(
                $demand->getPageId(),
                $demand->getLanguageId(),
                $demand->getPageLevels(),
            );
        }

        // Always include the current page's preview URL
        return empty($scanUrls) ? [$demand->getPreviewUrl()] : $scanUrls;
    }

    /**
     * Store the scan ID on the page record using DataHandler.
     */
    private function storeScanId(int $pageUid, string $scanId): bool
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        ScanStateDataHandlerGuard::withInternalWriteScope(static function () use ($dataHandler, $pageUid, $scanId): void {
            $dataHandler->start([
                'pages' => [
                    $pageUid => [
                        ScanStateService::FIELD_SCAN_ID => $scanId,
                        ScanStateService::FIELD_SCAN_UPDATED => time(),
                    ]
                ]
            ], []);

            $dataHandler->process_datamap();
        });

        return empty($dataHandler->errorLog);
    }
}
