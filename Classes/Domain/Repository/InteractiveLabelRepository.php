<?php

declare(strict_types=1);

namespace MindfulMarkup\MindfulA11y\Domain\Repository;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

final readonly class InteractiveLabelRepository
{
    public function __construct(
        private ConnectionPool $connectionPool,
    ) {
    }

    /**
     * @param string[] $fields
     *
     * @return array<int, array<string, mixed>>
     */
    public function findByPage(
        string $table,
        array $fields,
        int $pageId,
        int $languageId,
        ?string $targetField = null,
    ): array {
        if (!isset($GLOBALS['TCA'][$table])) {
            return [];
        }

        $validFields = array_values(
            array_filter(
                $fields,
                static fn(string $field): bool =>
                isset($GLOBALS['TCA'][$table]['columns'][$field]),
            ),
        );

        if ($validFields === []) {
            return [];
        }

        $selectFields = $validFields;

        if (
            $targetField !== null
            && isset($GLOBALS['TCA'][$table]['columns'][$targetField])
            && !in_array($targetField, $validFields, true)
        ) {
            $selectFields[] = $targetField;
        }

        $queryBuilder = $this->connectionPool
            ->getQueryBuilderForTable($table);

        $queryBuilder
            ->select(
                'uid',
                'pid',
                ...$selectFields,
            )
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq(
                    'pid',
                    $queryBuilder->createNamedParameter(
                        $pageId,
                        Connection::PARAM_INT,
                    ),
                ),
            );

        /*
         * Get the language field configured for this table.
         * Usually this is "sys_language_uid".
         */
        $languageField =
            $GLOBALS['TCA'][$table]['ctrl']['languageField'] ?? null;

        if (
            is_string($languageField)
            && $languageField !== ''
            && isset($GLOBALS['TCA'][$table]['columns'][$languageField])
        ) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq(
                    $languageField,
                    $queryBuilder->createNamedParameter(
                        $languageId,
                        Connection::PARAM_INT,
                    ),
                ),
            );
        }

        return $queryBuilder
            ->executeQuery()
            ->fetchAllAssociative();
    }
}
