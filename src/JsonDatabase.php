<?php
require_once __DIR__ . "/JdbErrorHandler.php";
require_once __DIR__ . "/JdbUtil.php";
require_once __DIR__ . "/JdbIndexHeader.php";
require_once __DIR__ . "/JdbLock.php";
require_once __DIR__ . "/JdbBinaryIndex.php";
require_once __DIR__ . "/JdbSecondaryIndex.php";

/**
 * JsonDatabase – Append-only JSONL database with an on-disk binary index.
 *
 * Each table consists of at least two files:
 *   - tableName.jsonl.php         JSONL data file (append-only, protected by <?php die(); ?>)
 *   - tableName.index.php         binary hash-table index (managed by JdbBinaryIndex)
 *   - tableName.idx_FIELD.php     one per field with a secondary index (optional)
 *
 * Every write operation follows the protocol:
 *   lock(data) → idx.open(write) → idx.beginWrite() →
 *   fwrite(data) → idx.put/remove() → idx.bumpNextId() [insert only] →
 *   idx.commitWrite() → markSecondaryDirty() → idx.close() → unlock(data)
 *
 * Secondary indexes:
 *   - Declared at construction via $secondaryFields
 *   - Marked dirty after every successful write
 *   - Rebuilt on-demand on the first selectRange() after writes
 *   - Explicitly rebuildable via rebuildSecondaryIndexes()
 *
 * Consistency guaranteed by dirty bit (primary) and on-demand rebuild (secondary).
 *
 * Compatibility: PHP 5.5+
 */
class JsonDatabase
{
    /** @var string Path to the data file (.jsonl.php) */
    private $dataFile;

    /** @var string Path to the binary index file (.index.php) */
    private $indexFile;

    /** @var string Data directory */
    private $dataDir;

    /** @var string Table name */
    private $tableName;

    /** @var JdbBinaryIndex Primary index manager */
    private $idx;

    /** @var JdbSecondaryIndex[] Secondary indexes: field => JdbSecondaryIndex */
    private $secondaryIndexes;

    /** @var bool Whether to auto-compact after writes when fragmentation is high */
    private $autoCompact = false;

    /** @var float Fragmentation fraction that triggers auto-compact (0.0–1.0) */
    private $autoCompactThreshold = 0.30;

    /**
     * Unified lock manager for both 'flock' and 'mkdir' backends.
     * Created in the constructor; all locking operations go through this instance.
     * @var JdbLock
     */
    private $lck;

    /**
     * Timeout in milliseconds to acquire the lock on the data file.
     * After this time openAndLock/openForRead fail with a stack frame
     * instead of blocking indefinitely.
     */
    const LOCK_TIMEOUT_MS = 500;

    // =========================================================================
    // Constructor and init
    // =========================================================================

    /**
     * @param string   $tableName       Table name (e.g. 'users')
     * @param string   $dataDir         Directory where files are stored (default: './data')
     * @param string[] $secondaryFields Fields to index for range queries (default: [])
     * @param int|null $lockTimeoutMs   Lock acquisition timeout in ms (null = LOCK_TIMEOUT_MS)
     * @param string   $lockBackend     'flock' (default) | 'mkdir' (NFS-safe)
     */
    public function __construct($tableName, $dataDir = './data',
                                $secondaryFields = array(), $lockTimeoutMs = null,
                                $lockBackend = 'flock')
    {
        if (!JdbUtil::isValidTableName($tableName)) {
            throw new InvalidArgumentException("Invalid table name: {$tableName}");
        }
        if (!JdbUtil::ensureDirectory($dataDir)) {
            throw new InvalidArgumentException("Unable to create {$dataDir}");
        }
        $this->tableName        = $tableName;
        $this->dataDir          = $dataDir;
        $this->secondaryIndexes = array();

        $resolvedTimeoutMs   = ($lockTimeoutMs !== null) ? (int)$lockTimeoutMs : self::LOCK_TIMEOUT_MS;
        $this->lck          = new JdbLock($lockBackend, $resolvedTimeoutMs);


        $this->dataFile  = "{$dataDir}/{$tableName}.jsonl.php";
        $this->indexFile = "{$dataDir}/{$tableName}.index.php";
        $this->idx       = new JdbBinaryIndex($this->indexFile);

        // Initialise the declared secondary indexes
        foreach ($secondaryFields as $field) {
            $field = (string)$field;
            if ($field === '' || strlen($field) > 64 || strpos($field, '..') !== false) {
                JdbErrorHandler::push('JsonDatabase', 'construct', "Skipping invalid secondary field: {$field}");
                continue;
            }
            // Normalise the field name for the filesystem
            $safeName = preg_replace('/[^a-zA-Z0-9_]/', '_', (string)$field);
            $idxFile  = "{$dataDir}/{$tableName}.idx_{$safeName}.php";
            $sidx     = new JdbSecondaryIndex($idxFile, $field);
            $this->secondaryIndexes[$field] = $sidx;
            $sidx->createIfMissing();
            $sidx->setLock($this->lck);
        }

        $this->initializeFiles();
    }

    /**
     * Enables or disables automatic compaction after writes.
     *
     * When enabled, checkAutoCompact() is called after every insert/update/delete.
     * It estimates fragmentation from the index header (O(1)) and triggers a
     * compact() when the fraction of estimated deleted records exceeds $threshold.
     *
     * @param bool  $enabled    true to enable auto-compact
     * @param float $threshold  Fragmentation fraction that triggers compaction (default 0.30)
     */
    public function setAutoCompact($enabled, $threshold = 0.30)
    {
        $this->autoCompact          = (bool)$enabled;
        $this->autoCompactThreshold = max(0.01, min(1.0, (float)$threshold));
    }

    /**
     * Changes the lock acquisition timeout for this instance.
     * Takes effect on the next locking call.
     *
     * @param int $ms Timeout in milliseconds (must be > 0)
     */
    public function setLockTimeoutMs($ms)
    {
        $this->lck->setTimeoutMs($ms);
    }

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Rebuilds the primary index by scanning the data file line by line.
     * Also marks secondary indexes dirty (they may have stale offsets).
     *
     * The data file is always the source of truth: rebuild() is always safe.
     *
     * @return bool
     */
    public function rebuildIndex()
    {
        JdbErrorHandler::resetStack();
        $lock = $this->acquireIndexRebuildLock();
        if ($lock === false) {
            JdbErrorHandler::push('JsonDatabase', 'rebuildIndex', 'Failed to acquire rebuild lock');
            return false;
        }
        try {
            return $this->doRebuildIndex();
        } finally {
            $this->releaseIndexRebuildLock($lock);
        }
    }

    /**
     * Returns the last error's stack trace as a formatted string.
     *
     * Each line represents a frame (from the API entry point down to the root
     * cause), identical to a standard PHP stack trace display.
     *
     * Returns an empty string if the last operation succeeded.
     *
     * @return string
     */
    public function getError()
    {
        return JdbErrorHandler::formatStack();
    }

    /**
     * Returns the raw array of frames from the last error.
     * Each element: ['class' => string, 'method' => string, 'msg' => string]
     * Index 0 is the root cause (deepest frame).
     *
     * @return array[]
     */
    public function getErrorStack()
    {
        return JdbErrorHandler::getStack();
    }

    /**
     * Clears the error stack.
     * Public operations (insert, update, delete, ...) clear it automatically
     * at the start: use this method to clear it manually after handling
     * a non-fatal error.
     */
    public function resetError()
    {
        JdbErrorHandler::resetStack();
    }

    /**
     * INSERT – Inserts a new record with an auto-increment ID.
     *
     * Delegates the encode→write→index cycle to writeSingleRecord(), which is
     * the single point of enforcement for all write invariants (duplicate guard,
     * version tracking, CRC, idx->put call site).
     *
     * @param  array     $data
     * @return int|false Inserted ID, false on error
     */
    public function insert($data)
    {
        JdbErrorHandler::resetStack();

        $fp = $this->openAndLock($this->dataFile);
        if (!$fp) {
            JdbErrorHandler::push('JsonDatabase', 'insert', 'openAndLock failed');
            return false;
        }

        try {
            $rollbackOffset = JdbUtil::getFileSize($fp);
            if ($rollbackOffset === false) {
                JdbErrorHandler::push('JsonDatabase', 'insert', 'fstat on data file failed');
                return false;
            }

            if ($this->ensureIndex(true) !== JdbBinaryIndex::OK) {
                JdbErrorHandler::push('JsonDatabase', 'insert', 'ensureIndex(write) failed');
                return false;
            }

            $idInt = $this->idx->nextId();
            $id = (string)$idInt;
            $data['id'] = $idInt;

            if ($this->idx->beginWrite() !== JdbBinaryIndex::OK) {
                JdbErrorHandler::push('JsonDatabase', 'insert', 'beginWrite failed');
                return false;
            }

            $currentOffset = $rollbackOffset;
            // allowDuplicate=false: auto-increment IDs are always new, but guard
            // defends against pathological index state (e.g. corrupted nextId).
            $result = $this->writeSingleRecord($fp, $id, $data, $currentOffset, false);
            if ($result === false) {
                JdbErrorHandler::push('JsonDatabase', 'insert',
                    "writeSingleRecord failed [id={$id}]");
                ftruncate($fp, $rollbackOffset);
                return false;
            }

            if (!fflush($fp)) {
                JdbErrorHandler::push('JsonDatabase', 'insert',
                    "fflush data file failed [id={$id}]");
                ftruncate($fp, $rollbackOffset);
                return false;
            }

            $this->idx->bumpNextId();

            if ($this->idx->commitWrite() !== JdbBinaryIndex::OK) {
                JdbErrorHandler::push('JsonDatabase', 'insert', 'commitWrite failed');
                ftruncate($fp, $rollbackOffset);
                return false;
            }

            $this->markSecondaryDirty();
            $this->checkAutoCompact((int)$this->idx->count(), (int)$this->idx->nextId());
            return $idInt;

        } finally {
            $this->idx->close();
            $this->unlockAndClose($fp);
        }
    }

