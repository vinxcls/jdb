<?php
require_once __DIR__ . "/JdbErrorHandler.php";
require_once __DIR__ . "/JdbUtil.php";
require_once __DIR__ . "/JdbIndexHeader.php";

/**
 * BinaryIndex – On-disk hash table for JsonDatabase.
 *
 * Manages the binary index file (.index.php): format, slot I/O, probe chain,
 * automatic grow, and consistency via dirty bit.
 *
 * Index file format:
 *   - Fixed 32-byte header: magic + version + slots + count + next_id + dirty + padding
 *   - N slots of 84 bytes each: open addressing with linear probing, fnv1a64 hash
 *
 * Handle lifecycle (state machine):
 *   CLOSED → open() → OPEN → beginWrite() → WRITING → commitWrite() → OPEN
 *                              ↑                                          |
 *                              └──────────────── close() ────────────────┘
 *
 * Dirty-bit mechanism:
 *   - beginWrite()  → sets dirty=1 in the header BEFORE writing the data file
 *   - commitWrite() → clears dirty=0 AFTER a successful index update
 *   - open()        → if dirty=1 → ERR_NEEDS_REBUILD → automatic rebuild in JsonDatabase
 *
 * Compatibility: PHP 5.5+
 */
class JdbBinaryIndex
{
    // =========================================================================
    // Status codes
    // =========================================================================

    /** Operation succeeded */
    const OK               = 0;

    /** ID not found in the index */
    const ERR_NOT_FOUND    = 1;

    /** Attempt to insert an already-existing active ID */
    const ERR_DUPLICATE    = 2;

    /** I/O error on the index file (fopen, fread, fwrite failed) */
    const ERR_IO           = 3;

    /** Corrupt index file (wrong magic, invalid data) */
    const ERR_CORRUPT      = 4;

    /**
     * Dirty bit found on open: the index is stale.
     * The caller must invoke rebuildIndex() and re-open.
     */
    const ERR_NEEDS_REBUILD = 5;

    const ERR_STATE         = 6;

    // =========================================================================
    // Internal states (state machine)
    // =========================================================================

    const STATE_CLOSED  = 0; // handle closed
    const STATE_OPEN    = 1; // handle open, no write in progress
    const STATE_WRITING = 2; // beginWrite() called, dirty=1 on disk

    // =========================================================================
    // Format constants
    // =========================================================================

    /** Size of each slot in bytes */
    const SLOT_SIZE  = 48;

    /** Initial number of slots (power of 2) */
    const INIT_SLOTS = 256;

    /** Maximum load factor before automatic grow */
    const LOAD_MAX   = 0.70;

    // =========================================================================
    // Slot constants
    // =========================================================================

    /** Never-used slot: terminates the probe chain */
    const SLOT_EMPTY     = 0;

    /** Occupied slot (active or logically deleted) */
    const SLOT_OCCUPIED  = 1;

    /**
     * Tombstone: slot left by a physical delete (not currently produced by the main
     * put/remove path, which uses SLOT_OCCUPIED + FLAG_DELETED for logical deletes).
     * Handled defensively in tempUpsert() and tempDelete() to preserve the probe chain
     * in externally built index files. Reserved for future physical-delete support.
     */
    const SLOT_TOMBSTONE = 2;
    const SLOT_EMPTY_CHAR = "\x00";
    const SLOT_TOMBSTONE_CHAR = "\x02";

    /** Flag: record soft-deleted */
    const FLAG_DELETED = 0x01;

    /**
     * Slot pack/unpack format (v1, 48 bytes). PHP 5.5+ compatible.
     *
     *   Offset  Size  Format  Field
     *        0     1  C       used       (0=empty, 1=occupied, 2=tombstone)
     *        1    32  a32     id_hash    (SHA-256 raw digest, 32 bytes)
     *       33     4  V       offset     (uint32 LE, position in .jsonl — max 4 GB file)
     *       37     4  V       length     (uint32 LE, row length in bytes)
     *       41     2  v       version    (uint16 LE — supports up to 65535 updates)
     *       43     1  C       flags      (bit 0 = FLAG_DELETED)
     *       44     4  V       crc32      (uint32 LE, CRC32 of the first 44 bytes)
     *                                   48 bytes total
     *
     * SLOT_PACK covers the 6 data fields (44 bytes); CRC appended separately.
     * SLOT_UNPACK covers all 48 bytes so crc32 is available on read.
     */
    const SLOT_PACK   = 'Ca32VVvC';
    const SLOT_UNPACK = 'Cused/a32id_hash/Voffset/Vlength/vversion/Cflags/Vcrc32';


    const SLOTS_PER_PAGE_SHIFT = 6;           // 2^6 = 64
    const SLOTS_PER_PAGE       = 64;          // 64 slot × 48 byte = 3072 byte/page
    const PAGE_SLOT_MASK       = 63;          // SLOTS_PER_PAGE - 1 (for the bitmask)
    const PAGE_BYTES           = 3072;        // SLOTS_PER_PAGE * SLOT_SIZE

    // =========================================================================
    // Internal state
    // =========================================================================

    /** @var string Path to the index file */
    private $file;

    /** @var resource|null Persistent handle opened by open(), closed by close() */
    private $fp;

    /** @var int Current state: STATE_CLOSED | STATE_OPEN | STATE_WRITING */
    private $state;

    /**
     * In-memory header cache for the duration of a transaction.
     *
     * Valid between open() and close(). Invalidated by:
     *   - close()    -> null (end of transaction)
     *   - idxGrow()  -> null (file replaced on disk)
     *
     * Updated by:
     *   - idxReadHeader()  -> populated on first access (cache miss)
     *   - idxWriteHeader() -> updated after every write (write-through)
     *   - beginWrite()     -> updates only the dirty field (dirty=1)
     *   - commitWrite()    -> updates only the dirty field (dirty=0)
     *
     * @var array|null
     */
    private $headerCache;

    private $hashCache = array();

    private $cachedPageId = -1;
    private $cachedPage = null;

    // =========================================================================
    // Constructor
    // =========================================================================

    /**
     * @param string $indexFile Full path to the index file (.index.php)
     */
    public function __construct($indexFile)
    {
        $this->file        = $indexFile;
        $this->fp          = null;
        $this->state       = self::STATE_CLOSED;
        $this->headerCache = null;
    }

