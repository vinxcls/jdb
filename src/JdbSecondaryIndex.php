<?php
require_once __DIR__ . "/JdbErrorHandler.php";
require_once __DIR__ . "/JdbUtil.php";
require_once __DIR__ . "/JdbRealpathCache.php";
require_once __DIR__ . "/JdbIndexHeader.php";
require_once __DIR__ . "/JdbLock.php";
require_once __DIR__ . "/JdbBinaryIndex.php";

/**
 * @class JdbSecondaryIndex
 * @brief On-disk sorted secondary index for range queries on arbitrary fields.
 *
 * Each instance covers a single field of JsonDatabase.
 * The file is a dense, sorted slot array (no tombstones, no empty slots),
 * fully rebuilt whenever the dirty bit is detected.
 *
 * Lifecycle:
 *   - Every write on JsonDatabase (insert/update/delete/compact) → markDirty()
 *   - Before every range query                                   → rebuild() if isDirty()
 *   - Explicit rebuild callable via rebuildSecondaryIndexes()    → rebuild()
 *
 * Header format (32 bytes): identical to the primary JdbBinaryIndex (magic 'JDB1').
 *
 * Slot format (136 bytes, PHP 5.5+ compatible):
 *   Bytes   0–127   a128  value_sort  sortable key, null-padded
 *   Bytes 128–131   V     offset      position in the data file (uint32 LE)
 *   Bytes 132–135   V     length      row length in bytes (uint32 LE)
 *
 * Note: the record ID is used as a tiebreak key during sorting only and is NOT
 * stored in the binary slot. Total slot size is therefore 136 bytes (not 200).
 *
 * value_sort encoding schema (at most 128 bytes):
 *   'I' + sprintf('%019d', int_value  + 10^15)   ← integers (range ±10^15)
 *   'F' + sprintf('%019d', float×1000 + 10^15)   ← floats   (3 decimals, range ±10^12)
 *   'S' + raw_string (max 119 chars)              ← strings  (lexicographic order)
 *   ASCII prefixes: F(70) < I(73) < S(83) → float < int < string
 *
 * Memory: O(N) only during rebuild() (for the sort). No data in RAM at steady state.
 * Range lookup: O(log N) binary search + O(K) sequential scan.
 *
 * Compatibility: PHP 5.5+
 */
class JdbSecondaryIndex
{
    // =========================================================================
    // Secondary index format constants
    // =========================================================================

    /** @var int Total size of one binary slot in bytes (a128 + uint32 + uint32). */
    const SLOT_SIZE      = 136;

    /** @var string pack() format string for writing a slot. */
    const SLOT_PACK      = 'a128VV';

    /** @var string unpack() format string for reading a slot. */
    const SLOT_UNPACK    = 'a128value_sort/Voffset/Vlength';

    /** @var int Length in bytes of the value_sort field within a slot. */
    const VALUE_SORT_LEN = 128;

    /** @var int Maximum number of characters stored for string sort keys (1 byte prefix + 119 chars). */
    const VALUE_STR_MAX  = 119;

    /** @var int Bias added to integer/float values before encoding to ensure lexicographic ordering. */
    const INT_BIAS       = 1000000000000000;

    /** @var int log2 of SLOTS_PER_PAGE; used for fast page index calculation via right-shift. */
    const SLOTS_PER_PAGE_SHIFT = 6;

    /** @var int Number of slots loaded in a single page read during range queries. */
    const SLOTS_PER_PAGE = 64;

    /** @var int Total byte size of one page (136 * 64 = 8704 bytes). */
    const PAGE_BYTES = self::SLOT_SIZE * self::SLOTS_PER_PAGE;

    // =========================================================================
    // Internal state
    // =========================================================================

    /**
     * @var string Absolute path to the binary index file on disk.
     */
    private $file;

    /**
     * @var string Name of the JSON field this index covers.
     */
    private $field;

    /**
     * @var JdbLock|null Lock instance used to protect chunk file creation.
     *                   Lazily initialised by getLock() if not set externally.
     */
    private $lck = null;

    /**
     * @var int Number of entries per sort chunk during rebuild.
     *          Can be tuned via setSortChunkSize().
     */
    public static $sortChunkSize = 2048;

    /**
     * @var int Page ID currently held in $cachedPage, or -1 when the cache is empty.
     */
    private $cachedPageId = -1;

    /**
     * @var string|null Raw bytes of the currently cached index page, or null.
     */
    private $cachedPage = null;

