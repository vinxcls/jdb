<?php
require_once __DIR__ . "/JdbUtil.php";

/**
 * @file JdbIndexHeader.php
 * @brief Single source of truth for the JDB1 binary header format.
 *
 * Shared by BinaryIndex and SecondaryIndex: avoids duplicating the constants
 * and header read/write methods (identical in both classes) and centralises
 * dirty-bit management on file paths.
 *
 * Header format (32 bytes, identical for all JDB1 index files):
 * @code
 *   Offset  Fmt  Field    Description
 *   0–3     a4   magic    "JDB1"
 *   4       C    version  1
 *   5–8     V    slots    uint32 LE – total slots in the file
 *   9–12    V    count    uint32 LE – active records
 *   13–16   V    next_id  uint32 LE – always 0 in secondary indexes
 *   17      C    dirty    0 = clean, 1 = write in progress or rebuild needed
 *   18–21   V    deleted_count      uint32 
 *   22–25   V    obsolete_versions  uint32 LE
 *   26–31        reserved zero padding (6 bytes)
 * @endcode
 *
 * @note Compatible with PHP 5.5+.
 */
class JdbIndexHeader
{
    /**
     * @var string Magic bytes identifying a valid JDB1 index file.
     */
    const MAGIC = 'JDB1';

    /**
     * @var int Header version byte.
     *
     * Must match BinaryIndex::VERSION and SecondaryIndex::VERSION.
     * Increment when the slot format changes so open() can detect stale index
     * files. Current value: 1 (slot format v1 — uint32 offset, uint16 version
     * field). PHP 5.5 compatible.
     */
    const VERSION = 1;

    /**
     * @var int Total size in bytes of the binary header written to disk.
     */
    const HDR_SIZE = 32;

    /**
     * @var int Byte offset of the dirty flag inside the header.
     *
     * Points to byte 17, which stores 0x00 (clean) or 0x01 (dirty/rebuild
     * needed). Used by readDirtyBit() and writeDirtyBit() to seek directly
     * to the flag without re-reading the full header.
     */
    const HDR_DIRTY_BYTE = 17;

    /**
     * @var int Sentinel value: the counters are non valid (post-rebuild).
     *          getStats() executes always the fallback of the scan on the file.
     *          Resetted to 0 in vacuum() after the compact.
     */
    const HDR_COUNTERS_INVALID = 0xFFFFFFFF;

    // =========================================================================
    // Header I/O on an already-open file handle
    // =========================================================================

    /**
     * @brief Reads and decodes the 32-byte header from an open file handle.
     *
     * Seeks to byte 0 before reading. Returns @c null when the handle is
     * invalid, the file is shorter than 18 bytes, or the magic string does
     * not match JDB1.
     *
     * @param  resource $fp  Open binary file handle (readable, seekable).
     * @return array|null    Associative array with keys
     *                       ['magic','version','slots','count','next_id','dirty'],
     *                       or @c null on any error or magic mismatch.
     */
    public static function read($fp)
    {
        if (is_resource($fp) && (fseek($fp, 0) === 0)) {
            $raw = fread($fp, 26);
            if ($raw === false || strlen($raw) < 26) {
                return null;
            }
            $h = unpack('a4magic/Cversion/Vslots/Vcount/Vnext_id/Cdirty/Vdeleted_count/Vobsolete_versions', $raw);
            if ($h['magic'] === self::MAGIC) {
                return $h;
            }
        }
        return null;
    }