    // =========================================================================
    // Private helper – push a frame onto the shared JdbErrorHandler stack
    // =========================================================================

    /**
     * Registers an error frame in the shared stack and returns $code.
     * Usage: return $this->fail(self::ERR_IO, 'methodName', 'description');
     *
     * @param  int    $code    Error code to return to the caller
     * @param  string $method  Name of the method that detected the error
     * @param  string $msg     Short description of the failure point
     * @return int
     */
    private function fail($code, $method, $msg)
    {
        JdbErrorHandler::push('JdbBinaryIndex', $method, $msg);
        return $code;
    }

    private function closeAndFail($code, $method, $msg)
    {
        is_resource($this->fp) && fclose($this->fp);
        $this->fp = null;
        return $this->fail($code, $method, $msg);
    }

    // =========================================================================
    // Lifecycle: open / close / beginWrite / commitWrite
    // =========================================================================

    /**
     * Opens the index file and validates the header.
     * Checks the dirty bit: if dirty=1 returns ERR_NEEDS_REBUILD.
     * Must be balanced by close() in the caller's finally block.
     *
     * @param  bool $writeMode true = r+b (read/write), false = rb (read-only)
     * @return int  OK | ERR_IO | ERR_CORRUPT | ERR_NEEDS_REBUILD
     */
    public function open($writeMode = false)
    {
        if ($this->state !== self::STATE_CLOSED) {
            return $this->fail(self::ERR_IO, 'open',
                'already open (state=' . $this->state . ')');
        }

        $mode = $writeMode ? 'r+b' : 'rb';
        $fp = fopen($this->file, $mode);
        if (!is_resource($fp)) {
            return $this->fail(self::ERR_IO, 'open',
                'fopen failed [' . $this->file . ', mode=' . $mode . ']');
        }

        $this->fp          = $fp;
        $this->headerCache = null;
        $this->cachedPageId = -1;
        $this->cachedPage   = null;
        $header            = $this->idxReadHeader();

        if ($header === null) {
            return $this->closeAndFail(self::ERR_CORRUPT, 'open',
                'invalid header or wrong magic [' . $this->file . ']');
        }
        $isWrongVersion = ((int)$header['version'] !== JdbIndexHeader::VERSION);
        $isDirty = $header['dirty'];

        if ($isWrongVersion || $isDirty) {
            fclose($this->fp);
            $this->fp = null;
            return self::ERR_NEEDS_REBUILD;
        }

        $this->state = self::STATE_OPEN;
        return self::OK;
    }

    /**
     * Closes the file handle. Safe to call even if already closed.
     */
    public function close()
    {
        is_resource($this->fp) && fclose($this->fp);
        $this->fp = null;
        $this->state = self::STATE_CLOSED;
        $this->headerCache = null;
        $this->cachedPageId = -1;
        $this->cachedPage  = null;
    }

    /**
     * Sets dirty=1 in the on-disk header.
     * Call BEFORE fwrite() on the data file.
     * Sets internal state to STATE_WRITING.
     */
    public function beginWrite()
    {
        if ($this->state < self::STATE_OPEN) {
            return $this->fail(self::ERR_IO, 'beginWrite',
                'invalid state (state=' . $this->state . ')');
        }
        $success = (fseek($this->fp, JdbIndexHeader::HDR_DIRTY_BYTE) === 0) &&
                   (fwrite($this->fp, "\x01") === 1) &&
                   fflush($this->fp);
        if (!$success) {
            return $this->fail(self::ERR_IO, 'beginWrite', 'set dirty failed');
        }
        $this->state = self::STATE_WRITING;
        $this->headerCache && ($this->headerCache['dirty'] = 1);

        return self::OK;
    }

    public function commitWrite()
    {
        if ($this->state !== self::STATE_WRITING) {
            return $this->fail(self::ERR_IO, 'commitWrite',
                'invalid state (state=' . $this->state . ', expected STATE_WRITING)');
        }
        $success = (fseek($this->fp, JdbIndexHeader::HDR_DIRTY_BYTE) === 0) &&
                   (fwrite($this->fp, "\x00") === 1) &&
                   fflush($this->fp);
        if (!$success) {
            return $this->fail(self::ERR_IO, 'commitWrite', 'clear dirty failed');
        }
        $this->state = self::STATE_OPEN;
        $this->headerCache && ($this->headerCache['dirty'] = 0);

        return self::OK;
    }

    // =========================================================================
    // Creation and rebuild
    // =========================================================================

    /**
     * Creates a new empty index file with INIT_SLOTS slots.
     * Does not require the handle to be open: operates directly on the path.
     *
     * @param  int $nextId Next auto-increment ID
     * @param  int $slots   Initial slot count (optional)
     * @return int OK | ERR_IO
     */
    public function create($nextId = 1, $slots = self::INIT_SLOTS)
    {
        // Normalise to power of 2 to maintain expected behaviour
        $slots = JdbUtil::nextPowerOfTwo(max((int)$slots, self::INIT_SLOTS));

        $fp = fopen($this->file, 'wb');
        if (!is_resource($fp)) {
            return self::ERR_IO;
        }

        $success = $this->writeHeaderToFp($fp, $slots, 0, $nextId, 0) &&
                   JdbUtil::writeEmptySlots($fp, $slots, self::SLOT_SIZE);
        
        fclose($fp);
        return ($success) ? self::OK : self::ERR_IO;
    }

