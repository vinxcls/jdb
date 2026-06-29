<?php

/**
 * @brief Centralised configuration registry for the JDB engine.
 *
 * Acts as the single entry point for all runtime settings.
 * JdbManager and JdbAggregate read values directly from this class at runtime;
 * no local copies are kept by either consumer.
 *
 * Configuration keys are split into three groups:
 *   - Shared:    keys forwarded to both JdbManager and JdbAggregate.
 *   - Manager:   keys forwarded only to JdbManager.
 *   - Aggregate: keys forwarded only to JdbAggregate.
 *
 * Lazy initialisation: getManagerConfig(), getAggregateConfig(), getAll() and
 * get() all seed self::$config with the merged defaults if configure() has not
 * yet been called.
 *
 * Side effects (JdbRealpathCache TTL, JdbErrorHandler wiring) are applied
 * centrally inside configure() so that neither consumer needs to call back
 * into this class.
 *
 * @see JdbManager
 * @see JdbAggregate
 */
class JdbConfig
{
    /**
     * @var array Default values for keys forwarded to both JdbManager and JdbAggregate.
     *
     * Keys:
     *   - data_dir            (string|null) Root directory for all table files.
     *   - log_errors          (bool)        Whether to write errors to a log file.
     *   - error_log_path      (string|null) Path to the error log file; null = disabled.
     *   - detailed_errors     (bool)        Include stack traces and context in log entries.
     *   - lock_backend        (string)      Locking strategy: 'flock' or other registered backend.
     *   - lock_timeout_ms     (int)         Maximum time to wait for a lock, in milliseconds.
     *   - realpath_cache_ttl  (int)         TTL for JdbRealpathCache entries, in seconds.
     *   - fsync               (bool)        Requires PHP >= 8.1
     */
    private static $sharedDefaults = array(
        'data_dir' => null,
        'log_errors' => false,
        'error_log_path' => null,
        'detailed_errors' => false,
        'lock_backend' => 'flock',
        'lock_timeout_ms' => 500,
        'realpath_cache_ttl' => 3600,
        'fsync' => false,
    );

    /**
     * @var array Default values for keys forwarded exclusively to JdbManager.
     *
     * Keys:
     *   - validate_table_names    (bool)   Reject table names that do not match the pattern below.
     *   - table_name_pattern      (string) PCRE pattern used to validate table names.
     *   - max_table_name_len      (int)    Maximum allowed length for a table name, in characters.
     *   - auto_create_indexes     (bool)   Automatically build binary indexes on first access.
     *   - auto_compact            (bool)   Trigger compaction automatically when the threshold is met.
     *   - auto_compact_threshold  (float)  Fragmentation ratio [0.0–1.0] above which auto-compact fires.
     */
    private static $managerDefaults = array(
        'validate_table_names' => true,
        'table_name_pattern' => '/^[a-zA-Z0-9_]+$/',
        'max_table_name_len' => 64,
        'auto_create_indexes' => true,
        'auto_compact' => false,
        'auto_compact_threshold' => 0.3,
    );

    /**
     * @var array Default values for keys forwarded exclusively to JdbAggregate.
     *
     * Keys:
     *   - max_retries            (int)         Number of retry attempts on transient failures.
     *   - retry_delay_ms         (int)         Delay between retries, in milliseconds.
     *   - max_scan_rows          (int)         Maximum rows scanned per query; 0 = unlimited.
     *   - max_query_results      (int)         Maximum rows returned by a single query.
     *   - batch_fetch_threshold  (int)         Row count above which bulk fetch is preferred over
     *                                          individual record reads.
     *   - audit_log_path         (string|null) Path to the audit log file; null = disabled.
     *   - enforce_foreign_keys   (bool)        Reject writes that violate declared FK constraints.
     */
    private static $aggregateDefaults = array(
        'max_retries' => 2,
        'retry_delay_ms' => 50,
        'max_scan_rows' => 0,
        'max_query_results' => 10000,
        'batch_fetch_threshold' => 200,
        'audit_log_path' => null,
        'enforce_foreign_keys' => false,
    );

    /**
     * @var array Merged runtime configuration (shared + manager + aggregate defaults,
     *            overlaid with any values passed to configure()).
     *            Empty until configure() or a lazy-init getter is first called.
     *
     * @see self::getAll()
     * @see self::configure()
     */
    private static $config = array();