    // =========================================================================
    // Constructor
    // =========================================================================

    /**
     * @brief Initialises a secondary index instance for the given file and field.
     *
     * @param string $file  Path to the binary index file (need not exist yet).
     * @param string $field Name of the JSON field this index covers.
     */
    public function __construct($file, $field)
    {
        $this->file  = $file;
        $this->field = $field;
    }

    /**
     * @brief Injects an external JdbLock instance to use for chunk-file locking.
     *
     * If not called, a default flock-based lock with a 500 ms timeout is created
     * lazily on first use.
     *
     * @param JdbLock $lock Lock instance to use.
     * @return void
     */
    public function setLock(JdbLock $lock)
    {
        $this->lck = $lock;
    }

    /**
     * @brief Returns the path to the binary index file.
     *
     * @return string
     */
    public function getFile()  { return $this->file; }

    /**
     * @brief Returns the name of the JSON field this index covers.
     *
     * @return string
     */
    public function getField() { return $this->field; }

    // =========================================================================
    // Dirty bit
    // =========================================================================

    /**
     * @brief Returns true if the index is marked dirty and needs rebuilding.
     *
     * @return bool
     */
    public function isDirty()
    {
        return JdbIndexHeader::readDirtyBit($this->file);
    }

    /**
     * @brief Marks the index as dirty, triggering a rebuild before the next query.
     *
     * Should be called after every insert, update, delete, or compact operation
     * on the parent JsonDatabase.
     *
     * @return bool True on success, false on failure.
     */
    public function markDirty()
    {
        return JdbIndexHeader::writeDirtyBit($this->file, 1);
    }

    /**
     * @brief Creates the index file with the dirty bit set if it does not already exist.
     *
     * @return void
     */
    public function createIfMissing()
    {
        JdbIndexHeader::createIfMissing($this->file, 0, 1);
    }

    /**
     * @brief Sets the number of entries grouped into each sort chunk during rebuild.
     *
     * Smaller values reduce peak memory usage; larger values reduce I/O overhead.
     * Valid range: 1 – 100 000.
     *
     * @param int $size Desired chunk size.
     * @return bool True on success, false if $size is out of range.
     */
    public static function setSortChunkSize($size)
    {
        if (!is_int($size) || $size <= 0 || $size > 100000) {
            JdbErrorHandler::push('JdbSecondaryIndex', 'setSortChunkSize',
                'Invalid size: must be 1-100000, got ' . $size);
            return false;
        }
        self::$sortChunkSize = (int)$size;
        return true;
    }

    /**
     * @brief Returns the current sort chunk size used during rebuild.
     *
     * @return int
     */
    public static function getSortChunkSize()
    {
        return self::$sortChunkSize;
    }

    // =========================================================================
    // Rebuild – refactored into phases
    // =========================================================================

    /**
     * @brief Fully rebuilds the binary index from the data file.
     *
     * The rebuild proceeds in three phases:
     *   - Phase 0: scan the data file and collect active entries (last-write-wins).
     *   - Phase 1: split entries into sorted temporary chunk files.
     *   - Phase 2: k-way merge all chunk files into the final index file.
     *
     * On success the dirty bit is cleared. On failure the index file is left
     * unchanged and an error is pushed to JdbErrorHandler.
     *
     * @param string $dataFile       Path to the NDJSON data file.
     * @param int    $dataHeaderSize Number of bytes to skip at the start of the data file.
     * @return bool True on success, false on failure.
     */
    public function rebuild($dataFile, $dataHeaderSize)
    {
        $realPath = JdbRealpathCache::get($dataFile);
        if ($realPath === false) {
            JdbErrorHandler::push('JdbSecondaryIndex', 'rebuild',
                "Path resolution failed for: $dataFile");
            return false;
        }

        $fp = fopen($realPath, 'rb');
        if (!is_resource($fp)) {
            JdbErrorHandler::push('JdbSecondaryIndex', 'rebuild',
                'fopen(' . $dataFile . ', rb) failed [field=' . $this->field . ']');
            return false;
        }

        // Phase 0: collect active entries
        $active = $this->collectActiveEntries($fp, $dataHeaderSize);
        fclose($fp);

        $totalRecords = count($active);

        // Fast-path: empty index
        if ($totalRecords === 0) {
            return $this->writeEmptyIndex();
        }

        // Phase 1: write sorted chunk files
        $chunkFiles = $this->writeChunkFiles($active);
        if ($chunkFiles === false) {
            return false;
        }
        
        unset($active);
        
        // Phase 2: k-way merge
        return $this->mergeChunkFiles($chunkFiles, $totalRecords);
    }
    