    /**
     * Rebuilds the index file from scratch using an array of entries.
     * Can be called when state is CLOSED (no prior open() required).
     * After rebuild() the handle remains closed: the caller must call open().
     *
     * @param  array $entries  id (string) => ['offset'=>int, 'length'=>int, 'version'=>int]
     * @param  int   $nextId
     * @return int   OK | ERR_IO
     */
    public function rebuild(array $entries, $nextId)
    {
        ($this->state !== self::STATE_CLOSED) && $this->close();

        $count = count($entries);
        $slots = JdbUtil::nextPowerOfTwo(max(self::INIT_SLOTS, $count * 2));

        $fp = fopen($this->file, 'w+b');
        if (!is_resource($fp)) {
            return self::ERR_IO;
        }

        // Initialise: header + empty slots
        $success = $this->writeHeaderToFp($fp, $slots, 0, $nextId, 0) &&
            JdbUtil::writeEmptySlots($fp, $slots, self::SLOT_SIZE);

        if (!$success) {
            fclose($fp);
            return self::ERR_IO;
        }

        // Insert all records without re-reading from disk: in-memory bitmap
        $occupied = str_repeat("\x00", $slots);
        $written = 0;

        foreach ($entries as $id => $r) {
            $id    = (string)$id;
            $hash = $this->getHash($id);
            $start = self::slotIndex($hash, $slots);

            for ($i = 0; $i < $slots; $i++) {
                $slotIdx = ($start + $i) % $slots;
                if ($occupied[$slotIdx] !== "\x00") {
                    continue;
                }
                $ret = $this->writeSlotToFp(
                    $fp, $slotIdx, self::SLOT_OCCUPIED, $hash,
                    $r['offset'], $r['length'], $r['version'], 0x00
                );
                if ($ret !== false) {
                    $occupied[$slotIdx] = "\x01";
                    $written++;
                }
                break;
            }
        }

        // Update the actual count in the header
        $success = $this->writeHeaderToFp($fp, $slots, $written, $nextId, 0);

        fclose($fp);
        return $success ? self::OK : self::ERR_IO;
    }

    // =========================================================================
    // Read / write API (require STATE_OPEN or STATE_WRITING)
    // =========================================================================

    public function lookupEx($id)
    {
        if (($this->state < self::STATE_OPEN) || ((string)$id === '')) {
            return null;
        }
        $header = $this->idxReadHeader();
        if ($header === null) {
            return null;
        }
        $probe = $this->idxProbe($header['slots'], $id);
        return ($probe && $probe['found'] && !($probe['entry']['flags'] & self::FLAG_DELETED)) ? $probe : null;
    }

    /**
     * Looks up an ID in the index. Returns null if not found or deleted.
     * Does not distinguish between "not found" and "found but deleted": in both
     * cases null signals effective absence to the caller.
     *
     * @param  string    $id
     * @return array|null ['offset'=>int, 'length'=>int, 'version'=>int] | null
     */
    public function lookup($id)
    {
        $probe = $this->lookupEx($id);
        return $probe ? array(
            'offset'  => $probe['entry']['offset'],
            'length'  => $probe['entry']['length'],
            'version' => $probe['entry']['version'],
        ) : null;
    }

    public function putEx($id, $offset, $length, $version = 1, $probe = null)
    {
        if (($this->state < self::STATE_OPEN) || ((string)$id === '')) {
            return $this->fail(self::ERR_STATE, 'put', 'invalid state or empty ID');
        }

        $header = $this->ensureCapacity();
        if ($header === null) {
            return $this->fail(self::ERR_CORRUPT, 'put',
                'idxReadHeader returned null (corrupt or unreadable header)');
        }
        return $this->doInsertOrUpdate($id, $offset, $length, $version, $header, $probe);
    }

    /**
     * Inserts or updates a slot in the index (upsert).
     *
     * Behaviour:
     *   - ID does not exist or is deleted → insert, count++
     *   - ID exists and is active         → update slot (offset/length/version), count unchanged
     *
     * Handles grow automatically if load factor ≥ LOAD_MAX.
     * FLAG_DELETED records are discarded during grow (implicit cleanup).
     *
     * @param  string $id
     * @param  int    $offset   Position in the data file
     * @param  int    $length   Row length in bytes
     * @param  int    $version  Record version
     * @return int    OK | ERR_IO | ERR_CORRUPT
     */
    public function put($id, $offset, $length, $version = 1)
    {
        return $this->putEx($id, $offset, $length, $version);
    }

    /**
     * Ensures the hash table has sufficient capacity for one more record.
     * If the load factor would exceed LOAD_MAX after insertion, triggers a grow.
     *
     * @return array|null  Current header (refreshed after grow if needed), or null on error
     */
    private function ensureCapacity()
    {
        $header = $this->idxReadHeader();
        if ($header === null) {
            return null;
        }

        if (($header['count'] + 1) / $header['slots'] < self::LOAD_MAX) {
            return $header;
        }

        // Grow needed
        $lf     = round(($header['count'] + 1) / $header['slots'], 3);
        $status = $this->idxGrow($header);
        if ($status !== self::OK) {
            JdbErrorHandler::push('JdbBinaryIndex', 'put',
                'grow needed (count=' . $header['count'] .
                ', slots=' . $header['slots'] . ', lf=' . $lf . ')');
            return null;
        }

        // Header changed after grow: re-read
        return $this->idxReadHeader();
    }

    /**
     * Performs the actual insert or update operation after capacity has been ensured.
     *
     * @param  string $id       Validated ID
     * @param  int    $offset
     * @param  int    $length
     * @param  int    $version
     * @param  array  $header   Current header (from ensureCapacity)
     * @return int    OK | ERR_IO
     */
    private function doInsertOrUpdate($id, $offset, $length, $version, array $header, $probe)
    {
        $slots = $header['slots'];

        if ($probe === null) {
            $probe = $this->idxProbe($slots, $id);
            if ($probe === null) {
                return $this->fail(self::ERR_IO, 'put',
                    'idxProbe returned null (table full or I/O error) [slots=' .
                    $slots . ', id=' . $id . ']');
            }
        }

        $found = $probe['found'];
        $slot = $probe['slot'];

        $isNew = !$found || ($probe['entry']['flags'] & self::FLAG_DELETED);
        $writeSlot = $found
            ? $slot
            : (($probe['first_tombstone'] !== null) ? $probe['first_tombstone'] : $slot);

        if ($this->idxWriteSlot($writeSlot, self::SLOT_OCCUPIED,
                $probe['id_hash'],
                $offset, $length, $version, 0x00) === false) {
            return $this->fail(self::ERR_IO, 'put',
                "idxWriteSlot failed [slot={$writeSlot}, offset={$offset}, length={$length}]");
        }
        $count = $header['count'] + 1;
        if ($isNew && ($this->idxWriteHeader($slots, $count, $header['next_id']) === false)) {
            return $this->fail(self::ERR_IO, 'put',
                    "idxWriteHeader post-insert failed [count={$count}]");
        }

        return self::OK;
    }