    /**
     * INSERT WITH CUSTOM ID – Inserts with a custom ID.
     * Allows re-insertion of previously deleted IDs.
     *
     * Delegates encode→write→index to writeSingleRecord() with
     * allowDuplicate=false, which enforces the duplicate guard and manages
     * versioning in the single canonical place.
     *
     * @param  mixed     $customId
     * @param  array     $data
     * @return mixed|false Inserted ID, false on error
     */
    public function insertWithId($customId, $data)
    {
        JdbErrorHandler::resetStack();
        $customId = (string)$customId;

        $fp = $this->openAndLock($this->dataFile);
        if (!$fp) return false;

        try {
            if ($this->ensureIndex(true) !== JdbBinaryIndex::OK) {
                return false;
            }
            $rollbackOffset = JdbUtil::getFileSize($fp);
            if ($rollbackOffset === false) {
                return false;
            }
            $data['id']     = $customId;

            if ($this->idx->beginWrite() !== JdbBinaryIndex::OK) {
                return false;
            }

            $currentOffset = $rollbackOffset;
            $result = $this->writeSingleRecord($fp, $customId, $data, $currentOffset, false);
            if ($result === false) {
                ftruncate($fp, $rollbackOffset);
                return false;
            }

            if (!fflush($fp)) {
                ftruncate($fp, $rollbackOffset);
                return false;
            }

            if ($this->idx->commitWrite() !== JdbBinaryIndex::OK) {
                ftruncate($fp, $rollbackOffset);
                return false;
            }

            $this->markSecondaryDirty();
            return $customId;

        } finally {
            $this->idx->close();
            $this->unlockAndClose($fp);
        }
    }

    /**
     * INSERT BATCH – Inserts multiple records under a single lock acquisition.
     *
     * All records are written to the data file and indexed in a single
     * lock/unlock cycle, making bulk imports orders of magnitude faster than
     * calling insert() in a loop (which re-acquires the lock for every record).
     *
     * Each element of $rows is an associative array of field => value pairs.
     * Auto-increment IDs are assigned sequentially starting from the current
     * nextId.  To use a custom ID for a specific row, include 'id' in that
     * row's array.
     *
     * Partial success: if writing fails mid-batch the data file is truncated
     * back to its size before the batch started, so either all or none of the
     * records are committed (atomic at the file level).
     *
     * Duplicate-ID rows: rows that supply an explicit 'id' already present in
     * the index are rejected (the entire batch is rolled back). This matches
     * the behaviour of insert() and insertWithId() and is enforced by the
     * shared writeSingleRecord() helper — it cannot be accidentally omitted.
     *
     * @param  array   $rows    Array of record arrays to insert
     * @return array|false  Associative array ['inserted' => int, 'ids' => array]
     *                      or false on error
     */
    public function insertBatch(array $rows)
    {
        JdbErrorHandler::resetStack();

        if (empty($rows)) {
            return array('inserted' => 0, 'ids' => array());
        }

        $fp = $this->openAndLock($this->dataFile);
        if (!$fp) {
            JdbErrorHandler::push('JsonDatabase', 'insertBatch', 'openAndLock failed');
            return false;
        }

        try {
            $batchStartOffset = JdbUtil::getFileSize($fp);
            if ($batchStartOffset === false) {
                JdbErrorHandler::push('JsonDatabase', 'insertBatch', 'fstat on data file failed');
                return false;
            }

            if ($this->ensureIndex(true) !== JdbBinaryIndex::OK) {
                JdbErrorHandler::push('JsonDatabase', 'insertBatch', 'ensureIndex(write) failed');
                return false;
            }

            if ($this->idx->beginWrite() !== JdbBinaryIndex::OK) {
                JdbErrorHandler::push('JsonDatabase', 'insertBatch', 'beginWrite failed');
                return false;
            }

            $insertedIds   = array();
            $inserted      = 0;
            // Track the write offset manually: avoids one fstat() syscall per row.
            // On an 'ab' handle all writes are appended, so $currentOffset stays in
            // sync with the actual file end after every fwrite() in writeSingleRecord.
            $currentOffset = $batchStartOffset;
            $batchBaseId = $this->idx->nextId();
            $batchAutoCount = 0;

            foreach ($rows as $rowIndex => $data) {
                if (!is_array($data)) {
                    JdbErrorHandler::push('JsonDatabase', 'insertBatch',
                        "row #{$rowIndex} is not an array");
                    ftruncate($fp, $batchStartOffset);
                    return false;
                }

                // Resolve ID: explicit 'id' in data, or auto-increment
                if (isset($data['id'])) {
                    $idVal = $data['id'];
                    $id     = (string)$idVal;
                } else {
                    $idVal = $batchBaseId + $batchAutoCount;
                    $id     = (string)$idVal;
                    $batchAutoCount++;
                }

                $data['id'] = $idVal;

                // writeSingleRecord enforces duplicate guard, versioning and
                // idx->put in one shared place. allowDuplicate=false: an explicit
                // ID that already exists in the index causes the whole batch to
                // roll back, consistent with insert() and insertWithId().
                $result = $this->writeSingleRecord($fp, $id, $data, $currentOffset, false);
                if ($result === false) {
                    JdbErrorHandler::push('JsonDatabase', 'insertBatch',
                        "writeSingleRecord failed on row #{$rowIndex} [id={$id}]");
                    ftruncate($fp, $batchStartOffset);
                    return false;
                }

                $insertedIds[] = $idVal;
                $inserted++;
            }

            if ($batchAutoCount > 0) {
                $this->idx->advanceNextId($batchAutoCount);
            }

            if (!fflush($fp)) {
                JdbErrorHandler::push('JsonDatabase', 'insertBatch', 'fflush failed');
                ftruncate($fp, $batchStartOffset);
                return false;
            }

            // Atomicity guarantee:
            // On early-return (rollback), commitWrite() is never called, so the
            // dirty bit remains 1. The next open() detects dirty=1 and rebuilds
            // the primary index from the data file — which has already been
            // truncated back to $batchStartOffset. Result: clean state.
            if ($this->idx->commitWrite() !== JdbBinaryIndex::OK) {
                JdbErrorHandler::push('JsonDatabase', 'insertBatch', 'commitWrite failed');
                ftruncate($fp, $batchStartOffset);
                return false;
            }

            $this->markSecondaryDirty();
            $this->checkAutoCompact((int)$this->idx->count(), (int)$this->idx->nextId());
            return array('inserted' => $inserted, 'ids' => $insertedIds);

        } finally {
            $this->idx->close();
            $this->unlockAndClose($fp);
        }
    }

    /**
     * SELECT BY ID – Direct access via the primary index.
     *
     * @param  mixed      $id
     * @return array|null
     */
    public function selectById($id)
    {
        JdbErrorHandler::resetStack();
        $id = (string)$id;

        $fp = $this->openForRead($this->dataFile);
        if (!$fp) return null;

        try {
            if ($this->ensureIndex(false) !== JdbBinaryIndex::OK) return null;
            $entry = $this->idx->lookup($id);
            if ($entry === null) return null;
            if (fseek($fp, $entry['offset']) !== 0) {
                return null;
            }
            $fsize = JdbUtil::getFileSize($fp);
            if ($fsize === false || !JdbUtil::isValidRecordBounds($entry['offset'], $entry['length'], $fsize)) {
                JdbErrorHandler::push('JsonDatabase', 'selectById', 'Invalid or out-of-bounds record entry');
                return null;
            }
            $line   = fread($fp, $entry['length']);
            return JdbUtil::jsonDecodeRecord($line);
        } finally {
            $this->idx->close();
            $this->unlockAndClose($fp);
        }
    }

