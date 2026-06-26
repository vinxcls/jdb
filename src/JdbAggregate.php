<?php
require_once __DIR__ . "/JdbErrorHandler.php";
require_once __DIR__ . "/JdbUtil.php";
require_once __DIR__ . "/JdbRealpathCache.php";
require_once __DIR__ . "/JdbConfig.php";
require_once __DIR__ . "/JdbManager.php";
require_once __DIR__ . "/JdbRelationType.php";
require_once __DIR__ . "/JdbRelationMeta.php";
require_once __DIR__ . "/JdbRelationValidator.php";
require_once __DIR__ . "/JdbAggLock.php";
require_once __DIR__ . "/JdbTransaction.php";

/**
 * JdbAggregate – Multi-table relation engine for JDB.
 *
 * Extends the single-table JsonDatabase / Database engine with support for
 * four canonical relation patterns and their associated aggregate operations.
 *
 * Architecture (five collaborating classes):
 *
 *   JdbRelationType       – Enumeration constants for supported relation kinds.
 *
 *   JdbRelationMeta       – Immutable value object that fully describes one
 *                           named relation (factory pattern; never constructed
 *                           directly).
 *
 *   JdbRelationValidator  – Stateless structural validator for JdbRelationMeta
 *                           instances and for raw identifier strings.  Writes
 *                           frames to JdbErrorHandler on any failure.
 *
 *   JdbAggLock            – Multi-table lock coordinator.  Wraps JdbLock to
 *                           acquire named mutexes across N tables in canonical
 *                           sorted order (deadlock prevention) with all-or-
 *                           nothing semantics on failure.
 *
 *   JdbAggregate          – Static façade: relation registry, query API (SHARED
 *                           scope), write API (EXCLUSIVE scope), error API.
 *
 * Provides:
 *   - Relation types: ONE_TO_MANY, MANY_TO_MANY, POLYMORPHIC, TEMPORAL.
 *   - Read API: getRelated, getPolymorphic, loadWith, getTemporalRange,
 *               countRelated, aggregateRelated, sumRelated, avgRelated,
 *               minRelated, maxRelated.
 *   - Write API: attach, detach, insertWithChildren, deleteWithChildren,
 *                syncChildren.
 *
 * Locking contract:
 *
 *   Lock types:
 *     SHARED    – read-only aggregate queries.  No aggregate-level mutex is
 *                 acquired; each sub-call to JdbManager::find*() / readAll() acquires
 *                 its own per-file LOCK_SH internally, which is sufficient.
 *     EXCLUSIVE – any write that touches ≥2 tables (insertWithChildren,
 *                 deleteWithChildren, syncChildren).  JdbAggLock acquires one
 *                 named mutex per table before executing any sub-operation.
 *
 *   Granularity:
 *     Table-level mutex (default): one mutex per table name, independent of
 *     which row is being modified.  Provides full serialisation of concurrent
 *     aggregate writes against the same tables.
 *
 *     Single-table writes (attach, detach) reuse the file-level lock already
 *     held by JdbManager::insert / JdbManager::delete; no aggregate mutex needed.
 *
 *   Acquisition order (deadlock prevention):
 *     Tables are sorted lexicographically before requesting mutexes.  Any two
 *     concurrent aggregate transactions requesting overlapping table sets will
 *     always request locks in the same order, eliminating circular wait.
 *
 *   Acquisition strategy:
 *     All-or-nothing: if mutex N in a sequence of M cannot be acquired within
 *     the configured per-table timeout, all previously acquired mutexes (0…N-1)
 *     are released immediately and the attempt fails.  JdbAggregate retries up
 *     to JdbConfig::get('max_retries') times with a configurable base delay plus a
 *     random jitter (± 50 % of the base) to reduce thundering-herd collisions.
 *
 *   Lock propagation across tables/junctions:
 *     MANY_TO_MANY writes (attach/detach) touch only the junction table →
 *     file-level lock is sufficient; no aggregate mutex.
 *
 *     insertWithChildren / deleteWithChildren touch ≥2 tables → aggregate
 *     mutex on [parentTable, childTable] (sorted) acquired before the first
 *     sub-operation, released in finally block regardless of outcome.
 *
 *     syncChildren (replace-all) touches only childTable in a loop of deletes
 *     + a batch insert → single-table aggregate mutex on childTable only,
 *     because the parent table is not modified.
 *
 *   Release:
 *     releaseAll() releases mutexes in reverse acquisition order (LIFO).
 *     Called unconditionally from every try/finally block in the write API.
 *
 * Transactional limitations:
 *     JDB is an append-only engine; there is no global multi-table rollback.
 *     If a multi-step write is interrupted after partial completion, the caller
 *     will observe a partially-written state.  JdbAggregate documents these
 *     points explicitly and pushes a JdbErrorHandler frame whenever a partial write
 *     is detected, so that callers can detect and compensate.
 *
 * Consistency note (snapshot isolation):
 *     Read operations across multiple tables are NOT atomic.  Concurrent writes
 *     may cause a parent record and its children to be read from different points
 *     in time (phantom reads).  For snapshot-like consistency, callers must
 *     either (a) acquire exclusive locks manually, or (b) design for eventual
 *     consistency and tolerate transient inconsistencies between related tables.
 *
 * PHP 5.5+ compatible.  No SQL.  Domain-agnostic.
 */

/**
 * JdbAggregate – Static façade for multi-table relation operations.
 *
 * Usage pattern mirrors the Database static class:
 *   1. Call configure() once at bootstrap.
 *   2. Register named relations with defineRelation().
 *   3. Use the read/write API via static method calls.
 *
 * Bootstrap example:
 * @code
 *   JdbAggregate::configure(array(
 *       'data_dir'        => '/var/data/myapp',
 *       'lock_backend'    => 'flock',
 *       'lock_timeout_ms' => 2000,
 *   ));
 *
 *   JdbAggregate::defineRelation('order_lines',
 *       JdbRelationMeta::oneToMany('orders', 'lines', 'order_id'));
 *
 *   JdbAggregate::defineRelation('document_tags',
 *       JdbRelationMeta::manyToMany('documents', 'tags', 'doc_tag_pivot',
 *                                   'document_id', 'tag_id'));
 *
 *   JdbAggregate::defineRelation('media',
 *       JdbRelationMeta::polymorphic('media', 'mediable_type', 'mediable_id'));
 *
 *   JdbAggregate::defineRelation('event_log',
 *       JdbRelationMeta::temporal('entities', 'events', 'entity_id',
 *                                 'occurred_at', strtotime('2024-01-01')));
 * @endcode
 *
 * Read example:
 * @code
 *   $lines = JdbAggregate::getRelated('order_lines', 42);
 *
 *   // Eager-load with per-relation limits
 *   $page = JdbAggregate::loadWith('orders', 42,
 *               array('order_lines', 'media'),
 *               array('order_lines' => 50));
 *
 *   // Aggregate shortcuts
 *   $total   = JdbAggregate::sumRelated('order_lines', 42, 'amount');
 *   $average = JdbAggregate::avgRelated('order_lines', 42, 'amount');
 *   $max     = JdbAggregate::maxRelated('order_lines', 42, 'amount');
 * @endcode
 *
 * Write example:
 * @code
 *   JdbAggregate::attach('document_tags', $docId, $tagId, array('added_by' => 7));
 *   JdbAggregate::detach('document_tags', $docId, $tagId);
 *   JdbAggregate::insertWithChildren('orders', $orderData, 'order_lines', $lineRows);
 * @endcode
 *
 * Consistency note:
 *   Read operations across multiple tables are NOT atomic.  Concurrent writes
 *   may cause a parent and its children to be read from different points in
 *   time.  Design for eventual consistency or acquire locks manually if
 *   snapshot isolation is required.
 */
class JdbAggregate
{
    // =========================================================================
    // Static state
    // =========================================================================

    /**
     * Named relation registry: name => JdbRelationMeta.
     * @var JdbRelationMeta[]
     */
    private static $relations = array();

    // =========================================================================
    // Configuration API
    // =========================================================================

