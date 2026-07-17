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
use TYPO3\CMS\Backend\View\BackendLayoutView;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/** Resolves backend-authorized editing metadata for analyzed structure records. */
final readonly class StructureAnalysisEnrichmentAjaxController
{
    use JsonErrorResponseTrait;
    use AjaxGuardTrait;

    /** Must match MAX_RECORDS_PER_REQUEST in service/structure/api.ts. */
    private const MAX_RECORDS_PER_REQUEST = 200;

    public function __construct(
        private PermissionService $permissionService,
        private FormDataCompiler $formDataCompiler,
        private UriBuilder $backendUriBuilder,
        private BackendLayoutView $backendLayoutView,
    ) {}

    public function enrichAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($error = $this->requireModuleAccess()) {
            return $error;
        }

        $body = $this->parseJsonBody($request);
        $references = is_array($body['records'] ?? null) ? $body['records'] : [];
        if (count($references) > self::MAX_RECORDS_PER_REQUEST) {
            return $this->errorResponse('structure.error.tooManyRecords', 400);
        }

        // Built once: the group is a stateless provider list, and rebuilding it
        // per record also rebuilds its dependency-ordered provider graph.
        $formDataGroup = GeneralUtility::makeInstance(TcaDatabaseRecord::class);
        $metadata = [];
        foreach ($this->groupColumnsByRecord($references) as $recordKey => $columnNames) {
            [$tableName, $uid] = explode(':', $recordKey, 2);
            $uid = (int)$uid;
            // The frontend annotation intentionally carries the live uid for an
            // existing workspace version. Resolve that coordinate into the row
            // currently rendered in the editor's workspace before applying any
            // layout or record-level permission checks.
            $record = BackendUtility::getRecordWSOL($tableName, $uid);
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
            if ($columnNames === [] || $this->isEditRestrictedByBackendLayout($tableName, $record)) {
                continue;
            }

            // workspaceOL() keeps `uid` at the live coordinate and exposes the
            // physical workspace-version uid as `_ORIG_uid`. FormDataCompiler
            // does no workspace overlay of its own, so it must receive the
            // physical uid to process the draft's CType, columnsOverrides and
            // dynamic select items. New workspace placeholders keep their uid.
            $formEngineUid = (int)($record['_ORIG_uid'] ?? $record['uid'] ?? 0);
            if ($formEngineUid <= 0) {
                continue;
            }

            try {
                // One compile per record, not per column: a record carrying both
                // a heading and a landmark annotation is requested twice, and a
                // single compile already yields the processed TCA for both.
                $formData = $this->formDataCompiler->compile([
                    'request' => $request,
                    'tableName' => $tableName,
                    'vanillaUid' => $formEngineUid,
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
     * Whether TYPO3 v14's backend-layout content restrictions forbid any write
     * to this record, mirroring core's DataHandlerContentElementRestrictionHook:
     * when a tt_content record's current CType is not allowed in its colPos,
     * the hook drops the whole datamap entry, so offering editing metadata
     * would expose selectors whose saves DataHandler silently discards.
     *
     * TYPO3 v13 has neither the restrictions nor the hook — the guard on the
     * v14-only BackendLayoutView API disables the check there.
     *
     * @param array<string, mixed> $record
     */
    private function isEditRestrictedByBackendLayout(string $tableName, array $record): bool
    {
        if ($tableName !== 'tt_content'
            || !method_exists($this->backendLayoutView, 'getColPosConfigurationForPage')
        ) {
            return false;
        }
        $contentType = (string)($record['CType'] ?? '');
        if ($contentType === '' || !isset($record['colPos'])) {
            return false;
        }

        $pageId = (int)($record['pid'] ?? 0);
        $backendLayout = $this->backendLayoutView->getBackendLayoutForPage($pageId);
        $columnConfiguration = $this->backendLayoutView->getColPosConfigurationForPage($backendLayout, (int)$record['colPos'], $pageId);
        $allowedContentTypes = GeneralUtility::trimExplode(',', $columnConfiguration['allowedContentTypes'] ?? '', true);
        $disallowedContentTypes = GeneralUtility::trimExplode(',', $columnConfiguration['disallowedContentTypes'] ?? '', true);

        return ($allowedContentTypes !== [] && !in_array($contentType, $allowedContentTypes, true))
            || ($disallowedContentTypes !== [] && in_array($contentType, $disallowedContentTypes, true));
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