    /**
     * SELECT EACH – Streaming iteration over all active records.
     *
     * $fn receives each record as a PHP array.
     * If $fn returns false: early exit.
     *
     * @param  callable $fn
     * @param  array    $conditions  Field=>value filters (AND)
     * @param  int      $limit       0 = no limit
     * @param  int      $chunkSlots
     * @return int Number of records passed to $fn
     */
    public function selectEach(
        callable $fn,
        array $conditions = array(),
        $limit = 0,
        $chunkSlots = 512
    ) {
        JdbErrorHandler::resetStack();
        $fp = $this->openForRead($this->dataFile);
        if (!$fp) return 0;

        try {
            if ($this->ensureIndex(false) !== JdbBinaryIndex::OK) return 0;

            $matched    = 0;
            $hasFilters = !empty($conditions);
            $hasLimit   = ($limit > 0);
            $self = $this;

            // hoist fstat: one syscall for the whole scan instead of one per record
            $fileSize = JdbUtil::getFileSize($fp);
            if ($fileSize === false) {
                return 0;
            }

            $self->idx->scanChunked(
                function ($entry) use ($fp, $fn, $conditions, $limit,
                                       $hasFilters, $hasLimit, &$matched, $self, $fileSize) {
                    if (fseek($fp, $entry['offset']) !== 0) return null;
                    if (!JdbUtil::isValidRecordBounds($entry['offset'], $entry['length'], $fileSize)) return null;
                    $line   = fread($fp, $entry['length']);
                    $record = JdbUtil::jsonDecodeRecord($line);
                    if ($record === null) {
                        return null;
                    }
                    if ($hasFilters && !JdbUtil::recordMatchesConditions($record, $conditions)) {
                        return null;
                    }
                    $matched++;
                    $cbResult = $fn($record);
                    if ($cbResult === false || ($hasLimit && $matched >= $limit)) return false;
                    return null;
                },
                $chunkSlots
            );

            return $matched;

        } finally {
            $this->idx->close();
            $this->unlockAndClose($fp);
        }
    }

    /**
     * SELECT ALL – Returns all active records as an array.
     *
     * @return array|false
     */
    public function selectAll()
    {
        JdbErrorHandler::resetStack();
        $results = array();
        $ok = $this->selectEach(function ($record) use (&$results) {
            $results[] = $record;
        });
        return ($ok !== false) ? $results : false;
    }

    /**
     * SELECT WHERE – Filters by exact field => value conditions.
     *
     * @param  array    $conditions
     * @param  int      $limit
     * @return array|null
     */
    public function selectWhere(array $conditions, $limit = 0)
    {
        JdbErrorHandler::resetStack();
        $results = array();
        $this->selectEach(
            function ($record) use (&$results) { $results[] = $record; },
            $conditions,
            $limit
        );
        return count($results) > 0 ? $results : null;
    }

    // =========================================================================
    // Pagination (cursor-based, O(1) RAM)
    // =========================================================================

    /**
     * SELECT PAGE – Cursor-based pagination without loading the whole table.
     *
     * First page: $cursor = null.  Next page: pass 'next_cursor' from the result.
     * End of data: 'next_cursor' === null.
     *
     * @param  int        $pageSize   Records per page (≥ 1)
     * @param  mixed|null $cursor     Cursor from previous page, or null
     * @param  array      $conditions Optional AND field => value filters
     * @return array|false  ['records'=>array[], 'next_cursor'=>mixed|null,
     *                       'has_more'=>bool, 'count'=>int], or false on error
     */
    public function selectPage($pageSize, $cursor = null, array $conditions = array())
    {
        JdbErrorHandler::resetStack();
        $pageSize    = max(1, (int)$pageSize);
        $fp          = $this->openForRead($this->dataFile);
        if (!$fp) return false;

        try {
            if ($this->ensureIndex(false) !== JdbBinaryIndex::OK) return false;

            $records     = array();
            $hasFilters  = !empty($conditions);
            $afterCursor = ($cursor === null);
            $nextCursor  = null;
            $collected   = 0;
            $hasMore     = false;
            $self = $this;

            // hoist fstat: one syscall for the whole scan
            $fileSize = JdbUtil::getFileSize($fp);
            if ($fileSize === false) {
                return false;
            }

            $self->idx->scanChunked(
                function ($entry) use (
                    $fp, $pageSize, $cursor, &$afterCursor, $conditions,
                    $hasFilters, $self, &$records, &$collected, &$nextCursor, &$hasMore, $fileSize
                ) {
                    if (!$afterCursor) {
                        if ((string)$entry['id_hash'] === (string)$cursor) {
                            $afterCursor = true;
                        }
                        return null;
                    }
                    if ($collected >= $pageSize) {
                        $hasMore = true;
                        return false; // early exit — one extra record detected
                    }
                    if (fseek($fp, $entry['offset']) !== 0) return null;
                    if (!JdbUtil::isValidRecordBounds($entry['offset'], $entry['length'], $fileSize)) {
                        return null;
                    }
                    $line   = fread($fp, $entry['length']);
                    $record = JdbUtil::jsonDecodeRecord($line);
                    if ($record === null) {
                        return null;
                    }
                    if ($hasFilters && !JdbUtil::recordMatchesConditions($record, $conditions)) {
                        return null;
                    }
                    $records[]  = $record;
                    $nextCursor = $entry['id_hash'];
                    $collected++;
                    return null;
                }
            );

            return array(
                'records'     => $records,
                'next_cursor' => $hasMore ? $nextCursor : null,
                'has_more'    => $hasMore,
                'count'       => $collected,
            );

        } finally {
            $this->idx->close();
            $this->unlockAndClose($fp);
        }
    }

    // =========================================================================
    // Range query (secondary index)
    // =========================================================================

    /**
     * SELECT RANGE EACH – Streaming iteration over records in a field range.
     *
     * Requires the field to have been declared in $secondaryFields at construction.
     * If the secondary index is dirty (pending updates), it is automatically rebuilt
     * before executing the query. Rebuild happens only once, then all subsequent
     * queries are O(log N + K) until the next write.
     *
     * $fn receives each record as a PHP array.
     * If $fn returns false: early exit.
     *
     * @param  string   $field       Field to range on
     * @param  mixed    $min         Inclusive lower bound (null = none)
     * @param  mixed    $max         Inclusive upper bound (null = none)
     * @param  callable $fn          Callback for each record found
     * @param  array    $conditions  Additional field=>value filters (AND)
     * @param  int      $limit       Max records (0 = no limit)
     * @return int|false Number of records emitted, false if field is not indexed
     */
    public function selectRangeEach($field, $min, $max, callable $fn,
                                     array $conditions = array(), $limit = 0)
    {
        $self = $this;
        JdbErrorHandler::resetStack();
        if (!isset($self->secondaryIndexes[$field])) {
            return false; // field not declared in $secondaryFields
        }

        $sidx = $self->secondaryIndexes[$field];

        // Lazy rebuild: reconstructs the index only on the first range query
        // after one or more writes (once per write batch, not per query)
        if ($sidx->isDirty()) {
            $sidx->rebuild($self->dataFile, JdbUtil::DATA_HEADER_SIZE);
        }

        $fp = $this->openForRead($self->dataFile);
        if (!$fp) {
            return 0;
        }

        try {
            $hasFilters = !empty($conditions);
            $hasLimit   = ($limit > 0);
            $matched    = 0;

            $sidx->rangeQuery(
                $fp, $min, $max,
                function ($record) use ($fn, $conditions, $hasFilters,
                                        $hasLimit, $limit, &$matched, $self) {
                    if ($hasFilters && !JdbUtil::recordMatchesConditions($record, $conditions)) {
                        return null;
                    }
                    $matched++;
                    $r = $fn($record);
                    if ($r === false || ($hasLimit && $matched >= $limit)) return false;
                    return null;
                }
            );

            return $matched;

        } finally {
            $self->unlockAndClose($fp);
        }
    }

    /**
     * SELECT RANGE – Returns an array of records in the specified range.
     *
     * @param  string     $field
     * @param  mixed      $min
     * @param  mixed      $max
     * @param  array      $conditions
     * @param  int        $limit
     * @return array|null|false  Array of records, null if no results,
     *                           false if the field is not indexed
     */
    public function selectRange($field, $min, $max,
                                 array $conditions = array(), $limit = 0)
    {
        JdbErrorHandler::resetStack();
        $results = array();
        $ret = $this->selectRangeEach(
            $field, $min, $max,
            function ($record) use (&$results) { $results[] = $record; },
            $conditions,
            $limit
        );
        if ($ret === false) return false;
        return count($results) > 0 ? $results : null;
    }

    /**
     * Forces a rebuild of all secondary indexes.
     * Useful to call off-peak after write batches, to avoid the rebuild
     * happening on the first range query.
     */
    public function rebuildSecondaryIndexes()
    {
        JdbErrorHandler::resetStack();
        foreach ($this->secondaryIndexes as $sidx) {
            $sidx->rebuild($this->dataFile, JdbUtil::DATA_HEADER_SIZE);
        }
    }

    /**
     * Checks whether a field has a declared secondary index.
     *
     * @param  string $field
     * @return bool
     */
    public function hasSecondaryIndex($field)
    {
        return isset($this->secondaryIndexes[$field]);
    }

    // =========================================================================
    // Write operations
    // =========================================================================