    // =========================================================================
    // Range query – refactored into phases
    // =========================================================================

    /**
     * @brief Executes a range query over the secondary index.
     *
     * Performs a binary search to locate the first slot whose sort key is >= $min,
     * then scans forward, reading slots page-by-page until the sort key exceeds $max,
     * the limit is reached, or $fn returns false.
     *
     * Returns -1 if the index is dirty or the callback is invalid.
     * Returns 0 if the index is empty or no records fall within the range.
     * Returns the count of matched records on success.
     *
     * @param resource      $dataFp Open readable handle to the NDJSON data file.
     * @param mixed|null    $min    Lower bound (inclusive), or null for no lower bound.
     * @param mixed|null    $max    Upper bound (inclusive), or null for no upper bound.
     * @param callable      $fn     Callback invoked with each matched record array.
     *                              Return false to stop iteration early.
     * @param int           $limit  Maximum number of records to return; 0 = unlimited.
     * @return int Number of matched records, or -1 on error.
     */
    public function rangeQuery($dataFp, $min, $max, callable $fn, $limit = 0)
    {
        if (!is_callable($fn)) {
            JdbErrorHandler::push('JdbSecondaryIndex', 'rangeQuery', 'Invalid callback type');
            return -1;
        }

        if ($this->isDirty()) {
            return -1;
        }

        $result = $this->validateAndOpenIndex();
        if ($result === false) {
            return 0;
        }

        list($fp, $h) = $result;

        $slots = (int)$h['slots'];
        if ($slots === 0) {
            fclose($fp);
            return 0;
        }

        $minKeyPad = ($min !== null)
            ? str_pad(self::toSortableKey($min), self::VALUE_SORT_LEN, "\x00")
            : null;
        $maxKeyPad = ($max !== null)
            ? str_pad(self::toSortableKey($max), self::VALUE_SORT_LEN, "\x00")
            : null;

        $start = ($minKeyPad !== null)
            ? $this->lowerBound($fp, $slots, $minKeyPad)
            : 0;

        $matched = 0;
        $maxAllowed = JdbUtil::MAX_RECORD_BYTES;
        $dataFileSize = JdbUtil::getFileSize($dataFp);
        if ($dataFileSize === false) {
            $dataFileSize = -1;
        }

        // Reset page cache before starting the scan
        $this->cachedPageId = -1;
        $this->cachedPage = null;

        $currentSlot = $start;
        $slotsPerPage = self::SLOTS_PER_PAGE;
        $pageMask = $slotsPerPage - 1;
        $firstTime = true;

        $slotSize = self::SLOT_SIZE;

        while ($currentSlot < $slots) {
            $page = $this->getPageCache($fp, $currentSlot, $firstTime);
            if ($page === null) {
                break;
            }
            $firstTime = false;

            $startInPage = $currentSlot & $pageMask;
            $pageLen = strlen($page);
            $maxSlotsFromPage = (int)($pageLen / $slotSize);
            if ($maxSlotsFromPage == 0) {
                break;
            }

            $slotsInThisPage = min(
                $slotsPerPage - $startInPage,
                $slots - $currentSlot,
                $maxSlotsFromPage
            );

            for ($i = 0; $i < $slotsInThisPage; $i++) {
                $offsetInPage = ($startInPage + $i) * $slotSize;
                if ($offsetInPage + self::SLOT_SIZE > $pageLen) {
                    break;
                }

                $raw = substr($page, $offsetInPage, $slotSize);
                if (strlen($raw) < self::SLOT_SIZE) {
                    break;
                }

                $e = unpack(self::SLOT_UNPACK, $raw);
                $valueSort = $e['value_sort'];

                if ($maxKeyPad !== null && strcmp($valueSort, $maxKeyPad) > 0) {
                    break 2; // exits both the inner and outer loops
                }

                $offset = (int)$e['offset'];
                $length = (int)$e['length'];

                if ($length <= 0 || $length > $maxAllowed || $dataFileSize < 0 || ($offset + $length) > $dataFileSize) {
                    continue;
                }

                if (fseek($dataFp, $offset) !== 0) {
                    continue;
                }
                $line = fread($dataFp, $length);
                if ($line === false || strlen($line) < $length) {
                    continue;
                }

                $record = json_decode(trim($line), true);
                if (json_last_error() !== JSON_ERROR_NONE || $record === null) {
                    continue;
                }

                $matched++;
                $r = $fn($record);
                if ($r === false) {
                    break 2;
                }

                if ($limit > 0 && $matched >= $limit) {
                    break 2;
                }
            }

            $currentSlot += $slotsInThisPage;
        }

        fclose($fp);
        $this->cachedPageId = -1;
        $this->cachedPage = null;
        return $matched;
    }

