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

namespace MindfulMarkup\MindfulA11y\ViewHelpers;

use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractTagBasedViewHelper;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Context\Context;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Database\ConnectionPool;
use MindfulMarkup\MindfulA11y\Enum\HeadingType;

/**
 * Abstract base class for all heading-related ViewHelpers in MindfulA11y.
 *
 * Provides shared logic for runtime cache, context, request handling, and database access.
 * All heading ViewHelpers should extend this class to ensure consistent behavior and dependency injection.
 *
 * @package MindfulMarkup\MindfulA11y\ViewHelpers
 */
abstract class AbstractHeadingViewHelper extends AbstractTagBasedViewHelper
{
    protected $tagName = HeadingType::H2->value;

    /**
     * ConnectionPool instance for database access.
     *
     * @var ConnectionPool
     */
    protected ConnectionPool $connectionPool;

    /**
     * Injects the ConnectionPool for database access.
     *
     * @param ConnectionPool $connectionPool
     * @return void
     */
    public function injectConnectionPool(ConnectionPool $connectionPool): void
    {
        $this->connectionPool = $connectionPool;
    }

    /**
     * Cache Manager instance for accessing TYPO3 caches.
     *
     * @var CacheManager
     */
    protected CacheManager $cacheManager;


    /**
     * Runtime cache instance for fast, request-lifetime caching.
     *
     * @var FrontendInterface
     */
    protected FrontendInterface $runtimeCache;


    /**
     * Context object with information about the current request and user.
     *
     * @var Context
     */
    protected Context $context;


    /**
     * Injects the CacheManager and initializes the runtime cache.
     *
     * @param CacheManager $cacheManager
     * @return void
     */
    public function injectCacheManager(CacheManager $cacheManager): void
    {
        $this->cacheManager = $cacheManager;
        $this->runtimeCache = $this->cacheManager->getCache('runtime');
    }


    /**
     * Injects the Context object for request/user information.
     *
     * @param Context $context
     * @return void
     */
    public function injectContext(Context $context): void
    {
        $this->context = $context;
    }

    /**
     * Returns the current PSR-7 request from the rendering context, if available.
     *
     * @return ServerRequestInterface|null The current request or null if not available.
     */
    protected function getRequest(): ?ServerRequestInterface
    {
        if ($this->renderingContext->hasAttribute(ServerRequestInterface::class)) {
            return $this->renderingContext->getAttribute(ServerRequestInterface::class);
        }
        return null;
    }

    /**
     * Checks if this is a structure analysis request and the backend user is logged in.
     *
     * @return bool True if the Mindfula11y-Structure-Analysis header is set and the user is logged in, false otherwise.
     */
    protected function isStructureAnalysisRequest(): bool
    {
        $request = $this->getRequest();
        return $request !== null && $request->hasHeader('Mindfula11y-Structure-Analysis')
            && $this->context->getPropertyFromAspect('backend.user', 'isLoggedIn', false);
    }

    /**
     * Resolves the heading type for a record.
     *
     * @param int $recordUid UID of the record to fetch heading type from.
     * @param string $recordTableName Name of the DB table containing the heading type field.
     * @param string $recordColumnName Name of the DB column for the heading type.
     *
     * @return HeadingType|null The resolved heading type or null if not found.
     */
    protected function resolveHeadingType(
        int $recordUid,
        string $recordTableName,
        string $recordColumnName,
    ): ?HeadingType {
        $columns = [$recordColumnName];
        $record = $this->getCachedRecord($recordTableName, $recordUid, $columns);

        if (null !== $record && !empty($record[$recordColumnName])) {
            $headingType = HeadingType::tryFrom($record[$recordColumnName]);
        }

        return ($headingType instanceof HeadingType) ? $headingType->value : null;
    }

    /**
     * Fetches a record's columns from cache or database, and caches the result for the request lifetime.
     *
     * @param string $tableName The database table name.
     * @param int    $uid       The UID of the record to fetch.
     * @param array  $columns   The columns to select from the record.
     * 
     * @return array|null       The associative array of the record, or null if not found.
     */
    protected function getCachedRecord(string $tableName, int $uid, array $columns): ?array
    {
        $runtimeCache = $this->cacheManager->getCache('runtime');
        $cacheIdentifier = 'mindfula11y_record_' . $tableName . '_' . $uid . '_' . implode('_', $columns);
        if ($runtimeCache->has($cacheIdentifier)) {
            $cached = $runtimeCache->get($cacheIdentifier);
            if (!empty($cached)) {
                return $cached;
            }
        }
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
        $queryBuilder
            ->select(...$columns)
            ->from($tableName)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, \TYPO3\CMS\Core\Database\Connection::PARAM_INT))
            )
            ->setMaxResults(1);
        $record = $queryBuilder->executeQuery()->fetchAssociative();
        if ($record && !empty($record[$columns[0]])) {
            $runtimeCache->set($cacheIdentifier, $record);
            return $record;
        }
        return null;
    }

    /**
     * Registers common arguments for heading ViewHelpers (table, column, UID, type).
     *
     * @return void
     */
    protected function registerCommonHeadingArguments(): void
    {
        $this->registerArgument('recordTableName', 'string', 'Database table name of the record with the heading. (Defaults to tt_content)', false, 'tt_content');
        $this->registerArgument('recordColumnName', 'string', 'Name of field that stores the heading type. (Defaults to tx_mindfula11y_headingtype)', false, 'tx_mindfula11y_headingtype');
        $this->registerArgument('recordUid', 'int', 'The UID of the record with the heading.', false, null);
        $this->registerArgument('type', 'string', 'The heading type to use (h1, h2, h3, h4, h5, h6, p, div, etc.). If not provided, the value will be fetched from the database record, relationship or set to "h2".', false, null);
    }

    /**
     * Check if data to fetch the record information is available.
     * 
     * @return bool True if record information is available, false otherwise.
     */
    protected function hasRecordInformation(): bool
    {
        return !empty($this->arguments['recordUid'])
            && !empty($this->arguments['recordTableName'])
            && !empty($this->arguments['recordColumnName']);
    }
}