    /**
     * UPDATE – Updates an existing record (append-only).
     *
     * Delegates encode→write→index to writeSingleRecord() with
     * allowDuplicate=true (intentional upsert: the ID must exist).
     * The existence check is done explicitly before calling the helper,
     * so a non-existent ID is still rejected at this level.
     *
     * @param  mixed $id
     * @param  array $newData
     * @param  int $expectedVersion
     * @return bool
     */
    public function update($id, $newData, $expectedVersion = null)
    {
        JdbErrorHandler::resetStack();
        $id = JdbUtil::normalizeId($id);

        $fp = $this->openAndLock($this->dataFile);
        if (!$fp) {
            JdbErrorHandler::push('JsonDatabase', 'update', 'openAndLock failed');
            return false;
        }

        try {
            if ($this->ensureIndex(true) !== JdbBinaryIndex::OK) {
                JdbErrorHandler::push('JsonDatabase', 'update', 'ensureIndex(write) failed');
                return false;
            }
            $entry = $this->idx->lookup($id);
            if ($entry === null) {
                JdbErrorHandler::push('JsonDatabase', 'update',
                    "lookup(id={$id}) not found");
                return false;
            }
            $entryVersion = (int)$entry['version'];
            if ($expectedVersion !== null && $entryVersion !== (int)$expectedVersion) {
                JdbErrorHandler::push('JsonDatabase', 'update',
                    "version conflict (expected={(int)$expectedVersion}, current={$entryVersion})");
                return false;
            }
            $rollbackOffset = JdbUtil::getFileSize($fp);
            if ($rollbackOffset === false) {
                JdbErrorHandler::push('JsonDatabase', 'update', 'fstat on data file failed');
                return false;
            }

            // Always normalise id type (string numeric → int) so the stored JSON
            // is consistent with what insert() would have written.
            // Any $newData['id'] supplied by the caller is intentionally overwritten
            // to prevent accidental id mismatch in the written record.
            $newData['id'] = is_numeric($id) ? (int)$id : $id;

            if ($this->idx->beginWrite() !== JdbBinaryIndex::OK) {
                JdbErrorHandler::push('JsonDatabase', 'update', 'beginWrite failed');
                return false;
            }

            $currentOffset = $rollbackOffset;
            // allowDuplicate=true: update is an intentional overwrite of an
            // existing record. writeSingleRecord will compute version = existing+1.
            $result = $this->writeSingleRecord($fp, $id, $newData, $currentOffset, true);
            if ($result === false) {
                JdbErrorHandler::push('JsonDatabase', 'update', "writeSingleRecord failed [id={$id}]");
                ftruncate($fp, $rollbackOffset);
                return false;
            }

            if (!fflush($fp)) {
                JdbErrorHandler::push('JsonDatabase', 'update', "fflush data file failed [id={$id}]");
                ftruncate($fp, $rollbackOffset);
                return false;
            }

            if ($this->idx->commitWrite() !== JdbBinaryIndex::OK) {
                JdbErrorHandler::push('JsonDatabase', 'update', 'commitWrite failed');
                ftruncate($fp, $rollbackOffset);
                return false;
            }

            $this->markSecondaryDirty();
            $this->checkAutoCompact((int)$this->idx->count(), (int)$this->idx->nextId());
            return true;

        } finally {
            $this->idx->close();
            $this->unlockAndClose($fp);
        }
    }

    /**
     * DELETE – Soft delete: tombstone in the data file, FLAG_DELETED in the index.
     *
     * @param  mixed $id
     * @param  int $expectedVersion
     * @return bool
     */
    public function delete($id, $expectedVersion = null)
    {
        JdbErrorHandler::resetStack();
        $id = (string)$id;

        $fp = $this->openAndLock($this->dataFile);
        if (!$fp) {
            JdbErrorHandler::push('JsonDatabase', 'delete', 'openAndLock failed');
            return false;
        }

        try {
            if ($this->ensureIndex(true) !== JdbBinaryIndex::OK) {
                JdbErrorHandler::push('JsonDatabase', 'delete', 'ensureIndex(write) failed');
                return false;
            }
            $idx = $this->idx;
            $entry = $idx->lookup($id);
            if ($entry === null) {
                JdbErrorHandler::push('JsonDatabase', 'delete',
                    "lookup(id={$id}) not found or already deleted");
                return false;
            }
            $entryVersion = (int)$entry['version'];
            if ($expectedVersion !== null && $entryVersion !== (int)$expectedVersion) {
                JdbErrorHandler::push('JsonDatabase', 'delete',
                    "version conflict (expected={(int)$expectedVersion}, current={$entryVersion})");
                return false;
            }
            $offset = JdbUtil::getFileSize($fp);
            if ($offset === false) {
                JdbErrorHandler::push('JsonDatabase', 'delete', 'fstat on data file failed');
                return false;
            }
            $idValue  = is_numeric($id) ? (int)$id : $id;
            $tombstone = json_encode(array('id' => $idValue, '_deleted' => true)) . "\n";
            $length    = strlen($tombstone);

            if ($idx->beginWrite() !== JdbBinaryIndex::OK) {
                JdbErrorHandler::push('JsonDatabase', 'delete', 'beginWrite failed');
                return false;
            }
            if (fwrite($fp, $tombstone) !== $length) {
                JdbErrorHandler::push('JsonDatabase', 'delete',
                    "fwrite tombstone failed [id={$id}, length={$length}]");
                ftruncate($fp, $offset);
                return false;
            }
            $ret = $idx->remove($id);
            if ($ret !== JdbBinaryIndex::OK) {
                JdbErrorHandler::push('JsonDatabase', 'delete', "idx->remove failed (code={$ret})");
                ftruncate($fp, $offset);
                return false;
            }
            if ($idx->commitWrite() !== JdbBinaryIndex::OK) {
                JdbErrorHandler::push('JsonDatabase', 'delete', 'commitWrite failed');
                ftruncate($fp, $offset);
                return false;
            }

            $this->markSecondaryDirty();
            $activeCount = (int)$idx->count();
            $nextId      = (int)$idx->nextId();

            $idx->close();               // close BEFORE releasing the lock
            $this->unlockAndClose($fp);  // release the lock
            $fp = null;                  // prevent double-close in the finally block

            $this->checkAutoCompact($activeCount, $nextId);
            return true;

        } finally {
            $this->idx->close();
            if ($fp !== null) {
                $this->unlockAndClose($fp);
            }
        }
    }

    /**
     * Returns the current version of a record (O(1), index-only read).
     * Useful for Optimistic Concurrency Control (OCC).
     *
     * @param  mixed  $id
     * @return int|false Record version, or false if not found/error
     */
    public function getEntryVersion($id)
    {
        JdbErrorHandler::resetStack();
        $id = (string)$id;
        $fp = $this->openForRead($this->dataFile);
        if (!is_resource($fp)) {
            JdbErrorHandler::push('JsonDatabase', 'getEntryVersion', 'openAndLock failed');
            return false;
        }
        try {
            if ($this->ensureIndex(false) !== JdbBinaryIndex::OK) {
                JdbErrorHandler::push('JsonDatabase', 'getEntryVersion', 'ensureIndex(read) failed');
                return false;
            }
            $entry = $this->idx->lookup($id);
            return ($entry !== null) ? (int)$entry['version'] : false;
        } finally {
            $this->idx->close();
            $this->unlockAndClose($fp);
        }
    }