    // =========================================================================
    // Value → sortable key
    // =========================================================================

    /**
     * @brief Converts an arbitrary PHP value to a fixed-width, lexicographically
     *        sortable binary string suitable for storage in value_sort.
     *
     * Encoding rules:
     *   - null / empty string → 'S' (empty string, sorts before all other strings)
     *   - numeric string      → cast to int or float before encoding
     *   - int                 → 'I' + 19-digit zero-padded (value + INT_BIAS)
     *   - float               → 'F' + 19-digit zero-padded (round(value*1000) + INT_BIAS)
     *   - string              → 'S' + first VALUE_STR_MAX characters
     *
     * The single-character prefixes ensure floats sort before ints, which sort
     * before strings (ASCII order: F=70 < I=73 < S=83).
     *
     * @param mixed $value The field value to encode.
     * @return string Sortable key string (at most VALUE_SORT_LEN bytes).
     */
    public static function toSortableKey($value)
    {
        if ($value === null || $value === '') return 'S';
        
        if (is_string($value) && is_numeric($value)) {
            $value = (strpos($value, '.') !== false || stripos($value, 'e') !== false)
                ? (float)$value : (int)$value;
        }
        
        if (is_int($value)) {
            return 'I' . sprintf('%019d', $value + self::INT_BIAS);
        }
        
        if (is_float($value)) {
            $scaled = (int)round($value * 1000.0);
            return 'F' . sprintf('%019d', $scaled + self::INT_BIAS);
        }
        
        return 'S' . substr((string)$value, 0, self::VALUE_STR_MAX);
    }
    
    // =========================================================================
    // Edge value (O(1) min/max)
    // =========================================================================

    /**
     * @brief Returns the minimum or maximum indexed value in O(1) time.
     *
     * Reads the first slot ('first') or last slot ('last') from the index file
     * and decodes its sort key back to a native PHP value.
     *
     * Returns false if the index is dirty, empty, or the file cannot be read.
     *
     * @param string $edge Either 'first' (minimum) or 'last' (maximum).
     * @return mixed|false The decoded PHP value, or false on error.
     */
    public function getEdgeValue($edge)
    {
        if ($this->isDirty()) {
            return false;
        }
        
        $result = $this->readEdgeSlot($edge);
        if ($result === false) {
            return false;
        }
        
        return $this->decodeSortKey($result);
    }
    
    // =========================================================================
    // Binary search
    // =========================================================================

    /**
     * @brief Returns the index of the first slot whose sort key is >= $keyPad.
     *
     * Classic lower-bound binary search operating directly on the index file.
     * Only the first VALUE_SORT_LEN bytes of each slot (the sort key) are read,
     * keeping I/O minimal.
     *
     * @param resource $fp     Open readable handle to the index file.
     * @param int      $n      Total number of slots in the index.
     * @param string   $keyPad Null-padded sort key to search for (VALUE_SORT_LEN bytes).
     * @return int First slot index i such that slot[i].value_sort >= $keyPad,
     *             or $n if all keys are smaller.
     */
    private function lowerBound($fp, $n, $keyPad)
    {
        $low  = 0;
        $high = $n;
        
        while ($low < $high) {
            $mid = (int)(($low + $high) / 2);
            
            if (fseek($fp, JdbIndexHeader::HDR_SIZE + $mid * self::SLOT_SIZE) !== 0) break;
            
            $raw = fread($fp, self::VALUE_SORT_LEN);
            if ($raw === false || strlen($raw) < self::VALUE_SORT_LEN) {
                $high = $mid;
                break;
            }
            
            if (strcmp($raw, $keyPad) < 0) {
                $low = $mid + 1;
            } else {
                $high = $mid;
            }
        }
        
        return $low;
    }
    
    // =========================================================================
    // Slot I/O
    // =========================================================================

