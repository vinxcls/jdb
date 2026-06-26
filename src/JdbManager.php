<?php
require_once __DIR__ . "/JdbErrorHandler.php";
require_once __DIR__ . "/JdbUtil.php";
require_once __DIR__ . "/JdbRealpathCache.php";
require_once __DIR__ . "/JdbSecondaryIndex.php";
require_once __DIR__ . "/JsonDatabase.php";
require_once __DIR__ . "/JdbConfig.php";
require_once __DIR__ . "/JdbTransaction.php";

/**
 * Manages all database operations with validation, instance caching and error handling.
 * 
 * Internal use: legacy functions delegate to this class.
 * Direct use: call JdbManager::method() for finer control.
 */
class JdbManager
{
    const VERSION = "1.0.0";

    /** @var array JsonDatabase instance cache */
    private static $instances = array();
    
    /** @var array Per-table configuration (secondary field lists) */
    private static $tableConfig = array();

    // ======================================================================
    // Configuration
    // ======================================================================
    /**
     * Configures the database manager.
     *
     * Thin alias for JdbConfig::configure(). Configuration is owned exclusively
     * by JdbConfig; this method exists for backward compatibility and for callers
     * who hold only a reference to JdbManager.
     *
     * @param array $config Configuration options
     * @return bool Configuration status
     */
    public static function configure(array $config)
    {
        $ok = JdbConfig::configure($config);
        if (!$ok) {
            // Mirror the error onto the JdbManager namespace so that
            // JdbManager::getLastError() returns it (JdbConfig writes to its own bucket).
            $last = JdbErrorHandler::getLast('JdbConfig');
            if ($last !== null) {
                JdbErrorHandler::set('JdbManager', $last['method'], $last['message']);
            }
        }
        return $ok;
    }
    
    /**
     * Returns JDB version
     *
     * @return string JDB Version
     */
    public static function getJdbVersion() {
        return self::VERSION;
    }

    /**
     * Returns a single configuration value.
     * Delegates to JdbConfig, the single source of truth.
     *
     * @param string $key Configuration key
     * @return mixed  Value, or null if the key does not exist
     */
    public static function getConfig($key)
    {
        return JdbConfig::get($key);
    }

    // ======================================================================
    // Secondary index configuration
    // ======================================================================

    /**
     * Configures the sort chunk size for all secondary indexes.
     *
     * @param int $size Must be 1-100000
     * @return bool
     */
    public static function configureSortChunkSize($size)
    {
        if (!is_int($size) || $size <= 0 || $size > 100000) {
            JdbErrorHandler::set('JdbManager', 'configureSortChunkSize', 'Invalid size: must be 1-100000');
            return false;
        }

        $result = JdbSecondaryIndex::setSortChunkSize($size);

        if (!$result) {
            JdbErrorHandler::set('JdbManager', 'configureSortChunkSize', JdbErrorHandler::formatStack());
        }

        return $result;
    }

    /**
     * Returns the current sort chunk size.
     *
     * @return int
     */
    public static function getSortChunkSize()
    {
        return JdbSecondaryIndex::getSortChunkSize();
    }

    /**
     * Configures the secondary indexes for a table.
     * Evicts any cached instance so the next access uses the new field list.
     *
     * @param string   $table  Table name
     * @param string[] $fields Fields to index
     */
    public static function configureSecondaryIndexes($table, array $fields)
    {
        if (!isset(self::$tableConfig[$table])) {
            self::$tableConfig[$table] = array();
        }
        self::$tableConfig[$table]['secondary_fields'] = $fields;
        
        // Evict the cached instance so it is recreated with the new config
        if (isset(self::$instances[$table])) {
            unset(self::$instances[$table]);
        }
    }

    /**
     * Convenience wrapper for configureSecondaryIndexes() + configureSortChunkSize().
     *
     * @param string   $table  Table name
     * @param string[] $fields Fields to index
     * @param int      $size   Sort chunk size (optional)
     * @return bool
     */
    public static function initSecondaryIndexes($table, array $fields, $size = null)
    {
        self::configureSecondaryIndexes($table, $fields);

        if ($size !== null) {
            self::configureSortChunkSize($size);
        }

        return true;
    }
    
    /**
     * Returns the configuration for a table.
     *
     * @param string $table Table name
     * @return array  Configuration array, or empty array if not configured
     */
    public static function getTableConfig($table)
    {
        return isset(self::$tableConfig[$table]) ? self::$tableConfig[$table] : array();
    }
    