    /**
     * Marks a record as deleted (soft delete).
     * Sets FLAG_DELETED in the slot and decrements count in the header.
     * The slot remains SLOT_OCCUPIED to avoid breaking the probe chain.
     *
     * @param  string $id
     * @return int    OK | ERR_NOT_FOUND | ERR_IO | ERR_CORRUPT
     */
    public function remove($id)
    {
        if (($this->state < self::STATE_OPEN) || ((string)$id === '')) {
            return $this->fail(self::ERR_STATE, 'remove', 'invalid state or empty ID');
        }

        $header = $this->idxReadHeader();
        if ($header === null) {
            return $this->fail(self::ERR_CORRUPT, 'remove',
                'idxReadHeader returned null (corrupt header)');
        }

        $probe = $this->idxProbe($header['slots'], $id);
        if ($probe === null) {
            return $this->fail(self::ERR_IO, 'remove',
                'idxProbe returned null (I/O error or table full) [id=' . $id . ']');
        }
        if (!$probe['found'] || ($probe['entry']['flags'] & self::FLAG_DELETED)) {
            return self::ERR_NOT_FOUND;
        }

        $e = $probe['entry'];
        if ($this->idxWriteSlot($probe['slot'], self::SLOT_OCCUPIED,
                                $probe['id_hash'],
                                $e['offset'], $e['length'], $e['version'],
                                self::FLAG_DELETED) === false) {
            return $this->fail(self::ERR_IO, 'remove',
                'idxWriteSlot FLAG_DELETED failed [slot=' . $probe['slot'] . ', id=' . $id . ']');
        }

        if ($this->idxWriteHeader($header['slots'], $header['count'] - 1, $header['next_id']) === false) {
            return $this->fail(self::ERR_IO, 'remove',
                'idxWriteHeader post-delete failed [count=' . ($header['count'] - 1) . ']');
        }
        return self::OK;
    }

    /**
     * Iterates all active (non-deleted) slots by reading the index in chunks.
     *
     * Instead of loading the entire index file into a single blob (O(N) allocation),
     * reads CHUNK_SLOTS slots at a time (~4 KB per chunk) and calls $fn for
     * each active slot found.
     *
     * Memory: O(1) — only the current chunk (~4 KB) is ever in RAM.
     *
     * Default chunk is 512 slots:
     *   512 slots × 48 bytes/slot = 24,576 bytes ≈ 24 KB per fread
     *
     * $fn receives: ['id'=>string, 'offset'=>int, 'length'=>int, 'version'=>int]
     * If $fn returns false the scan stops (early exit).
     *
     * @param  callable $fn         Callback for each active slot
     * @param  int      $chunkSlots Slots per chunk (default 512 ≈ 24 KB)
     * @return int                  Number of active slots visited
     */
    public function scanChunked(callable $fn, $chunkSlots = 512)
    {
        if ($this->state < self::STATE_OPEN) {
            return 0;
        }
        $header = $this->idxReadHeader();
        if ($header === null) {
            return 0;
        }

        $totalSlots = (int)$header['slots'];
        $visited    = 0;

        if (fseek($this->fp, JdbIndexHeader::HDR_SIZE) !== 0) {
            return 0;
        }

        for ($base = 0; $base < $totalSlots; $base += $chunkSlots) {
            // Read a chunk: at most $chunkSlots slots, fewer if near end of file
            $slotsInChunk = min($chunkSlots, $totalSlots - $base);
            $chunk = fread($this->fp, $slotsInChunk * self::SLOT_SIZE);
            if ($chunk === false) {
                break;
            }
            $lchunk = strlen($chunk);
            if ($lchunk === 0) {
                break;
            }
            if (($lchunk % self::SLOT_SIZE) !== 0) {
                JdbErrorHandler::push('JdbBinaryIndex', 'scanChunked', 'Corrupt chunk at offset ' . $base);
                break;
            }
            $slotsRead = (int)($lchunk / self::SLOT_SIZE);

            for ($i = 0; $i < $slotsRead; $i++) {
                $raw = substr($chunk, $i * self::SLOT_SIZE, self::SLOT_SIZE);
                if (strlen($raw) < self::SLOT_SIZE) {
                    break 2; // truncated chunk : EOF
                }

                $e = unpack(self::SLOT_UNPACK, $raw);

                if ($e['used'] !== self::SLOT_OCCUPIED) {
                    continue; // empty slot or tombstone: skip
                }
                if ($e['flags'] & self::FLAG_DELETED) {
                    continue; // soft-deleted: skip
                }

                $visited++;
                $result = $fn(array(
                    'id_hash' => $e['id_hash'],
                    'offset'  => $e['offset'],
                    'length'  => $e['length'],
                    'version' => $e['version'],
                ));

                if ($result === false) {
                    return $visited; // early exit requested by the callback
                }
            }
        }

        return $visited;
    }

    /**
     * Compatibility: scan() now delegates to scanChunked() collecting results
     * into an array. Used internally by compact() and rebuildIndex().
     * For low-memory iteration prefer scanChunked() directly.
     *
     * @return array  Array of ['id_hash'=>string, 'offset'=>int, 'length'=>int, 'version'=>int]
     */
    public function scan()
    {
        $results = array();
        $this->scanChunked(function ($entry) use (&$results) {
            $results[] = $entry;
        });
        return $results;
    }

    /**
     * Number of active records (reads only the header: O(1)).
     *
     * @return int|false
     */
    public function count()
    {
        if ($this->state < self::STATE_OPEN) {
            return false;
        }
        $header = $this->idxReadHeader();
        return $header !== null ? (int)$header['count'] : false;
    }

    /**
     * Total number of slots in the hash table (reads only the header: O(1)).
     *
     * @return int|false
     */
    public function slots()
    {
        if ($this->state < self::STATE_OPEN) {
            return false;
        }
        $header = $this->idxReadHeader();
        return $header !== null ? (int)$header['slots'] : false;
    }

    /**
     * Next auto-increment ID (reads only the header: O(1)).
     *
     * @return int|false
     */
    public function nextId()
    {
        if ($this->state < self::STATE_OPEN) {
            return false;
        }
        $header = $this->idxReadHeader();
        return $header !== null ? (int)$header['next_id'] : false;
    }