    /**
     * Configures the aggregate engine. Call once at bootstrap.
     *
     * Thin alias for JdbConfig::configure(). Configuration is owned exclusively
     * by JdbConfig; this method exists for backward compatibility and for callers
     * who hold only a reference to JdbAggregate.
     *
     * @param  array $config  Key-value pairs
     * @return bool           False if an unknown key is present
     */
    public static function configure(array $config)
    {
        $ok = JdbConfig::configure($config);
        if (!$ok) {
            // Mirror the error onto the JdbAggregate namespace so that
            // JdbAggregate::getLastError() returns it (JdbConfig writes to its own bucket).
            $last = JdbErrorHandler::getLast('JdbConfig');
            if ($last !== null) {
                JdbErrorHandler::set('JdbAggregate', $last['method'], $last['message']);
            }
        }
        return $ok;
    }

    /**
     * Returns a single configuration value.
     * Delegates to JdbConfig, the single source of truth.
     *
     * @param  string $key
     * @return mixed|null
     */
    public static function getConfig($key)
    {
        return JdbConfig::get($key);
    }

    // =========================================================================
    // Relation registry
    // =========================================================================

    /**
     * Registers a named relation.  Overwrites any existing relation with the
     * same name (allows re-configuration at runtime).
     *
     * Performs structural validation via JdbRelationValidator before storing.
     *
     * @param  string          $name  Relation identifier (e.g. 'order_lines')
     * @param  JdbRelationMeta $meta  Descriptor built by a factory method
     * @return bool                   False if $name is empty or metadata is invalid
     */
    public static function defineRelation($name, JdbRelationMeta $meta)
    {
        JdbErrorHandler::resetStack();
        if (!is_string($name) || trim($name) === '') {
            self::setError('defineRelation', 'Relation name must be non-empty string');
            return false;
        }
        if (!JdbRelationValidator::validate($meta)) {
            self::setError('defineRelation',
                'Invalid metadata for "' . $name . '": ' . JdbErrorHandler::formatStack());
            return false;
        }
        self::$relations[$name] = $meta;
        return true;
    }

    /**
     * Returns the JdbRelationMeta for $name, or null if not registered.
     *
     * @param  string $name
     * @return JdbRelationMeta|null
     */
    public static function getRelationMeta($name)
    {
        return isset(self::$relations[$name]) ? self::$relations[$name] : null;
    }

    /**
     * Returns true if a relation with $name is registered.
     *
     * @param  string $name
     * @return bool
     */
    public static function hasRelation($name)
    {
        return isset(self::$relations[$name]);
    }

    /**
     * Removes a single named relation from the registry.
     *
     * @param string $name
     */
    public static function removeRelation($name)
    {
        unset(self::$relations[$name]);
    }

    /**
     * Clears all registered relations.
     */
    public static function clearRelations()
    {
        self::$relations = array();
    }

    /**
     * Full static reset: restores defaults for all static properties.
     * Intended for test isolation between test cases; do not call in production.
     *
     * @internal
     */
    public static function resetAll()
    {
        self::$relations = array();
        JdbConfig::resetAll();
        JdbErrorHandler::clear('JdbAggregate');
        JdbErrorHandler::configure('JdbAggregate', JdbConfig::getAggregateConfig());
    }

    // =========================================================================
    // Transaction factory
    // =========================================================================

    /**
     * Creates and starts a JdbTransaction configured with the aggregate engine settings.
     *
     * Acquires an exclusive mutex on each of the given tables before returning.
     * Returns null (and sets the last error) if the lock cannot be acquired within
     * the configured timeout; use getLastError() to inspect the failure reason.
     *
     * Retry behaviour: up to max_retries attempts with retry_delay_ms base delay
     * plus a random jitter (0..+50 % of the base) between attempts.
     *
     * Usage:
     * @code
     *   $tx = JdbAggregate::beginTransaction(['orders', 'lines']);
     *   if ($tx === null) { return false; }
     *
     *   $parentId = $tx->insert('orders', $orderData);
     *   $tx->insertBatch('lines', $lineRows);
     *   $tx->commit();
     * @endcode
     *
     * @param  string[] $tables
     * @return JdbTransaction|null
     */
    public static function beginTransaction(array $tables)
    {
        $tx = new JdbTransaction(
            JdbManager::getDataDir(),
            (string)JdbConfig::get('lock_backend'),
            (int)JdbConfig::get('lock_timeout_ms')
        );

        $maxRetries = max(0, (int)JdbConfig::get('max_retries'));
        $baseDelay  = max(1, (int)JdbConfig::get('retry_delay_ms'));

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            if ($tx->begin($tables)) {
                return $tx;
            }
            if ($attempt < $maxRetries) {
                $tx->reset();
                $jitter = (int)($baseDelay * 0.5 * (mt_rand(0, 100) / 100.0));
                usleep(($baseDelay + $jitter) * 1000);
            }
        }