    // ======================================================================
    // Error handling
    // ======================================================================
    /**
     * Clears the last operation error.
     */
    public static function clearError()
    {
        JdbErrorHandler::clear('JdbManager'); 
    }

    /**
     * Returns the last operation error, or null if the last operation succeeded.
     *
     * @return array|null  ['method' => string, 'message' => string, 'time' => int] | null
     */
    public static function getLastError()
    {
        return JdbErrorHandler::getLast('JdbManager');
    }
    
    /**
     * Returns the JdbErrorHandler stack trace string from the underlying JsonDatabase instance.
     * Useful for diagnosing low-level I/O failures.
     *
     * @param string $table Table name
     * @return string  Formatted stack trace, or empty string if no error
     */
    public static function getDbError($table)
    {
        if (!self::isValidTableName($table)) {
            return '';
        }
        $db = self::getInstance($table);
        return $db ? $db->getError() : '';
    }
    
    // ======================================================================
    // Instance management
    // ======================================================================

    /**
     * Removes an instance from the cache.
     * The next access will recreate it from disk (useful for testing or config reload).
     *
     * @param string $table Table name
     */
    public static function clearInstance($table)
    {
        if (isset(self::$instances[$table])) {
            unset(self::$instances[$table]);
        }
    }
    
    /**
     * Removes all instances from the cache.
     * Each table will be reopened from disk on next access.
     *
     * @param bool $resetConfig Also clear the per-table secondary-field configuration.
     *                          Pass true in tests to achieve full isolation between test cases.
     */
    public static function clearAllInstances($resetConfig = false)
    {
        self::$instances = array();
        if ($resetConfig) {
            self::$tableConfig = array();
        }
    }

    /**
     * Removes the secondary-field configuration for a single table.
     * The cached instance is also evicted so the next access recreates it
     * without any secondary indexes.
     *
     * @param string $table Table name
     */
    public static function clearTableConfig($table)
    {
        if (isset(self::$tableConfig[$table])) {
            unset(self::$tableConfig[$table]);
        }
        self::clearInstance($table);
    }

    /**
     * Removes all per-table secondary-field configuration and evicts all instances.
     * Equivalent to clearAllInstances(true).
     */
    public static function clearAllConfig()
    {
        self::$tableConfig = array();
        self::$instances   = array();
    }

    /**
     * Full static reset: restores defaults for all four static properties.
     *
     * Clears $instances, $tableConfig, and resets the configuration
     * to the factory defaults. Intended for test isolation between test cases.
     * Do not call in production code.
     *
     * @internal
     */
    public static function resetAll()
    {
        self::$instances   = array();
        self::$tableConfig = array();
        JdbConfig::resetAll();
    }
    
    // ======================================================================
    // Database operations – base API
    // ======================================================================
    /**
     * Returns all active records from a table.
     *
     * @param string $table Table name
     * @return array|false  Array of record arrays on success, false on error or invalid table name
     */
    public static function readAll($table)
    {
        if (!self::isValidTableName($table)) {
            JdbErrorHandler::set('JdbManager', 'readAll', 'Invalid table name: ' . $table);
            return false;
        }

        $db = self::getInstance($table);
        if (!$db) {
            return false;
        }

        $result = $db->selectAll();
        return is_array($result) ? $result : false;
    }
    
    /**
     * Finds a single record by its ID.
     *
     * @param string $table Table name
     * @param mixed  $id    Record ID
     * @return array|null  Record array, or null if not found / deleted
     */
    public static function findById($table, $id)
    {
        if (!self::isValidTableName($table)) {
            JdbErrorHandler::set('JdbManager', 'findById', 'Invalid table name: ' . $table);
            return null;
        }
        
        $db = self::getInstance($table);
        if (!$db) {
            return null;
        }
        
        return $db->selectById($id);
    }
    
    /**
     * Returns all records matching the given field => value conditions (AND).
     *
     * @param string  $table      Table name
     * @param array   $conditions Field => value pairs (all must match)
     * @return array|null|false False in case of error, null if no match found, array if matching records
     */
    public static function findWhere($table, array $conditions)
    {
        if (!self::isValidTableName($table)) {
            JdbErrorHandler::set('JdbManager', 'findWhere', 'Invalid table name: ' . $table);
            return false;
        }

        $db = self::getInstance($table);
        if (!$db) {
            return false;
        }

        // selectWhere() returns array|null: null means no matches (not an error).
        return $db->selectWhere($conditions);
    }
    