    /**
     * VACUUM – Compacts the data file by removing obsolete versions and deleted records.
     * Also rebuilds a clean primary index and marks secondary indexes dirty.
     *
     * Named vacuum() instead of compact() to avoid ambiguity with the PHP built-in
     * compact() function inside method bodies and to follow database convention.
     * DatabaseManager::compact() delegates here.
     *
     * @return array|false Detailed compaction statistics
     */
    public function vacuum()
    {
        JdbErrorHandler::resetStack();

        // --- Preflight disk check -------------------------------------------------
        // The in-place approach only creates a temporary index file (~10-15 % of the
        // data file). Abort early if even that headroom is not available, so the
        // caller gets a clean false instead of a half-written file.
        $dataDir   = dirname($this->dataFile);
        $freeBytes = @disk_free_space($dataDir);
        if ($freeBytes !== false) {
            $currentSize = file_exists($this->dataFile) ? filesize($this->dataFile) : 0;
            $needed      = (int)($currentSize * 0.20) + 1048576; // 20 % + 1 MB headroom
            if ($freeBytes < $needed) {
                JdbErrorHandler::push('JsonDatabase', 'vacuum',
                    'insufficient disk space (free=' . $freeBytes . ', needed=' . $needed . ')');
                return false;
            }
        }

        $lockFp = $this->openAndLock($this->dataFile);
        if (!$lockFp) {
            return false;
        }

        $dataFp    = null;
        $writeFp   = null;
        $tmpIdxFp  = null;
        $committed = false;
        $idxClosed = false;

        try {
            if ($this->ensureIndex(true) !== JdbBinaryIndex::OK) {
                return false;
            }

            $nextId = (int)$this->idx->nextId();
            $oldSize = file_exists($this->dataFile) ? filesize($this->dataFile) : 0;

            $tempIndex = $this->indexFile . '.tmp';

            // --- Collect and sort live entries by file offset --------------------
            // In-place compaction requires write_ptr <= read_ptr at every step.
            // idx->scan() returns entries in hash order; sorting by ascending offset
            // enforces the invariant: each fread is always ahead of the fwrite.
            $entries = $this->idx->scan();
            usort($entries, function ($a, $b) {
                if ($a['offset'] < $b['offset']) { return -1; }
                if ($a['offset'] > $b['offset']) { return  1; }
                return 0;
            });

            // --- Stat-gathering scan (read-only, before any modification) --------
            // One lightweight fgets pass to count rows and tombstones.
            // Uses strpos instead of json_decode for speed — tombstone rows are
            // identified by the presence of the "_deleted" key string.
            $dataFp = fopen($this->dataFile, 'rb');
            if (!is_resource($dataFp)) {
                return false;
            }
            $dataFileSize = JdbUtil::getFileSize($dataFp);
            if ($dataFileSize === false) {
                $dataFileSize = PHP_INT_MAX;
            }

            $totalLinesBefore = 0;
            $deleteRecords    = 0;

            if (fseek($dataFp, JdbUtil::DATA_HEADER_SIZE) !== 0) {
                return false;
            }
            while (!feof($dataFp)) {
                $line    = fgets($dataFp);
                if ($line === false) {
                    break;
                }
                $trimmed = trim($line);
                if ($trimmed === '' || $trimmed[0] !== '{') {
                    continue;
                }
                if (strpos($trimmed, '"id"') === false) {
                    continue;
                }
                $totalLinesBefore++;
                if (strpos($trimmed, '"_deleted"') !== false) {
                    $deleteRecords++;
                }
            }

            // --- Mark index dirty BEFORE touching the data file ------------------
            // If the process dies between ftruncate() and the index safeOverwrite(),
            // dirty=1 forces a full index rebuild on the next open(). The rebuild
            // scans the (already compacted) data file and finds records at their
            // new offsets — no data loss.
            // On Windows, safeOverwrite() uses unlink+rename (not atomic), so this
            // guard is even more important: it covers the gap between the two calls.
            if ($this->idx->beginWrite() !== JdbBinaryIndex::OK) {
                JdbErrorHandler::push('JsonDatabase', 'vacuum', 'beginWrite failed');
                return false;
            }

            // --- In-place compaction: two independent handles on the same file ---
            // $dataFp  (rb)  : read handle, advances in ascending offset order.
            // $writeFp (r+b) : write handle, always behind $dataFp (write_ptr <= read_ptr).
            //
            // POSIX: the two handles share the kernel page cache; writes by $writeFp
            // are immediately visible to subsequent reads by $dataFp.
            // Windows (buffered CRT I/O): $writeFp's write buffer is never re-read
            // by $dataFp because $dataFp always reads from offsets strictly ahead
            // of write_ptr. No fflush() between individual records is needed.
            $writeFp = fopen($this->dataFile, 'r+b');
            if (!is_resource($writeFp)) {
                return false;
            }

            $writePtr    = JdbUtil::DATA_HEADER_SIZE; // skip the PHP die header
            $compacted   = array();
            $recordsKept = 0;

            foreach ($entries as $entry) {
                if (!JdbUtil::isValidRecordBounds($entry['offset'], $entry['length'], $dataFileSize)) {
                    JdbErrorHandler::push('JsonDatabase', 'vacuum',
                        'skip invalid bounds id=' . bin2hex($entry['id_hash']) . ' offset=' . $entry['offset']);
                    continue;
                }

                if (fseek($dataFp, $entry['offset']) !== 0) {
                    return false;
                }
                $data = fread($dataFp, $entry['length']);
                if ($data === false || strlen($data) < $entry['length']) {
                    return false;
                }

                // Optimisation: skip fwrite when the record is already in place.
                // This happens when write_ptr has never fallen behind (no dead records
                // precede this entry), making vacuum a true no-op on unfragmented files.
                if ($writePtr !== $entry['offset']) {
                    if (fseek($writeFp, $writePtr) !== 0) {
                        return false;
                    }
                    $written = fwrite($writeFp, $data);
                    if ($written === false || $written === 0) {
                        return false;
                    }
                } else {
                    $written = $entry['length'];
                }

                $compacted[] = array(
                    'id_hash' => $entry['id_hash'],
                    'offset'  => $writePtr,
                    'length'  => $written,
                    'version' => $entry['version'],
                );
                $writePtr += $written;
                $recordsKept++;
            }

            // Flush and shrink the data file to its compacted size.
            // After ftruncate the data file is fully self-consistent.
            if (!fflush($writeFp)) { return false; }
            if (!ftruncate($writeFp, $writePtr)) { return false; }
            fclose($dataFp);  $dataFp  = null;
            fclose($writeFp); $writeFp = null;

            // --- Rebuild index on a .tmp file, then atomically swap it in --------
            // Aggressive sizing: avoids a grow() on the first inserts after vacuum.
            $slots    = JdbUtil::nextPowerOfTwo(max(JdbBinaryIndex::INIT_SLOTS, $recordsKept * 2));
            $tmpIdxFp = fopen($tempIndex, 'w+b');
            if (!is_resource($tmpIdxFp)) { return false; }

            if (JdbBinaryIndex::writeHeader($tmpIdxFp, $slots, 0, $nextId, 0) === false) {
                return false;
            }
            if (!JdbUtil::writeEmptySlots($tmpIdxFp, $slots, JdbBinaryIndex::SLOT_SIZE)) {
                return false;
            }

            $occupied   = str_repeat("\x00", $slots);
            $indexCount = 0;

            foreach ($compacted as $entry) {
                if (!JdbBinaryIndex::tempUpsert(
                    $tmpIdxFp, $occupied, $slots,
                    $entry['id_hash'], $entry['offset'], $entry['length'],
                    $entry['version'], 0x00, $indexCount
                )) {
                    return false;
                }
            }

            if (JdbBinaryIndex::writeHeader($tmpIdxFp, $slots, $indexCount, $nextId, 0) === false) {
                return false;
            }
            fclose($tmpIdxFp); $tmpIdxFp = null;

            $this->idx->close();
            $idxClosed = true;
            $err = null;
            if (!JdbUtil::safeOverwrite($tempIndex, $this->indexFile, $err)) {
                JdbErrorHandler::push('JsonDatabase', 'vacuum', 'safeOverwrite index failed: ' . $err);
                return false;
            }

            $this->unlockAndClose($lockFp);
            $committed = true;
            $lockFp    = null;

            // Secondary indexes have stale offsets after compaction: mark dirty.
            $this->markSecondaryDirty();

            $newSize = $writePtr;
            $obsoleteVersions = max(0, $totalLinesBefore - $recordsKept - $deleteRecords);

            return array(
                'records_kept'      => $recordsKept,
                'deleted_records'   => $deleteRecords,
                'obsolete_versions' => $obsoleteVersions,
                'records_removed'   => $deleteRecords + $obsoleteVersions,
                'old_size'          => $oldSize,
                'new_size'          => $newSize,
                'space_saved'       => $oldSize - $newSize,
            );

        } finally {
            if (is_resource($dataFp))   fclose($dataFp);
            if (is_resource($writeFp))  fclose($writeFp);
            if (is_resource($tmpIdxFp)) fclose($tmpIdxFp);
            if ($this->idx !== null && $idxClosed) {
                $this->idx->close();
            }
            if (!$committed && is_resource($lockFp)) {
                $this->unlockAndClose($lockFp);
            }
        }
    }

    /**
     * COMPACT – Alias for vacuum().
     *
     * Provided for API symmetry with DatabaseManager::compact().
     * All compaction logic is implemented in vacuum().
     *
     * @return array|false Detailed compaction statistics, or false on error
     */
    public function compact()
    {
        return $this->vacuum();
    }

    /**
     * COUNT – Number of active records (O(1), reads only the header).
     *
     * @return int|false
     */
    public function count()
    {
        JdbErrorHandler::resetStack();
        $fp = $this->openForRead($this->dataFile);
        if (!$fp) return false;
        try {
            if ($this->ensureIndex(false) !== JdbBinaryIndex::OK) return false;
            return $this->idx->count();
        } finally {
            $this->idx->close();
            $this->unlockAndClose($fp);
        }
    }

    /**
     * EXISTS – Checks whether a record exists (O(1) hash probe, no fread on the data file).
     *
     * @param  mixed $id
     * @return bool
     */
    public function exists($id)
    {
        JdbErrorHandler::resetStack();
        $id = (string)$id;

        $fp = $this->openForRead($this->dataFile);
        if (!$fp) return false;

        try {
            if ($this->ensureIndex(false) !== JdbBinaryIndex::OK) return false;
            return $this->idx->lookup($id) !== null;
        } finally {
            $this->idx->close();
            $this->unlockAndClose($fp);
        }
    }