    /**
     * Increments next_id in the header. Call after a successful insert().
     */
    public function bumpNextId()
    {
        if ($this->state < self::STATE_OPEN) {
            return $this->fail(self::ERR_STATE, 'bumpNextId', "invalid state (state={$this->state})");
        }

        $header = $this->idxReadHeader();
        if ($header === null) {
            return $this->fail(self::ERR_IO, 'bumpNextId', 'idxReadHeader returned null');
        }

        $incrNextId = $header['next_id'] + 1;
        if ($this->idxWriteHeader($header['slots'], $header['count'], $incrNextId) === false) {
            return $this->fail(self::ERR_IO, 'bumpNextId', "idxWriteHeader failed [next_id={$incrNextId}]");
        }

        return self::OK;
    }

    // =========================================================================
    // Private methods: header
    // =========================================================================

    /**
     * Reads and decodes the header from $this->fp.
     * Cache-aside: returns the cache if valid, otherwise reads from disk
     * and populates the cache for subsequent reads in the same transaction.
     *
     * @return array|null
     */
    private function idxReadHeader()
    {
        if ($this->headerCache !== null) {
            return $this->headerCache; // cache hit: zero I/O
        }

        // Cache miss: delegate to JdbIndexHeader (single implementation)
        $h = JdbIndexHeader::read($this->fp);
        $this->headerCache = $h;
        return $h;
    }

    /**
     * Writes the header (32 bytes) to $this->fp and updates the cache (write-through).
     * The dirty bit is derived from the internal state.
     *
     * @param int $slots
     * @param int $count
     * @param int $nextId
     */
    private function idxWriteHeader($slots, $count, $nextId)
    {
        $dirty = ($this->state === self::STATE_WRITING) ? 1 : 0;
        if (JdbIndexHeader::write($this->fp, $slots, $count, $nextId, $dirty) === false) {
            return false;
        }

        // Write-through: update the cache to avoid re-reading from disk
        $this->headerCache = array(
            'magic'   => JdbIndexHeader::MAGIC,
            'version' => JdbIndexHeader::VERSION,
            'slots'   => $slots,
            'count'   => $count,
            'next_id' => $nextId,
            'dirty'   => $dirty,
        );
        return true;
    }

    /**
     * Writes the header to an external handle (used in grow and rebuild).
     * The dirty bit is passed explicitly.
     *
     * @param resource $fp
     * @param int      $slots
     * @param int      $count
     * @param int      $nextId
     * @param int      $dirty  0 or 1
     */
    private function writeHeaderToFp($fp, $slots, $count, $nextId, $dirty)
    {
        return JdbIndexHeader::write($fp, $slots, $count, $nextId, $dirty);
    }

    // =========================================================================
    // Private methods: slot
    // =========================================================================

    /**
     * Encodes and writes a slot to $this->fp.
     */
    private function idxWriteSlot($slotIdx, $used, $idHash, $offset, $length, $version, $flags)
    {
        $ret = $this->writeSlotToFp($this->fp, $slotIdx, $used, $idHash, $offset, $length, $version, $flags);
        if ($ret) {
            $this->invalidatePageCache($slotIdx);
        }
        return $ret;
    }

    /**
     * Encodes and writes a slot to an external handle (used in grow and rebuild).
     */
    private function writeSlotToFp($fp, $slotIdx, $used, $idHash, $offset, $length, $version, $flags)
    {
        $ssize = self::SLOT_SIZE;
        $payload = pack(self::SLOT_PACK, $used, $idHash, $offset, $length, $version, $flags);
        $crc = JdbUtil::crc32u($payload);
        return (fseek($fp, JdbIndexHeader::HDR_SIZE + ($slotIdx * $ssize)) === 0) &&
               (fwrite($fp, $payload . pack('V', $crc)) === $ssize);
    }

    // =========================================================================
    // Private methods: hash and probe
    // =========================================================================

