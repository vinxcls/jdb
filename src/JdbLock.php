<?php

/**
 * @file JdbLock.php
 * @brief Unified locking abstraction for JsonDatabase.
 *
 * Supports two backends, configured at construction time:
 *
 * - @b flock (default): standard PHP flock(). Fast and correct on local
 *   filesystems (ext4, XFS, APFS, NTFS). NOT reliable on NFS mounts where
 *   two processes on different hosts may both acquire the lock simultaneously.
 *
 * - @b mkdir: uses mkdir() as an atomic primitive. Works on NFS because the
 *   NFS server serialises directory operations. No shared-lock equivalent
 *   exists, so even read operations acquire an exclusive lock (lower
 *   concurrency in read-heavy workloads). Includes stale-lock detection
 *   based on PID and timestamp.
 *
 * @par Public API
 * | Method                              | Description                                         |
 * |-------------------------------------|-----------------------------------------------------|
 * | acquireFile($filename, $openMode)   | Exclusive lock + fopen                              |
 * | acquireFileShared($filename)        | Shared lock (flock) or exclusive (mkdir) + fopen rb |
 * | releaseFile($fp)                    | Unlock + fclose                                     |
 * | acquireMutex($lockName)             | Named mutex (no file opened), returns a token       |
 * | releaseMutex($token)                | Release a token returned by acquireMutex()          |
 *
 * @note Compatible with PHP 5.5+.
 */
class JdbLock
{
    /**
     * @var string Backend identifier for the standard PHP flock() strategy.
     */
    const BACKEND_FLOCK = 'flock';

    /**
     * @var string Backend identifier for the NFS-safe atomic mkdir() strategy.
     */
    const BACKEND_MKDIR = 'mkdir';

    /**
     * @var int Polling interval in microseconds between successive lock attempts.
     *          Equals 10 ms (10 000 µs).
     */
    const POLL_INTERVAL_US = 10000;

    /**
     * @var string Active backend: 'flock' or 'mkdir'.
     *             Any constructor value that is not 'mkdir' defaults to 'flock'.
     */
    private $backend;

    /**
     * @var int Lock-acquisition timeout in milliseconds.
     *          Always ≥ 1; enforced by setTimeoutMs() and the constructor.
     */
    private $timeoutMs;

    /**
     * @var string|null Path of the lock directory currently held for a file lock
     *                  (mkdir backend only). At most one active file lock per
     *                  instance because PHP is single-threaded per request.
     *                  @c null when no file lock is held.
     */
    private $activeMkdirLockDir = null;

    // =========================================================================
    // Constructor and accessors
    // =========================================================================

    /**
     * @brief Initialises the locking backend and acquisition timeout.
     *
     * Any value other than @c 'mkdir' for @p $backend silently selects the
     * @c 'flock' backend. Values of @p $timeoutMs that are ≤ 0 are clamped
     * to 1 ms.
     *
     * @param string $backend   Locking strategy: @c 'flock' (default) or @c 'mkdir'.
     * @param int    $timeoutMs Maximum time to wait for lock acquisition, in milliseconds.
     */
    public function __construct($backend = 'flock', $timeoutMs = 500)
    {
        $this->backend   = ($backend === 'mkdir') ? 'mkdir' : 'flock';
        $this->timeoutMs = max(1, (int)$timeoutMs);
    }

    /**
     * @brief Returns the active backend identifier.
     * @return string @c 'flock' or @c 'mkdir'.
     */
    public function getBackend() { return $this->backend; }

    /**
     * @brief Returns the current lock-acquisition timeout.
     * @return int Timeout in milliseconds (always ≥ 1).
     */
    public function getTimeoutMs() { return $this->timeoutMs; }

    /**
     * @brief Updates the lock-acquisition timeout.
     *
     * Takes effect on the next acquire call. Values ≤ 0 are clamped to 1 ms.
     *
     * @param int $ms New timeout in milliseconds.
     * @return void
     */
    public function setTimeoutMs($ms)
    {
        $this->timeoutMs = max(1, (int)$ms);
    }

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * @brief Opens @p $filename in @p $openMode and acquires an exclusive lock.
     *
     * Used for write operations (default mode @c 'ab'). With the mkdir backend,
     * this method is also called for reads by acquireFileShared(), because no
     * shared-lock equivalent exists in that backend.
     *
     * @param  string $filename  Path to the file to open and lock.
     * @param  string $openMode  fopen mode — typically @c 'ab' for appending or
     *                           @c 'r+b' for in-place updates.
     * @return resource|false    Locked file handle on success, @c false on
     *                           timeout or if fopen() fails.
     */
    public function acquireFile($filename, $openMode = 'ab')
    {
        if ($this->backend === 'mkdir') {
            return $this->acquireFileMkdir($filename, $openMode);
        }
        return $this->acquireFileFlock($filename, $openMode, LOCK_EX);
    }