    /**
     * Streaming iteration over all active records in a table.
     *
     * Calls $callback for each record without accumulating results in an array.
     * This is the low-memory alternative to readAll() for large tables.
     * Return false from $callback to stop iteration early.
     *
     * @param string   $table      Table name
     * @param callable $callback   Called with each record array; return false to stop
     * @param array    $conditions Optional AND field => value filters
     * @param int      $limit      Max records to visit (0 = no limit)
     * @return int|false  Number of records passed to $callback, false on error
     */
    public static function forEachRecord($table, $callback,
                                         array $conditions = array(), $limit = 0)
    {
        if (!self::isValidTableName($table)) {
            JdbErrorHandler::set('JdbManager', 'forEachRecord', 'Invalid table name: ' . $table);
            return false;
        }

        if (!is_callable($callback)) {
            JdbErrorHandler::set('JdbManager', 'forEachRecord', 'Callback must be callable');
            return false;
        }

        $db = self::getInstance($table);
        if (!$db) {
            return false;
        }

        $result = $db->selectEach($callback, $conditions, $limit);

        if ($result === false) {
            JdbErrorHandler::set('JdbManager', 'forEachRecord', $db->getError());
            return false;
        }

        return $result;
    }

    /**
     * Inserts a new record.
     *
     * ID resolution order:
     *   1. $customId parameter (if not null)
     *   2. $data['id'] (if present — stripped from data before insert)
     *   3. Auto-increment
     *
     * @param string     $table    Table name
     * @param array      $data     Record fields
     * @param mixed|null $customId Explicit custom ID (optional)
     * @return mixed|false  Inserted ID, or false on error
     */
    public static function insert($table, array $data, $customId = null)
    {
        if (!self::isValidTableName($table)) {
            JdbErrorHandler::set('JdbManager', 'insert', 'Invalid table name: ' . $table);
            return false;
        }

        if (!JdbUtil::isNonEmptyArray($data)) {
            JdbErrorHandler::set('JdbManager', 'insert', 'Invalid record data');
            return false;
        }
        
        $db = self::getInstance($table);
        if (!$db) {
            return false;
        }
        
        if ($customId !== null) {
            $result = $db->insertWithId($customId, $data);
        } elseif (isset($data['id'])) {
            $customId = $data['id'];
            unset($data['id']);
            $result = $db->insertWithId($customId, $data);
        } else {
            $result = $db->insert($data);
        }
        
        if ($result === false) {
            JdbErrorHandler::set('JdbManager', 'insert', $db->getError());
        }
        
        return $result;
    }
    
    /**
     * Inserts multiple records in a single lock/unlock cycle.
     *
     * Dramatically faster than calling insert() in a loop for bulk imports —
     * one flock acquisition covers the entire batch.
     *
     * Each element of $rows is an array of field => value pairs.
     * Auto-increment IDs are assigned unless 'id' is present in a row.
     * The operation is atomic: on any failure the data file is rolled back
     * and none of the batch records are committed.
     *
     * @param string  $table Table name
     * @param array[] $rows  Array of record arrays
     * @return array|false  ['inserted' => int, 'ids' => array], or false on error
     */
    public static function insertBatch($table, array $rows)
    {
        if (!self::isValidTableName($table)) {
            JdbErrorHandler::set('JdbManager', 'insertBatch', 'Invalid table name: ' . $table);
            return false;
        }

        if (empty($rows)) {
            return array('inserted' => 0, 'ids' => array());
        }

        foreach ($rows as $i => $row) {
            if (!JdbUtil::isNonEmptyArray($row)) {
                JdbErrorHandler::set('JdbManager', 'insertBatch', 'Invalid record data at row #' . $i);
                return false;
            }
        }

        $db = self::getInstance($table);
        if (!$db) {
            return false;
        }

        $result = $db->insertBatch($rows);

        if ($result === false) {
            JdbErrorHandler::set('JdbManager', 'insertBatch', $db->getError());
        }

        return $result;
    }

    /**
     * Updates an existing record (append-only: writes a new version to the data file).
     * $data['id'] is automatically set to $id before writing.
     *
     * @param string $table Table name
     * @param mixed  $id    Record ID
     * @param array  $data  New field values (must be non-empty)
     * @param int    $expectedVersion Optimistic Concurrency Control
     * @return bool
     */
    public static function update($table, $id, array $data, $expectedVersion = null)
    {
        if (!self::isValidTableName($table)) {
            JdbErrorHandler::set('JdbManager', 'update', 'Invalid table name: ' . $table);
            return false;
        }
        
        if (!JdbUtil::isNonEmptyArray($data)) {
            JdbErrorHandler::set('JdbManager', 'update', 'Invalid record data');
            return false;
        }
        
        $db = self::getInstance($table);
        if (!$db) {
            return false;
        }
        
        // Normalise id type for consistency; overwrites any $data['id'] supplied by caller.
        $data['id'] = is_numeric($id) ? (int)$id : $id;

        $result = $db->update($id, $data, $expectedVersion);
        
        if ($result === false) {
            JdbErrorHandler::set('JdbManager', 'update', $db->getError());
        }
        
        return $result;
    }
    
