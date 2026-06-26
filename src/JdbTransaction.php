<?php
require_once __DIR__ . "/JdbErrorHandler.php";
require_once __DIR__ . "/JdbUtil.php";
require_once __DIR__ . "/JdbManager.php";

/**
 * @class JdbTransaction
 * @brief ACID transaction layer for JDB.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  DESIGN                                                                  │
 * │                                                                          │
 * │  • Isolation    → JdbAggLock (exclusive per-table mutexes acquired      │
 * │                   in alphabetical order to prevent deadlocks)            │
 * │  • Durability   → a .txn_{pid}_{random}.php file is written before any  │
 * │                   operation; it stores the data-file offsets at begin(). │
 * │                   Protected by <?php die(); ?> and CRC32 to detect      │
 * │                   corruption.                                            │
 * │  • commit()     → unlink() of the .txn file (atomic POSIX operation)   │
 * │  • rollback()   → ftruncate() of data files to the saved offsets +      │
 * │                   deletion of index files (lazily rebuilt on next use) + │
 * │                   unlink() of the .txn file                             │
 * │  • Crash recovery → recover() finds orphan .txn files (dead process)   │
 * │                   and applies a truncation-based rollback                │
 * │                                                                          │
 * │  WHY TRUNCATION IS CORRECT                                               │
 * │    JDB is append-only: all state prior to begin() is contained in the   │
 * │    first N bytes of the data file. Truncating to N restores the file    │
 * │    to the exact pre-transaction state without a separate journal or      │
 * │    compensation records.                                                  │
 * │                                                                          │
 * │  LIMITATION: cooperative isolation                                       │
 * │    The mutex blocks other JdbTransaction instances, but NOT direct calls │
 * │    to JdbManager that bypass JdbAggLock. For full guarantees, all       │
 * │    writers must go through JdbTransaction.                               │
 * │                                                                          │
 * │  COMPATIBILITY                                                            │
 * │    PHP >= 5.5, 64-bit architecture (PHP integer = 64-bit signed)        │
 * │    POSIX filesystem (atomic rename(), ftruncate() available)             │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * .txn file format  (size: 16 + 31 + N * 80 bytes)
 *
 *  Offset  Size  Type        Field
 *  ─────────────────────────────────────────────────────────────────────────
 *  PHP HEADER (16 bytes, fixed)
 *    0      16  raw         "<?php die(); ?>\n"    blocks direct HTTP access
 *
 *  FIXED HEADER (31 bytes)
 *    0       4  a4          Magic "JDBT"
 *    4       2  uint16 LE   Version
 *    6       1  uint8       Number of tables
 *    7       4  uint32 LE   PID of the owning process
 *   11       8  uint64 LE   Unix timestamp (two consecutive uint32 LE)
 *   19       8  raw         TXN random ID
 *   27       4  uint32 LE   CRC32 of the preceding 27 bytes
 *
 *  PER-TABLE RECORD (80 bytes each)
 *    0       1  uint8       Table name length
 *    1      63  raw         Table name (null-padded)
 *   64       8  uint64 LE   Data file offset at begin()
 *   72       8  uint64 LE   Index file offset at begin()
 */
class JdbTransaction
{
    /** @var string Transaction has not been started yet. */
    const STATE_IDLE        = 'idle';

    /** @var string begin() succeeded; commit/rollback not yet called. */
    const STATE_ACTIVE      = 'active';

    /** @var string commit() completed successfully. */
    const STATE_COMMITTED   = 'committed';

    /** @var string rollback() completed successfully. */
    const STATE_ROLLED_BACK = 'rolled_back';

    /** @var int Size in bytes of the fixed binary header (4+2+1+4+8+8+4 = 31). */
    const TXN_FIXED_SIZE  = 31;

    /** @var int Size in bytes of each per-table record (1+63+8+8 = 80). */
    const TXN_TABLE_SIZE  = 80;