    /**
     * GET STATS – Complete database statistics, including secondary indexes.
     *
     * @return array|false
     */
    public function getStats()
    {
        JdbErrorHandler::resetStack();
        $fp = $this->openForRead($this->dataFile);
        if (!$fp) {
            return false;
        }

        $idx = $this->idx;

        try {
            if ($this->ensureIndex(false) !== JdbBinaryIndex::OK) {
                return false;
            }

            $count   = $idx->count();
            $nextId = $idx->nextId();
            $slots   = $idx->slots();

            $totalLines     = 0;
            $deleteRecords = 0;
            if (fseek($fp, JdbUtil::DATA_HEADER_SIZE) !== 0) {
                return false;
            }

            $overlapSize = 8; // strlen('"_deleted"') - 2: overlap guard to avoid splitting the key across chunks
            $carry       = '';
            $bufferSize = 65536;

            while (true) {
                $raw = fread($fp, $bufferSize);
                if ($raw === false || $raw === '') {
                    break;
                }

                $chunk  = $carry . $raw;
                $isLast = feof($fp);

                if ($isLast) {
                    // $carry has not been counted yet: process everything without splitting
                    $totalLines     += substr_count($chunk, '"id"');
                    $deleteRecords += substr_count($chunk, '"_deleted"');
                    $carry = '';
                    break;
                }

                $safeLen = strlen($chunk) - $overlapSize;
                if ($safeLen > 0) {
                    $safe = substr($chunk, 0, $safeLen);
                    $totalLines     += substr_count($safe, '"id"');
                    $deleteRecords += substr_count($safe, '"_deleted"');
                    $carry = substr($chunk, $safeLen); // last $overlapSize bytes, not yet counted
                } else {
                    $carry = $chunk; // chunk too small, accumulate without counting
                }
            }

            // Defensive: if the loop exits without isLast (empty file or read error)
            if ($carry !== '') {
                $totalLines     += substr_count($carry, '"id"');
                $deleteRecords += substr_count($carry, '"_deleted"');
            }

            $obsolete = max(0, $totalLines - $count - $deleteRecords);

            // Secondary index info
            $secondary = array();
            foreach ($this->secondaryIndexes as $field => $sidx) {
                $sidxFile = $sidx->getFile();
                $secondary[$field] = array(
                    'dirty'     => $sidx->isDirty(),
                    'file_size' => file_exists($sidxFile) ? filesize($sidxFile) : 0,
                );
            }

            if ($slots > 0) {
                $loadFactor = round($count / $slots, 3);
            } else {
                $loadFactor = 0;
            }

            if ($totalLines > 0) {
                $fragmentation = round(($deleteRecords / $totalLines) * 100, 2);
            } else {
                $fragmentation = 0;
            }

            return array(
                'active_records'        => (int)$count,
                'total_lines'           => $totalLines,
                'deleted_records'       => $deleteRecords,
                'obsolete_versions'     => $obsolete,
                'data_file_size'        => filesize($this->dataFile),
                'index_file_size'       => filesize($this->indexFile),
                'next_id'               => (int)$nextId,
                'index_slots'           => (int)$slots,
                'index_load_factor'     => $loadFactor,
                'fragmentation_percent' => $fragmentation,
                'secondary_indexes'     => $secondary,
            );

        } finally {
            $idx->close();
            $this->unlockAndClose($fp);
        }
    }

    // =========================================================================
    // Aggregations
    // =========================================================================

    /**
     * AGGREGATE – Computes aggregation functions over a field.
     *
     * Supported functions: 'count', 'sum', 'avg', 'min', 'max'.
     * 'min' and 'max' on indexed fields are O(1) (first/last slot of the
     * sorted secondary index). All other functions scan matching records — O(N).
     *
     * @param  string     $fn         Aggregation function: count|sum|avg|min|max
     * @param  string     $field      Field to aggregate over
     * @param  array      $conditions Optional AND field => value filters
     * @return mixed|false  Result value, or false on error / unsupported function
     */
    public function aggregate($fn, $field, array $conditions = array())
    {
        JdbErrorHandler::resetStack();
        $fn = strtolower(trim($fn));

        if (!in_array($fn, array('count', 'sum', 'avg', 'min', 'max'), true)) {
            JdbErrorHandler::push('JsonDatabase', 'aggregate',
                'unsupported function: ' . $fn . ' (allowed: count, sum, avg, min, max)');
            return false;
        }

        // ── Fast path: count with no conditions ───────────────────────────────
        // The exact counter is already stored in the primary index header.
        // No data-file scan required: O(1) cost.
        if ($fn === 'count' && empty($conditions)) {
            if ($this->ensureIndex(false) !== JdbBinaryIndex::OK) {
                return false;
            }
            $n = (int)$this->idx->count();
            $this->idx->close();
            return $n;
        }

        // ── Fast path: min/max on an indexed field with no conditions ─────────
        // Reads only the first or last slot of the secondary index: O(1).
        if (($fn === 'min' || $fn === 'max') && empty($conditions)) {
            $sidx = isset($this->secondaryIndexes[$field])
                ? $this->secondaryIndexes[$field]
                : null;
            if ($sidx !== null) {
                if ($sidx->isDirty()) {
                    $sidx->rebuild($this->dataFile, JdbUtil::DATA_HEADER_SIZE);
                }
                $val = $sidx->getEdgeValue($fn === 'min' ? 'first' : 'last');
                if ($val !== false) {
                    return $val;
                }
                // getEdgeValue failed: fall back to full scan
            }
        }

        // ── Full data-file scan ───────────────────────────────────────────────
        $fp = $this->openForRead($this->dataFile);
        if (!$fp) {
            return false;
        }

        try {
            if ($this->ensureIndex(false) !== JdbBinaryIndex::OK) {
                return false;
            }

            $count      = 0;
            $sum        = 0.0;
            $minVal     = null;
            $maxVal     = null;
            $hasFilters = !empty($conditions);
            $self       = $this;

            // Single fstat for the entire scan
            $fileSize = JdbUtil::getFileSize($fp);
            if ($fileSize === false) {
                return false;
            }

            $self->idx->scanChunked(function ($entry) use (
                $fp, $fn, $field, $conditions, $hasFilters, $self,
                $fileSize, &$count, &$sum, &$minVal, &$maxVal
            ) {
                if (!JdbUtil::isValidRecordBounds($entry['offset'], $entry['length'], $fileSize)) {
                    return null;
                }
                if (fseek($fp, $entry['offset']) !== 0) {
                    return null;
                }
                $line   = fread($fp, $entry['length']);
                $record = JdbUtil::jsonDecodeRecord($line);
                if ($record === null) {
                    return null;
                }
                if ($hasFilters && !JdbUtil::recordMatchesConditions($record, $conditions)) {
                    return null;
                }
                if ($fn !== 'count' && !array_key_exists($field, $record)) {
                    return null;
                }

                $count++;
                if ($fn === 'count') {
                    return null;
                }

                $v = $record[$field];
                if (!is_numeric($v)) {
                    return null;
                }
                $v = (float)$v;

                if ($fn === 'sum' || $fn === 'avg') {
                    $sum += $v;
                } elseif ($fn === 'min') {
                    if ($minVal === null || $v < $minVal) {
                        $minVal = $v;
                    }
                } elseif ($fn === 'max') {
                    if ($maxVal === null || $v > $maxVal) {
                        $maxVal = $v;
                    }
                }
                return null;
            });

            switch ($fn) {
                case 'count': return $count;
                case 'sum':   return $sum;
                case 'avg':   return $count > 0 ? $sum / $count : null;
                case 'min':   return $minVal;
                case 'max':   return $maxVal;
            }
            return false;

        } finally {
            $this->idx->close();
            $this->unlockAndClose($fp);
        }
    }

    /**
     * Sorts the entry buffer by (id_hash ASC, offset ASC) and writes it as a
     * fixed-size binary chunk file (43 bytes per entry).
     *
     * Entry layout:
     *   a32  id_hash  — raw SHA-256 digest
     *   N    offset   — uint32 big-endian, byte position in the data file
     *   N    length   — uint32 big-endian, record byte count
     *   v    version  — uint16 little-endian
     *   C    deleted  — uint8, 1 = tombstone, 0 = live record
     *
     * The buffer is emptied after a successful write to release memory immediately.
     *
     * @param  array  &$buf       Entry buffer (sorted and emptied on success)
     * @param  string $chunkPath  Destination path for the binary chunk file
     * @return bool
     */
    private function rebuildWriteChunk(array &$buf, $chunkPath)
    {
        // Sort by (id ASC, offset ASC) so that during the k-way merge we can
        // advance through each chunk sequentially and detect id-group boundaries.
        // Within the same id, lower offset = older version; the merge keeps the
        // entry with the highest offset (last-write-wins).
        usort($buf, function ($a, $b) {
            $cmp = strcmp($a['id_hash'], $b['id_hash']);
            return ($cmp !== 0) ? $cmp : ($a['offset'] - $b['offset']);
        });

        $fp = fopen($chunkPath, 'wb');
        if (!is_resource($fp)) {
            return false;
        }

        // Binary entry layout (43 bytes, fixed-size for O(1) random access):
        //   a32  id_hash  — SHA-256 raw digest
        //   N    offset   — uint32 big-endian, position in data file
        //   N    length   — uint32 big-endian, record byte count
        //   v    version  — uint16 little-endian
        //   C    deleted  — uint8, 1 = tombstone, 0 = live
        foreach ($buf as $e) {
            $packed = pack('a32NNvC',
                $e['id_hash'],
                $e['offset'],
                $e['length'],
                $e['version'],
                $e['deleted']
            );
            if (fwrite($fp, $packed) !== 43) {
                fclose($fp);
                return false;
            }
        }

        fclose($fp);
        $buf = array(); // release memory immediately
        return true;
    }