        self::setError('beginTransaction',
            'Lock acquisition failed for: ' . implode(', ', $tables));
        return null;
    }

    // =========================================================================
    // READ API – SHARED lock scope
    // =========================================================================

    /**
     * Returns the child records related to $parentId through $relationName.
     *
     * Supports ONE_TO_MANY, MANY_TO_MANY, and TEMPORAL.
     * For POLYMORPHIC relations use getPolymorphic() instead.
     *
     * ONE_TO_MANY / TEMPORAL:
     *   Queries the child table for all rows where fkField = $parentId.
     *   For TEMPORAL, rows before archiveCutoff are annotated '_archived'=>true.
     *
     * MANY_TO_MANY:
     *   Streams junction rows for the parent, loads each child by ID, and
     *   merges declared payloadFields under the '_junction' key.
     *   For result sets larger than batch_fetch_threshold, switches to a
     *   two-pass batch fetch to avoid N+1 I/O.
     *
     * Consistency note: NOT atomic across tables (see class docblock).
     *
     * @param  string $relationName
     * @param  mixed  $parentId
     * @param  array  $conditions   Additional AND conditions (field => value)
     * @param  int    $limit        Maximum records to return; 0 = no limit
     * @return array|null|false     Records array, null if none, false on error
     */
    public static function getRelated($relationName, $parentId,
                                      array $conditions = array(), $limit = 0)
    {
        JdbErrorHandler::resetStack();
        if (!is_scalar($parentId) && !is_numeric($parentId)) {
            self::setError('getRelated', 'parentId cannot be null or empty');
            return false;
        }
        $parentId = JdbUtil::normalizeId($parentId);
        $meta     = self::getMeta($relationName,
            array(JdbRelationType::ONE_TO_MANY,
                  JdbRelationType::MANY_TO_MANY,
                  JdbRelationType::TEMPORAL),
            'getRelated');
        if ($meta === false) {
            return false;
        }

        switch ($meta->type) {
            case JdbRelationType::ONE_TO_MANY:
            case JdbRelationType::TEMPORAL:
                return self::queryOneToMany($meta, $parentId, $conditions, $limit);
            case JdbRelationType::MANY_TO_MANY:
                return self::queryManyToMany($meta, $parentId, $conditions, $limit);
        }
        return false; // unreachable
    }

    /**
     * Returns child records for a POLYMORPHIC relation, scoped to a specific
     * parent type and parent ID.
     *
     * Queries: typeField = $parentTable  AND  idField = $parentId
     *
     * Consistency note: NOT atomic across tables.
     *
     * @param  string $relationName
     * @param  string $parentTable   Concrete parent table name (e.g. 'orders')
     * @param  mixed  $parentId
     * @param  array  $conditions    Additional AND conditions
     * @param  int    $limit         0 = no limit
     * @return array|null|false
     */
    public static function getPolymorphic($relationName, $parentTable, $parentId,
                                          array $conditions = array(), $limit = 0)
    {
        JdbErrorHandler::resetStack();
        if (!is_scalar($parentId) && !is_numeric($parentId)) {
            self::setError('getPolymorphic', 'parentId cannot be null or empty');
            return false;
        }
        $parentId = JdbUtil::normalizeId($parentId);
        $meta     = self::getMeta($relationName,
            array(JdbRelationType::POLYMORPHIC), 'getPolymorphic');
        if ($meta === false) return false;
        if (!JdbUtil::isValidIdentifier($parentTable)) {
            self::setError('getPolymorphic', 'Invalid parentTable: "' . $parentTable . '"');
            return false;
        }
        $filter = array_merge(
            array($meta->typeField => $parentTable, $meta->idField => $parentId),
            $conditions
        );
        return self::collectWhere($meta->childTable, $filter, $limit);
    }

    /**
     * Eager-loads a parent record together with one or more of its relations.
     *
     * Returns:
     * @code
     *   array(
     *     'record'    => [...parent fields...],
     *     'relations' => [
     *       'order_lines' => [[...], [...]],  // null if none, false on error
     *       'media'       => [[...]],
     *     ],
     *   )
     * @endcode
     *
     * If the parent record does not exist, returns null.
     * If any relation query fails, that key contains false; loading continues
     * for remaining relations.
     *
     * Consistency note: individual relation loads are NOT atomic across tables.
     *
     * @param  string   $parentTable    Table from which to load the parent record
     * @param  mixed    $parentId       Primary key of the parent record
     * @param  string[] $relationNames  Names of previously defined relations to load
     * @param  int[]    $relationLimits Optional per-relation row limits:
     *                                  array('relation_name' => maxRows, ...)
     *                                  Absent keys default to 0 (no limit).
     * @return array|null|false
     */
    public static function loadWith($parentTable, $parentId,
                                    array $relationNames,
                                    array $relationLimits = array())
    {
        JdbErrorHandler::resetStack();
        if (!is_scalar($parentId) && !is_numeric($parentId)) {
            self::setError('loadWith', 'parentId cannot be null or empty');
            return false;
        }
        $parentId = JdbUtil::normalizeId($parentId);
        if (!JdbUtil::isValidIdentifier($parentTable)) {
            self::setError('loadWith', 'Invalid parentTable: "' . $parentTable . '"');
            return false;
        }

        $record = JdbManager::findById($parentTable, $parentId);
        if ($record === null) return null;
        if ($record === false) {
            self::setError('loadWith',
                'JdbManager::findById failed for table "' . $parentTable . '"');
            return false;
        }

        $relations = array();
        foreach ($relationNames as $relName) {
            $limit = isset($relationLimits[$relName]) ? (int)$relationLimits[$relName] : 0;
            $meta  = self::getMeta($relName,
                array(JdbRelationType::ONE_TO_MANY, JdbRelationType::MANY_TO_MANY,
                      JdbRelationType::TEMPORAL,    JdbRelationType::POLYMORPHIC),
                'loadWith');
            if ($meta === false) {
                $relations[$relName] = false;
                continue;
            }

            if ($meta->type === JdbRelationType::POLYMORPHIC) {
                $relations[$relName] = self::collectWhere(
                    $meta->childTable,
                    array($meta->typeField => $parentTable, $meta->idField => $parentId),
                    $limit
                );
            } elseif ($meta->type === JdbRelationType::MANY_TO_MANY) {
                $relations[$relName] = self::queryManyToMany($meta, $parentId, array(), $limit);
            } else {
                // ONE_TO_MANY, TEMPORAL
                $relations[$relName] = self::queryOneToMany($meta, $parentId, array(), $limit);
            }
        }

        return array('record' => $record, 'relations' => $relations);
    }

    /**
     * Returns child records within a timestamp window for a TEMPORAL relation.
     *
     * Query path selection (with per-request caching):
     *   If $meta->timestampField has a secondary index, uses JdbManager::selectRange
     *   (O(log N + K)).  Otherwise falls back to a full-table scan via
     *   temporalScan() (O(N)).
     *
     * Rows below archiveCutoff are annotated '_archived' => true.
     *
     * Consistency note: NOT atomic across tables.
     *
     * @param  string $relationName
     * @param  mixed  $parentId
     * @param  int    $fromTs     Inclusive lower bound (Unix timestamp; >= 0)
     * @param  int    $toTs       Inclusive upper bound (0 = open-ended)
     * @param  array  $conditions Additional AND conditions
     * @return array|null|false
     */
    public static function getTemporalRange($relationName, $parentId,
                                            $fromTs, $toTs,
                                            array $conditions = array())
    {
        JdbErrorHandler::resetStack();
        if (!is_scalar($parentId) && !is_numeric($parentId)) {
            self::setError('getTemporalRange', 'parentId cannot be null or empty');
            return false;
        }
        $parentId = JdbUtil::normalizeId($parentId);
        $meta     = self::getMeta($relationName,
            array(JdbRelationType::TEMPORAL), 'getTemporalRange');
        if ($meta === false) return false;

        $fromTs = (int)$fromTs;
        $toTs   = (int)$toTs;
        if ($fromTs < 0) {
            self::setError('getTemporalRange', 'fromTs must be >= 0');
            return false;
        }

        $rangeConditions = array_merge(array($meta->fkField => $parentId), $conditions);
        $toArg           = ($toTs > 0) ? $toTs : null;

        // Route to indexed range query or full-table scan based on cached probe.
        if (JdbManager::hasSecondaryIndex($meta->childTable, $meta->timestampField)) {
            $result = JdbManager::selectRange(
                $meta->childTable, $meta->timestampField,
                $fromTs, $toArg, $rangeConditions, 0
            );
        } else {
            $result = self::temporalScan($meta, $parentId, $fromTs, $toTs, $conditions);
        }

        if (!is_array($result)) return $result;

        // Annotate archived rows.
        $cutoff = (int)$meta->archiveCutoff;
        if ($cutoff > 0) {
            foreach ($result as &$row) {
                $ts = isset($row[$meta->timestampField])
                    ? (int)$row[$meta->timestampField] : 0;
                $row['_archived'] = ($ts < $cutoff);
            }
            unset($row);
        }
        return $result;
    }

    /**
     * Returns the count of child records related to $parentId.
     *
     * For MANY_TO_MANY, counts junction rows (not child records, which may
     * be shared with other parents).
     *
     * @param  string $relationName
     * @param  mixed  $parentId
     * @param  array  $conditions    Additional AND conditions
     * @return int|false
     */
    public static function countRelated($relationName, $parentId,
                                        array $conditions = array())
    {
        JdbErrorHandler::resetStack();
        if (!is_scalar($parentId) && !is_numeric($parentId)) {
            self::setError('countRelated', 'parentId cannot be null or empty');
            return false;
        }
        $parentId = JdbUtil::normalizeId($parentId);
        $meta     = self::getMeta($relationName,
            array(JdbRelationType::ONE_TO_MANY,
                  JdbRelationType::MANY_TO_MANY,
                  JdbRelationType::TEMPORAL),
            'countRelated');
        if ($meta === false) return false;

        if ($meta->type === JdbRelationType::MANY_TO_MANY) {
            $table  = $meta->junctionTable;
            $filter = array_merge(array($meta->leftFk => $parentId), $conditions);
        } else {
            $table  = $meta->childTable;
            $filter = array_merge(array($meta->fkField => $parentId), $conditions);
        }

        $count = 0;
        $res   = JdbManager::forEachRecord($table,
            function ($row) use (&$count) {
                $count++;
            }, $filter);

        if ($res === false) {
            self::setError('countRelated', 'JdbManager::forEachRecord failed on "' . $table . '"');
            return false;
        }

        return $count;
    }

    /**
     * Applies an aggregate function to a field of the related child records.
     *
     * Supported functions: count, sum, avg, min, max.
     *
     * ONE_TO_MANY / TEMPORAL:
     *   Delegates to JdbManager::aggregate (engine-level; O(log N) with a
     *   secondary index on $field, O(N) otherwise).
     *
     * MANY_TO_MANY:
     *   Uses a streaming accumulator (aggregateManyToMany) that iterates
     *   junction rows and fetches each child individually.  Memory usage is
     *   O(1) regardless of result set size, avoiding the full materialisation
     *   of the child set that was used in earlier versions.
     *
     * Two separate counts are maintained internally for MANY_TO_MANY:
     *   – totalCount   for 'count' (all matching children).
     *   – numericCount for 'avg'   (children where $field is numeric).
     *
     * @param  string $relationName
     * @param  mixed  $parentId
     * @param  string $fn          'count' | 'sum' | 'avg' | 'min' | 'max'
     * @param  string $field       Field name in the child table
     * @param  array  $conditions  Additional AND conditions applied to child rows
     * @return mixed|false         Aggregate result or false on error;
     *                             null if no numeric values found (sum/avg/min/max)
     */
    public static function aggregateRelated($relationName, $parentId, $fn, $field,
                                            array $conditions = array())
    {
        JdbErrorHandler::resetStack();
        if (!is_scalar($parentId) && !is_numeric($parentId)) {
            self::setError('aggregateRelated', 'parentId cannot be null or empty');
            return false;
        }
        $parentId = JdbUtil::normalizeId($parentId);
        $meta     = self::getMeta($relationName,
            array(JdbRelationType::ONE_TO_MANY,
                  JdbRelationType::MANY_TO_MANY,
                  JdbRelationType::TEMPORAL),
            'aggregateRelated');
        if ($meta === false) return false;
        if (!JdbUtil::isValidIdentifier($field)) {
            self::setError('aggregateRelated', 'Invalid field name: "' . $field . '"');
            return false;
        }
        if (!in_array($fn, array('count', 'sum', 'avg', 'min', 'max'), true)) {
            self::setError('aggregateRelated', 'Unknown function "' . $fn . '"');
            return false;
        }

        if ($meta->type === JdbRelationType::MANY_TO_MANY) {
            return self::aggregateManyToMany($meta, $parentId, $fn, $field, $conditions);
        }

        // ONE_TO_MANY / TEMPORAL: delegate to engine-level aggregate.
        $filter = array_merge(array($meta->fkField => $parentId), $conditions);
        $result = JdbManager::aggregate($meta->childTable, $fn, $field, $filter);
        if ($result === false) {
            self::setError('aggregateRelated',
                'JdbManager::aggregate failed on "' . $meta->childTable . '"');
        }
        return $result;
    }

    // =========================================================================
    // Aggregate shortcut methods
    // =========================================================================

    /**
     * Returns the sum of $field over child records related to $parentId.
     * Shortcut for aggregateRelated(..., 'sum', ...).
     *
     * @param  string $relationName
     * @param  mixed  $parentId
     * @param  string $field
     * @param  array  $conditions
     * @return float|null|false  null if no numeric values found; false on error
     */
    public static function sumRelated($relationName, $parentId, $field,
                                      array $conditions = array())
    {
        return self::aggregateRelated($relationName, $parentId, 'sum', $field, $conditions);
    }

    /**
     * Returns the average of $field over child records related to $parentId.
     * Shortcut for aggregateRelated(..., 'avg', ...).
     *
     * @param  string $relationName
     * @param  mixed  $parentId
     * @param  string $field
     * @param  array  $conditions
     * @return float|null|false
     */
    public static function avgRelated($relationName, $parentId, $field,
                                      array $conditions = array())
    {
        return self::aggregateRelated($relationName, $parentId, 'avg', $field, $conditions);
    }

    /**
     * Returns the minimum of $field over child records related to $parentId.
     * Shortcut for aggregateRelated(..., 'min', ...).
     *
     * @param  string $relationName
     * @param  mixed  $parentId
     * @param  string $field
     * @param  array  $conditions
     * @return mixed|null|false
     */
    public static function minRelated($relationName, $parentId, $field,
                                      array $conditions = array())
    {
        return self::aggregateRelated($relationName, $parentId, 'min', $field, $conditions);
    }

    /**
     * Returns the maximum of $field over child records related to $parentId.
     * Shortcut for aggregateRelated(..., 'max', ...).
     *
     * @param  string $relationName
     * @param  mixed  $parentId
     * @param  string $field
     * @param  array  $conditions
     * @return mixed|null|false
     */
    public static function maxRelated($relationName, $parentId, $field,
                                      array $conditions = array())
    {
        return self::aggregateRelated($relationName, $parentId, 'max', $field, $conditions);
    }

    // =========================================================================
    // WRITE API – EXCLUSIVE lock scope
    // =========================================================================

    /**
     * Attaches two records through a MANY_TO_MANY junction table.
     *
     * Idempotent: if an active junction row already exists for the pair
     * ($parentId, $childId), the payload fields are updated in-place.
     *
     * Payload validation:
     *   $payload must not contain the fields 'id', $meta->leftFk, or
     *   $meta->rightFk.  Additionally, all payload field names are validated
     *   to be safe (no path traversal, length ≤ 64, alphanumeric+underscore).
     *   Passing any of these reserved fields corrupts the junction row's
     *   primary key or FK columns; the method returns false with a descriptive
     *   error before any write.
     *
     * Single-table operation: no aggregate mutex required.
     *
     * @param  string $relationName
     * @param  mixed  $parentId
     * @param  mixed  $childId
     * @param  array  $payload     Optional extra fields for the junction row
     * @return mixed|false         Junction row ID (new or existing), false on error
     */
    public static function attach($relationName, $parentId, $childId,
                                  array $payload = array())
    {
        JdbErrorHandler::resetStack();
        if ((!is_scalar($parentId) && !is_numeric($parentId)) ||
            (!is_scalar($childId)  && !is_numeric($childId))) {
            self::setError('attach', 'parentId and childId cannot be null or empty');
            return false;
        }

        $parentId = JdbUtil::normalizeId($parentId);
        $childId  = JdbUtil::normalizeId($childId);
        $meta     = self::getMeta($relationName, array(JdbRelationType::MANY_TO_MANY), 'attach');
        if ($meta === false) {
            return false;
        }

        // Foreign key checks (optional)
        if (JdbConfig::get('enforce_foreign_keys')) {
            if (!JdbManager::exists($meta->parentTable, $parentId)) {
                self::setError('attach', 'Parent record does not exist in ' . $meta->parentTable);
                return false;
            }
            if (!JdbManager::exists($meta->childTable, $childId)) {
                self::setError('attach', 'Child record does not exist in ' . $meta->childTable);
                return false;
            }
        }

        // Reject payload fields that would overwrite FK or primary-key columns.
        foreach (array('id', $meta->leftFk, $meta->rightFk) as $reserved) {
            if (array_key_exists($reserved, $payload)) {
                self::setError('attach',
                    'Payload must not contain reserved field "' . $reserved . '"');
                return false;
            }
        }

        // Validate all payload field names (security: no path traversal, length)
        foreach (array_keys($payload) as $fld) {
            if (!JdbUtil::isValidIdentifier($fld)) {
                self::setError('attach', 'Invalid payload field name: "' . $fld . '"');
                return false;
            }
            if (!in_array($fld, $meta->payloadFields, true)) {
                self::setError('attach', 'Payload field "' . $fld . '" not allowed for this relation');
                return false;
            }
        }

        $filter   = array($meta->leftFk => $parentId, $meta->rightFk => $childId);
        $existing = JdbManager::findWhere($meta->junctionTable, $filter);

        if ($existing !== null && $existing !== false && count($existing) > 0) {
            $rowId = isset($existing[0]['id']) ? $existing[0]['id'] : null;
            if ($rowId !== null) {
                $ok = JdbManager::update($meta->junctionTable, $rowId,
                                         array_merge($filter, $payload));
                return $ok ? $rowId : false;
            }
        }

        $newId = JdbManager::insert($meta->junctionTable, array_merge($filter, $payload));
        if ($newId === false) {
            self::setError('attach',
                'Junction insert failed on "' . $meta->junctionTable . '"');
        }
        return $newId;
    }

    /**
     * Detaches two records by soft-deleting the junction row.
     * Is a no-op (returns true) if no active junction row exists.
     *
     * @param  string $relationName
     * @param  mixed  $parentId
     * @param  mixed  $childId
     * @return bool
     */
    public static function detach($relationName, $parentId, $childId)
    {
        JdbErrorHandler::resetStack();
        if ((!is_scalar($parentId) && !is_numeric($parentId)) ||
            (!is_scalar($childId)  && !is_numeric($childId))) {
            self::setError('detach', 'parentId and childId cannot be null or empty');
            return false;
        }
        $parentId = JdbUtil::normalizeId($parentId);
        $childId  = JdbUtil::normalizeId($childId);
        $meta     = self::getMeta($relationName,
            array(JdbRelationType::MANY_TO_MANY), 'detach');
        if ($meta === false) {
            return false;
        }

        // Foreign key checks (optional)
        if (JdbConfig::get('enforce_foreign_keys')) {
            if (!JdbManager::exists($meta->parentTable, $parentId)) {
                self::setError('detach', 'Parent record does not exist in ' . $meta->parentTable);
                return false;
            }
            if (!JdbManager::exists($meta->childTable, $childId)) {
                self::setError('detach', 'Child record does not exist in ' . $meta->childTable);
                return false;
            }
        }

        $filter   = array($meta->leftFk => $parentId, $meta->rightFk => $childId);
        $existing = JdbManager::findWhere($meta->junctionTable, $filter);
        if ($existing === null || $existing === false || count($existing) === 0) {
            return true; // idempotent no-op
        }

        $ok = true;
        foreach ($existing as $row) {
            if (!isset($row['id'])) {
                continue;
            }
            if (JdbManager::delete($meta->junctionTable, $row['id']) === false) {
                $ok = false;
                self::setError('detach', 'Delete failed for junction id=' . $row['id']);
            }
        }
        return $ok;
    }

    /**
     * Atomically inserts a parent record and its initial set of child records.
     *
     * Locking:
     *   Exclusive aggregate mutexes on [$parentTable, $meta->childTable] (sorted).
     *
     * Transactional note (append-only engine):
     *   If the child batch insert fails, $tx->rollback() is called to remove the
     *   already-inserted parent row.  If that rollback itself fails (e.g. process
     *   crash), the parent row may remain as an orphan; a JdbErrorHandler frame is
     *   pushed in that case so callers can implement additional compensating logic.
     *
     * TEMPORAL: child rows with timestamp < archiveCutoff are rejected before
     * any write takes place. Additionally, child rows that already contain
     * the fkField are rejected (to avoid accidental overwrite).
     *
     * @param  string   $parentTable  Parent table name
     * @param  array    $parentData   Parent record fields (excluding 'id')
     * @param  string   $relationName ONE_TO_MANY or TEMPORAL relation name
     * @param  array[]  $childRows    Array of child record field arrays
     * @return array|false  ['parent_id' => mixed, 'child_ids' => array] or false
     */
    public static function insertWithChildren($parentTable, array $parentData,
                                              $relationName, array $childRows)
    {
        JdbErrorHandler::resetStack();
        if (!JdbUtil::isValidIdentifier($parentTable)) {
            self::setError('insertWithChildren',
                'Invalid parentTable: "' . $parentTable . '"');
            return false;
        }
        $meta = self::getMeta($relationName,
            array(JdbRelationType::ONE_TO_MANY, JdbRelationType::TEMPORAL),
            'insertWithChildren');
        if ($meta === false) {
            return false;
        }
        if ($meta->parentTable !== $parentTable) {
            self::setError('insertWithChildren',
                'Relation expects parentTable "' . $meta->parentTable . '"');
            return false;
        }

        foreach ($childRows as $i => $row) {
            if (array_key_exists($meta->fkField, $row)) {
                self::setError('insertWithChildren',
                    'Child row #' . $i . ' must not contain the foreign key field "' . $meta->fkField . '"');
                return false;
            }
        }

        if ($meta->type === JdbRelationType::TEMPORAL && (int)$meta->archiveCutoff > 0) {
            foreach ($childRows as $i => $row) {
                if (self::isArchivedRow($meta, $row)) {
                    self::setError('insertWithChildren',
                        'Row #' . $i . ' is below archiveCutoff');
                    return false;
                }
            }
        }

        $tx = self::beginTransaction(array($parentTable, $meta->childTable));
        if ($tx === null) {
            self::setError('insertWithChildren', 'Lock acquisition failed');
            return false;
        }

        $parentId = $tx->insert($parentTable, $parentData);
        if ($parentId === false) {
            $tx->rollback();
            self::setError('insertWithChildren', 'Parent insert failed');
            return false;
        }

        if (empty($childRows)) {
            $tx->commit();
            return array('parent_id' => $parentId, 'child_ids' => array());
        }

        $prepared = array();
        foreach ($childRows as $row) {
            $row[$meta->fkField] = $parentId;
            $prepared[]          = $row;
        }

        $batch = $tx->insertBatch($meta->childTable, $prepared);
        if ($batch === false) {
            $tx->rollback(); // compensate: delete the just-inserted parent
            self::setError('insertWithChildren', 'Child batch failed');
            return false;
        }

        $tx->commit();
        return array('parent_id' => $parentId, 'child_ids' => $batch['ids']);
    }

    /**
     * Soft-deletes child records related to $parentId, optionally the parent.
     *
     * Consistency strategy (append-only engine):
     *   1. Collect all child IDs into memory via forEachRecord (O(1) streaming, no writes yet)
     *   2. Filter archived rows BEFORE any disk write (TEMPORAL safety)
     *   3. Delete children first, parent last. If parent delete fails, children
     *      are already tombstoned (safe; dangling children are harmless).
     *   4. Fully idempotent: retrying on partial failure does not corrupt data.
     *
     * TEMPORAL: rows below archiveCutoff are skipped (counted in 'skipped').
     *
     * Locking:
     *   MANY_TO_MANY  → exclusive mutex on junctionTable only.
     *   ONE_TO_MANY   → exclusive mutex on childTable.
     *   $deleteParent → also acquires mutex on parentTable.
     *
     * Return value:
     *   ['deleted' => int, 'skipped' => int]
     *   If $deleteParent = true, also includes: 'parent_deleted' => bool.
     *
     * @param  string $relationName
     * @param  mixed  $parentId
     * @param  bool   $deleteParent  default false
     * @return array|false
     */
    public static function deleteWithChildren($relationName, $parentId, $deleteParent = false)
    {
        JdbErrorHandler::resetStack();
        if (!is_scalar($parentId) && !is_numeric($parentId)) {
            self::setError('deleteWithChildren', 'parentId cannot be null or empty');
            return false;
        }
        $parentId = JdbUtil::normalizeId($parentId);
        $meta     = self::getMeta($relationName,
            array(JdbRelationType::ONE_TO_MANY, JdbRelationType::MANY_TO_MANY, JdbRelationType::TEMPORAL),
            'deleteWithChildren');
        if ($meta === false) {
            return false;
        }

        // ── 1. Determine tables to lock ─────────────────────────────────────
        $tables = self::determineTablesForDelete($meta, $deleteParent);

        // ── 2. Collect IDs (read-only, no exclusive lock held yet) ──────────
        $collected = self::collectIdsToDelete($meta, $parentId);
        if ($collected === false) {
            self::setError('deleteWithChildren', 'Failed to collect IDs for deletion');
            return false;
        }
        
        $idsToDelete = $collected['ids'];
        $skipped     = $collected['skipped'];
        $table       = $collected['table'];

        // ── 3. Check scan limits ────────────────────────────────────────────
        $maxScan = (int)JdbConfig::get('max_scan_rows');
        $totalToProcess = count($idsToDelete) + $skipped;
        if ($maxScan > 0 && $totalToProcess >= $maxScan) {
            self::setError('deleteWithChildren',
                'Scan limit exceeded (' . $totalToProcess . ' >= ' . $maxScan . '), operation aborted');
            return false;
        }

        // ── 4. FK check (read-only, before lock) ────────────────────────────
        if (JdbConfig::get('enforce_foreign_keys')) {
            if ($meta->type !== JdbRelationType::MANY_TO_MANY && !empty($meta->parentTable)) {
                if (!$deleteParent && !JdbManager::exists($meta->parentTable, $parentId)) {
                    self::setError('deleteWithChildren', 'Parent record does not exist');
                    return false;
                }
            }
        }

        // ── 5. Atomic deletion via transaction ──────────────────────────────
        $tx = self::beginTransaction($tables);
        if ($tx === null) {
            self::setError('deleteWithChildren', 'Lock acquisition failed');
            return false;
        }

        $deleted = 0;
        foreach ($idsToDelete as $id) {
            if (!$tx->delete($table, $id)) {
                $tx->rollback();
                self::setError('deleteWithChildren',
                    'Delete failed for id=' . $id . '; rolled back ' . $deleted . ' deletion(s)');
                return false;
            }
            $deleted++;
        }

        $result = array('deleted' => $deleted, 'skipped' => $skipped);

        if ($deleteParent && !empty($meta->parentTable)) {
            if (!$tx->delete($meta->parentTable, $parentId)) {
                $tx->rollback();
                self::setError('deleteWithChildren',
                    'Parent delete failed; rolled back all ' . $deleted . ' child deletion(s)');
                return false;
            }
            $result['parent_deleted'] = true;
        }

        $tx->commit();
        return $result;
    }


    /**
     * Replaces the entire set of children for $parentId with $newChildRows.
     *
     * Sequence (exclusive mutex on childTable):
     *   1. Batch-insert the new rows (so that if the process crashes, old rows
     *      remain untouched, avoiding data loss). Duplicate children may appear
     *      if crash occurs before deletion; subsequent syncChildren() calls are
     *      idempotent and will clean up.
     *   2. Soft-delete all existing child rows that are not archived.
     *
     * TEMPORAL: new rows with timestamp < archiveCutoff are rejected before
     * any write takes place.
     *
     * Supports ONE_TO_MANY and TEMPORAL.
     *
     * @param  string  $relationName
     * @param  mixed   $parentId
     * @param  array[] $newChildRows
     * @return array|false  ['deleted' => int, 'skipped' => int, 'inserted' => int]
     */
    public static function syncChildren($relationName, $parentId, array $newChildRows)
    {
        JdbErrorHandler::resetStack();
        if (!is_scalar($parentId) && !is_numeric($parentId)) {
            self::setError('syncChildren', 'parentId cannot be null or empty');
            return false;
        }
        $parentId = JdbUtil::normalizeId($parentId);
        $meta     = self::getMeta($relationName,
            array(JdbRelationType::ONE_TO_MANY, JdbRelationType::TEMPORAL),
            'syncChildren');
        if ($meta === false) {
            return false;
        }

        // ── 1. Validate new rows ──────────────────────────────────────────
        $validationError = self::validateNewChildRowsForSync($meta, $newChildRows);
        if ($validationError !== true) {
            self::setError('syncChildren', $validationError);
            return false;
        }

        // ── 2. Pre-flight FK check (optional) ─────────────────────────────
        if (JdbConfig::get('enforce_foreign_keys')) {
            if (!JdbManager::exists($meta->parentTable, $parentId)) {
                self::setError('syncChildren',
                    'Parent record does not exist in ' . $meta->parentTable);
                return false;
            }
        }

        // ── 3. Begin Transaction ──────────────────────────────────────────
        $tx = self::beginTransaction(array($meta->childTable));
        if ($tx === null) {
            self::setError('syncChildren', 'Lock acquisition failed');
            return false;
        }

        // ── 4. Phase 1: Insert new rows ───────────────────────────────────
        $inserted = 0;
        $newIds   = array();
        if (!empty($newChildRows)) {
            $prepared = array();
            foreach ($newChildRows as $row) {
                $row[$meta->fkField] = $parentId;
                $prepared[]          = $row;
            }
            $batch = $tx->insertBatch($meta->childTable, $prepared);
            if ($batch === false) {
                $tx->rollback();
                self::setError('syncChildren', 'Batch insert failed');
                return false;
            }
            $newIds   = isset($batch['ids']) ? $batch['ids'] : array();
            $inserted = $batch['inserted'];
        }

        // ── 5. Phase 2: Collect old IDs to delete ─────────────────────────
        $collected = self::collectOldIdsForSync($meta, $parentId, $newIds);
        if ($collected === false) {
            $tx->rollback(); // Rollback the inserts we just made
            return false;    // Error already set by the helper
        }
        $idsToDelete = $collected['ids'];
        $skipped     = $collected['skipped'];

        // ── 6. Check scan limits ──────────────────────────────────────────
        $maxScan = (int)JdbConfig::get('max_scan_rows');
        if ($maxScan > 0 && (count($idsToDelete) + $skipped) >= $maxScan) {
            $tx->rollback();
            self::setError('syncChildren', 'Scan limit exceeded, operation aborted');
            return false;
        }

        // ── 7. Phase 3: Delete old rows ───────────────────────────────────
        $deleted = 0;
        foreach ($idsToDelete as $id) {
            if (!$tx->delete($meta->childTable, $id)) {
                $tx->rollback();
                self::setError('syncChildren',
                    'Delete failed for id=' . $id . '; rolled back all changes');
                return false;
            }
            $deleted++;
        }

        $tx->commit();
        return array('deleted' => $deleted, 'skipped' => $skipped, 'inserted' => $inserted);
    }

    // =========================================================================
    // Error API
    // =========================================================================

    /**
     * Returns the last error, or null if the last operation succeeded.
     *
     * @return array|null  ['method' => string, 'message' => string, 'time' => int]
     *                     If detailed_errors is false, message may be generic.
     */
    public static function getLastError()
    {
        $lastError = JdbErrorHandler::getLast('JdbAggregate');
        return $lastError !== null && !JdbConfig::get('detailed_errors')
            ? array('method' => $lastError['method'], 'message' => 'Operation failed', 'time' => $lastError['time'])
            : $lastError;
    }

   /**
     * Clears the last error.
     */
    public static function clearError()
    {
        JdbErrorHandler::clear('JdbAggregate');
    }

    /**
     * Executes a ONE_TO_MANY or TEMPORAL child query via JdbManager::forEachRecord.
     *
     * Streams results into an array without holding the full table in RAM.
     * Returns early (via callback returning false) when $limit is reached.
     *
     * @param  JdbRelationMeta $meta
     * @param  mixed           $parentId  Already normalised to string
     * @param  array           $conditions
     * @param  int             $limit      0 = no limit
     * @return array|null|false
     */
    private static function queryOneToMany(JdbRelationMeta $meta, $parentId,
                                           array $conditions, $limit)
    {
        $filter   = array_merge([$meta->fkField => $parentId], $conditions);
        $rows     = array();
        $cutoff   = ($meta->type === JdbRelationType::TEMPORAL)
            ? (int)$meta->archiveCutoff : 0;
        $max      = (int)$limit;
        $rowCount = 0;
        // Captured once here – avoids a JdbConfig::get() call per row inside the closure.
        $maxQueryResults = (int)JdbConfig::get('max_query_results');

        $callback = function ($row) use (&$rows, &$rowCount, $cutoff, $meta, $max, $maxQueryResults) {
            if ($maxQueryResults > 0 && $rowCount >= $maxQueryResults) {
                return false;
            }
            if ($max > 0 && $rowCount >= $max) {
                return false;
            }
            if ($cutoff > 0 && isset($row[$meta->timestampField])) {
                $row['_archived'] = ((int)$row[$meta->timestampField] < $cutoff);
            }
            $rows[] = $row;
            $rowCount++;
        };

        $res = JdbManager::forEachRecord($meta->childTable, $callback, $filter, 0);

        if ($res === false) {
            self::setError('queryOneToMany', 'JdbManager::forEachRecord failed');
            return false;
        }

        if ($maxQueryResults > 0 && $rowCount >= $maxQueryResults) {
            self::setError('queryOneToMany',
                'Result set exceeded max_query_results (' . $maxQueryResults . ')');
            return false;
        }

        return empty($rows) ? null : $rows;
    }

    /**
     * Returns the batch_fetch_threshold config field.
     * If not configured is 200 by default.
     *
     * @return int
     */
    private static function batchFetchThreshold() {
        $cfg = self::getConfig('batch_fetch_threshold');
        return ($cfg !== null) ? (int)$cfg : 200;
    }

    /**
     * Executes a MANY_TO_MANY child query with strict memory & I/O control.
     *
     * - Collects child IDs and junction payloads in a single O(1) streaming pass.
     * - Respects $limit at the junction level (stops reading early).
     * - Switches to batch fetch (> threshold) to avoid N+1 I/O storm.
     * - Falls back to findById loop for small sets (lower CPU overhead).
     *
     * @param  JdbRelationMeta $meta
     * @param  mixed           $parentId  Already normalised
     * @param  array           $conditions  Applied to child records
     * @param  int             $limit       0 = no limit
     * @return array|null|false
     */
    private static function queryManyToMany(JdbRelationMeta $meta, $parentId,
        array $conditions, $limit)
    {
        $results      = array();
        $childIds     = array();
        $junctionData = array();
        $max          = (int)$limit;
        $hasPayload   = !empty($meta->payloadFields);
        $countChild   = 0;

        // Phase 1: Single-pass junction scan (respects $limit)
        $junctionRes = JdbManager::forEachRecord($meta->junctionTable,
            function ($jRow) use ($meta, $max, $hasPayload, &$countChild, &$childIds, &$junctionData) {
                if ($max > 0 && $countChild >= $max) {
                    return false; // stop reading junction file early
                }
                if (isset($jRow[$meta->rightFk])) {
                    $cid = (string)$jRow[$meta->rightFk];
                    $childIds[] = $cid;
                    $countChild++;
                    if ($hasPayload) {
                        $p = array();
                        foreach ($meta->payloadFields as $pf) {
                            if (isset($jRow[$pf])) {
                                $p[$pf] = $jRow[$pf];
                            }
                        }
                        $junctionData[$cid] = $p; // last occurrence wins (intentional)
                    }
                }
            },
            array($meta->leftFk => $parentId)
        );

        if ($junctionRes === false) {
            self::setError('queryManyToMany',
                'JdbManager::forEachRecord failed on "' . $meta->junctionTable . '"');
            return false;
        }

        $maxQueryResults = (int)JdbConfig::get('max_query_results');
        if ($maxQueryResults > 0 && $countChild > $maxQueryResults) {
            self::setError('queryManyToMany',
                'Max query results reached in "' . $meta->junctionTable . '"');
            return false;
        }

        if (empty($childIds)) {
            return null;
        }

        // Phase 2: Adaptive fetch strategy
        $uniqueIds = array_unique($childIds);
        $count     = count($uniqueIds);
        $threshold = self::batchFetchThreshold();
        $resultCount = 0;

        if ($max > 0 && $count <= $threshold) {
            // Small set: findById loop has lower CPU overhead than map building
            $childrenMap = array();
            foreach ($uniqueIds as $cid) {
                $child = JdbManager::findById($meta->childTable, $cid);
                if ($child !== null && $child !== false) {
                    $childrenMap[$cid] = $child;
                }
            }
            foreach ($childIds as $cid) {
                if ($max > 0 && $resultCount >= $max) {
                    break;
                }
                if (isset($childrenMap[$cid])) {
                    $child = $childrenMap[$cid];
                    if (!empty($conditions) && !JdbUtil::recordMatchesConditions($child, $conditions)) {
                        continue;
                    }
                    if ($hasPayload && isset($junctionData[$cid])) {
                        $child['_junction'] = $junctionData[$cid];
                    }
                    $results[] = $child;
                    $resultCount++;
                }
            }
        } else {
            // Large/Unlimited set: single pass on childTable (avoids N+1 I/O)
            $idLookup = array();
            foreach ($uniqueIds as $cid) $idLookup[$cid] = true;
            $childrenMap = array();

            JdbManager::forEachRecord($meta->childTable, function ($child) use (&$childrenMap, &$idLookup) {
                $cid = isset($child['id']) ? (string)$child['id'] : null;
                if ($cid !== null && isset($idLookup[$cid])) {
                    $childrenMap[$cid] = $child;
                }
            });

            foreach ($childIds as $cid) {
                if ($max > 0 && $resultCount >= $max) {
                    break;
                } 
                if (isset($childrenMap[$cid])) {
                    $child = $childrenMap[$cid];
                    if (!empty($conditions) && !JdbUtil::recordMatchesConditions($child, $conditions)) {
                        continue;
                    }
                    if ($hasPayload && isset($junctionData[$cid])) {
                        $child['_junction'] = $junctionData[$cid];
                    }
                    $results[] = $child;
                    $resultCount++;
                }
            }
        }

        return empty($results) ? null : $results;
    }

    /**
     * Streaming aggregate for MANY_TO_MANY relations.
     * Orchestrator: delegates to Small or Large strategy based on threshold.
     */
    private static function aggregateManyToMany(JdbRelationMeta $meta, $parentId,
        $fn, $field, array $conditions)
    {
        // ── Phase 1: collect child IDs from junction table ────────────────────
        $junctionChildIds = array();
        $res = JdbManager::forEachRecord($meta->junctionTable,
            function ($jRow) use (&$junctionChildIds, $meta) {
                if (isset($jRow[$meta->rightFk])) {
                    $junctionChildIds[] = (string)$jRow[$meta->rightFk];
                }
            },
            array($meta->leftFk => $parentId)
        );

        if ($res === false) {
            self::setError('aggregateManyToMany',
                'JdbManager::forEachRecord failed on "' . $meta->junctionTable . '"');
            return false;
        }

        if (empty($junctionChildIds)) {
            return ($fn === 'count') ? 0 : null;
        }

        // ── Phase 2: Adaptive strategy delegation ────────────────────────────
        $threshold   = self::batchFetchThreshold();
        $uniqueCount = count(array_unique($junctionChildIds));

        if ($uniqueCount <= $threshold) {
            return self::aggregateManyToManySmall($meta, $junctionChildIds, $fn, $field, $conditions);
        }

        return self::aggregateManyToManyLarge($meta, $junctionChildIds, $fn, $field, $conditions);
    }

    /**
     * Strategy for small sets: uses findById loop (lower CPU overhead).
     */
    private static function aggregateManyToManySmall(JdbRelationMeta $meta, array $junctionChildIds,
        $fn, $field, array $conditions)
    {
        $accumulator  = null;
        $totalCount   = 0;
        $numericCount = 0;

        foreach ($junctionChildIds as $childId) {
            $child = JdbManager::findById($meta->childTable, $childId);
            if ($child === null || $child === false) continue;
            if (!empty($conditions) && !JdbUtil::recordMatchesConditions($child, $conditions)) continue;

            $totalCount++;
            $rawVal = isset($child[$field]) ? $child[$field] : null;
            self::accumulateValue($accumulator, $numericCount, $rawVal, $fn, 1);
        }

        return self::finalizeAggregate($fn, $accumulator, $totalCount, $numericCount);
    }

    /**
     * Strategy for large sets: single streaming pass on child table (O(1) memory).
     */
    private static function aggregateManyToManyLarge(JdbRelationMeta $meta, array $junctionChildIds,
        $fn, $field, array $conditions)
    {
        $accumulator  = null;
        $totalCount   = 0;
        $numericCount = 0;

        // Map child_id -> occurrence_count
        $idCounts = array();
        foreach ($junctionChildIds as $cid) {
            $idCounts[$cid] = isset($idCounts[$cid]) ? $idCounts[$cid] + 1 : 1;
        }

        JdbManager::forEachRecord($meta->childTable,
            function ($child) use (&$totalCount, &$accumulator, &$numericCount, $idCounts, $conditions, $fn, $field) {
                $cid = isset($child['id']) ? (string)$child['id'] : null;
                if ($cid === null || !isset($idCounts[$cid])) return;
                if (!empty($conditions) && !JdbUtil::recordMatchesConditions($child, $conditions)) return;

                $occurrences = $idCounts[$cid];
                $totalCount += $occurrences;

                $rawVal = isset($child[$field]) ? $child[$field] : null;
                self::accumulateValue($accumulator, $numericCount, $rawVal, $fn, $occurrences);
            }
        );

        return self::finalizeAggregate($fn, $accumulator, $totalCount, $numericCount);
    }

    /**
     * Unified accumulator logic. Eliminates the duplicated switch($fn) block.
     */
    private static function accumulateValue(&$accumulator, &$numericCount, $rawVal, $fn, $occurrences = 1)
    {
        if ($fn === 'count') return;
        if ($rawVal === null || !is_numeric($rawVal)) return;

        $val = (float)$rawVal;
        $numericCount += $occurrences;

        switch ($fn) {
            case 'sum':
            case 'avg':
                $contribution = $val * $occurrences;
                $accumulator = ($accumulator === null) ? $contribution : $accumulator + $contribution;
                break;
            case 'min':
                $accumulator = ($accumulator === null) ? $val : min($accumulator, $val);
                break;
            case 'max':
                $accumulator = ($accumulator === null) ? $val : max($accumulator, $val);
                break;
        }
    }

    /**
     * Finalizes the aggregate calculation (handles count and avg division).
     */
    private static function finalizeAggregate($fn, $accumulator, $totalCount, $numericCount)
    {
        if ($fn === 'count') return $totalCount;
        if ($fn === 'avg')   return ($numericCount > 0) ? $accumulator / $numericCount : null;
        return $accumulator; // sum / min / max
    }

    /**
     * Full-table scan fallback for TEMPORAL range queries on un-indexed fields.
     *
     * The fkField pre-filter is passed to JdbManager::forEachRecord so the engine
     * can apply its own primary/hash index filter first.  The callback
     * re-checks with strict string comparison as a safety net against
     * engine-level type-coercion differences.
     *
     * @param  JdbRelationMeta $meta
     * @param  mixed           $parentId  Already normalised
     * @param  int             $fromTs
     * @param  int             $toTs      0 = no upper bound
     * @param  array           $conditions
     * @return array|null
     */
    private static function temporalScan(JdbRelationMeta $meta, $parentId,
                                          $fromTs, $toTs, array $conditions)
    {
        $rows      = array();
        $parentStr = (string)$parentId;

        $callback = function ($row)
                    use (&$rows, $meta, $parentStr, $fromTs, $toTs, $conditions)
        {
            // Strict fkField re-check (safety net; forEachRecord already pre-filters).
            if (!isset($row[$meta->fkField])
             || (string)$row[$meta->fkField] !== $parentStr) return;

            $ts = isset($row[$meta->timestampField])
                ? (int)$row[$meta->timestampField] : 0;
            if ($ts < $fromTs) return;
            if ($toTs > 0 && $ts > $toTs) return;

            if (!empty($conditions) && !JdbUtil::recordMatchesConditions($row, $conditions)) {
                return;
            }

            $rows[] = $row;
        };

        $res = JdbManager::forEachRecord($meta->childTable, $callback, array($meta->fkField => $parentId));

        if ($res === false) {
            self::setError('temporalScan', 'JdbManager::forEachRecord failed on "' . $meta->childTable . '"');
            return false;
        }

        return empty($rows) ? null : $rows;
    }

    /**
     * Collects records from $table matching $filter up to $limit rows.
     * Uses forEachRecord to stream results without materialising the full table.
     *
     * @param  string $table
     * @param  array  $filter
     * @param  int    $limit   0 = no limit
     * @return array|null|false
     */
    private static function collectWhere($table, array $filter, $limit)
    {
        $rows = array();
        $max  = (int)$limit;

        $res = JdbManager::forEachRecord($table,
            function ($row) use (&$rows, $max) {
                $rows[] = $row;
                if ($max > 0 && count($rows) >= $max) return false;
            }, $filter, 0);

        if ($res === false) {
            self::setError('collectWhere', 'JdbManager::forEachRecord failed');
            return false;
        }

        $maxQuery = (int)JdbConfig::get('max_query_results');
        if ($maxQuery > 0 && count($rows) >= $maxQuery) {
            self::setError('collectWhere', 'Result set exceeded max_query_results');
            return false;
        }

        return empty($rows) ? null : $rows;
    }

    // =========================================================================
    // Private: metadata, validation, and utility helpers
    // =========================================================================

    /**
     * Retrieves a named relation and asserts its type is in $allowedTypes.
     * Sets last error and returns false on any failure.
     *
     * @param  string   $name
     * @param  string[] $allowedTypes  JdbRelationType constants
     * @param  string   $caller        Calling method name (for error messages)
     * @return JdbRelationMeta|false
     */
    private static function getMeta($name, array $allowedTypes, $caller)
    {
        if (!isset(self::$relations[$name])) {
            self::setError($caller, 'Unknown relation "' . $name . '"');
            return false;
        }
        $meta = self::$relations[$name];
        if (!in_array($meta->type, $allowedTypes, true)) {
            self::setError($caller,
                'Type mismatch: expected '
                . implode('|', $allowedTypes) . ', got ' . $meta->type);
            return false;
        }
        return $meta;
    }

    /**
     * Returns true if $row's timestamp is below the archive cutoff.
     *
     * A missing or non-numeric timestamp is treated as 0 (epoch), which is
     * below any positive cutoff.
     *
     * @param  JdbRelationMeta $meta
     * @param  array           $row
     * @return bool
     */
    private static function isArchivedRow(JdbRelationMeta $meta, array $row)
    {
        if ((int)$meta->archiveCutoff <= 0) return false;
        $ts = isset($row[$meta->timestampField])
            ? (int)$row[$meta->timestampField] : 0;
        return $ts < (int)$meta->archiveCutoff;
    }

    // =========================================================================
    // Private: configuration and error helpers
    // =========================================================================

    /**
     * Records an internal error, pushes a JdbErrorHandler frame, and optionally
     * appends to the configured error log file.
     *
     * @param string $method  Calling method name
     * @param string $message Human-readable description
     */
    private static function setError($method, $message)
    {
        JdbErrorHandler::set('JdbAggregate', $method, $message, true);

        // Audit logging: errors from audited public methods only
        $auditPath = JdbConfig::get('audit_log_path');
        if ($auditPath && in_array($method, self::auditedMethods(), true)) {
            $log = sprintf("[%s] %s: %s\n", date('c'), $method, $message);
            $fileExists = file_exists($auditPath);
            $canWrite   = $fileExists ? is_writable($auditPath) : is_writable(dirname($auditPath));
            if ($canWrite) {
                error_log($log, 3, $auditPath);
            } else {
                // fallback on stderr
                error_log('[JdbAggregate] Audit Log path not writable: ' . $auditPath);
            }
        }
    }

    private static function auditedMethods()
    {
        return array(
            'getRelated', 'getPolymorphic', 'loadWith',
            'getTemporalRange', 'countRelated', 'aggregateRelated',
            'attach', 'detach', 'insertWithChildren',
            'deleteWithChildren', 'syncChildren',
        );
    }
    
    /**
     * Determines which tables need an exclusive lock for the delete operation.
     *
     * @param JdbRelationMeta $meta
     * @param bool $deleteParent
     * @return string[]
     */
    private static function determineTablesForDelete(JdbRelationMeta $meta, $deleteParent)
    {
        $tables = ($meta->type === JdbRelationType::MANY_TO_MANY)
            ? array($meta->junctionTable)
            : array($meta->childTable);

        if ($deleteParent && !empty($meta->parentTable)) {
            $tables[] = $meta->parentTable;
        }
        return $tables;
    }

    /**
     * Collects the IDs of the rows to be deleted, skipping archived rows for TEMPORAL relations.
     * Returns false on scan failure, or an array with the collected data.
     *
     * @param JdbRelationMeta $meta
     * @param mixed $parentId
     * @return array|false  ['ids' => string[], 'skipped' => int, 'table' => string] or false on error
     */
    private static function collectIdsToDelete(JdbRelationMeta $meta, $parentId)
    {
        $cutoff  = ($meta->type === JdbRelationType::TEMPORAL) ? (int)$meta->archiveCutoff : 0;
        $table   = ($meta->type === JdbRelationType::MANY_TO_MANY) ? $meta->junctionTable : $meta->childTable;
        $fkField = ($meta->type === JdbRelationType::MANY_TO_MANY) ? $meta->leftFk : $meta->fkField;
        
        $ids     = array();
        $skipped = 0;
        
        $res = JdbManager::forEachRecord($table, function ($row) use ($cutoff, &$ids, &$skipped, $meta) {
            if (!isset($row['id'])) return;
            if ($cutoff > 0 && self::isArchivedRow($meta, $row)) {
                $skipped++;
                return;
            }
            $ids[] = $row['id'];
        }, array($fkField => $parentId));
        
        if ($res === false) {
            self::setError('collectIdsToDelete', 'JdbManager::forEachRecord failed on "' . $table . '"');
            return false;
        }
        
        return array('ids' => $ids, 'skipped' => $skipped, 'table' => $table);
    }

    /**
     * Validates new child rows before a sync operation.
     *
     * @param JdbRelationMeta $meta
     * @param array[] $newChildRows
     * @return string|true  Returns true if valid, or an error message string if invalid.
     */
    private static function validateNewChildRowsForSync(JdbRelationMeta $meta, array $newChildRows)
    {
        foreach ($newChildRows as $i => $row) {
            if (array_key_exists($meta->fkField, $row)) {
                return 'New row #' . $i . ' must not contain the foreign key field "' . $meta->fkField . '"';
            }
            if ($meta->type === JdbRelationType::TEMPORAL && (int)$meta->archiveCutoff > 0) {
                if (self::isArchivedRow($meta, $row)) {
                    return 'New row #' . $i . ' is below archiveCutoff';
                }
            }
        }
        return true;
    }

    /**
     * Scans the child table to collect IDs of old rows that need to be deleted.
     * Skips newly inserted rows and archived rows (for TEMPORAL relations).
     *
     * @param JdbRelationMeta $meta
     * @param mixed $parentId
     * @param array $newIds  IDs of rows just inserted in this transaction
     * @return array|false  ['ids' => string[], 'skipped' => int] or false on error
     */
    private static function collectOldIdsForSync(JdbRelationMeta $meta, $parentId, array $newIds)
    {
        $cutoff      = ($meta->type === JdbRelationType::TEMPORAL) ? (int)$meta->archiveCutoff : 0;
        $idsToDelete = array();
        $skipped     = 0;

        $res = JdbManager::forEachRecord($meta->childTable,
            function ($row) use ($meta, $parentId, $cutoff, $newIds, &$idsToDelete, &$skipped) {
                if (!isset($row['id'])) {
                    return;
                }
                if (in_array($row['id'], $newIds, true)) {
                    return; // Skip newly inserted rows
                }
                if ($cutoff > 0 && self::isArchivedRow($meta, $row)) {
                    $skipped++;
                    return;
                }
                $idsToDelete[] = $row['id'];
            },
            array($meta->fkField => $parentId)
        );

        if ($res === false) {
            self::setError('collectOldIdsForSync',
                'JdbManager::forEachRecord failed on "' . $meta->childTable . '"');
            return false;
        }

        return array('ids' => $idsToDelete, 'skipped' => $skipped);
    }
}
