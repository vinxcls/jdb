<?php

/**
 * @file JdbUtil.php
 *
 * @brief Static utility helpers for the JDB engine.
 *
 * Centralises filesystem operations, identifier validation, CRC helpers,
 * and other routines that would otherwise be duplicated across multiple classes.
 *
 * @note Compatibility: PHP 5.5+
 */
class JdbUtil
{
    // -------------------------------------------------------------------------
    // Identifiers (table name, field name)
    // -------------------------------------------------------------------------

    /**
     * @var int Maximum allowed length for table and field names (default).
     */
    const MAX_IDENTIFIER_LEN = 64;

    /**
     * @var string Default regex pattern for safe identifiers (alphanumeric + underscore only).
     */
    const SAFE_IDENTIFIER_PATTERN = '/^[a-zA-Z0-9_]+$/';

    /**
     * @var string PHP die header prepended to every JDB data file to prevent
     *             direct HTTP access. Decoded value: "<?php die(); ?>\n"
     */
    const PHP_DIE_HEADER = "\x3c\x3f\x70\x68\x70\x20\x64\x69\x65\x28\x29\x3b\x20\x3f\x3e\x0a";

    /**
     * @var int Byte length of PHP_DIE_HEADER (= strlen(self::PHP_DIE_HEADER)).
     */
    const DATA_HEADER_SIZE = 16;

    /**
     * @var int Maximum allowed size for a single record in bytes (10 MB).
     */
    const MAX_RECORD_BYTES = 10485760;

    /**
     * @var int 2^32, used to normalise negative CRC32 values on 32-bit builds.
     */
    const UINT32_OVERFLOW = 0x100000000;

    /**
     * @var int CRC32 good-packet residue.
     */
    const CRC32_RESIDUE = 0x2144DF1C;

    // =========================================================================
    // ID and data
    // =========================================================================

    /**
     * @brief Normalises a parentId or childId value to its natural PHP scalar type.
     *
     * The JDB engine encodes all row fields as JSON scalars; primary-key
     * comparisons in the engine are effectively string comparisons.
     * Normalising here ensures consistent behaviour regardless of whether the
     * caller supplies an integer (42) or a numeric string ("42").
     *
     * @param  mixed           $id  Raw identifier value (int, float, or string).
     * @return int|float|string     Numeric string cast to int/float; non-numeric left as string.
     */
    public static function normalizeId($id)
    {
        if (is_numeric($id)) {
            return $id + 0; // "42" -> 42, "3.14" -> 3.14
        }
        return (string)$id;
    }

    // =========================================================================
    // Validation
    // =========================================================================

    /**
     * @brief Checks that an ID is a non-empty string after casting.
     *
     * @warning The original implementation compared the string-cast $id against
     *          integer 0 using strict inequality (!==), which is always true
     *          (different types), making the false-branch unreachable and the
     *          empty-string case incorrectly accepted. Fixed to compare against
     *          the empty string ('').
     *
     * @param  mixed        $id  Raw identifier value.
     * @return string|false      The identifier cast to string on success,
     *                           false if the resulting string is empty.
     */
    public static function isValidId($id)
    {
        $id = (string)$id;
        return ($id !== '') ? $id : false;
    }

    /**
     * @brief Validates that the given value is a non-empty array.
     *
     * Used to ensure record data passed to write operations contains at least
     * one field before any I/O is attempted.
     *
     * @param  mixed $data  Value to test.
     * @return bool         True if $data is an array with at least one element.
     */
    public static function isNonEmptyArray($data)
    {
        return is_array($data) && !empty($data);
    }

    /**
     * @brief Decodes a single JSON line from a JDB data file.
     *
     * Trims surrounding whitespace before decoding. Returns null on any
     * JSON error or if the decoded value is itself null, so callers can treat
     * null as a sentinel for "skip this line".
     *
     * @param  string     $line  Raw line read from the data file.
     * @return array|null        Associative array on success, null on decode failure.
     */
    public static function jsonDecodeRecord($line)
    {
        $record = json_decode(trim($line), true);
        if (json_last_error() !== JSON_ERROR_NONE || $record === null) {
            return null;
        }
        return $record;
    }

    // =========================================================================
    // Filesystem helpers
    // =========================================================================