    /**
     * Reads one entry from a chunk file.
     * Returns null on EOF or truncated read.
     *
     * @param  resource $fp
     * @return array|null  ['id_hash'=>string, 'offset'=>int, 'length'=>int,
     *                      'version'=>int, 'deleted'=>int] | null
     */
    private function rebuildReadChunkEntry($fp)
    {
        $raw = fread($fp, 43);
        if ($raw === false || strlen($raw) < 43) {
            return null; // EOF or truncated chunk file
        }
        return unpack('a32id_hash/Noffset/Nlength/vversion/Cdeleted', $raw);
    }

    /**
     * Internal rebuild implementation. Assumes the index rebuild lock is already held.
     * @internal
     * @return bool
     */
    private function doRebuildIndex()
    {
        // Phase 0: empty table fast-path
        if (!file_exists($this->dataFile)) {
            return $this->createEmptyIndex();
        }

        $chunkFiles = array();
        try {
            // Phase 1: scan data file → sorted chunk files
            $scanResult = $this->scanDataFileToChunks($chunkFiles);
            if ($scanResult === false) {
                return false;
            }
            list($totalRecs, $nextId) = $scanResult;

            // Phase 2: merge chunks → final index
            return $this->kWayMergeChunksToIndex($chunkFiles, $totalRecs, $nextId);
        } finally {
            // Single cleanup point — covers every failure path safely
            $this->cleanupChunkFiles($chunkFiles);
        }
    }

    /**
     * Phase 0: creates a bare index file for an empty table.
     * @return bool
     */
    private function createEmptyIndex()
    {
        $emptyFp = fopen($this->indexFile, 'w+b');
        if (!is_resource($emptyFp)) {
            return false;
        }
        try {
            if (JdbBinaryIndex::writeHeader($emptyFp, JdbBinaryIndex::INIT_SLOTS, 0, 1, 0) === false) {
                return false;
            }
            $empty = str_repeat("\x00", JdbBinaryIndex::SLOT_SIZE);
            for ($i = 0; $i < JdbBinaryIndex::INIT_SLOTS; $i++) {
                if (fwrite($emptyFp, $empty) !== JdbBinaryIndex::SLOT_SIZE) {
                    return false;
                }
            }
            $this->markSecondaryDirty();
            return true;
        } finally {
            fclose($emptyFp);
        }
    }

    /**
     * Phase 1: scans the data file and writes sorted chunk files.
     * @param  array &$chunkFiles Populated with chunk file paths on output
     * @return array|false        [$totalRecs, $nextId] or false on error
     */
    private function scanDataFileToChunks(array &$chunkFiles)
    {
        $chunkSize  = 5000;
        $chunkBuf   = array();
        $totalRecs  = 0;
        $nextId    = 1;
        $tempBase   = $this->indexFile . '.tmp';

        $fp = fopen($this->dataFile, 'rb');
        if (!is_resource($fp)) {
            return false;
        }
        try {
            if (fseek($fp, JdbUtil::DATA_HEADER_SIZE) !== 0) {
                return false;
            }

            $offset = JdbUtil::DATA_HEADER_SIZE;
            while (!feof($fp)) {
                $line = fgets($fp);
                if ($line === false) {
                    break;
                }

                $lineLen    = strlen($line);
                $lineOffset = $offset;
                $offset    += $lineLen;

                if (trim($line) === '') {
                    continue;
                }

                $record = JdbUtil::jsonDecodeRecord($line);
                if ($record === null) {
                    continue;
                }

                $id = (string)$record['id'];

                if (is_numeric($id)) {
                    $val = (int)$id;
                    if ($val >= $nextId) {
                        $nextId = $val + 1;
                    }
                } elseif (preg_match('/_([0-9]+)$/', $id, $m)) {
                    $val = (int)$m[1];
                    if ($val >= $nextId) {
                        $nextId = $val + 1;
                    }
                }

                $chunkBuf[] = array(
                    'id_hash' => hash('sha256', $id, true),
                    'offset'  => $lineOffset,
                    'length'  => $lineLen,
                    'version' => (isset($record['version']) && is_int($record['version'])) ? $record['version'] : 1,
                    'deleted' => isset($record['_deleted']) ? 1 : 0,
                );
                $totalRecs++;

                if (count($chunkBuf) >= $chunkSize) {
                    if (!$this->flushChunk($chunkBuf, $tempBase, count($chunkFiles), $chunkFiles)) {
                        return false;
                    }
                }
            }

            // Flush remaining records
            if (!empty($chunkBuf)) {
                if (!$this->flushChunk($chunkBuf, $tempBase, count($chunkFiles), $chunkFiles)) {
                    return false;
                }
            }

            return array($totalRecs, $nextId);
        } finally {
            fclose($fp);
        }
    }

    /**
     * Sorts the buffer and writes it as a binary chunk file.
     * @param  array  &$buf      Chunk buffer (consumed, emptied on success)
     * @param  string $tempBase  Base path for temp files
     * @param  int    $index     Chunk sequence number
     * @param  array  &$files    Chunk file list (appended on success)
     * @return bool
     */
    private function flushChunk(array &$buf, $tempBase, $index, array &$files)
    {
        $chunkPath = $tempBase . '.ck' . $index;
        if (!$this->rebuildWriteChunk($buf, $chunkPath)) {
            return false;
        }
        $files[] = $chunkPath;
        $buf     = array(); // release memory immediately
        return true;
    }

    /**
     * Removes all temporary chunk files.
     * Safe to call with an empty array or after partial failures.
     * @param array $chunkFiles
     */
    private function cleanupChunkFiles(array $chunkFiles)
    {
        foreach ($chunkFiles as $cf) {
            @unlink($cf);
        }
    }

    /**
     * WRITE SINGLE RECORD – Shared kernel for insert(), insertWithId(), insertBatch().
     *
     * This is the ONLY method in JsonDatabase that calls JdbBinaryIndex::put() and
     * fwrite() on the data file for record insertion. Centralising here means
     * every invariant is enforced in one place and cannot be accidentally omitted
     * in a new insert path:
     *
     *   1. Duplicate guard   – if $allowDuplicate=false and the ID already exists
     *                          in the index the call returns false immediately.
     *                          (Previously missing from insertBatch(), causing silent
     *                          record overwrite — the security flaw this refactor fixes.)
     *   2. Version tracking  – new records get version=1; updates get existing+1.
     *                          Previously insertBatch() always passed hardcoded 1,
     *                          causing version regression on overwrite.
     *   3. Single put() site – JdbBinaryIndex::put() is called only here, not scattered
     *                          across three separate public methods.
     *
     * Prerequisites (enforced by callers):
     *   - The data file handle $fp must be open in 'ab' mode with an exclusive lock.
     *   - idx->beginWrite() must have been called (dirty=1 already set).
     *   - $data['id'] must already be set to the resolved ID value.
     *
     * $currentOffset is updated by reference: the caller tracks the running append
     * position without extra fstat/ftell syscalls.
     *
     * Returns an info array on success:
     *   ['offset' => int, 'length' => int, 'version' => int, 'is_new' => bool]
     * Returns false on any failure (duplicate, encode error, fwrite error, idx error).
     * On fwrite failure the caller is responsible for truncating back to the
     * pre-call offset (passed in by reference as $currentOffset before the call).
     *
     * Compatibility: PHP 5.5+ (no type hints, no short closures, no ?? operator).
     *
     * @param  resource $fp             Data file handle ('ab', exclusive lock held)
     * @param  string   $id             Already-validated record ID
     * @param  array    $data           Record data ($data['id'] already set)
     * @param  int      $currentOffset  Running append offset (updated by reference)
     * @param  bool     $allowDuplicate false = reject if ID exists; true = upsert
     * @return array|false
     */
    private function writeSingleRecord($fp, $id, array $data, &$currentOffset, $allowDuplicate = false)
    {
        $id = JdbUtil::normalizeId($id);

        $idx = $this->idx;
        // ── 1. Duplicate guard ─────────────────────────────────────────────────
        // Single enforcement point: previously absent from insertBatch().
        $probe = $idx->lookupEx($id);
        if (!$allowDuplicate && $probe !== null) {
            JdbErrorHandler::push('JsonDatabase', 'writeSingleRecord',
                "duplicate id={$id} rejected (allowDuplicate=false)");
            return false;
        }

        // ── 2. Encode ──────────────────────────────────────────────────────────
        $line = json_encode($data);
        if ($line === false) {
            JdbErrorHandler::push('JsonDatabase', 'writeSingleRecord',
                "json_encode failed [id={$id}]");
            return false;
        }
        $line  .= "\n";
        $length = strlen($line);
        $offset = $currentOffset;

        // ── 3. Append to data file ─────────────────────────────────────────────
        if (fwrite($fp, $line) !== $length) {
            JdbErrorHandler::push('JsonDatabase', 'writeSingleRecord',
                "fwrite failed [id={$id}, offset={$offset}, length={$length}]");
            return false;
        }
        $currentOffset += $length;

        // ── 4. Update primary index ────────────────────────────────────────────
        // Version: new records start at 1; updates increment the existing value.
        // Previously insertBatch() always passed 1 regardless, causing silent
        // version regression when overwriting an already-updated record.
        $existing = ($probe !== null) ? $probe['entry'] : null;
        $newVersion = ($existing !== null) ? min(0xFFFF, $existing['version'] + 1) : 1;
        if ($existing !== null && $existing['version'] >= 0xFFFF) {
            JdbErrorHandler::push('JsonDatabase', 'writeSingleRecord',
                "version overflow prevented for id={$id} (capped at 0xFFFF)");
        }

        if ($idx->putEx($id, $offset, $length, $newVersion, $probe) !== JdbBinaryIndex::OK) {
            JdbErrorHandler::push('JsonDatabase', 'writeSingleRecord',
                "idx->put failed [id={$id}, offset={$offset}, length={$length}]");
            return false;
        }

        return array(
            'offset'  => $currentOffset,
            'length'  => $length,
            'version' => $newVersion,
            'is_new'  => ($existing === null),
        );
    }