    /**
     * @brief Opens @p $filename read-only and acquires a shared lock (flock backend)
     *        or an exclusive lock (mkdir backend).
     *
     * The mkdir backend has no shared-lock equivalent, so this method falls back
     * to an exclusive mkdir lock for all read operations.
     *
     * @param  string $filename  Path to the file to open and lock.
     * @return resource|false    Locked file handle on success, @c false on
     *                           timeout or if fopen() fails.
     */
    public function acquireFileShared($filename)
    {
        if ($this->backend === 'mkdir') {
            return $this->acquireFileMkdir($filename, 'rb');
        }
        return $this->acquireFileFlockShared($filename);
    }

    /**
     * @brief Releases the lock on @p $fp and closes the file handle.
     *
     * For the flock backend, issues LOCK_UN before closing.
     * For the mkdir backend, removes the lock directory via
     * releaseMkdirLockDir() after closing the handle.
     *
     * @param resource $fp  Locked file handle returned by acquireFile() or
     *                      acquireFileShared().
     * @return void
     */
    public function releaseFile($fp)
    {
        if ($this->backend === 'mkdir') {
            fclose($fp);
            $this->releaseMkdirLockDir();
        } else {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * @brief Acquires a named mutex without opening a file.
     *
     * Used to serialise concurrent index rebuilds. The returned token is an
     * opaque array whose internal structure depends on the active backend:
     * - flock:  @c ['type' => 'flock', 'fp' => resource, 'file' => string]
     * - mkdir:  @c ['type' => 'mkdir', 'dir' => string, 'owner' => string]
     *
     * Always pass the token to releaseMutex() — never inspect it directly.
     *
     * @param  string       $lockName  Full filesystem path used as the lock
     *                                 identifier (e.g. @c $indexFile . '.rebuild.lock').
     * @return array|false             Opaque lock token on success,
     *                                 @c false on timeout or acquisition failure.
     */
    public function acquireMutex($lockName)
    {
        if ($this->backend === 'mkdir') {
            return $this->acquireMutexMkdir($lockName);
        }
        return $this->acquireMutexFlock($lockName);
    }

    /**
     * @brief Releases a mutex token returned by acquireMutex().
     *
     * For the flock backend, issues LOCK_UN and closes the lock file handle.
     * For the mkdir backend, deletes the owner file and removes the lock directory.
     * Silently no-ops if @p $token is not an array or lacks the @c 'type' key,
     * and also if @c 'type' does not match a known backend value.
     *
     * @param array|false $token  Token returned by acquireMutex(), or @c false.
     * @return void
     */
    public function releaseMutex($token)
    {
        if (!is_array($token) || !isset($token['type'])) {
            return;
        }
        if ($token['type'] === 'flock') {
            flock($token['fp'], LOCK_UN);
            fclose($token['fp']);
        } elseif ($token['type'] === 'mkdir') {
            @unlink($token['owner']);
            @rmdir($token['dir']);
        }
    }

    // =========================================================================
    // Private: flock backend
    // =========================================================================

    /**
     * @brief Opens @p $filename and acquires an flock lock of type @p $lockType
     *        with a polling retry loop.
     *
     * fopen() is called once before the retry loop; if it fails, the method
     * returns @c false immediately without retrying. The lock is attempted
     * non-blocking (@c LOCK_NB) on every iteration until either it succeeds
     * or the deadline is reached. On timeout, logs via JdbErrorHandler and
     * closes the file.
     *
     * @param  string   $filename  Path to the file to open.
     * @param  string   $openMode  fopen mode (e.g. @c 'ab', @c 'r+b').
     * @param  int      $lockType  flock lock type constant (@c LOCK_EX or @c LOCK_SH).
     * @return resource|false      Locked handle on success, @c false on failure or timeout.
     */
    private function acquireFileFlock($filename, $openMode, $lockType)
    {
        $fp = fopen($filename, $openMode);
        if (!is_resource($fp)) {
            JdbErrorHandler::push('JdbLock', 'acquireFileFlock',
                'fopen(' . $filename . ', ' . $openMode . ') failed');
            return false;
        }
        $deadline = microtime(true) + $this->timeoutMs / 1000.0;
        do {
            if (flock($fp, $lockType | LOCK_NB)) {
                return $fp;
            }
            usleep(self::POLL_INTERVAL_US);
        } while (microtime(true) < $deadline);

        fclose($fp);
        JdbErrorHandler::push('JdbLock', 'acquireFileFlock',
            'lock (type=' . $lockType . ') not acquired within ' . $this->timeoutMs .
            'ms [' . $filename . ']');
        return false;
    }

    /**
     * @brief Opens @p $filename read-only and acquires a shared flock (@c LOCK_SH)
     *        with a polling retry loop.
     *
     * Unlike acquireFileFlock(), fopen() is retried inside the loop because the
     * file may not exist yet at the start of the timeout window (e.g. a writer
     * has not created it yet). On timeout, closes the handle if open and logs
     * via JdbErrorHandler.
     *
     * @param  string        $filename  Path to the file to open read-only.
     * @return resource|false           Shared-locked handle on success,
     *                                  @c false on failure or timeout.
     */
    private function acquireFileFlockShared($filename)
    {
        $deadline = microtime(true) + $this->timeoutMs / 1000.0;
        $fp = null;
        do {
            if (!is_resource($fp)) {
                $fp = fopen($filename, 'rb');
                if (!is_resource($fp)) {
                    usleep(self::POLL_INTERVAL_US);
                    continue;
                }
            }
            if (flock($fp, LOCK_SH | LOCK_NB)) {
                return $fp;
            }
            usleep(self::POLL_INTERVAL_US);
        } while (microtime(true) < $deadline);

        if (is_resource($fp)) fclose($fp);
        JdbErrorHandler::push('JdbLock', 'acquireFileFlockShared',
            'shared lock not acquired within ' . $this->timeoutMs .
            'ms [' . $filename . ']');
        return false;
    }

    /**
     * @brief Acquires a named mutex using flock (@c LOCK_EX) on a dedicated
     *        lock file, with a polling retry loop.
     *
     * Creates or opens @p $lockName with mode @c 'c' (create if missing, never
     * truncate). On success returns an opaque token; on timeout returns @c false
     * and closes the file handle.
     *
     * @note Unlike acquireFileFlock() and acquireFileMkdir(), this method does
     *       NOT call JdbErrorHandler::push() on timeout. Callers that need error
     *       reporting must handle the @c false return themselves.
     *
     * @param  string      $lockName  Filesystem path of the mutex lock file.
     * @return array|false            Token @c ['type','fp','file'] on success,
     *                                @c false on timeout or if fopen() fails.
     */
    private function acquireMutexFlock($lockName)
    {
        $fp = fopen($lockName, 'c');
        if (!is_resource($fp)) {
            return false;
        }
        $deadline = microtime(true) + $this->timeoutMs / 1000.0;
        do {
            if (flock($fp, LOCK_EX | LOCK_NB)) {
                return array('type' => 'flock', 'fp' => $fp, 'file' => $lockName);
            }
            usleep(self::POLL_INTERVAL_US);
        } while (microtime(true) < $deadline);
        fclose($fp);
        return false;
    }

    // =========================================================================
    // Private: mkdir backend
    // =========================================================================

    /**
     * @brief Core mkdir lock-acquisition loop shared by file and mutex helpers.
     *
     * On each iteration:
     * 1. Attempts @c mkdir($lockDir, 0700) atomically.
     * 2. On success, writes an owner file containing @c "PID:timestamp".
     *    If the write fails the directory is removed immediately and the loop
     *    retries (defensive recovery against partial-write races).
     * 3. On mkdir failure (directory already exists), calls
     *    breakStaleMkdirLock() to remove the directory if it is stale.
     *
     * @param  string $lockDir       Path of the lock directory to create.
     * @param  string $ownerFile     Path of the owner file inside @p $lockDir.
     * @param  float  $staleAfterSec Age threshold in seconds beyond which an
     *                               existing lock is considered stale.
     * @param  float  $deadline      Absolute deadline as a microtime(true) value.
     * @return bool                  @c true if the lock directory was created and
     *                               the owner file was written; @c false on timeout.
     */
    private function acquireMkdirLockDir($lockDir, $ownerFile, $staleAfterSec, $deadline)
    {
        do {
            if (@mkdir($lockDir, 0700)) {
                $ownerContent = getmypid() . ':' . microtime(true);
                if (@file_put_contents($ownerFile, $ownerContent) !== false) {
                    return true;
                }
                @rmdir($lockDir);
            }
            $this->breakStaleMkdirLock($lockDir, $ownerFile, $staleAfterSec);
            usleep(self::POLL_INTERVAL_US);
        } while (microtime(true) < $deadline);

        return false;
    }

    /**
     * @brief Acquires an exclusive mkdir-based lock and opens @p $filename.
     *
     * mkdir() is atomic even on NFS (the server serialises directory creation),
     * so two processes on different hosts cannot both create the lock directory.
     * The lock is modelled as a directory @c "<filename>.lock/" containing an
     * @c "owner" file with PID and timestamp for stale-lock detection.
     *
     * A lock is considered stale if either condition holds (OR):
     * - The owning process is dead: posix_kill($pid, 0) returns @c false.
     * - The lock age exceeds @c max(2 s, 3 × timeoutMs): process is stuck or zombie.
     *
     * On success, stores the lock directory in @c $activeMkdirLockDir so that
     * releaseMkdirLockDir() can clean it up. On fopen() failure after acquiring
     * the lock, the lock directory is released before returning.
     *
     * @param  string        $filename  Path to the file to open after locking.
     * @param  string        $openMode  fopen mode (e.g. @c 'ab', @c 'rb', @c 'r+b').
     * @return resource|false           Locked file handle on success, @c false on
     *                                  timeout, lock failure, or fopen() failure.
     */
    private function acquireFileMkdir($filename, $openMode)
    {
        $lockDir       = $filename . '.lock';
        $ownerFile     = $lockDir . '/owner';
        $deadline      = microtime(true) + $this->timeoutMs / 1000.0;
        $staleAfterSec = max(2.0, ($this->timeoutMs / 1000.0) * 3);

        if (!$this->acquireMkdirLockDir($lockDir, $ownerFile, $staleAfterSec, $deadline)) {
            JdbErrorHandler::push('JdbLock', 'cquireFileMkdir',
                'mkdir lock not acquired within ' . $this->timeoutMs . 'ms [' . $lockDir . ']');
            return false;
        }

        $this->activeMkdirLockDir = $lockDir;
        $fp = fopen($filename, $openMode);
        if (!is_resource($fp)) {
            $this->releaseMkdirLockDir();
            JdbErrorHandler::push('JdbLock', 'acquireFileMkdir',
                'fopen(' . $filename . ', ' . $openMode . ') failed after mkdir');
            return false;
        }
        return $fp;
    }

    /**
     * @brief Acquires a named mutex using the mkdir backend.
     *
     * @p $lockName is used directly as the lock directory path (no @c '.lock'
     * suffix is appended, unlike in acquireFileMkdir()). Does NOT set
     * @c $activeMkdirLockDir because mutex tokens carry their own cleanup state
     * and are released through releaseMutex() rather than releaseFile().
     *
     * @param  string      $lockName  Filesystem path to use as the lock directory.
     * @return array|false            Token @c ['type','dir','owner'] on success,
     *                                @c false on timeout or acquisition failure.
     */
    private function acquireMutexMkdir($lockName)
    {
        $lockDir       = $lockName;
        $ownerFile     = $lockDir . '/owner';
        $deadline      = microtime(true) + $this->timeoutMs / 1000.0;
        $staleAfterSec = max(2.0, ($this->timeoutMs / 1000.0) * 3);

        if (!$this->acquireMkdirLockDir($lockDir, $ownerFile, $staleAfterSec, $deadline)) {
            return false;
        }
        return array('type' => 'mkdir', 'dir' => $lockDir, 'owner' => $ownerFile);
    }

    /**
     * @brief Releases the mkdir file lock stored in @c $activeMkdirLockDir.
     *
     * Deletes the owner file, removes the lock directory, and resets
     * @c $activeMkdirLockDir to @c null. Silently no-ops if no file lock
     * is currently held (@c $activeMkdirLockDir === null).
     *
     * @return void
     */
    private function releaseMkdirLockDir()
    {
        if ($this->activeMkdirLockDir === null) {
            return;
        }
        $lockDir   = $this->activeMkdirLockDir;
        $ownerFile = $lockDir . '/owner';
        @unlink($ownerFile);
        @rmdir($lockDir);
        $this->activeMkdirLockDir = null;
    }

    /**
     * @brief Checks whether an existing mkdir lock is stale and removes it if so.
     *
     * Stale conditions (OR):
     * - The owner file is absent (orphaned directory): the directory is removed.
     * - The owning process is dead: detected via posix_kill($pid, 0).
     * - The lock age exceeds @p $staleAfterSec.
     *
     * @note When @c posix_kill() is unavailable (e.g. Windows), PID-based
     *       detection is skipped entirely and only the age criterion applies.
     *
     * @param string $lockDir       Path of the lock directory to inspect.
     * @param string $ownerFile     Path of the owner file inside @p $lockDir.
     * @param float  $staleAfterSec Age threshold in seconds for stale detection.
     * @return void
     */
    private function breakStaleMkdirLock($lockDir, $ownerFile, $staleAfterSec)
    {
        $data = @file_get_contents($ownerFile);
        if ($data === false) {
            // Orphaned lock directory (no owner file): remove it.
            @rmdir($lockDir);
            return;
        }

        $parts       = explode(':', $data, 2);
        $pid         = (int)(isset($parts[0]) ? $parts[0] : 0);
        $since       = (float)(isset($parts[1]) ? $parts[1] : 0);
        $ageSeconds  = microtime(true) - $since;

        $processDead = false;
        if ($pid > 0 && function_exists('posix_kill')) {
            $processDead = !@posix_kill($pid, 0);
        }

        if ($processDead || $ageSeconds > $staleAfterSec) {
            @unlink($ownerFile);
            @rmdir($lockDir);
        }
    }

}