    /**
     * Finds the slot corresponding to $id using linear probing on $this->fp.
     *
     * Per-slot cost is kept minimal:
     *   • No method calls inside the loop (idxInspectRawSlot is fully inlined).
     *   • No substr() allocation per slot: substr_compare() operates directly on
     *     the page buffer to check the hash before any unpack() or CRC.
     *   • No intermediate $ret array per slot.
     *   • unpack() + CRC only on hash match (hot path stays allocation-free for
     *     every non-matching slot, which is the common case).
     *
     * Page layout (SLOT_SIZE = 48 bytes):
     *   byte [0]      used flag   (SLOT_EMPTY=0, SLOT_OCCUPIED=1, SLOT_TOMBSTONE=2)
     *   bytes [1..32] id_hash     (SHA-256, 32 bytes)
     *   bytes [33..44] payload    (offset, length, version, flags — packed)
     *   bytes [44..47] CRC32      (unsigned 32-bit LE over bytes [0..43])
     *
     * Bug fixed vs. previous version: the old code combined an outer for-loop
     * increment ($i++) with an inner $i++ per slot, causing one slot to be
     * skipped at every page boundary. The outer loop is now a while-loop and
     * $i is incremented only once per slot, inside the inner for-loop.
     *
     * Page-boundary guard: each fread is limited to the slots remaining before
     * the physical end of the table, preventing a read from spanning the circular
     * wrap-around boundary. Without this guard, $slotIdxPhys + $s could exceed
     * $numSlots and produce a wrong 'slot' value in the return array.
     *
     * Returns:
     *   'found'           => bool     – true if the ID was found (and not deleted)
     *   'slot'            => int      – slot index available for insert, or the
     *                                   slot occupied by the found ID
     *   'entry'           => array|null – unpacked slot data (null if found=false)
     *   'first_tombstone' => int|null – first SLOT_TOMBSTONE index, for re-use on insert
     *   'id_hash'         => string   – raw binary hash (reused by the caller)
     *
     * @param  int    $numSlots
     * @param  string $id
     * @return array|null  null on I/O error or corrupt CRC; should never be null
     *                     in a healthy table with load factor < LOAD_MAX
     */
    private function idxProbe($numSlots, $id)
    {
        $idHash = $this->getHash($id);
        $start = self::slotIndex($idHash, $numSlots);
        $fistTomb = null;
        $i = 0;
        $firstTime = true;

        while ($i < $numSlots) {
            // Physical slot index in the file (linear, no wrap inside a single page).
            $slotIdxPhys = ($start + $i) % $numSlots;

            $page = $this->getPageCache($slotIdxPhys, $firstTime);
            if ($page === null) {
                JdbErrorHandler::push('JdbBinaryIndex', 'idxProbe', 'null page cache');
                return null;
            }

            $firstTime = false;
            $slotInPage = $slotIdxPhys & self::PAGE_SLOT_MASK;
            $plen = strlen($page);

            $slotsToProcess = min(
                (int)($plen / self::SLOT_SIZE) - $slotInPage,
                $numSlots - $slotIdxPhys,
                $numSlots - $i
            );

            if ($slotsToProcess <= 0) {
                JdbErrorHandler::push('JdbBinaryIndex', 'idxProbe', "slotsToProcess <= 0 [slot_idx={$slotIdxPhys}, i={$i}]");
                return null;
            }

            if ($plen <= (($slotInPage + $slotsToProcess - 1) * self::SLOT_SIZE)) {
                JdbErrorHandler::push('JdbBinaryIndex', 'idxProbe', "page shorter than required offset");
                return null;
            }

            $crcResidue = JdbUtil::CRC32_RESIDUE;
            $crcFunc = 'JdbUtil::crc32u';

            // ── Inner loop: no method calls, no per-slot string allocations ────────
            for ($s = 0; $s < $slotsToProcess; $s++, $i++) {
                $off  = ($slotInPage + $s) * self::SLOT_SIZE;

                $flag = $page[$off];
                // ── SLOT_EMPTY (0) or SLOT_TOMBSTONE (2) ─────────────────────────
                if (($flag | self::SLOT_TOMBSTONE_CHAR) === self::SLOT_TOMBSTONE_CHAR) {
                    if ($flag === self::SLOT_EMPTY_CHAR) {
                        // Empty slot terminates the probe: ID not present.
                        return array(
                            'found'           => false,
                            'slot'            => $slotIdxPhys + $s,
                            'entry'           => null,
                            'first_tombstone' => $fistTomb,
                            'id_hash'         => $idHash,
                        );
                    }
                    // SLOT_TOMBSTONE: soft-deleted, keep probing; remember for re-use.
                    if ($fistTomb === null) {
                        $fistTomb = $slotIdxPhys + $s;
                    }
                    continue;
                }

                // ── SLOT_OCCUPIED: check hash first (no allocation) ───────────────
                // substr_compare operates directly on $page; no substr() copy made.
                if (substr_compare($page, $idHash, $off + 1, 32) !== 0) {
                    continue; // hash mismatch — skip unpack() and CRC entirely
                }

                // ── Hash match: verify CRC (unpack only here) ─────────────────────
                $slot = substr($page, $off, self::SLOT_SIZE);
                if ($crcFunc($slot) !== $crcResidue) {
                    JdbErrorHandler::push('JdbBinaryIndex', 'idxProbe', "CRC32 mismatch [slot={($slotIdxPhys + $s)}]");
                    return null;
                }
                
                return array(
                    'found'           => true,
                    'slot'            => $slotIdxPhys + $s,
                    'entry'           => unpack(self::SLOT_UNPACK, $slot),
                    'first_tombstone' => $fistTomb,
                    'id_hash'         => $idHash,
                );
            }
            // ── End of page: continue outer loop with updated $i ──────────────────
        }

        JdbErrorHandler::push('JdbBinaryIndex', 'idxProbe', 'table full');
        return null; // table full — should never happen with controlled load factor
    }

    // =========================================================================
    // Private methods: grow
    // =========================================================================

    /**
     * Doubles the number of hash table slots.
     *
     * Strategy:
     *   1. Create a temporary file with 2× the slots
     *   2. Re-insert all active slots (FLAG_DELETED discarded = implicit cleanup)
     *   3. Preserve the dirty bit from the current state
     *   4. Close $this->fp, rename the temp file, reopen $this->fp
     *
     * @param  array $oldHeader Current header (already read by the caller)
     * @return int   OK | ERR_IO
     */
    private function idxGrow($oldHeader)
    {
        $newFp = $this->prepareGrowth($oldHeader, $newSlots, $dirty);
        if ($newFp === false) {
            return self::ERR_IO;
        }

        $active = $this->rehashAllSlots($oldHeader, $newFp, $newSlots, $dirty);
        if ($active === false) {
            fclose($newFp);
            return self::ERR_IO;
        }

        return $this->finalizeGrowth($newFp);
    }

    /**
     * Prepares the growth operation: allocates the temp file and writes the initial header.
     *
     * @param  array $oldHeader
     * @param  int  &$newSlots  Output: number of slots in the new file
     * @param  int  &$dirty      Output: dirty bit value to preserve
     * @return resource|false    Handle to the temp file, or false on error
     */
    private function prepareGrowth(array $oldHeader, &$newSlots, &$dirty)
    {
        $newSlots = $oldHeader['slots'] * 2;
        $dirty     = ($this->state === self::STATE_WRITING) ? 1 : 0;
        $temp      = $this->file . '.tmp';

        $newFp = fopen($temp, 'w+b');
        if (!is_resource($newFp)) {
            $this->fail(self::ERR_IO, 'idxGrow', "open {$temp} failed");
            return false;
        }

        $success = $this->writeHeaderToFp($newFp, $newSlots, 0, $oldHeader['next_id'], $dirty) &&
                   JdbUtil::writeEmptySlots($newFp, $newSlots, self::SLOT_SIZE);
        if (!$success) {
            $this->fail(self::ERR_IO, 'idxGrow', "write failed [new_slots={$newSlots}]");
            return false;
        }

        return $newFp;
    }

