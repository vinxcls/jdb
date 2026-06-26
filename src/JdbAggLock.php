<?php
require_once __DIR__ . "/JdbErrorHandler.php";

/**
 * JdbAggLock – Deadlock-safe multi-table mutex coordinator.
 *
 * Wraps a JdbLock instance to provide an "acquire N mutexes or none" primitive
 * for JdbAggregate's cross-table write operations.
 *
 * Deadlock prevention strategy (canonical lock ordering):
 *   Before issuing any acquireMutex() call, the table list is sorted
 *   lexicographically.  Two concurrent processes requesting overlapping table
 *   sets therefore always request mutexes in the same global order, eliminating
 *   the circular-wait condition that is the necessary precondition for a deadlock.
 *
 * All-or-nothing acquisition:
 *   If mutex acquisition for table T_k fails (timeout), all mutexes already
 *   acquired for T_0 … T_{k-1} in this call are released immediately.
 *   The caller retries or propagates the error; it never holds a partial lock set.
 *
 * Release order:
 *   releaseAll() releases in reverse acquisition order (LIFO).
 *
 * Mutex path convention:
 *   $dataDir . DIRECTORY_SEPARATOR . '.jdbagg_' . sanitized($tableName)
 *   This namespace is distinct from per-file flock handles and .rebuild.lock
 *   mutexes used by JsonDatabase.
 *
 * The acquisition of a shared lock for read-only is not implemented because is not
 * needed in this case.
 *
 */
class JdbAggLock
{
    /** Lock-type constant: read-only scope (no aggregate mutex acquired). */
    const TYPE_SHARED    = 'shared';

    /** Lock-type constant: write scope (named mutex per table). */
    const TYPE_EXCLUSIVE = 'exclusive';

    /**
     * Granularity constant: one mutex covers the entire table.
     * Default and sufficient for correctness in all current use cases.
     */
    const GRANULARITY_TABLE    = 'table';

    /**
     * Granularity constant (informational): one mutex per named relation.
     * Not enforced at filesystem level; documents intended scope.
     */
    const GRANULARITY_RELATION = 'relation';

    /**
     * Granularity constant (informational): one mutex per (table, parentId).
     * Higher concurrency for disjoint parent-scoped writes.  Reserved for
     * future use.
     */
    const GRANULARITY_ROW      = 'row';

    /** @var JdbLock Underlying lock primitive (flock or mkdir backend) */
    private $lck;

    /** @var string Absolute path to the data directory for mutex files */
    private $dataDir;

    /**
     * Acquired mutex tokens in acquisition order.
     * Each element: ['name' => string, 'token' => mixed]
     * Initialised to an empty array so that releaseAll() is always safe to
     * call, even before acquireExclusive() has been invoked.
     *
     * @var array[]
     */
    private $held = array();

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * @param string $dataDir   Absolute path to the data directory
     * @param string $backend   'flock' (default) | 'mkdir' (NFS-safe)
     * @param int    $timeoutMs Per-table acquisition timeout in milliseconds
     */
    public function __construct($dataDir, $backend = 'flock', $timeoutMs = 1500)
    {
        $this->lck     = new JdbLock($backend, (int)$timeoutMs);
        $this->dataDir = rtrim($dataDir, '/\\');
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Acquires exclusive named mutexes for all $tables in canonical sorted order.
     *
     * Sorting guarantees deadlock freedom: any two processes requesting
     * overlapping table sets will always request locks in the same order.
     *
     * All-or-nothing: on any individual acquisition failure, all mutexes
     * acquired so far in this call are released and false is returned.
     *
     * @param  string[] $tables  Table names to lock (duplicates deduplicated)
     * @return bool              True if all mutexes were acquired; false on timeout
     */
    public function acquireExclusive(array $tables)
    {
        if (empty($tables)) return true;

        $ordered = array_values(array_unique($tables));
        sort($ordered); // canonical order: prevents circular wait

        foreach ($ordered as $table) {
            $token = $this->lck->acquireMutex($this->mutexPath($table));
            if ($token === false) {
                JdbErrorHandler::push('JdbAggLock', 'acquireExclusive',
                    'mutex timeout for table [' . $table . ']; rolling back '
                    . count($this->held) . ' lock(s)');
                $this->releaseAll();
                return false;
            }
            $this->held[] = array('name' => $table, 'token' => $token);
        }

        return true;
    }

    /**
     * Releases all held mutexes in reverse acquisition order (LIFO).
     * Safe to call multiple times; subsequent calls are silent no-ops.
     */
    public function releaseAll()
    {
        foreach (array_reverse($this->held) as $entry) {
            $this->lck->releaseMutex($entry['token']);
        }
        $this->held = array();
    }

    /**
     * Returns true if at least one mutex is currently held.
     *
     * @return bool
     */
    public function isHolding()
    {
        return !empty($this->held);
    }

    /**
     * Returns the names of tables for which a mutex is currently held,
     * in acquisition order.
     *
     * @return string[]
     */
    public function heldTables()
    {
        return array_column($this->held, 'name');
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Builds the filesystem path for the named aggregate mutex of a table.
     *
     * Convention: $dataDir/.jdbagg_{sanitized_table}
     *
     * The ".jdbagg_" prefix:
     *   – Distinguishes aggregate mutexes from per-file flock handles.
     *   – Distinguishes them from ".rebuild.lock" index-rebuild mutexes.
     *   – Leading dot keeps them hidden on Unix file systems.
     *
     * The table name is sanitized to [a-zA-Z0-9_] to ensure the path is safe
     * for both mkdir() and fopen() backends regardless of platform.
     *
     * @param  string $table  Validated table name
     * @return string         Absolute mutex path
     */
    private function mutexPath($table)
    {
        $safe = preg_replace('/[^a-zA-Z0-9_]/', '_', (string)$table);
        // CRC32 is fast and native in PHP 5.5+. dechex() generates filesystem-safe chars.
        $hash = dechex(crc32((string)$table));
        return $this->dataDir . DIRECTORY_SEPARATOR . '.jdbagg_' . $safe . '_' . $hash;
    }
}
