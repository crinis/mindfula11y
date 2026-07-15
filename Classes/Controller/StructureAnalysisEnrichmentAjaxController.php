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

namespace MindfulMarkup\MindfulA11y\Controller;

use MindfulMarkup\MindfulA11y\Service\PermissionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Form\FormDataCompiler;
use TYPO3\CMS\Backend\Form\FormDataGroup\TcaDatabaseRecord;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/** Resolves backend-authorized editing metadata for analyzed structure records. */
final readonly class StructureAnalysisEnrichmentAjaxController
{
    /** Must match MAX_RECORDS_PER_REQUEST in service/structure/api.ts. */
    private const MAX_RECORDS_PER_REQUEST = 200;

    public function __construct(
        private PermissionService $permissionService,
        private FormDataCompiler $formDataCompiler,
        private UriBuilder $backendUriBuilder,
    ) {}

    public function enrichAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = json_decode((string)$request->getBody(), true);
        $references = is_array($body) && is_array($body['records'] ?? null) ? $body['records'] : [];
        if (count($references) > self::MAX_RECORDS_PER_REQUEST) {
            return new JsonResponse(['error' => 'Too many records requested for structure editing metadata.'], 400);
        }

        // Built once: the group is a stateless provider list, and rebuilding it
        // per record also rebuilds its dependency-ordered provider graph.
        $formDataGroup = GeneralUtility::makeInstance(TcaDatabaseRecord::class);
        $metadata = [];
        foreach ($this->groupColumnsByRecord($references) as $recordKey => $columnNames) {
            [$tableName, $uid] = explode(':', $recordKey, 2);
            $uid = (int)$uid;
            $record = BackendUtility::getRecord($tableName, $uid);
            if (!is_array($record)) {
                continue;
            }
            // Kept per column: checkRecordEditAccess() ANDs the non-exclude
            // check over the columns it is given, so a user allowed only one of
            // a record's annotated fields must still be enriched for that one.
            $columnNames = array_values(array_filter(
                $columnNames,
                fn(string $columnName): bool => $this->permissionService->checkRecordEditAccess(
                    $tableName,
                    $record,
                    [$columnName],
                ),
            ));
            if ($columnNames === []) {
                continue;
            }

            try {
                // One compile per record, not per column: a record carrying both
                // a heading and a landmark annotation is requested twice, and a
                // single compile already yields the processed TCA for both.
                $formData = $this->formDataCompiler->compile([
                    'request' => $request,
                    'tableName' => $tableName,
                    'vanillaUid' => $uid,
                    'command' => 'edit',
                    // Only processedTca[columns][…][config][items] is read below.
                    // Left at core's default, TcaInline runs a full nested compile
                    // per existing inline child (every FAL reference on the row),
                    // multiplying the cost of a request that may carry up to
                    // MAX_RECORDS_PER_REQUEST records. The resolved children are
                    // discarded here; the processed TCA this reads is unaffected.
                    'inlineResolveExistingChildren' => false,
                ], $formDataGroup);
            } catch (\Throwable) {
                continue;
            }
            $editLink = (string)$this->backendUriBuilder->buildUriFromRoute('record_edit', [
                'edit' => [$tableName => [$uid => 'edit']],
            ]);
            foreach ($columnNames as $columnName) {
                $metadata[] = [
                    'tableName' => $tableName,
                    'columnName' => $columnName,
                    'uid' => $uid,
                    'editLink' => $editLink,
                    'availableValues' => $this->extractSelectItems($formData, $columnName),
                ];
            }
        }

        return new JsonResponse(['records' => $metadata]);
    }

    /**
     * Validates the requested references and groups their columns per record.
     *
     * Custom TCA columns are intentionally supported: integrators may configure
     * their own heading or landmark annotation fields.
     *
     * @param array<array-key, mixed> $references
     * @return array<string, list<string>> Keyed by `<tableName>:<uid>`.
     */
    private function groupColumnsByRecord(array $references): array
    {
        $columnsByRecord = [];
        foreach ($references as $reference) {
            if (!is_array($reference)) {
                continue;
            }
            $tableName = is_string($reference['tableName'] ?? null) ? $reference['tableName'] : '';
            $columnName = is_string($reference['columnName'] ?? null) ? $reference['columnName'] : '';
            $uid = (int)($reference['uid'] ?? 0);
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $tableName)
                || !preg_match('/^[a-zA-Z0-9_]+$/', $columnName)
                || $uid <= 0
                || !isset($GLOBALS['TCA'][$tableName]['columns'][$columnName])
            ) {
                continue;
            }
            $columnsByRecord[$tableName . ':' . $uid][$columnName] = $columnName;
        }

        return array_map(array_values(...), $columnsByRecord);
    }

    /**
     * @param array<string, mixed> $formData
     * @return array<string, string> Select item labels by value.
     */
    private function extractSelectItems(array $formData, string $columnName): array
    {
        $items = $formData['processedTca']['columns'][$columnName]['config']['items'] ?? [];
        if (!is_array($items)) {
            return [];
        }
        $availableValues = [];
        foreach ($items as $item) {
            if (is_array($item) && isset($item['value'], $item['label'])) {
                $availableValues[(string)$item['value']] = (string)$item['label'];
            }
        }

        return $availableValues;
    }
}