    /**
     * @brief Writes the PHP die() header to an open file handle.
     *
     * The header prevents direct HTTP access to raw JDB data files when they
     * reside inside a web-accessible directory. Must be the very first write
     * to a new data file.
     *
     * @param  resource $fp  Open, writable file handle positioned at offset 0.
     * @return bool          True if exactly DATA_HEADER_SIZE bytes were written.
     */
    public static function writePhpDieHeader($fp)
    {
        return fwrite($fp, self::PHP_DIE_HEADER) === self::DATA_HEADER_SIZE;
    }

    /**
     * @brief Returns the size of an open file handle in bytes.
     *
     * Uses fstat() so the result reflects the current on-disk size without
     * requiring a separate stat() call on the path.
     *
     * @param  resource    $fp  Open file handle.
     * @return int|false        File size in bytes, or false if $fp is not a
     *                          valid resource or fstat() fails.
     */
    public static function getFileSize($fp)
    {
        if (!is_resource($fp)) {
            return false;
        }
        $stat = fstat($fp);
        return ($stat !== false) ? $stat['size'] : false;
    }

    /**
     * @brief Validates that a record byte range lies within the file.
     *
     * Rejects non-positive lengths, lengths exceeding MAX_RECORD_BYTES,
     * negative offsets, and ranges that extend beyond $fileSize.
     *
     * @param  int  $offset    Byte offset of the record from the start of the file.
     * @param  int  $length    Byte length of the record.
     * @param  int  $fileSize  Total file size in bytes (e.g. from getFileSize()).
     * @return bool            True if the range is valid and fits within the file.
     */
    public static function isValidRecordBounds($offset, $length, $fileSize)
    {
        if ($offset < 0 || $length <= 0 || $length > self::MAX_RECORD_BYTES) {
            return false;
        }
        if ($offset + $length > $fileSize) {
            return false;
        }
        return true;
    }

    /**
     * @brief Writes N zero-filled slots to a file handle using chunked I/O.
     *
     * Batches multiple slot writes into larger chunks to reduce the number of
     * fwrite() syscalls, which is significant when pre-allocating many slots.
     *
     * @param  resource $fp         Open, writable file handle.
     * @param  int      $slots      Number of zero-filled slots to write.
     * @param  int      $slotSize   Size of each slot in bytes.
     * @param  int      $chunkSize  Target chunk size in bytes (default 4096).
     *                              Actual chunk size is rounded down to a whole
     *                              number of slots.
     * @return bool                 True if all bytes were written successfully.
     */
    public static function writeEmptySlots($fp, $slots, $slotSize, $chunkSize = 4096)
    {
        $slotsPerChunk = (int)floor($chunkSize / $slotSize);
        if ($slotsPerChunk < 1) {
            $slotsPerChunk = 1;
        }

        $chunkBytes = $slotSize * $slotsPerChunk;
        $chunkData  = str_repeat("\x00", $chunkBytes);

        $fullChunks = (int)floor($slots / $slotsPerChunk);
        $remaining  = $slots % $slotsPerChunk;

        for ($i = 0; $i < $fullChunks; $i++) {
            if (fwrite($fp, $chunkData) !== $chunkBytes) {
                return false;
            }
        }

        if ($remaining > 0) {
            $remBytes = $slotSize * $remaining;
            $remainingData = str_repeat("\x00", $remBytes);
            if (fwrite($fp, $remainingData) !== $remBytes) {
                return false;
            }
        }

        return true;
    }