    /**
     * @brief Serialises and writes one slot to the given file handle.
     *
     * The slot layout is: a128 (value_sort) + V (offset, uint32 LE) + V (length, uint32 LE).
     * Note: the $id parameter is accepted for API symmetry with the entry array format
     * but is NOT written to disk; the id is used only as a sort tiebreak during rebuild.
     *
     * @param resource $fp      Writable file handle positioned at the target byte offset.
     * @param string   $sortKey Encoded sort key (will be truncated/padded to VALUE_SORT_LEN).
     * @param int      $offset  Byte offset of the record in the data file.
     * @param int      $length  Byte length of the record in the data file.
     * @return bool True if exactly SLOT_SIZE bytes were written, false otherwise.
     */
    private function writeSlot($fp, $sortKey, $offset, $length)
    {
        $valuePad = str_pad(substr($sortKey, 0, self::VALUE_SORT_LEN),
            self::VALUE_SORT_LEN, "\x00");
        
        return fwrite($fp, pack(self::SLOT_PACK, $valuePad,
            (int)$offset, (int)$length)) === self::SLOT_SIZE;
    }

    /**
     * @brief Returns the active lock instance, creating a default one if necessary.
     *
     * @return JdbLock
     */
    private function getLock()
    {
        if ($this->lck === null) {
            $this->lck = new JdbLock('flock', 500);
        }
        return $this->lck;
    }

    /**
     * @brief Phase 0: scans the data file and collects active entries (last-write-wins).
     *
     * Iterates over every NDJSON line in the data file. Deleted records and records
     * missing the indexed field are removed from the active set. For duplicate IDs
     * only the last occurrence is kept, which implements last-write-wins semantics.
     *
     * @param resource $fp             Open file handle positioned at the start of the data file.
     * @param int      $dataHeaderSize Byte offset at which NDJSON rows begin.
     * @return array Associative array keyed by record ID, each value being
     *               [sortKey, id, byteOffset, byteLength].
     */
    private function collectActiveEntries($fp, $dataHeaderSize)
    {
        $active = array();
        
        if (fseek($fp, $dataHeaderSize) !== 0) {
            JdbErrorHandler::push('JdbSecondaryIndex', 'collectActiveEntries',
                'fseek dataHeaderSize=' . $dataHeaderSize . ' failed [field=' . $this->field . ']');
            return array();
        }
        
        $offset = $dataHeaderSize;
        while (!feof($fp)) {
            $line = fgets($fp);
            if ($line === false) break;
            
            $lineLen    = strlen($line);
            $lineOffset = $offset;
            $offset    += $lineLen;
            
            if (trim($line) === '') continue;
            
            $record = json_decode($line, true);
            if (json_last_error() !== JSON_ERROR_NONE || !isset($record['id'])) continue;
            
            $id = (string)$record['id'];
            
            if (isset($record['_deleted']) || !array_key_exists($this->field, $record)) {
                unset($active[$id]);
                continue;
            }
            
            $active[$id] = array(
                self::toSortableKey($record[$this->field]),
                $id,
                $lineOffset,
                $lineLen,
            );
        }
        
        return $active;
    }
    
    /**
     * @brief Fast-path: writes an empty (zero-slot) index file and atomically replaces
     *        the live index via a temporary file.
     *
     * @return bool True on success, false on failure.
     */
    private function writeEmptyIndex()
    {
        $tmpFile = $this->file . '.tmp';
        $outFp   = fopen($tmpFile, 'wb');
        if (!is_resource($outFp)) return false;
        
        JdbIndexHeader::write($outFp, 0, 0, 0, 0);
        fclose($outFp);
        
        $err = null;
        return JdbUtil::safeOverwrite($tmpFile, $this->file, $err);
    }
    
    /**
     * @brief Phase 1: partitions the active entries into sorted temporary chunk files.
     *
     * Each chunk contains at most $sortChunkSize entries, sorted by (sortKey, id).
     * The chunk files are raw slot streams with no header.
     *
     * @param array $active       Active entry map as returned by collectActiveEntries().
     * @return array|false Array of temporary chunk file paths on success, false on failure.
     */
    private function writeChunkFiles(array $active)
    {
        $chunkSize  = max(64, (int)self::$sortChunkSize);
        $chunkFiles = array();
        $chunk      = array();
        $chunkIdx   = 0;
        
        foreach ($active as $entry) {
            $chunk[] = $entry;
            if (count($chunk) >= $chunkSize) {
                $cf = $this->writeSortedChunk($chunk, $chunkIdx++);
                if ($cf === false) {
                    $this->cleanupChunks($chunkFiles);
                    return false;
                }
                $chunkFiles[] = $cf;
                $chunk = array();
            }
        }
        
        if (!empty($chunk)) {
            $cf = $this->writeSortedChunk($chunk, $chunkIdx);
            if ($cf === false) {
                $this->cleanupChunks($chunkFiles);
                return false;
            }
            $chunkFiles[] = $cf;
        }
        
        return $chunkFiles;
    }
    