    /**
     * Creates the files if they do not exist.
     */
    private function initializeFiles()
    {
        if (!file_exists($this->dataFile)) {
            $fp = fopen($this->dataFile, 'wb');
            if (is_resource($fp)) {
                JdbUtil::writePhpDieHeader($fp);
                fclose($fp);
            }  else {
                JdbErrorHandler::push('JsonDatabase', 'initializeFiles', 'Unable to create data file: ' . $this->dataFile);
            }
        }
        if (!file_exists($this->indexFile)) {
            $this->rebuildIndex();
        }
    }

    // =========================================================================
    // Helpers: locking and index open
    // =========================================================================

    /**
     * Opens $filename in append mode and acquires an exclusive lock.
     * Delegates to JdbLock::acquireFile().
     *
     * @param  string $filename
     * @return resource|false
     */
    private function openAndLock($filename)
    {
        return $this->lck->acquireFile($filename, 'ab');
    }

    /**
     * Opens $filename read-only and acquires a shared lock (flock) or an
     * exclusive lock (mkdir — LOCK_SH has no equivalent with mkdir).
     * Delegates to JdbLock::acquireFileShared().
     *
     * @param  string $filename
     * @return resource|false
     */
    private function openForRead($filename)
    {
        return $this->lck->acquireFileShared($filename);
    }

    /**
     * Releases the lock and closes the file handle.
     * Delegates to JdbLock::releaseFile().
     *
     * @param resource $fp
     */
    private function unlockAndClose($fp)
    {
        $this->lck->releaseFile($fp);
    }

    /**
     * Acquires an exclusive mutex to serialise concurrent index rebuilds.
     * Delegates to JdbLock::acquireMutex() which handles both backends.
     *
     * @return array|false  Lock token, or false on timeout/failure
     */
    private function acquireIndexRebuildLock()
    {
        return $this->lck->acquireMutex($this->indexFile . '.rebuild.lock');
    }

    /**
     * Releases the index rebuild mutex.
     * Delegates to JdbLock::releaseMutex().
     *
     * @param array|false $lock  Token returned by acquireIndexRebuildLock()
     */
    private function releaseIndexRebuildLock($lock)
    {
        $this->lck->releaseMutex($lock);
    }

    /**
     * Opens the index and triggers an automatic rebuild if the dirty bit is set.
     * Locking is serialized using the configured backend (flock or mkdir).
     *
     * @param  bool $writeMode
     * @return int  JdbBinaryIndex::OK | error code
     */
    private function ensureIndex($writeMode = false)
    {
        $status = $this->idx->open($writeMode);
        if ($status === JdbBinaryIndex::ERR_NEEDS_REBUILD) {
            // Acquire rebuild lock regardless of original $writeMode
            $lock = $this->acquireIndexRebuildLock();
            if ($lock === false) {
                JdbErrorHandler::push('JsonDatabase', 'ensureIndex',
                    'Failed to acquire rebuild lock within ' . $this->lck->getTimeoutMs() . 'ms');
                return JdbBinaryIndex::ERR_IO;
            }
            try {
                // Re-check dirty bit inside critical section
                if (JdbIndexHeader::readDirtyBit($this->indexFile)) {
                    $this->doRebuildIndex(); // Always safe: rebuilds from data file
                }
                // Re-open in the originally requested mode
                $status = $this->idx->open($writeMode);
            } finally {
                // Guaranteed release even if rebuildIndex() or idx->open() throws/errors
                $this->releaseIndexRebuildLock($lock);
            }
        }
        if ($status !== JdbBinaryIndex::OK) {
            JdbErrorHandler::push('JsonDatabase', 'ensureIndex',
                'idx->open(' . ($writeMode ? 'write' : 'read') .
                ') failed (code=' . $status . ')');
        }
        return $status;
    }

    /**
     * Marks all secondary indexes dirty.
     * Call after every successful write on the data file.
     */
    private function markSecondaryDirty()
    {
        foreach ($this->secondaryIndexes as $idx) {
            $idx->markDirty();
        }
    }

    /**
     * Called after every successful write (insert/update/delete/insertBatch).
     *
     * Receives the current active record count and nextId already known to the
     * caller — no extra lock acquisition or index re-open is needed.
     *
     * @param int $active  Current number of active (non-deleted) records
     * @param int $nextId Next auto-increment ID (proxy for total records ever inserted)
     */
    private function checkAutoCompact($active, $nextId)
    {
        if (!$this->autoCompact) {
            return;
        }

        $totalEver  = max(1, $nextId - 1);
        $deletedEst = max(0, $totalEver - $active);
        $wastedRatio = $deletedEst / $totalEver;

        if ($wastedRatio < $this->autoCompactThreshold) {
            return; // below percentage threshold
        }

        // Absolute-size guard: skip compaction when the estimated wasted space
        // is below 5 MB. This avoids unnecessary I/O on small tables where the
        // percentage threshold would otherwise trigger on tiny amounts of data
        // (e.g. 30 % of a 1 MB file = 300 KB saved — not worth the overhead).
        $minSavedBytes = 5 * 1024 * 1024; // 5 MB
        $fileSize      = @filesize($this->dataFile);
        if ($fileSize !== false) {
            $estimatedWastedBytes = (int)($fileSize * $wastedRatio);
            if ($estimatedWastedBytes < $minSavedBytes) {
                return;
            }
        }

        $this->vacuum();
    }

    /**
     * Phase 2: k-way merge of sorted chunk files into the final index.
     * Uses the original linear-scan approach, which is proven and safe.
     * @param  array $chunkFiles
     * @param  int   $totalRecs
     * @param  int   $nextId
     * @return bool
     */
    private function kWayMergeChunksToIndex(array $chunkFiles, $totalRecs, $nextId)
    {
        $tempBase = $this->indexFile . '.tmp';
        $handles  = array();
        $heads    = array();

        foreach ($chunkFiles as $i => $cf) {
            $handles[$i] = fopen($cf, 'rb');
            if (!is_resource($handles[$i])) {
                return false; // finally block in doRebuildIndex will clean up
            }
            $heads[$i] = $this->rebuildReadChunkEntry($handles[$i]);
        }

        $slots = JdbUtil::nextPowerOfTwo(max(JdbBinaryIndex::INIT_SLOTS, $totalRecs * 2));
        $tmpFp = fopen($tempBase, 'w+b');
        if (!is_resource($tmpFp)) {
            return false;
        }

        try {
            if (JdbBinaryIndex::writeHeader($tmpFp, $slots, 0, $nextId, 0) === false
                || !JdbUtil::writeEmptySlots($tmpFp, $slots, JdbBinaryIndex::SLOT_SIZE)
            ) {
                return false;
            }

            $occupied = str_repeat("\x00", $slots);
            $count    = 0;

            while (true) {
                // Linear scan: find the chunk whose current head has the minimum id.
                $minHash = null;
                foreach ($heads as $e) {
                    if ($e === null) {
                        continue;
                    }
                    if ($minHash === null || strcmp($e['id_hash'], $minHash) < 0) {
                        $minHash = $e['id_hash'];
                    }
                }
                if ($minHash === null) {
                    break;
                } // all chunks exhausted

                // Collect every entry for $minId across all chunks.
                $best = null;
                foreach ($heads as $i => $e) {
                    if ($e === null || $e['id_hash'] !== $minHash) {
                        continue;
                    }

                    // Drain: keep reading while the id stays the same.
                    while ($e !== null && $e['id_hash'] === $minHash) {
                        if ($best === null || $e['offset'] > $best['offset']) {
                            $best = $e;
                        }
                        $e = $this->rebuildReadChunkEntry($handles[$i]);
                    }
                    $heads[$i] = $e; // first entry after the minId group (or null = EOF)
                }

                if ($best !== null && !$best['deleted']) {
                    JdbBinaryIndex::tempUpsert(
                        $tmpFp, $occupied, $slots,
                        $best['id_hash'], $best['offset'], $best['length'],
                        $best['version'], 0x00, $count
                    );
                }
            }

            JdbBinaryIndex::writeHeader($tmpFp, $slots, $count, $nextId, 0);
        } finally {
            fclose($tmpFp);
            foreach ($handles as $h) {
                if (is_resource($h)) { fclose($h); }
            }
        }

        $err = null;
        if (!JdbUtil::safeOverwrite($tempBase, $this->indexFile, $err)) {
            JdbErrorHandler::push('JsonDatabase', 'doRebuildIndex', $err);
            return false;
        }

        $this->markSecondaryDirty();
        return true;
    }

}