    /**
     * @brief Validates a name for use as a table or field identifier.
     *
     * A valid identifier must:
     * - Be a non-empty string.
     * - Contain no path-traversal sequences (.., /, \).
     * - Not exceed $maxLen characters (default: MAX_IDENTIFIER_LEN).
     * - Match $pattern (default: alphanumeric + underscore only).
     *
     * Path-traversal rejection is a hard security constraint and cannot be
     * disabled via the $pattern override.
     *
     * @param  string      $name     Name to validate.
     * @param  int|null    $maxLen   Maximum allowed length; null uses MAX_IDENTIFIER_LEN.
     * @param  string|null $pattern  Regex override; null uses SAFE_IDENTIFIER_PATTERN.
     * @return bool                  True if the name is a valid identifier.
     */
    public static function isValidIdentifier($name, $maxLen = null, $pattern = null)
    {
        if (!is_string($name) || $name === '') {
            return false;
        }

        // Reject path-traversal sequences (hard security constraint, not overridable).
        if (strpos($name, '..') !== false ||
            strpos($name, '/')  !== false ||
            strpos($name, '\\') !== false) {
            return false;
        }

        $max = ($maxLen === null) ? self::MAX_IDENTIFIER_LEN : (int)$maxLen;
        if (strlen($name) > $max) {
            return false;
        }

        $regex = ($pattern === null) ? self::SAFE_IDENTIFIER_PATTERN : $pattern;
        return preg_match($regex, $name) === 1;
    }

    /**
     * @brief Validates a table name.
     *
     * Convenience alias for isValidIdentifier() with default constraints.
     *
     * @param  string $table  Table name to validate.
     * @return bool           True if $table is a valid identifier.
     */
    public static function isValidTableName($table)
    {
        return self::isValidIdentifier($table);
    }

    /**
     * @brief Validates a field name.
     *
     * Convenience alias for isValidIdentifier() with default constraints.
     *
     * @param  string $field  Field name to validate.
     * @return bool           True if $field is a valid identifier.
     */
    public static function isValidFieldName($field)
    {
        return self::isValidIdentifier($field);
    }

    // -------------------------------------------------------------------------
    // Filesystem operations
    // -------------------------------------------------------------------------

    /**
     * @brief Atomically overwrites a destination file with a source file.
     *
     * Uses rename() for atomic replacement on Unix. On Windows, rename() fails
     * when the destination already exists, so the destination is removed first
     * with exponential-backoff retries before the rename is attempted.
     * No additional external lock is required.
     *
     * @param  string      $source  Path to the source file (must exist).
     * @param  string      $dest    Path to the destination file.
     * @param  string|null &$error  Populated with an error message on failure.
     * @return bool                 True on success, false on failure (see $error).
     */
    public static function safeOverwrite($source, $dest, &$error = null)
    {
        $error = null;

        if (!self::validateOverwritePaths($source, $dest, $error)) {
            return false;
        }

        if ($source === $dest) {
            return true; // same file, nothing to do
        }

        // On Windows, rename() fails if the destination already exists.
        // Remove it first with retry + backoff.
        if (DIRECTORY_SEPARATOR === '\\' && file_exists($dest)) {
            if (!self::removeDestinationWindows($dest, $error)) {
                return false;
            }
        }

        if (!@rename($source, $dest)) {
            $lastErr = error_get_last();
            $error = 'Rename failed: "' . $source . '" -> "' . $dest . '". ' .
                (isset($lastErr['message']) ? $lastErr['message'] : 'unknown error');
            return false;
        }

        return true;
    }

    /**
     * @brief Creates a directory recursively if it does not already exist.
     *
     * @param  string $path  Directory path to create.
     * @param  int    $mode  Permission bits for new directories (default 0755).
     * @return bool          True if the directory exists or was created successfully.
     */
    public static function ensureDirectory($path, $mode = 0755)
    {
        if (is_dir($path)) {
            return true;
        }
        return mkdir($path, $mode, true);
    }

    /**
     * @brief Generates a cryptographically random hex string for use in temporary filenames.
     *
     * Prefers openssl_random_pseudo_bytes() when available and confirmed strong.
     * Falls back to a sha256 hash seeded from uniqid() + PID + mt_rand(), which is
     * not cryptographically strong but sufficient for collision avoidance in filenames.
     *
     * @note On PHP 7+ prefer bin2hex(random_bytes($bytes)) instead.
     *
     * @param  int    $bytes  Number of random bytes to generate
     *                        (default 8, yielding 16 hex characters).
     * @return string         Lowercase hexadecimal string of length $bytes * 2.
     */
    public static function randomSuffix($bytes = 8)
    {
        if (function_exists('openssl_random_pseudo_bytes')) {
            $rand = openssl_random_pseudo_bytes($bytes, $strong);
            if ($rand !== false && $strong) {
                return bin2hex($rand);
            }
        }
        // Deterministic fallback: not cryptographically strong, but sufficient for filenames.
        return substr(hash('sha256', uniqid(getmypid() . mt_rand(), true)), 0, $bytes * 2);
    }