    /**
     * @brief Phase 2: merges all sorted chunk files into the final index file.
     *
     * Writes the index header with the total record count, runs a k-way merge,
     * cleans up chunk files, and atomically overwrites the live index.
     *
     * @param array $chunkFiles Array of temporary chunk file paths.
     * @param int   $totalRecords Total number of records to write into the header.
     * @return bool True on success, false on failure.
     */
    private function mergeChunkFiles(array $chunkFiles, $totalRecords)
    {
        $tmpFile = $this->file . '.tmp';
        $outFp   = fopen($tmpFile, 'wb');
        if (!is_resource($outFp)) {
            $this->cleanupChunks($chunkFiles);
            return false;
        }
        
        JdbIndexHeader::write($outFp, $totalRecords, $totalRecords, 0, 0);
        
        $ok = $this->kWayMerge($chunkFiles, $outFp);
        fclose($outFp);
        $this->cleanupChunks($chunkFiles);
        
        if (!$ok) {
            @unlink($tmpFile);
            JdbErrorHandler::push('JdbSecondaryIndex', 'rebuild',
                'k-way merge failed [field=' . $this->field . ']');
            return false;
        }
        
        $err = null;
        return JdbUtil::safeOverwrite($tmpFile, $this->file, $err);
    }
    
    /**
     * @brief Sorts one entry chunk and writes it as a raw binary slot file.
     *
     * Entries are sorted by (sortKey, id) to produce a stable, deterministic order.
     * The output file is created with an exclusive open ('xb') using a random suffix
     * to avoid collisions; up to 3 retries are attempted on failure.
     * A mutex lock is held around file creation to prevent TOCTOU races.
     *
     * @param array $chunk Array of entries, each being [sortKey, id, offset, length].
     * @param int   $idx   Zero-based chunk index, used as part of the file name.
     * @return string|false Path to the written chunk file, or false on failure.
     */
    private function writeSortedChunk(array $chunk, $idx)
    {
        usort($chunk, function ($a, $b) {
            $cmp = strcmp($a[0], $b[0]);
            return ($cmp !== 0) ? $cmp : strcmp($a[1], $b[1]);
        });
        
        $suffix = JdbUtil::randomSuffix(8);
        $path = $this->file . '.chunk' . $idx . '.' . $suffix;
        
        if (is_link($path)) return false;
        
        $lockToken = $this->getLock()->acquireMutex($this->file . '.chunk.lock');
        if ($lockToken === false) {
            JdbErrorHandler::push('JdbSecondaryIndex', 'writeSortedChunk',
                'acquireMutex failed for chunk.lock [field=' . $this->field . ']');
            return false;
        }
        
        $fp = fopen($path, 'xb');
        if (!is_resource($fp)) {
            for ($retry = 0; $retry < 3; $retry++) {
                $suffix = JdbUtil::randomSuffix(8);
                $path   = $this->file . '.chunk' . $idx . '.' . $suffix;
                $fp     = fopen($path, 'xb');
                if (is_resource($fp)) break;
            }
            if (!is_resource($fp)) {
                $this->getLock()->releaseMutex($lockToken);
                return false;
            }
        }
        
        foreach ($chunk as $e) {
            // $e[0]=sortKey, $e[1]=id (tiebreak only, not stored), $e[2]=offset, $e[3]=length
            if ($this->writeSlot($fp, $e[0], $e[2], $e[3]) === false) {
                fclose($fp);
                @unlink($path);
                $this->getLock()->releaseMutex($lockToken);
                return false;
            }
        }
        
        fclose($fp);
        $this->getLock()->releaseMutex($lockToken);
        return $path;
    }
    
    /**
     * @brief K-way merge: reads the minimum slot from all open chunk files in order.
     *
     * Maintains one "head" slot per chunk file and repeatedly selects the globally
     * smallest key, writes it to the output, then advances that chunk's head.
     *
     * @param array    $chunkFiles Array of chunk file paths to merge.
     * @param resource $outFp      Writable file handle for the merged output.
     * @return bool True on success, false if a write error occurs.
     */
    private function kWayMerge(array $chunkFiles, $outFp)
    {
        $handles = $this->openChunkFiles($chunkFiles);
        if ($handles === false) return false;

        $heads = $this->readChunkHeads($handles);

        while (!empty($heads)) {
            $minIdx = $this->findMinHead($heads);

            if (fwrite($outFp, $heads[$minIdx][1]) !== self::SLOT_SIZE) {
                $this->closeChunkFiles($handles);
                return false;
            }

            $this->advanceChunkHead($handles, $heads, $minIdx);
        }

        $this->closeChunkFiles($handles);
        return true;
    }
    