    /**
     * Rehashes all active slots from the old index into the new (larger) file.
     *
     * Reads the old file in chunks (to limit RAM usage) and inserts each active,
     * non-deleted slot into the new file using linear probing.
     *
     * @param  array    $oldHeader
     * @param  resource $newFp
     * @param  int      $newSlots
     * @param  int      $dirty
     * @return int|false  Number of active slots rehashed, or false on fatal error
     */
    private function rehashAllSlots(array $oldHeader, $newFp, $newSlots, $dirty)
    {
        $occupied = str_repeat("\x00", $newSlots);
        $active   = 0;
        $writeFailCount = 0;
        $chunkSlots = 512;

        if (fseek($this->fp, JdbIndexHeader::HDR_SIZE) !== 0) {
            $this->fail(self::ERR_IO, 'idxGrow',
                'fseek HDR_SIZE on source file failed');
            return false;
        }

        for ($base = 0; $base < $oldHeader['slots']; $base += $chunkSlots) {
            $slotsInChunk = min($chunkSlots, $oldHeader['slots'] - $base);
            $chunk = fread($this->fp, $slotsInChunk * self::SLOT_SIZE);
            if ($chunk === false || strlen($chunk) === 0) {
                break;
            }

            $slotsRead = (int)(strlen($chunk) / self::SLOT_SIZE);
            for ($i = 0; $i < $slotsRead; $i++) {
                $raw = substr($chunk, $i * self::SLOT_SIZE, self::SLOT_SIZE);
                if (strlen($raw) < self::SLOT_SIZE) {
                    break 2;
                }

                $e = unpack(self::SLOT_UNPACK, $raw);
                if (($e['used'] !== self::SLOT_OCCUPIED) || ($e['flags'] & self::FLAG_DELETED)) {
                    continue;
                }

                $rehashResult = $this->rehashSlotInto($newFp, $newSlots, $occupied, $e);
                if ($rehashResult === true) {
                    $active++;
                } else {
                    $writeFailCount++;
                }
            }
        }

        ($writeFailCount > 0) && JdbErrorHandler::push('JdbBinaryIndex', 'idxGrow',
            "writeSlotToFp non-fatal failures: {$writeFailCount} slots lost (active={$active}/{$oldHeader['count']})");

        return $this->writeHeaderToFp($newFp, $newSlots, $active, $oldHeader['next_id'], $dirty) ?
            $active :
            $this->fail(self::ERR_IO, 'idxGrow', "writeHeaderToFp final write failed [active={$active}]");
    }

    /**
     * Rehashes a single slot into the new (larger) index file.
     *
     * Uses linear probing starting from the hash-derived slot index.
     * Updates the $occupied bitmap on success.
     *
     * @param  resource $newFp
     * @param  int      $newSlots
     * @param  string  &$occupied   Bitmap of occupied slots (modified in place)
     * @param  array    $e          Unpacked slot data from the old file
     * @return bool                 true on success, false if no free slot found or write failed
     */
    private function rehashSlotInto($newFp, $newSlots, &$occupied, array $e)
    {
        $hash  = $e['id_hash'];
        $start = self::slotIndex($hash, $newSlots);

        for ($j = 0; $j < $newSlots; $j++) {
            $slotIdx = ($start + $j) % $newSlots;
            if ($occupied[$slotIdx] !== "\x00") {
                continue;
            }

            $ret = $this->writeSlotToFp(
                $newFp, $slotIdx, self::SLOT_OCCUPIED, $hash,
                $e['offset'], $e['length'], $e['version'], $e['flags']
            );

            if ($ret !== false) {
                $occupied[$slotIdx] = "\x01";
                return true;
            }
            return false;
        }
        return false;
    }

    /**
     * Finalizes the growth operation: closes handles, atomically swaps the temp file,
     * and reopens the index handle.
     *
     * @param  resource $newFp
     * @return int      OK | ERR_IO
     */
    private function finalizeGrowth($newFp)
    {
        fclose($newFp);
        fclose($this->fp);
        $this->fp = null;
        $this->headerCache = null;
        $this->cachedPageId = -1;
        $this->cachedPage = null;

        $err = null;
        if (!JdbUtil::safeOverwrite($this->file . '.tmp', $this->file, $err)) {
            $this->state = self::STATE_CLOSED;
            return $this->fail(self::ERR_IO, 'idxGrow', $err);
        }

        $this->fp = fopen($this->file, 'r+b');
        if (!is_resource($this->fp)) {
            $this->state = self::STATE_CLOSED;
            return $this->fail(self::ERR_IO, 'idxGrow',
                'fopen post-rename (' . $this->file . ', r+b) failed');
        }

        return self::OK;
    }

    // =========================================================================
    // Static helpers: serialisation to an external handle
    //
    // Centralise the binary format (header + slot) for any code that needs
    // to build an index file without going through open()/put().
    // Used by JsonDatabase::compact() and JsonDatabase::rebuildIndex()
    // to avoid duplicating the private serialisation methods.
    // =========================================================================

    /**
     * Writes the binary header (HDR_SIZE bytes) to an external handle.
     * Delegates to JdbIndexHeader::write() – single shared implementation.
     *
     * @param resource $fp
     * @param int      $slots
     * @param int      $count
     * @param int      $nextId
     * @param int      $dirty   0 = clean, 1 = write in progress
     */
    public static function writeHeader($fp, $slots, $count, $nextId, $dirty)
    {
        return JdbIndexHeader::write($fp, $slots, $count, $nextId, $dirty);
    }

    /**
     * Initialises an empty index file on an already-open handle:
     * writes the header and $slots zeroed slots.
     *
     * @param resource $fp
     * @param int      $slots
     * @param int      $nextId
     */
    public static function initFile($fp, $slots, $nextId)
    {
        return self::writeHeader($fp, $slots, 0, $nextId, 0) && 
               JdbUtil::writeEmptySlots($fp, $slots, self::SLOT_SIZE);
    }

    /**
     * Writes a slot to an external handle.
     *
     * @param resource $fp
     * @param int      $slotIdx
     * @param string   $idHash  32 raw bytes sha256
     * @param int      $offset
     * @param int      $length
     * @param int      $version
     * @param int      $flags
     */
    public static function writeSlot($fp, $slotIdx, $idHash, $offset, $length, $version, $flags)
    {
        $payload = pack(
            self::SLOT_PACK, self::SLOT_OCCUPIED, $idHash, 
            (int)$offset, (int)$length, (int)$version, (int)$flags
        );
        $crc = JdbUtil::crc32u($payload);
        $raw = $payload . pack('V', $crc);
        return (fseek($fp, JdbIndexHeader::HDR_SIZE + $slotIdx * self::SLOT_SIZE) === 0) &&
               fwrite($fp, $raw) === self::SLOT_SIZE;
    }