    /**
     * @brief Configures the JDB engine from a single associative array.
     *
     * This is the single entry point for configuration. JdbManager and JdbAggregate
     * read values directly from JdbConfig at runtime; no local copies are kept.
     *
     * Validation: returns false (and pushes an error via JdbErrorHandler) on the
     * first unknown key found; valid keys processed before that point are discarded.
     *
     * Lazy initialisation: if self::$config is empty, it is seeded with the full
     * set of defaults before the supplied values are merged in.
     *
     * Side effects applied after a successful merge:
     *   - JdbRealpathCache::setDefaultTtl() — if 'realpath_cache_ttl' is present.
     *   - JdbErrorHandler::configure()       — always, for both Manager and Aggregate.
     *
     * @param  array $config Associative array of configuration key–value pairs.
     *                       All keys must belong to one of the three default groups.
     * @return bool  true on success; false if an unknown key is present.
     */
    public static function configure(array $config)
    {
        $known = array_merge(
            array_keys(self::$sharedDefaults),
            array_keys(self::$managerDefaults),
            array_keys(self::$aggregateDefaults)
        );

        foreach (array_keys($config) as $key) {
            if (!in_array($key, $known, true)) {
                JdbErrorHandler::set('JdbConfig', 'configure', 'Unknown configuration key: "' . $key . '"');
                return false;
            }
        }

        if (empty(self::$config)) {
            self::$config = array_merge(
                self::$sharedDefaults, self::$managerDefaults, self::$aggregateDefaults
            );
        }

        self::$config = array_merge(self::$config, $config);

        // ── Side effects applied centrally (no cross-calls to Manager/Aggregate) ──
        if (isset($config['realpath_cache_ttl']) && is_numeric($config['realpath_cache_ttl'])) {
            JdbRealpathCache::setDefaultTtl((int)$config['realpath_cache_ttl']);
        }
        JdbErrorHandler::configure('JdbManager',   self::getManagerConfig());
        JdbErrorHandler::configure('JdbAggregate', self::getAggregateConfig());

        return true;
    }

    /**
     * @brief Returns the entire current configuration as a merged associative array.
     *
     * If configure() has not yet been called, seeds self::$config with the full
     * set of merged defaults (lazy initialisation) before returning.
     *
     * @return array Associative array containing all configuration keys and their
     *               current values (defaults overridden by any configure() call).
     */
    public static function getAll()
    {
        return self::$config;
    }

    /**
     * @brief Returns the current value of a single configuration key.
     *
     * Does NOT trigger lazy initialisation: if configure() has not been called,
     * the key will not exist in self::$config and $default is returned.
     * Call getAll() or getManagerConfig() first if you need defaults populated.
     *
     * @param  string $key     Configuration key to look up.
     * @param  mixed  $default Value to return when the key does not exist (default: null).
     * @return mixed  The stored value for $key, or $default if the key is absent.
     */
    public static function get($key, $default = null)
    {
        return array_key_exists($key, self::$config) ? self::$config[$key] : $default;
    }

    /**
     * @brief Returns the configuration slice relevant to JdbManager.
     *
     * Includes all shared keys and all manager-specific keys.
     * Aggregate-only keys are excluded so that JdbManager never receives
     * configuration it does not own.
     *
     * If configure() has not yet been called, seeds self::$config with the full
     * set of merged defaults (lazy initialisation) before filtering.
     *
     * @return array Associative array of shared + manager keys and their current values.
     */
    public static function getManagerConfig()
    {
        if (empty(self::$config)) {
            self::$config = array_merge(
                self::$sharedDefaults, self::$managerDefaults, self::$aggregateDefaults
            );
        }
        $managerKeys = array_merge(
            array_keys(self::$sharedDefaults),
            array_keys(self::$managerDefaults)
        );
        return array_intersect_key(self::$config, array_flip($managerKeys));
    }

    /**
     * @brief Returns the configuration slice relevant to JdbAggregate.
     *
     * Includes all shared keys and all aggregate-specific keys.
     * Manager-only keys are excluded so that JdbAggregate never receives
     * configuration it does not own.
     *
     * If configure() has not yet been called, seeds self::$config with the full
     * set of merged defaults (lazy initialisation) before filtering.
     *
     * @return array Associative array of shared + aggregate keys and their current values.
     */
    public static function getAggregateConfig()
    {
        if (empty(self::$config)) {
            self::$config = array_merge(
                self::$sharedDefaults, self::$managerDefaults, self::$aggregateDefaults
            );
        }
        $aggregateKeys = array_merge(
            array_keys(self::$sharedDefaults),
            array_keys(self::$aggregateDefaults)
        );
        return array_intersect_key(self::$config, array_flip($aggregateKeys));
    }

    /**
     * @brief Resets self::$config to an empty array.
     *
     * After this call, the next invocation of configure() or any lazy-init getter
     * will re-seed self::$config from the static defaults.
     *
     * @note Partial reset only: side effects applied by a previous configure() call
     *       are NOT undone. Specifically:
     *         - JdbRealpathCache default TTL remains at the last configured value.
     *         - JdbErrorHandler wiring for JdbManager and JdbAggregate is not cleared.
     *       Call resetAll() only in test teardown or controlled bootstrap sequences
     *       where stale side-effect state is acceptable.
     *
     * @return void
     */
    public static function resetAll()
    {
        self::$config = array();
    }
}