    /**
     * Soft-deletes a record (writes a tombstone; record is excluded from all queries).
     *
     * @param string $table Table name
     * @param mixed  $id    Record ID
     * @param int    $expectedVersion Optimistic Concurrency Control
     * @return bool
     */
    public static function delete($table, $id, $expectedVersion = null)
    {
        if (!self::isValidTableName($table)) {
            JdbErrorHandler::set('JdbManager', 'delete', 'Invalid table name: ' . $table);
            return false;
        }
        
        $db = self::getInstance($table);
        if (!$db) {
            return false;
        }
        
        $result = $db->delete($id, $expectedVersion);
        
        if ($result === false) {
            JdbErrorHandler::set('JdbManager', 'delete', $db->getError());
        }
        
        return $result;
    }
    
    /**
     * Returns the current version number of a record.
     *
     * Useful for Optimistic Concurrency Control: pass the returned version
     * as $expectedVersion to update() or delete() to prevent lost updates.
     *
     * @param string $table Table name
     * @param mixed  $id    Record ID
     * @return int|false  Current version number, or false if the record does not exist or an error occurred
     */
    public static function getEntryVersion($table, $id)
    {
        if (!self::isValidTableName($table)) {
            JdbErrorHandler::set('JdbManager', 'getEntryVersion', 'Invalid table name: ' . $table);
            return false;
        }
        
        $db = self::getInstance($table);
        if (!$db) {
            return false;
        }
        
        $result = $db->getEntryVersion($id);
        
        if ($result === false) {
            JdbErrorHandler::set('JdbManager', 'getEntryVersion', $db->getError());
        }
        
        return $result;
    }

    /**
     * Returns the number of active (non-deleted) records in a table.
     *
     * @param string $table Table name
     * @return int|false  Record count on success, false on error or invalid table name
     */
    public static function count($table)
    {
        if (!self::isValidTableName($table)) {
            JdbErrorHandler::set('JdbManager', 'count', 'Invalid table name: ' . $table);
            return false;
        }

        $db = self::getInstance($table);
        if (!$db) {
            return false;
        }

        $result = $db->count();
        if ($result === false) {
            JdbErrorHandler::set('JdbManager', 'count', $db->getError());
            return false;
        }
        return $result;
    }
    
    /**
     * Compacts a table: rewrites the data file removing tombstones and obsolete versions,
     * then rebuilds a clean primary index.
     *
     * @param string $table Table name
     * @return array|false  Compaction statistics on success, false on error or invalid table name
     */
    public static function compact($table)
    {
        if (!self::isValidTableName($table)) {
            JdbErrorHandler::set('JdbManager', 'compact', 'Invalid table name: ' . $table);
            return false;
        }

        $db = self::getInstance($table);
        if (!$db) {
            return false;
        }

        $result = $db->vacuum();
        if ($result === false) {
            JdbErrorHandler::set('JdbManager', 'compact', $db->getError());
        }
        return $result;
    }
    
    /**
     * Returns statistics for a table (record counts, file sizes, index health, etc.).
     *
     * @param string $table Table name
     * @return array|false  Stats array on success, false on error or invalid table name
     */
    public static function getStats($table)
    {
        if (!self::isValidTableName($table)) {
            JdbErrorHandler::set('JdbManager', 'getStats', 'Invalid table name: ' . $table);
            return false;
        }

        $db = self::getInstance($table);
        if (!$db) {
            return false;
        }

        $result = $db->getStats();
        return is_array($result) ? $result : false;
    }
    
    /**
     * Checks whether a record with the given ID exists and is not deleted.
     *
     * @param string $table Table name
     * @param mixed  $id    Record ID
     * @return bool
     */
    public static function exists($table, $id)
    {
        if (!self::isValidTableName($table)) {
            JdbErrorHandler::set('JdbManager', 'exists', 'Invalid table name: ' . $table);
            return false;
        }
        
        $db = self::getInstance($table);
        if (!$db) {
            return false;
        }
        
        return $db->exists($id);
    }
    