    /**
     * @brief Opens all chunk files for reading.
     *
     * If any file cannot be opened, all already-opened handles are closed before
     * returning false.
     *
     * @param array $chunkFiles Array of chunk file paths.
     * @return array|false Associative array of file handles indexed by chunk index,
     *                     or false on failure.
     */
    private function openChunkFiles(array $chunkFiles)
    {
        $handles = array();
        foreach ($chunkFiles as $i => $path) {
            $fp = fopen($path, 'rb');
            if (!is_resource($fp)) {
                $this->closeChunkFiles($handles);
                return false;
            }
            $handles[$i] = $fp;
        }
        return $handles;
    }

    /**
     * @brief Reads the first slot from each open chunk file to seed the merge heap.
     *
     * Chunks that are empty (zero bytes) are simply omitted from the returned array.
     *
     * @param array $handles Associative array of open file handles.
     * @return array Array of [valueSortBytes, rawSlotBytes] pairs, indexed by chunk index.
     */
    private function readChunkHeads(array $handles)
    {
        $heads = array();
        foreach ($handles as $i => $fp) {
            $raw = fread($fp, self::SLOT_SIZE);
            if ($raw !== false && strlen($raw) === self::SLOT_SIZE) {
                $heads[$i] = array(substr($raw, 0, self::VALUE_SORT_LEN), $raw);
            }
        }
        return $heads;
    }
    
    /**
     * @brief Returns the index of the chunk whose current head has the smallest sort key.
     *
     * @param array $heads Current head array as maintained by the merge loop.
     * @return int|null Index of the minimum-key chunk, or null if $heads is empty.
     */
    private function findMinHead(array $heads)
    {
        $minKey = null;
        $minIdx = null;
        foreach ($heads as $i => $h) {
            if ($minKey === null || strcmp($h[0], $minKey) < 0) {
                $minKey = $h[0];
                $minIdx = $i;
            }
        }
        return $minIdx;
    }

    /**
     * @brief Advances one chunk to its next slot.
     *
     * Reads the next slot from the chunk at $minIdx. If no more slots are available
     * the file handle is closed and both $handles[$minIdx] and $heads[$minIdx] are
     * removed, effectively retiring the exhausted chunk from the merge.
     *
     * @param array $handles Reference to the open-handle array; may be modified.
     * @param array $heads   Reference to the current-head array; may be modified.
     * @param int   $minIdx  Index of the chunk to advance.
     * @return void
     */
    private function advanceChunkHead(array &$handles, array &$heads, $minIdx)
    {
        $raw = fread($handles[$minIdx], self::SLOT_SIZE);
        if ($raw !== false && strlen($raw) === self::SLOT_SIZE) {
            $heads[$minIdx] = array(substr($raw, 0, self::VALUE_SORT_LEN), $raw);
        } else {
            fclose($handles[$minIdx]);
            unset($handles[$minIdx], $heads[$minIdx]);
        }
    }
    
    /**
     * @brief Closes all open chunk file handles.
     *
     * @param array $handles Array of open file handles to close.
     * @return void
     */
    private function closeChunkFiles(array $handles)
    {
        foreach ($handles as $h) fclose($h);
    }
    
    /**
     * @brief Deletes all temporary chunk files created during a rebuild.
     *
     * Silently ignores files that no longer exist.
     *
     * @param array $chunkFiles Array of chunk file paths to remove.
     * @return void
     */
    private function cleanupChunks(array $chunkFiles)
    {
        foreach ($chunkFiles as $path) {
            if (file_exists($path)) @unlink($path);
        }
    }
    