    /**
     * @brief Writes the full 32-byte header at position 0 of an open file handle.
     *
     * All integer parameters are clamped to the uint32 range [0, 0xFFFFFFFF]
     * before packing to prevent buffer overflows. Secondary indexes must always
     * pass @p $nextId = 0. Returns @c null when @p $fp is not a valid resource.
     *
     * @param resource $fp       Open binary file handle (writable, seekable).
     * @param int      $slots    Total number of slots in the index file.
     * @param int      $count    Number of currently active records.
     * @param int      $nextId  Next auto-increment ID; pass 0 for secondary indexes.
     * @param int      $dirty    Dirty flag: 0 = clean, 1 = write in progress / rebuild needed.
     * @param int      $deletedCount
     * @param int      $obsoleteVersions
     * @return bool|null         @c true on success, @c false if the seek or write fails,
     *                           @c null if @p $fp is not a valid resource.
     */
    public static function write($fp, $slots, $count, $nextId, $dirty,
                                  $deletedCount = 0, $obsoleteVersions = 0)
    {
        if (!is_resource($fp)) {
            return null;
        }
        $maxInt           = 0xFFFFFFFF;
        $minInt           = 0;
        $slots            = (int)max($minInt, min($maxInt, $slots));
        $count            = (int)max($minInt, min($maxInt, $count));
        $nextId           = (int)max($minInt, min($maxInt, $nextId));
        $dirty            = $dirty ? 1 : 0;
        $deletedCount     = (int)max($minInt, min($maxInt, $deletedCount));
        $obsoleteVersions = (int)max($minInt, min($maxInt, $obsoleteVersions));

        $header = pack('a4CVVVCVVx6',
                    self::MAGIC, self::VERSION,
                    $slots, $count, $nextId, $dirty,
                    $deletedCount, $obsoleteVersions);
        return (fseek($fp, 0) === 0) && (fwrite($fp, $header) === self::HDR_SIZE);
    }

    // =========================================================================
    // Dirty-bit I/O on a file path (no persistent handle)
    // =========================================================================

    /**
     * @brief Reads the dirty bit from an index file without keeping it open.
     *
     * Returns @c true (treat as dirty) when the file is missing, unreadable,
     * or the seek fails — following the convention that an absent index must
     * be rebuilt before use.
     *
     * @param  string $file  Filesystem path to the index file.
     * @return bool          @c true if the dirty bit is set or the file cannot
     *                       be read; @c false if the file is clean.
     */
    public static function readDirtyBit($file)
    {
        if (!file_exists($file)) {
            return true;
        }
        $fp = fopen($file, 'rb');
        if (!is_resource($fp)) {
            return true;
        }
        if (fseek($fp, self::HDR_DIRTY_BYTE) !== 0) {
            fclose($fp);
            return true;
        }
        $byte = fread($fp, 1);
        fclose($fp);
        // dirty=1 → true; dirty=0 → false; fread failed (false/"") → true (safe default).
        return $byte !== "\x00";
    }

    /**
     * @brief Writes the dirty bit to an index file without keeping it open.
     *
     * If the file does not yet exist, delegates to createIfMissing() to
     * initialise a full header with the requested dirty value before returning.
     * Only the single dirty byte at HDR_DIRTY_BYTE is overwritten when the
     * file already exists, leaving the rest of the header untouched.
     *
     * @param string $file   Filesystem path to the index file.
     * @param int    $dirty  Dirty value to write: 0 = clean, 1 = needs rebuild.
     * @return bool          @c true on success, @c false on any I/O failure.
     */
    public static function writeDirtyBit($file, $dirty)
    {
        if (!file_exists($file)) {
            return self::createIfMissing($file, 0, (int)$dirty);
        }
        $fp = fopen($file, 'r+b');
        if (!is_resource($fp)) {
            return false;
        }
        if (fseek($fp, self::HDR_DIRTY_BYTE) !== 0) {
            fclose($fp);
            return false;
        }
        $writeByte = chr($dirty & 1);
        $ok = (fwrite($fp, $writeByte) === 1);
        fclose($fp);
        return $ok;
    }

    /**
     * @brief Creates an empty index file with a valid header if it does not exist.
     *
     * Writes a 32-byte header with slots=0 and count=0 so the file is
     * immediately recognisable as a JDB1 index. The @p $dirty flag controls
     * whether the caller must rebuild the index before use. This method is a
     * no-op (returns @c true) when the file already exists.
     *
     * @param string $file    Filesystem path to the index file to create.
     * @param int    $nextId Initial next-ID value; pass 0 for secondary indexes.
     * @param int    $dirty   Initial dirty flag: 0 = clean, 1 = rebuild on first access.
     * @return bool           @c true if the file already existed or was created
     *                        successfully; @c false on any I/O failure.
     */
    public static function createIfMissing($file, $nextId = 0, $dirty = 1)
    {
        if (file_exists($file)) {
            return true;
        }
        $fp = fopen($file, 'wb');
        if (!is_resource($fp)) {
            return false;
        }
        $ok = (self::write($fp, 0, 0, (int)$nextId, (int)$dirty, self::HDR_COUNTERS_INVALID, self::HDR_COUNTERS_INVALID) !== false);
        fclose($fp);
        return $ok;
    }
}