    /**
     * @brief Returns true if $record satisfies all field => value conditions.
     *
     * Every condition must match for the method to return true (logical AND).
     * Comparison casts both sides to string, consistent with JDB's JSONL
     * representation where all scalar values are stored as strings.
     * array_key_exists() is used instead of isset() so that fields explicitly
     * set to null are still considered present.
     *
     * @param  array $record      Associative array representing one JDB record.
     * @param  array $conditions  Map of field => expected_value; all must match.
     * @return bool               True if every condition is satisfied.
     */
    public static function recordMatchesConditions(array $record, array $conditions)
    {
        foreach ($conditions as $field => $value) {
            // Use array_key_exists to distinguish null values from missing keys.
            if (!array_key_exists($field, $record) || (string)$record[$field] !== (string)$value) {
                return false;
            }
        }
        return true;
    }

    // -------------------------------------------------------------------------
    // Math utilities
    // -------------------------------------------------------------------------

    /**
     * @brief Returns the smallest power of two that is >= $n.
     *
     * Returns 1 for any $n < 1. Used to compute hash-table capacity.
     *
     * @param  int $n  Input value.
     * @return int     Smallest power of two >= $n (minimum 1).
     */
    public static function nextPowerOfTwo($n)
    {
        $n = (int)$n;
        if ($n < 1) {
            return 1;
        }
        $p = 1;
        while ($p < $n) {
            $p <<= 1;
        }
        return $p;
    }

    /**
     * @brief Returns the CRC32 checksum as an unsigned 32-bit integer.
     *
     * @param  string $data  Input data to checksum.
     * @return int           Unsigned CRC32 value in the range [0, 2^32 - 1].
     */
    public static function crc32u($data)
    {
        $v = crc32($data);
        return $v - ($v < 0) * JdbUtil::UINT32_OVERFLOW;
    }

    /**
     * @brief Attempts to unlink a file with exponential-backoff retry.
     *
     * Retries up to 3 times with delays of 100 ms, 200 ms, and 400 ms,
     * accommodating brief file locks (e.g. antivirus scans on Windows).
     * Declared protected to allow override in test subclasses.
     *
     * @param  string $path  Path to the file to remove.
     * @return bool          True if the file was successfully removed.
     */
    protected static function unlinkWithRetry($path)
    {
        $delayUs = 100000; // 100 ms
        $retries = 3;

        for ($i = 0; $i < $retries; $i++) {
            if (@unlink($path)) {
                return true;
            }
            usleep($delayUs);
            $delayUs *= 2;
        }
        return false;
    }

    /**
     * @brief Validates source and destination paths for safeOverwrite().
     *
     * Verifies that the source file exists and that the destination's parent
     * directory is a valid, existing directory.
     *
     * @param  string      $source  Path to the source file.
     * @param  string      $dest    Path to the destination file.
     * @param  string|null &$error  Populated with an error message on failure.
     * @return bool                 True if both paths are valid.
     */
    private static function validateOverwritePaths($source, $dest, &$error)
    {
        if (!file_exists($source)) {
            $error = 'Source file does not exist: ' . $source;
            return false;
        }

        $destDir = dirname($dest);
        if (!is_dir($destDir)) {
            $error = 'Invalid destination directory: ' . $destDir;
            return false;
        }

        return true;
    }

    /**
     * @brief Removes the destination file on Windows using exponential-backoff retry.
     *
     * Required because rename() on Windows fails when the destination file
     * already exists. Delegates to unlinkWithRetry() and populates $error on failure.
     *
     * @param  string      $dest    Path to the destination file to remove.
     * @param  string|null &$error  Populated with an error message on failure.
     * @return bool                 True if the file was removed successfully.
     */
    private static function removeDestinationWindows($dest, &$error)
    {
        if (self::unlinkWithRetry($dest)) {
            return true;
        }

        $lastErr = error_get_last();
        $error = 'Unable to remove "' . $dest . '" on Windows: ' .
            (isset($lastErr['message']) ? $lastErr['message'] : 'file locked or permission denied');
        return false;
    }
}