    // ======================================================================
    // Database operations – secondary indexes (range queries)
    // ======================================================================
    /**
     * Executes a range query using the secondary index on $field.
     *
     * @param string   $table      Table name
     * @param string   $field      Indexed field name
     * @param mixed    $min        Inclusive lower bound (null = no lower bound)
     * @param mixed    $max        Inclusive upper bound (null = no upper bound)
     * @param array    $conditions Additional AND conditions (field => value)
     * @param int      $limit      Max records to return (0 = no limit)
     * @return array|null|false  Records array, null if no results, false if field not indexed
     */
    public static function selectRange($table, $field, $min = null, $max = null, 
                                       array $conditions = array(), $limit = 0)
    {
        if (!self::isValidTableName($table)) {
            JdbErrorHandler::set('JdbManager', 'selectRange', 'Invalid table name: ' . $table);
            return false;
        }

        if (!JdbUtil::isValidIdentifier($field, 64, '/^[a-zA-Z0-9_]+$/')) {
            JdbErrorHandler::set('JdbManager', 'selectRange', 'Invalid field name: ' . $field);
            return false;
        }
        
        $db = self::getInstance($table);
        if (!$db) {
            return false;
        }
        
        $result = $db->selectRange($field, $min, $max, $conditions, $limit);
        
        if ($result === false) {
            JdbErrorHandler::set('JdbManager', 'selectRange', 'Field not indexed: ' . $field);
        }
        
        return $result;
    }
    
    /**
     * Streaming range query: calls $callback for each matching record.
     * Lower memory footprint than selectRange() — no result array is built.
     * Return false from $callback to stop iteration early.
     *
     * @param string   $table      Table name
     * @param string   $field      Indexed field name
     * @param mixed    $min        Inclusive lower bound (null = no lower bound)
     * @param mixed    $max        Inclusive upper bound (null = no upper bound)
     * @param callable $callback   Called with each matching record array
     * @param array    $conditions Additional AND conditions
     * @param int      $limit      Max records to process (0 = no limit)
     * @return int|false  Number of records processed, or false if field not indexed
     */
    public static function selectRangeEach($table, $field, $min = null, $max = null,
                                           callable $callback, array $conditions = array(), $limit = 0)
    {
        if (!self::isValidTableName($table)) {
            JdbErrorHandler::set('JdbManager', 'selectRangeEach', 'Invalid table name: ' . $table);
            return false;
        }
        
        if (!JdbUtil::isValidIdentifier($field, 64, '/^[a-zA-Z0-9_]+$/')) {
            JdbErrorHandler::set('JdbManager', 'selectRangeEach', 'Invalid field name: ' . $field);
            return false;
        }
        
        if (!is_callable($callback)) {
            JdbErrorHandler::set('JdbManager', 'selectRangeEach', 'Callback must be callable');
            return false;
        }
        
        $db = self::getInstance($table);
        if (!$db) {
            return false;
        }
        
        $result = $db->selectRangeEach($field, $min, $max, $callback, $conditions, $limit);
        
        if ($result === false) {
            JdbErrorHandler::set('JdbManager', 'selectRangeEach', 'Field not indexed: ' . $field);
        }
        
        return $result;
    }
    
    /**
     * Checks whether a field has a configured and active secondary index.
     *
     * @param string $table Table name
     * @param string $field Field name
     * @return bool
     */
    public static function hasSecondaryIndex($table, $field)
    {
        if (!self::isValidTableName($table)) {
            return false;
        }

        if (!JdbUtil::isValidIdentifier($field, 64, '/^[a-zA-Z0-9_]+$/')) {
            return false;
        }
        
        $db = self::getInstance($table);
        if (!$db) {
            return false;
        }
        
        return $db->hasSecondaryIndex($field);
    }
    
    /**
     * Forces an immediate rebuild of all secondary indexes for a table.
     * Clears the dirty flag so subsequent range queries skip the lazy rebuild.
     *
     * @param string $table Table name
     * @return void
     */
    public static function rebuildSecondaryIndexes($table)
    {
        if (!self::isValidTableName($table)) {
            JdbErrorHandler::set('JdbManager', 'rebuildSecondaryIndexes', 'Invalid table name: ' . $table);
            return;
        }
        
        $db = self::getInstance($table);
        if ($db) {
            $db->rebuildSecondaryIndexes();
        }
    }
    
    /**
     * Returns the list of fields that have an active secondary index.
     *
     * @param string $table Table name
     * @return string[]  Field names, or empty array on error
     */
    public static function getSecondaryIndexes($table)
    {
        if (!self::isValidTableName($table)) {
            return array();
        }
        
        $db = self::getInstance($table);
        if (!$db) {
            return array();
        }
        
        $stats = $db->getStats();
        return isset($stats['secondary_indexes']) ? array_keys($stats['secondary_indexes']) : array();
    }
    