    /**
     * Reads and decodes a slot from an external handle.
     *
     * @param  resource $fp
     * @param  int      $slotIdx
     * @return array|null
     */
    public static function readSlot($fp, $slotIdx)
    {
        if (fseek($fp, JdbIndexHeader::HDR_SIZE + $slotIdx * self::SLOT_SIZE) !== 0) {
            return null;
        }
        $raw = fread($fp, self::SLOT_SIZE);
        if ($raw === false || strlen($raw) < self::SLOT_SIZE) {
            return null;
        }
        return unpack(self::SLOT_UNPACK, $raw);
    }

    /**
     * Computes the slot index for a raw hash (XOR-fold of the first 8 bytes).
     * Works for any hash length ≥ 8 bytes; with SHA-256 the first 8 bytes
     * already provide excellent distribution.
     *
     * @param  string $hash   Raw binary hash (≥ 8 bytes)
     * @param  int    $slots  Always a power of 2
     * @return int
     */
    public static function slotIndex($hash, $slots)
    {
        // because the sha is at the beginning we can avoid the substr
        $int64 = unpack('V2', $hash);
        return (($int64[1] ^ $int64[2]) & 0x7FFFFFFF) & ($slots - 1);
    }

    /**
     * Advances next_id by $n in a single write (more efficient than N calls to bumpNextId).
     */
    public function advanceNextId($n)
    {
        $n = (int)$n;
        if ($n <= 0) {
            return self::OK;
        }
        if ($this->state < self::STATE_OPEN) {
            return $this->fail(self::ERR_STATE, 'advanceNextId', 'invalid state (state=' . $this->state . ')');
        }
        $header = $this->idxReadHeader();
        if ($header === null) {
            return $this->fail(self::ERR_IO, 'advanceNextId', 'idxReadHeader returned null');
        }
        if ($this->idxWriteHeader($header['slots'], $header['count'], $header['next_id'] + $n) === false) {
            return $this->fail(self::ERR_IO, 'advanceNextId', 'idxWriteHeader failed [next_id=' . ($header['next_id'] + $n) . ']');
        }
        return self::OK;
    }

    /**
     * Inserts a record into a temporary index file pointed to by $fp.
     * Uses the $occupied bitmap to know which slots are free without fread.
     *
     * @param resource $fp
     * @param string  &$occupied  Bitmap string (1 byte per slot)
     * @param int      $slots
     * @param string   $idHash   32 raw bytes (SHA-256 of the ID)
     * @param int      $offset
     * @param int      $length
     * @param int      $version
     * @param int      $flags
     * @param int     &$count     Incremented on successful new insert
     */
    public static function tempUpsert($fp, &$occupied, $slots, $idHash, $offset, $length, $version, $flags, &$count)
    {
        $start = self::slotIndex($idHash, $slots);
        $fistTomb = null;
        for ($i = 0; $i < $slots; $i++) {
            $slotIdx = ($start + $i) % $slots;
            if ($occupied[$slotIdx] === "\x00") {
                $writeIdx = ($fistTomb !== null) ? $fistTomb : $slotIdx;
                $ret = self::writeSlot($fp, $writeIdx, $idHash, $offset, $length, $version, $flags);
                if ($ret !== false) {
                    $occupied[$writeIdx] = "\x01";
                    $count++;
                }
                return $ret;
            }
            $e = self::readSlot($fp, $slotIdx);
            if ($e === null) {
                return false;
            }
            if ($e['used'] === self::SLOT_TOMBSTONE) {
                if ($fistTomb === null) {
                    $fistTomb = $slotIdx;
                }
                continue;
            }
            if ($e['used'] === self::SLOT_OCCUPIED && $e['id_hash'] === $idHash) {
                return self::writeSlot($fp, $slotIdx, $idHash, $offset, $length, $version, $flags);
            }
        }
        return false;
    }

    /**
     * Marks a record as deleted in a temporary index file.
     */
    public static function tempDelete($fp, &$occupied, $slots, $idHash, &$count)
    {
        $start = self::slotIndex($idHash, $slots);
        for ($i = 0; $i < $slots; $i++) {
            $slotIdx = ($start + $i) % $slots;
            if ($occupied[$slotIdx] === "\x00") {
                return true; // not in index - nothing to delete
            }
            $e = self::readSlot($fp, $slotIdx);
            if ($e === null) {
                return false;
            }
            if ($e['used'] === self::SLOT_TOMBSTONE) {
                continue;
            }
            if ($e['used'] === self::SLOT_OCCUPIED && $e['id_hash'] === $idHash) {
                if (($e['flags'] & self::FLAG_DELETED) === 0) {
                    $count--;
                }
                return self::writeSlot(
                    $fp, $slotIdx, $idHash,
                    $e['offset'], $e['length'], $e['version'],
                    self::FLAG_DELETED
                );
            }
        }
        return true;
    }

    private function getHash($id) {
        if (!isset($this->hashCache[$id])) {
            $this->hashCache[$id] = hash('sha256', $id, true);
            (count($this->hashCache) > 10000) && array_shift($this->hashCache);
        }
        return $this->hashCache[$id];
    }

    private function getPageCache($slotIdx, $firstTime)
    {
        $oldPageId = $this->cachedPageId;
        $pageId = $slotIdx >> self::SLOTS_PER_PAGE_SHIFT;

        if ($oldPageId === $pageId) {
            $cachedPage = $this->cachedPage;
            if ($cachedPage) {
                return $cachedPage;
            }
        }

        // Cache miss read slot page
        $fp = $this->fp;
        if ($firstTime || (($oldPageId + 1) !== $pageId)) {
            if (fseek($fp, JdbIndexHeader::HDR_SIZE + ($pageId * self::PAGE_BYTES)) !== 0) {
                return null;
            }
        }
        $page = fread($fp, self::PAGE_BYTES);
        if ($page === false) {
            return null;
        }
        // Update the single-page cache
        $this->cachedPageId     = $pageId;
        $this->cachedPage       = $page;

        return $page;
    }

    private function invalidatePageCache($slotIdx)
    {
        $pageId = $slotIdx >> self::SLOTS_PER_PAGE_SHIFT;

        if ($this->cachedPageId === $pageId) {
            $this->cachedPageId = -1;
            $this->cachedPage   = null;
        }
    }
}

