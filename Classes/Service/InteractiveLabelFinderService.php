<?php

declare(strict_types=1);

namespace MindfulMarkup\MindfulA11y\Service;

use MindfulMarkup\MindfulA11y\Domain\Repository\InteractiveLabelRepository;
use MindfulMarkup\MindfulA11y\Enum\InteractiveLabelType;
use TYPO3\CMS\Backend\Utility\BackendUtility;

final readonly class InteractiveLabelFinderService
{
    private const TARGET_FIELD = 'button_link';

    public function __construct(
        private InteractiveLabelRepository $repository,
        private InteractiveLabelRuleProvider $ruleProvider,
        private InteractiveLabelChecker $checker,
        private PermissionService $permissionService,
    ) {
    }

    /**
     * Returns all interactive labels found on the page.
     *
     * A label does not need to have a single-value issue. This is important
     * because page-wide rules such as repeated labels and identical labels
     * with different targets need to see all labels.
     *
     * @param string[] $fields
     * @param string[] $additionalVagueLabels Project-specific terms to flag alongside the built-in list.
     * @param string[] $ignoredLabels Built-in (or additional) terms to exempt for this project.
     *
     * @return array<int, array<string, mixed>>
     */
    public function find(
        int $pageId,
        int $languageId,
        string $locale,
        string $table,
        array $fields,
        InteractiveLabelType $type,
        array $additionalVagueLabels = [],
        array $ignoredLabels = [],
    ): array {
        if ($table === '' || $fields === []) {
            return [];
        }

        $rules = $this->ruleProvider->getRules($locale, $additionalVagueLabels, $ignoredLabels);

        $records = $this->repository->findByPage(
            $table,
            $fields,
            $pageId,
            $languageId,
            self::TARGET_FIELD,
        );

        // Whole-table gate first: skip the per-record getRecordWSOL() calls
        // below entirely when the user cannot write to this table at all.
        $canWriteTable = $this->permissionService->checkTableWriteAccess($table);

        $labels = [];

        foreach ($records as $record) {
            $uid = (int)($record['uid'] ?? 0);

            $fullRecord = ($canWriteTable && $uid > 0)
                ? BackendUtility::getRecordWSOL($table, $uid)
                : null;

            $target = trim(
                (string)($record[self::TARGET_FIELD] ?? ''),
            );

            foreach ($fields as $field) {
                $value = trim(
                    (string)($record[$field] ?? ''),
                );

                if ($value === '') {
                    continue;
                }

                $issue = null;

                if ($rules !== []) {
                    $issue = $this->checker->check(
                        $value,
                        $type,
                        $rules,
                    );
                }

                // Mirrors AltlessFileReferenceViewHelper's edit-access gate:
                // the label input/save button (and edit-record link) are only
                // offered to a user who could actually write this field.
                $editable = $fullRecord !== null
                    && $this->permissionService->checkNonExcludeFields($table, [$field])
                    && $this->permissionService->checkRecordEditAccess($table, $fullRecord, [$field]);

                $label = [
                    'table' => $table,
                    'uid' => $uid,
                    'field' => $field,
                    'value' => $value,
                    'type' => $type,
                    'target' => $target,
                    'editable' => $editable,

                    // Tells the aggregator whether the normal
                    // per-label checker already found something.
                    'hasSingleIssue' => $issue !== null,
                ];

                if ($issue !== null) {
                    $label = [
                        ...$label,
                        ...$issue,
                    ];
                }

                // IMPORTANT:
                // Do not continue when $issue === null.
                // The aggregator must also see valid-looking labels.
                $labels[] = $label;
            }
        }

        return $labels;
    }
}