    /**
     * Convenience wrapper: selectRange() with only a lower bound.
     *
     * @param string $table      Table name
     * @param string $field      Indexed field name
     * @param mixed  $min        Inclusive lower bound
     * @param array  $conditions Additional AND conditions
     * @param int    $limit      Max records (0 = no limit)
     * @return array|null|false
     */
    public static function selectRangeFrom($table, $field, $min, 
                                           array $conditions = array(), $limit = 0)
    {
        return self::selectRange($table, $field, $min, null, $conditions, $limit);
    }
    
    /**
     * Convenience wrapper: selectRange() with only an upper bound.
     *
     * @param string $table      Table name
     * @param string $field      Indexed field name
     * @param mixed  $max        Inclusive upper bound
     * @param array  $conditions Additional AND conditions
     * @param int    $limit      Max records (0 = no limit)
     * @return array|null|false
     */
    public static function selectRangeTo($table, $field, $max, 
                                         array $conditions = array(), $limit = 0)
    {
        return self::selectRange($table, $field, null, $max, $conditions, $limit);
    }
    
    /**
     * Exact-match lookup on an indexed field (faster than findWhere for indexed fields).
     * Equivalent to selectRange($table, $field, $value, $value, ...).
     *
     * @param string $table      Table name
     * @param string $field      Indexed field name
     * @param mixed  $value      Exact value to match
     * @param array  $conditions Additional AND conditions
     * @param int    $limit      Max records (0 = no limit)
     * @return array|null|false
     */
    public static function selectByIndexedField($table, $field, $value,
                                                 array $conditions = array(), $limit = 0)
    {
        return self::selectRange($table, $field, $value, $value, $conditions, $limit);
    }
    
    // ======================================================================
    // Pagination
    // ======================================================================

    /**
     * Cursor-based pagination over a table with O(1) memory usage.
     *
     * Pass $cursor=null to retrieve the first page. Use the 'next_cursor' value
     * from the returned array as $cursor for the subsequent page.
     * Returns false (and sets the last error) on validation or I/O failure.
     *
     * @param string         $table      Table name
     * @param int            $pageSize   Number of records per page (must be > 0)
     * @param mixed|null     $cursor     Opaque cursor from the previous page, or null for the first page
     * @param array          $conditions Optional AND field => value filters
     * @return array|false  Associative array with keys 'records' and 'next_cursor', or false on error
     */
    public static function selectPage($table, $pageSize, $cursor = null,
                                      array $conditions = array())
    {
        if (!self::isValidTableName($table)) {
            JdbErrorHandler::set('JdbManager', 'selectPage', 'Invalid table name: ' . $table);
            return false;
        }
        $db = self::getInstance($table);
        if (!$db) return false;
        $result = $db->selectPage($pageSize, $cursor, $conditions);
        if ($result === false) JdbErrorHandler::set('JdbManager', 'selectPage', $db->getError());
        return $result;
    }

    // ======================================================================
    // Aggregations
    // ======================================================================

    /**
     * Computes an aggregation function over a field in a table.
     *
     * Supported functions: 'count', 'sum', 'avg', 'min', 'max'.
     * When $field has a secondary index, 'min' and 'max' are resolved in O(1)
     * without scanning the full table.
     *
     * @param string $table      Table name
     * @param string $fn         Aggregation function: 'count' | 'sum' | 'avg' | 'min' | 'max'
     * @param string $field      Field name to aggregate over
     * @param array  $conditions Optional AND field => value filters applied before aggregation
     * @return mixed|false  Aggregated value (int or float), or false on error or unknown function
     */
    public static function aggregate($table, $fn, $field,
                                     array $conditions = array())
    {
        if (!self::isValidTableName($table)) {
            JdbErrorHandler::set('JdbManager', 'aggregate', 'Invalid table name: ' . $table);
            return false;
        }
        if (!JdbUtil::isValidIdentifier($field, 64, '/^[a-zA-Z0-9_]+$/')) {
            JdbErrorHandler::set('JdbManager', 'aggregate', 'Invalid field name: ' . $field);
            return false;
        }
        $db = self::getInstance($table);
        if (!$db) return false;
        $result = $db->aggregate($fn, $field, $conditions);
        if ($result === false) JdbErrorHandler::set('JdbManager', 'aggregate', $db->getError());
        return $result;
    }