    /** @var string Four-byte magic word identifying a JDB transaction file. */
    const TXN_MAGIC       = 'JDBT';

    /** @var int Current .txn file format version. */
    const TXN_VERSION     = 1;

    /** @var int Maximum number of characters allowed in a table name stored in the .txn file. */
    const TXN_MAX_NAME    = 63;

    // ─────────────────────────────────────────────────────────────────────────
    // Internal state
    // ─────────────────────────────────────────────────────────────────────────

    /** @var string Current lifecycle state (one of the STATE_* constants). */
    private $state = self::STATE_IDLE;

    /** @var JdbAggLock|null Aggregate lock held for the duration of the transaction. */
    private $lock = null;

    /** @var string|null Absolute path to the current .txn durability file, or null. */
    private $txnFile = null;

    /**
     * @var array File offsets sampled at begin(), indexed by table name.
     *            Structure: array( 'tableName' => array('data' => int, 'index' => int) )
     */
    private $tableOffsets = array();

    /** @var string Absolute path to the data directory (same as JdbManager/JdbAggregate). */
    private $dataDir;

    /** @var string Lock backend identifier: 'flock' or 'mkdir'. */
    private $lockBackend;

    /** @var int Timeout in milliseconds to wait for each mutex acquisition. */
    private $lockTimeoutMs;