    /**
     * @brief Validates the index file format and returns an open file handle plus its header.
     *
     * Checks that the file can be opened and that the stored version matches
     * JdbIndexHeader::VERSION. Pushes an error and returns false on any mismatch.
     *
     * @return array|false Two-element array [resource $fp, array $header] on success,
     *                     or false on failure.
     */
    private function validateAndOpenIndex()
    {
        $fp = fopen($this->file, 'rb');
        if (!is_resource($fp)) {
            JdbErrorHandler::push('JdbSecondaryIndex', 'rangeQuery',
                'fopen(' . $this->file . ', rb) failed [field=' . $this->field . ']');
            return false;
        }
        
        $h = JdbIndexHeader::read($fp);
        if ($h === null) {
            fclose($fp);
            return false;
        }
        
        if ((int)$h['version'] !== JdbIndexHeader::VERSION) {
            fclose($fp);
            JdbErrorHandler::push('JdbSecondaryIndex', 'rangeQuery',
                'version mismatch (got=' . $h['version'] . ', want=' . JdbIndexHeader::VERSION .
                ') — rebuild required [field=' . $this->field . ']');
            return false;
        }
        
        return array($fp, $h);
    }
    
    /**
     * @brief Reads the raw sort key of the first or last slot in the index.
     *
     * @param string $edge Either 'first' or 'last'.
     * @return string|false Null-stripped sort key string, or false on failure.
     */
    private function readEdgeSlot($edge)
    {
        $fp = fopen($this->file, 'rb');
        if (!is_resource($fp)) return false;
        
        $h = JdbIndexHeader::read($fp);
        if ($h === null || (int)$h['slots'] === 0 || (int)$h['version'] !== JdbIndexHeader::VERSION) {
            fclose($fp);
            return false;
        }
        
        $n      = (int)$h['slots'];
        $slotNo = ($edge === 'first') ? 0 : $n - 1;
        
        if (fseek($fp, JdbIndexHeader::HDR_SIZE + $slotNo * self::SLOT_SIZE) !== 0) {
            fclose($fp);
            return false;
        }
        
        $raw = fread($fp, self::SLOT_SIZE);
        fclose($fp);
        
        if ($raw === false || strlen($raw) < self::SLOT_SIZE) return false;
        
        $e = unpack(self::SLOT_UNPACK, $raw);
        return rtrim($e['value_sort'], "\x00");
    }
    
    /**
     * @brief Decodes a sortable key string back to its original PHP value.
     *
     * Reverses the encoding applied by toSortableKey():
     *   'I' prefix → integer (subtracts INT_BIAS)
     *   'F' prefix → float   (subtracts INT_BIAS, divides by 1000, rounds to 3 decimals)
     *   'S' prefix → string  (body returned as-is)
     *
     * @param string $sortKey Encoded sort key as stored in the index.
     * @return int|float|string|false Decoded value, or false if the key is malformed.
     */
    private function decodeSortKey($sortKey)
    {
        if (strlen($sortKey) < 2) {
            return false;
        }
        
        $prefix = $sortKey[0];
        $body   = substr($sortKey, 1);
        
        if ($prefix === 'I') {
            return (int)((float)$body - self::INT_BIAS);
        }
        if ($prefix === 'F') {
            return round(((float)$body - self::INT_BIAS) / 1000.0, 3);
        }
        return $body; // 'S' prefix: return raw string
    }
    
    /**
     * @brief Returns the raw bytes of the index page that contains slot $slotIdx,
     *        using a single-page in-memory cache to avoid redundant fread() calls.
     *
     * Pages are read sequentially during a range scan. When the requested page is
     * already cached it is returned immediately. When the new page immediately
     * follows the previous one (sequential access) fseek() is skipped; otherwise
     * an explicit seek is performed first.
     * A partial last page (shorter than PAGE_BYTES) is handled transparently.
     *
     * @param resource $fp        Open readable handle to the index file.
     * @param int      $slotIdx   Zero-based index of the slot to access.
     * @param bool     $firstTime True on the very first call of a scan; forces an fseek.
     * @return string|null Raw page bytes on success, or null on read failure.
     */
    private function getPageCache($fp, $slotIdx, $firstTime)
    {
        $oldPageId = $this->cachedPageId;
        $pageId = $slotIdx >> self::SLOTS_PER_PAGE_SHIFT;
        if ($oldPageId === $pageId) {
            $cachedPage = $this->cachedPage;
            if ($cachedPage) {
                return $cachedPage;
            }
        }

        if ((($oldPageId + 1) !== $pageId) || $firstTime) {
            if (fseek($fp, JdbIndexHeader::HDR_SIZE + ($pageId * self::PAGE_BYTES)) !== 0) {
                return null;
            }
        }
        $page = fread($fp, self::PAGE_BYTES);
        if ($page === false) {
            return null;
        }
        // A shorter-than-PAGE_BYTES read is normal for the last page; still valid.
        $this->cachedPageId = $pageId;
        $this->cachedPage = $page;
        return $page;
    }

}