    /**
     * Returns the status of all secondary indexes for a table.
     *
     * @param string $table Table name
     * @return array  field => ['dirty' => bool, 'file_size' => int], or empty array on error
     */
    public static function checkSecondaryIndexes($table)
    {
        if (!self::isValidTableName($table)) {
            return array();
        }
        
        $db = self::getInstance($table);
        if (!$db) {
            return array();
        }
        
        $stats = $db->getStats();
        return isset($stats['secondary_indexes']) ? $stats['secondary_indexes'] : array();
    }
    
    /**
     * Checks whether any secondary index for the table is dirty and needs rebuilding.
     *
     * @param string $table Table name
     * @return bool  True if at least one index is dirty
     */
    public static function secondaryIndexesNeedRebuild($table)
    {
        $indexes = self::checkSecondaryIndexes($table);
        foreach ($indexes as $info) {
            if (isset($info['dirty']) && $info['dirty']) {
                return true;
            }
        }
        return false;
    }

    // ======================================================================
    // Transactions
    // ======================================================================

    /**
     * Creates and starts a JdbTransaction on the given tables.
     *
     * Acquires an exclusive mutex on each table before returning.
     * Returns null (and sets the last error) if the lock cannot be acquired
     * within the configured timeout.
     *
     * Usage:
     * @code
     *   $tx = JdbManager::beginTransaction(['orders', 'order_items']);
     *   if ($tx === null) { // lock timeout }
     *
     *   $orderId = $tx->insert('orders', ['total' => 99.90]);
     *   $tx->insertBatch('order_items', [['order_id' => $orderId, 'sku' => 'A1']]);
     *   $tx->commit();
     * @endcode
     *
     * @param  string[] $tables  All tables that will be written inside the transaction
     * @return JdbTransaction|null  Ready-to-use transaction, or null on lock failure
     */
    public static function beginTransaction(array $tables)
    {
        $dataDirConfig = JdbConfig::get('data_dir');
        $dataDir = ($dataDirConfig !== null)
            ? $dataDirConfig
            : (defined('DATA_PATH') ? DATA_PATH : '.');

        $tx = new JdbTransaction(
            $dataDir,
            (string)JdbConfig::get('lock_backend'),
            (int)JdbConfig::get('lock_timeout_ms')
        );

        if (!$tx->begin($tables)) {
            JdbErrorHandler::set('JdbManager', 'beginTransaction',
                'Lock acquisition failed for: ' . implode(', ', $tables));
            return null;
        }

        return $tx;
    }

    /**
     * Resolves the data directory path.
     *
     * Resolution order:
     *   1. JdbConfig::get('data_dir') (explicit config)
     *   2. DATA_PATH constant (application-level constant)
     *   3. '.' (current working directory – last resort)
     *
     * @return string
     */
    public static function getDataDir()
    {
        $dir = JdbConfig::get('data_dir');
        if (!empty($dir)) {
            return (string)$dir;
        }
        if (defined('DATA_PATH')) {
            return DATA_PATH;
        }
        return '.';
    }
    
    /**
     * Returns the full filesystem path to the JSONL data file for a table.
     *
     * @param string $table Table name
     * @return string  Absolute or relative path to the data file (e.g. /data/users.jsonl.php)
     */
    public static function getDataPath($table)
    {
        return JdbManager::getDataDir() . '/' . $table . '.jsonl.php';
    }

    /**
     * Returns the full filesystem path to the primary index file for a table.
     *
     * @param string $table Table name
     * @return string  Absolute or relative path to the primary index file (e.g. /data/users.index.php)
     */
    public static function getIndexPath($table) {
        return JdbManager::getDataDir() . '/' . $table . '.index.php';
    }

    /**
     * Returns the full filesystem path to the secondary index file for a given table field.
     *
     * Non-alphanumeric characters in $field are replaced with underscores to produce a safe filename.
     *
     * @param string $table Table name
     * @param string $field Indexed field name
     * @return string  Absolute or relative path to the secondary index file (e.g. /data/users.idx_email.php)
     */
    public static function getSecondaryIndexPath($table, $field) {
        $safe = preg_replace('/[^a-zA-Z0-9_]/', '_', (string)$field);
        return self::getDataDir() . '/' . $table . '.idx_' . $safe . '.php';
    }