    // ─────────────────────────────────────────────────────────────────────────
    // Constructor
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @brief Creates a new JdbTransaction instance.
     *
     * @param string $dataDir       Same directory used by JdbManager/JdbAggregate.
     * @param string $lockBackend   Lock implementation: 'flock' (default) or 'mkdir'.
     * @param int    $lockTimeoutMs Timeout in milliseconds to wait for each mutex (default 1500).
     */
    public function __construct($dataDir, $lockBackend = 'flock', $lockTimeoutMs = 1500)
    {
        $this->dataDir       = rtrim((string)$dataDir, '/\\');
        $this->lockBackend   = ($lockBackend === 'mkdir') ? 'mkdir' : 'flock';
        $this->lockTimeoutMs = max(1, (int)$lockTimeoutMs);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Lifecycle
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @brief Starts the transaction.
     *
     * Sequence:
     *   1. Acquires exclusive mutexes on all requested tables (alphabetical order,
     *      deadlock prevention guaranteed by JdbAggLock).
     *   2. Samples the current byte offsets of the data and index files; no
     *      concurrent write is possible while the locks are held.
     *   3. Writes the .txn file atomically (temp + rename) so that a crash
     *      after this point is detectable and recoverable.
     *
     * If any phase fails the locks are released, the state stays IDLE, and the
     * caller can invoke reset() + begin() to retry.
     *
     * @param  array $tables Names of all tables that will be written during this transaction.
     * @return bool True on success, false on failure.
     */
    public function begin(array $tables)
    {
        if ($this->state !== self::STATE_IDLE) {
            return $this->fail('begin', "Transaction already started (state={$this->state})");
        }
        if (empty($tables)) {
            return $this->fail('begin', 'Empty table list');
        }

        // 1. Acquire exclusive locks (alphabetical order, ensured by JdbAggLock)
        $this->lock = new JdbAggLock($this->dataDir, $this->lockBackend, $this->lockTimeoutMs);
        if (!$this->lock->acquireExclusive($tables)) {
            $this->lock = null;
            return $this->fail('begin', 'Failed to acquire lock on: ' . implode(', ', $tables));
        }

        // 2. Sample file offsets (no concurrent write possible from this point)
        $this->tableOffsets = array();
        foreach ($tables as $table) {
            $dataFile  = JdbManager::getDataPath($table);
            $indexFile = JdbManager::getIndexPath($table);
            clearstatcache(true, $dataFile);
            clearstatcache(true, $indexFile);
            $this->tableOffsets[$table] = array(
                'data'  => file_exists($dataFile)  ? (int)filesize($dataFile)  : 0,
                'index' => file_exists($indexFile) ? (int)filesize($indexFile) : 0,
            );
        }

        // 3. Write the .txn durability file before any data modification
        if (!$this->writeTxnFile()) {
            $this->lock->releaseAll();
            $this->lock         = null;
            $this->tableOffsets = array();
            return false;
        }

        $this->state = self::STATE_ACTIVE;
        return true;
    }

    /**
     * @brief Commits the transaction.
     *
     * The commit signal is the deletion of the .txn file: on POSIX filesystems
     * unlink() is atomic. After this point the transaction is durable and crash
     * recovery will not touch it.
     *
     * @return bool True on success, false if the transaction is not active.
     */
    public function commit()
    {
        if (!$this->assertActive('commit')) {
            return false;
        }

        if ($this->txnFile !== null) {
            @unlink($this->txnFile);
            $this->txnFile = null;
        }

        $this->releaseLock();
        $this->state = self::STATE_COMMITTED;
        return true;
    }

    /**
     * @brief Rolls back the transaction by truncating data files to their pre-begin() offsets.
     *
     * For every table involved:
     *   - truncates the data file to the offset sampled at begin()
     *   - deletes all index files (primary and secondary); they will be rebuilt
     *     lazily on the next access
     *   - invalidates the JdbManager instance cache for that table
     *
     * Then deletes the .txn file and releases all locks.
     *
     * @return bool True if all truncations succeeded, false if one or more failed.
     */
    public function rollback()
    {
        if ($this->state !== self::STATE_ACTIVE) {
            return $this->fail('rollback', "Cannot rollback in state={$this->state}");
        }

        $errors = 0;
        foreach ($this->tableOffsets as $table => $offsets) {
            if (!JdbManager::truncateTable($table, $offsets['data'])) {
                $errors++;
            }
        }

        if ($this->txnFile !== null) {
            @unlink($this->txnFile);
            $this->txnFile = null;
        }

        $this->releaseLock();
        $this->state = self::STATE_ROLLED_BACK;

        if ($errors > 0) {
            return $this->fail('rollback', $errors . ' table not truncated - verify filesystem permissions');
        }
        return true;
    }

    /**
     * @brief Resets the transaction to STATE_IDLE so the instance can be reused.
     *
     * Must only be called after commit() or rollback() (or on a transaction that
     * was never started). If the state is ACTIVE a warning is recorded and the
     * method returns without doing anything: rollback() must be called first.
     *
     * @return void
     */
    public function reset()
    {
        if ($this->state === self::STATE_ACTIVE) {
            JdbErrorHandler::push('JdbTransaction', 'reset',
                'reset() called on an active transaction — call rollback() first');
            return;
        }
        $this->state        = self::STATE_IDLE;
        $this->txnFile      = null;
        $this->tableOffsets = array();
        JdbErrorHandler::clear('JdbTransaction');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Write operations
    // ─────────────────────────────────────────────────────────────────────────
    // Operations are executed directly via JdbManager.
    // Rollback relies on physical truncation of the data file to the offset
    // sampled at begin(), not on an in-memory journal.

    /**
     * @brief Inserts a single record within the active transaction.
     *
     * @param  string     $table    Target table name.
     * @param  array      $data     Record fields to insert.
     * @param  mixed|null $customId Optional explicit record ID; auto-generated if null.
     * @return mixed Inserted record ID, or false on failure.
     */
    public function insert($table, array $data, $customId = null)
    {
        if (!$this->assertActive('insert')) {
            return false;
        }

        $id = JdbManager::insert($table, $data, $customId);
        if ($id === false) return $this->forwardError('insert', $table);
        return $id;
    }

    /**
     * @brief Inserts multiple records in a single batch operation.
     *
     * @param  string $table Target table name.
     * @param  array  $rows  Array of record arrays to insert.
     * @return array|false Associative array ['inserted' => N, 'ids' => [...]], or false on failure.
     */
    public function insertBatch($table, array $rows)
    {
        if (!$this->assertActive('insertBatch')) {
            return false;
        }

        $result = JdbManager::insertBatch($table, $rows);
        if ($result === false) return $this->forwardError('insertBatch', $table);
        return $result;
    }

    /**
     * @brief Updates an existing record within the active transaction.
     *
     * @param  string   $table           Target table name.
     * @param  mixed    $id              ID of the record to update.
     * @param  array    $data            New field values.
     * @param  int|null $expectedVersion Optimistic concurrency check; null disables it.
     * @return bool True on success, false on failure.
     */
    public function update($table, $id, array $data, $expectedVersion = null)
    {
        if (!$this->assertActive('update')) {
            return false;
        }
        $ok = JdbManager::update($table, $id, $data, $expectedVersion);
        if (!$ok) return $this->forwardError('update', $table . '#' . $id);
        return true;
    }

    /**
     * @brief Deletes a record within the active transaction.
     *
     * @param  string   $table           Target table name.
     * @param  mixed    $id              ID of the record to delete.
     * @param  int|null $expectedVersion Optimistic concurrency check; null disables it.
     * @return bool True on success, false on failure.
     */
    public function delete($table, $id, $expectedVersion = null)
    {
        if (!$this->assertActive('delete')) {
            return false;
        }
        $ok = JdbManager::delete($table, $id, $expectedVersion);
        if (!$ok) return $this->forwardError('delete', $table . '#' . $id);
        return true;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // State and error inspection
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @brief Returns the current transaction lifecycle state.
     *
     * Possible values: STATE_IDLE, STATE_ACTIVE, STATE_COMMITTED, STATE_ROLLED_BACK.
     *
     * @return string
     */
    public function getState() { return $this->state; }

    /**
     * @brief Returns the last error recorded for this transaction, or null if none.
     *
     * @return array|null Associative array with keys 'method', 'message', 'time', or null.
     */
    public function getLastError() {
        return JdbErrorHandler::getLast('JdbTransaction');
    }

    /**
     * @brief Returns true if begin() has been called and the transaction is still open.
     *
     * @return bool
     */
    public function isActive() { return $this->state === self::STATE_ACTIVE; }

    // ─────────────────────────────────────────────────────────────────────────
    // Crash recovery (static method)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @brief Scans $dataDir for orphan .txn_*.php files (left by crashed processes)
     *        and applies a truncation-based rollback to each one.
     *
     * Decision logic for each file found:
     *   1. Parse + CRC check   → corrupted file? delete and continue.
     *   2. Is the PID alive?   → transaction still active, skip.
     *   3. Dead process        → truncate tables, delete index files, remove .txn.
     *
     * Safe to call at application startup (idempotent).
     * A recover.lock mutex ensures only one recover() runs at a time.
     *
     * @param  string $dataDir Same directory used by JdbManager/JdbAggregate.
     * @return int Number of transactions successfully recovered.
     */
    public static function recover($dataDir)
    {
        $dataDir = rtrim((string)$dataDir, '/\\');
        $lock = new JdbLock('flock', 5000);
        $mutex = $lock->acquireMutex($dataDir . '/.recover.lock');
        if (!$mutex) {
            return 0;
        }
        try {
            $files   = glob($dataDir . '/.txn_*.php');
            if (empty($files)) return 0;

            $recovered = 0;
            foreach ($files as $txnFile) {
                $parsed = self::parseTxnFile($txnFile);
                if ($parsed === null) {
                    // Corrupted or truncated file: remove and move on
                    @unlink($txnFile);
                    JdbErrorHandler::push('JdbTransaction', 'recover',
                        'Deleted corrupted file .txn: ' . basename($txnFile));
                    continue;
                }

                if (self::isProcessAlive($parsed['pid'])) {
                    // Transaction belongs to a live process: do not touch
                    continue;
                }

                // Dead process → rollback by truncation
                JdbErrorHandler::push('JdbTransaction', 'recover',
                    'Orphan transaction recovery: pid=' . $parsed['pid']
                    . ', started=' . date('Y-m-d H:i:s', $parsed['timestamp'])
                    . ', file='    . basename($txnFile));

                foreach ($parsed['tables'] as $table => $offsets) {
                    JdbManager::truncateTable($table, $offsets['data']);
                }

                @unlink($txnFile);
                $recovered++;
            }

            return $recovered;
        } finally {
            $lock->releaseMutex($mutex);
        }
    }

    /**
     * @brief Returns the contents of a .txn file as a human-readable string.
     *
     * Useful for debugging and diagnostics. Decodes all header fields and
     * reports per-table offsets. Shows whether the owning process is alive.
     *
     * @param  string $path Path to the .txn file to inspect.
     * @return string Formatted multi-line string, or an error message if the file is invalid.
     */
    public static function dumpTxnFile($path)
    {
        $parsed = self::parseTxnFile($path);
        if ($parsed === null) {
            return 'Invalid or corrupted file: ' . $path . "\n";
        }

        $alive = self::isProcessAlive($parsed['pid']) ? ' (alive)' : ' (dead)';
        $lines = array(
            'File:      ' . basename($path),
            'Version:   ' . $parsed['version'],
            'TXN ID:    ' . $parsed['txnid'],
            'PID:       ' . $parsed['pid'] . $alive,
            'Started:   ' . date('Y-m-d H:i:s', $parsed['timestamp']),
            'Tables:',
        );
        foreach ($parsed['tables'] as $table => $offsets) {
            $lines[] = sprintf('  %-30s data=%d  index=%d',
                $table, $offsets['data'], $offsets['index']);
        }
        return implode("\n", $lines) . "\n";
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private: uint64 serialisation
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @brief Serialises an integer as a uint64 little-endian value (two consecutive uint32 LE).
     *
     * Compatible with PHP 5.5: does not use the 'P' pack() format which requires
     * PHP >= 5.6.3. Requires a 64-bit architecture (PHP integer = 64-bit signed).
     *
     * @param  int $value Integer value to encode.
     * @return string 8 raw bytes.
     */
    private static function packU64($value)
    {
        $value = (int)$value;
        $lo    = $value & 0xFFFFFFFF;
        $hi    = ($value >> 32) & 0xFFFFFFFF;
        return pack('VV', $lo, $hi);
    }

    /**
     * @brief Deserialises 8 bytes starting at $pos as a uint64 little-endian value.
     *
     * Uses multiplication instead of bit-shifting to avoid incorrect results on
     * 32-bit PHP builds.
     *
     * @param  string $bin Binary string containing the encoded value.
     * @param  int    $pos Byte offset within $bin at which the 8-byte value starts.
     * @return int Decoded integer value.
     */
    private static function unpackU64($bin, $pos)
    {
        $lo = unpack('V', substr($bin, $pos,     4));
        $hi = unpack('V', substr($bin, $pos + 4, 4));
        // Multiply instead of shift to avoid issues on 32-bit PHP builds
        return (int)$lo[1] + (int)$hi[1] * JdbUtil::UINT32_OVERFLOW;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private: .txn file
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @brief Builds and atomically writes the .txn durability file (temp + rename).
     *
     * The file name embeds the PID and a random ID to guarantee global uniqueness:
     *   .txn_{pid}_{hex(random_8_bytes)}.php
     *
     * Writing to a temporary file first ensures that the final file either exists
     * with complete, intact content or does not exist at all — no partial writes.
     *
     * File layout written:
     *   - PHP die header (JdbUtil::PHP_DIE_HEADER, 16 bytes)   blocks HTTP access
     *   - Fixed binary header (27 bytes payload + 4 bytes CRC32)
     *   - One 80-byte per-table record for each entry in $tableOffsets
     *
     * @return bool True on success, false on failure.
     */
    private function writeTxnFile()
    {
        $randomHex = JdbUtil::randomSuffix(8);
        $randomBytes = hex2bin($randomHex);
        $pid    = getmypid();
        $count  = count($this->tableOffsets);

        // Fixed header: 27 bytes of payload + 4 bytes CRC = 31 bytes total
        //   a4  magic
        //   v   version  (uint16 LE)
        //   C   table count (uint8)
        //   V   pid (uint32 LE)
        //   VV  timestamp (uint64 LE via packU64)
        //   a8  random id
        $payload  = pack('a4vCV', self::TXN_MAGIC, self::TXN_VERSION, $count, $pid);
        $payload .= self::packU64(time());
        $payload .= pack('a8', $randomBytes);
        // CRC covers the 27 payload bytes; masked to 32 bits for consistency on
        // 64-bit platforms where crc32() may return negative signed integers
        $payload .= pack('V', JdbUtil::crc32u($payload));

        // Per-table records: 80 bytes each
        //   C   name length (uint8)
        //   a63 null-padded table name
        //   VV  data offset  (uint64 LE via packU64)
        //   VV  index offset (uint64 LE via packU64)
        $tableData = '';
        foreach ($this->tableOffsets as $table => $offsets) {
            $nameLen = strlen($table);
            if ($nameLen > self::TXN_MAX_NAME) {
                return $this->fail('writeTxnFile', 'Table name too long (' . $nameLen . ' > ' . self::TXN_MAX_NAME . '): ' . $table);
            }
            $tableData .= pack('Ca63', $nameLen, $table);
            $tableData .= self::packU64($offsets['data']);
            $tableData .= self::packU64($offsets['index']);
        }

        $contents = JdbUtil::PHP_DIE_HEADER . $payload . $tableData;

        $txnName = sprintf('.txn_%d_%s.php', $pid, $randomHex);
        $final   = $this->dataDir . '/' . $txnName;
        $tmp     = $this->dataDir . '/.txn_tmp_' . $pid . '.php';

        if (file_put_contents($tmp, $contents, LOCK_EX) === false) {
            return $this->fail('writeTxnFile', 'Failed to write .txn temporary file: ' . $tmp);
        }

        if (!rename($tmp, $final)) {
            unlink($tmp);
            return $this->fail('writeTxnFile', 'Failed to rename .txn file in: ' . $final);
        }

        $this->txnFile = $final;
        return true;
    }

    /**
     * @brief Reads and validates a .txn file, returning its decoded content.
     *
     * Returns null if the file is absent, too short, has a wrong magic word,
     * fails the CRC check, or has a table count inconsistent with the file size.
     *
     * @param  string $path Absolute path to the .txn file.
     * @return array|null Associative array with keys 'version', 'pid', 'timestamp',
     *                    'txnid', 'tables' on success; null on any validation failure.
     */
    private static function parseTxnFile($path)
    {
        if (!file_exists($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        // Minimum size: PHP header + fixed binary header (no table records yet)
        $minSize = JdbUtil::DATA_HEADER_SIZE + self::TXN_FIXED_SIZE;
        if (strlen($raw) < $minSize) {
            return null;
        }

        // Skip the PHP die header; read the 31-byte fixed binary header
        $fixedRaw = substr($raw, JdbUtil::DATA_HEADER_SIZE, self::TXN_FIXED_SIZE);

        // CRC check covers the first 27 bytes of the fixed header
        $crcPayload = substr($fixedRaw, 0, 27);
        $storedCrc  = unpack('V', substr($fixedRaw, 27, 4));
        $storedCrc  = (int)$storedCrc[1];
        $computed   = JdbUtil::crc32u($crcPayload);
        if ($computed !== ($storedCrc & 0xFFFFFFFF)) {
            return null;
        }

        // Extract fixed-header fields
        //   a4  magic
        //   v   version (uint16 LE)
        //   C   table count (uint8)
        //   V   pid (uint32 LE)
        // Timestamp and txnid are decoded separately via unpackU64
        $hdr = unpack('a4magic/vversion/Ccount/Vpid', substr($crcPayload, 0, 11));
        if ($hdr['magic'] !== self::TXN_MAGIC) {
            return null;
        }

        $count     = (int)$hdr['count'];
        $timestamp = self::unpackU64($crcPayload, 11);
        $txnid     = bin2hex(substr($crcPayload, 19, 8));

        // Verify that the file is large enough to hold all declared table records
        $expectedSize = $minSize + $count * self::TXN_TABLE_SIZE;
        if (strlen($raw) < $expectedSize) {
            return null;
        }

        // Parse per-table records
        $tables = array();
        $pos    = $minSize;
        for ($i = 0; $i < $count; $i++) {
            $rec    = substr($raw, $pos, self::TXN_TABLE_SIZE);
            $header = unpack('Cname_len/a63name', substr($rec, 0, 64));
            // Use name_len to strip the null-padding from the stored name
            $table  = substr($header['name'], 0, (int)$header['name_len']);
            $tables[$table] = array(
                'data'  => self::unpackU64($rec, 64),
                'index' => self::unpackU64($rec, 72),
            );
            $pos += self::TXN_TABLE_SIZE;
        }

        return array(
            'version'   => (int)$hdr['version'],
            'pid'       => (int)$hdr['pid'],
            'timestamp' => $timestamp,
            'txnid'     => $txnid,
            'tables'    => $tables,
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private: process liveness check
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @brief Returns true if the process with the given PID is still running.
     *
     * Uses posix_kill($pid, 0) when the POSIX extension is available (Linux,
     * macOS, BSD). Falls back to checking /proc/$pid on Linux environments
     * where the POSIX extension is not loaded.
     *
     * @param  int  $pid Process ID to check.
     * @return bool True if the process is alive, false otherwise.
     */
    private static function isProcessAlive($pid)
    {
        if ($pid <= 0) return false;
        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0);
        }
        return is_dir('/proc/' . $pid);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private: lock management
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @brief Releases all locks held by the current transaction and clears the lock reference.
     *
     * Safe to call even when no lock is held (null-check guards the release call).
     *
     * @return void
     */
    private function releaseLock()
    {
        if ($this->lock !== null) {
            $this->lock->releaseAll();
            $this->lock = null;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private: error helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @brief Asserts that the transaction is in STATE_ACTIVE; records an error if not.
     *
     * @param  string $method Name of the calling method, used in the error record.
     * @return bool True if the state is STATE_ACTIVE, false otherwise.
     */
    private function assertActive($method)
    {
        if ($this->state !== self::STATE_ACTIVE) {
            return $this->fail($method, 'No active transaction (state=' . $this->state . ')');
        }
        return true;
    }

    /**
     * @brief Records a fatal error via JdbErrorHandler and returns false.
     *
     * Centralises error reporting so all failure paths return a single call.
     *
     * @param  string $method  Name of the method where the error occurred.
     * @param  string $message Human-readable error description.
     * @return false Always returns false.
     */
    private function fail($method, $message)
    {
        JdbErrorHandler::set('JdbTransaction', $method, $message, true);
        return false;
    }

    /**
     * @brief Forwards the last JdbManager error as a JdbTransaction error and returns false.
     *
     * Used by write-operation wrappers (insert, update, delete, …) to propagate
     * the underlying JdbManager failure message into the transaction's error channel.
     *
     * @param  string $method  Name of the transaction method forwarding the error.
     * @param  string $context Descriptive context string (e.g. table name or "table#id").
     * @return false Always returns false.
     */
    private function forwardError($method, $context)
    {
        $err = JdbManager::getLastError();
        $msg = ($err !== null && isset($err['message'])) ? $err['message'] : 'Operation failed';
        return $this->fail($method, "{$msg} [{$context}]");
    }
}