    /**
     * Truncates the data file of $table to $dataOffset bytes and deletes all
     * associated index files (primary and secondary), which will be rebuilt lazily
     * on the next access.
     *
     * If $dataOffset is 0 the data file is deleted entirely: the table
     * did not exist before the transaction and must be restored to a non-existent state.
     *
     * The JdbManager instance is invalidated so that the next access
     * reads from disk and rebuilds the index.
     *
     * @param  string $table       Table name
     * @param  int    $dataOffset  Byte offset to truncate the data file to; 0 removes the file entirely
     * @return bool  True on success, false if the file is not writable or a filesystem operation fails
     */
    public static function truncateTable($table, $dataOffset = 0)
    {
        $dataFile = self::getDataPath($table);
        if ($dataOffset === 0) {
            if (file_exists($dataFile)) {
                if (!is_writable($dataFile)) {
                    return false;
                }
                if (!unlink($dataFile)) {
                    return false;
                }
            }
        } else {
            if (!is_writable($dataFile)) {
                return false;
            }
            $fp = fopen($dataFile, 'r+');
            if (!is_resource($fp)) {
                return false;
            }
            if (!ftruncate($fp, $dataOffset)) {
                fclose($fp);
                return false;
            }
            fclose($fp);
        }
        // Delete index files
        $dataDir = dirname($dataFile);
        foreach (glob($dataDir . '/' . $table . '.index.php') as $f) {
            unlink($f);
        }
        foreach (glob($dataDir . '/' . $table . '.idx_*.php') as $f) {
            unlink($f);
        }
        self::clearInstance($table);
        return true;
    }

    /**
     * Validates a table name against the configured pattern and length limits.
     * Also blocks path-traversal sequences.
     *
     * @param string $table Table name
     * @return bool
     */
    private static function isValidTableName($table)
    {
        if (empty($table) || !is_string($table)) {
            return false;
        }

        // Unconditional hard block: cannot be bypassed via validate_table_names.
        // Path-traversal checks are a minimum security guarantee of the code,
        // not a configurable preference for the operator.
        if (strpos($table, '..') !== false ||
            strpos($table, '/')  !== false ||
            strpos($table, '\\') !== false) {
            return false;
        }

        // The following checks are configurable by the operator.
        if (!JdbConfig::get('validate_table_names')) {
            return true;
        }

        $maxLen  = (int)JdbConfig::get('max_table_name_len');
        $pattern = JdbConfig::get('table_name_pattern');
        return JdbUtil::isValidIdentifier($table, $maxLen, $pattern);
    }
    
    /**
     * Returns (or lazily creates) the JsonDatabase instance for a table.
     * Uses DATA_PATH as the data directory and applies any configured secondary fields.
     *
     * @param string $table Table name
     * @return JsonDatabase|null  null if the instance could not be created
     */
    private static function getInstance($table)
    {
        if (!isset(self::$instances[$table])) {
            try {
                // Retrieve secondary field list for this table
                $tableConfig     = self::getTableConfig($table);
                $secondaryFields = isset($tableConfig['secondary_fields'])
                    ? $tableConfig['secondary_fields'] : array();

                // Resolve data directory: config key → DATA_PATH constant → '.'
                $dataDirConfig = JdbConfig::get('data_dir');
                $dataPath = ($dataDirConfig !== null)
                    ? $dataDirConfig
                    : (defined('DATA_PATH') ? DATA_PATH : '.');

                // Validate data directory (creates it if missing, avoids realpath() failure on new tables)
                if (!JdbUtil::ensureDirectory($dataPath)) {
                    JdbErrorHandler::set('JdbManager', 'getInstance', 'Cannot create data directory');
                    return null;
                }

                $realDataDir = JdbRealpathCache::get($dataPath);
                if ($realDataDir === false || !is_writable($realDataDir)) {
                    JdbErrorHandler::set('JdbManager', 'getInstance', 'Data path missing or not writable');
                    return null;
                }

                $indexFile = $dataPath . '/' . $table . '.index.php';

                $fileDir = dirname($indexFile);
                $realFileDir = JdbRealpathCache::get($fileDir);

                if ($realFileDir === false || ($realFileDir !== $realDataDir)) {
                    JdbErrorHandler::set('JdbManager', 'getInstance', 'Path traversal detected in table name');
                    return null;
                }

                // Per-instance lock timeout
                $lockMs      = (int)JdbConfig::get('lock_timeout_ms');
                $lockBackend = JdbConfig::get('lock_backend');

                $instance = new JsonDatabase($table, $dataPath, $secondaryFields, $lockMs, $lockBackend);

                // Auto-compact wiring
                if (!empty(JdbConfig::get('auto_compact'))) {
                    $threshold = (float)JdbConfig::get('auto_compact_threshold');
                    $instance->setAutoCompact(true, $threshold);
                }

                self::$instances[$table] = $instance;
            } catch (Exception $e) {
                JdbErrorHandler::set('JdbManager', 'getInstance', $e->getMessage());
                return null;
            }
        }

        return self::$instances[$table];
    }
    
}
