<?php
use Codeception\Test\Unit;

/**
 * @file JdbAggLockTest.php
 * @brief Integration tests for JdbAggLock against the real JdbLock implementation.
 *
 * @details
 * All tests use the 'mkdir' backend because:
 *   - mkdir() is atomic on all real filesystems, guaranteeing reliable mutual exclusion.
 *   - Mutex presence and absence are directly inspectable via is_dir().
 *   - flock() on Linux is per-process: a second flock() call from the same PID
 *     succeeds unconditionally, making mutual exclusion unreliable in
 *     single-process test runners such as Codeception.
 *
 * @covers JdbAggLock
 */
class JdbAggLockTest extends Unit
{
    /** @var string $dataDir Absolute path to the temporary directory used as the lock data store. */
    private $dataDir;

    /** @var JdbAggLock $lock Primary lock instance shared across tests. */
    private $lock;

    // =========================================================================
    // Fixture setup / teardown
    // =========================================================================

    /**
     * @brief Creates a unique temporary directory and initialises the primary lock instance.
     *
     * @details
     * A fresh directory is created for each test so that no mutex state leaks
     * between test cases.  The lock timeout is set to 200 ms to keep the test
     * suite fast while still allowing a brief retry window.
     *
     * @return void
     */
    protected function _before()
    {
        $this->dataDir = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR . 'jdblock_' . uniqid('', true);
        mkdir($this->dataDir, 0755, true);
        // Short timeout so failure cases do not block the test runner.
        $this->lock = new JdbAggLock($this->dataDir, 'mkdir', 200);
    }

    /**
     * @brief Releases all locks and recursively removes the temporary directory.
     *
     * @details
     * Calling releaseAll() before deletion guarantees that no mutex directory
     * is left behind if a test aborts mid-way.  The recursive removal via
     * _rmDir() cleans up any mutex directories that were not released by the
     * test itself.
     *
     * @return void
     */
    protected function _after()
    {
        $this->lock->releaseAll();
        $this->_rmDir($this->dataDir);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * @brief Reconstructs the filesystem path of the mutex directory for a given table.
     *
     * @details
     * This method mirrors the internal naming convention of JdbAggLock so that
     * tests can assert mutex presence and absence directly on the filesystem
     * without accessing private implementation details.
     *
     * Naming scheme: <dataDir>/.jdbagg_<safe>_<crc32hex>
     *   - <safe>     : table name with every non-alphanumeric/underscore character
     *                  replaced by an underscore.
     *   - <crc32hex> : lower-case hexadecimal CRC32 of the original table name,
     *                  used to distinguish tables whose safe names would collide.
     *
     * @param  string $table  The logical table name passed to acquireExclusive().
     * @return string         Absolute path of the expected mutex directory.
     */
    private function _mutexPath($table)
    {
        $safe = preg_replace('/[^a-zA-Z0-9_]/', '_', (string)$table);
        $hash = dechex(crc32((string)$table));
        return $this->dataDir . DIRECTORY_SEPARATOR . '.jdbagg_' . $safe . '_' . $hash;
    }

    /**
     * @brief Recursively removes a directory and all of its contents.
     *
     * @details
     * Silently returns when $dir does not exist, making the method safe to
     * call even after a partial cleanup.  Files are unlinked before their
     * parent directories so that rmdir() never fails on a non-empty directory.
     *
     * @param  string $dir  Absolute path of the directory to remove.
     * @return void
     */
    private function _rmDir($dir)
    {
        if (!is_dir($dir)) return;
        foreach (array_diff(scandir($dir), array('.', '..')) as $f) {
            $p = $dir . DIRECTORY_SEPARATOR . $f;
            is_dir($p) ? $this->_rmDir($p) : unlink($p);
        }
        rmdir($dir);
    }

    // =========================================================================
    // acquireExclusive() – success cases
    // =========================================================================

    /**
     * @brief acquireExclusive() returns true when locking a single table.
     *
     * @covers JdbAggLock::acquireExclusive
     * @group  acquireExclusive
     *
     * @return void
     */
    public function testAcquireExclusiveSingleTableReturnsTrue()
    {
        $this->assertTrue($this->lock->acquireExclusive(array('orders')));
        $this->lock->releaseAll();
    }

    /**
     * @brief acquireExclusive() physically creates the mutex directory on disk.
     *
     * @details
     * Verifies that the 'mkdir' backend translates a successful lock acquisition
     * into an actual directory entry, which is the mechanism that prevents
     * concurrent processes from acquiring the same lock.
     *
     * @covers JdbAggLock::acquireExclusive
     * @group  acquireExclusive
     *
     * @return void
     */
    public function testAcquireExclusiveCreatesMutexDirectoryOnDisk()
    {
        $this->lock->acquireExclusive(array('orders'));
        $this->assertTrue(is_dir($this->_mutexPath('orders')));
        $this->lock->releaseAll();
    }

    /**
     * @brief acquireExclusive() creates one mutex directory per table.
     *
     * @details
     * When multiple table names are supplied, every requested mutex must be
     * materialised on disk so that other lock instances observe the full set
     * of held locks.
     *
     * @covers JdbAggLock::acquireExclusive
     * @group  acquireExclusive
     *
     * @return void
     */
    public function testAcquireExclusiveMultipleTablesCreatesAllMutexes()
    {
        $this->lock->acquireExclusive(array('orders', 'lines'));
        $this->assertTrue(is_dir($this->_mutexPath('orders')));
        $this->assertTrue(is_dir($this->_mutexPath('lines')));
        $this->lock->releaseAll();
    }

    /**
     * @brief acquireExclusive() with an empty table list returns true and creates no mutex.
     *
     * @details
     * Locking zero tables is a valid no-op: it must succeed and must leave the
     * data directory unchanged so that unrelated lock instances are unaffected.
     *
     * @covers JdbAggLock::acquireExclusive
     * @group  acquireExclusive
     *
     * @return void
     */
    public function testAcquireExclusiveEmptyListReturnsTrueAndCreatesNothing()
    {
        $this->assertTrue($this->lock->acquireExclusive(array()));
        $mutexes = array_filter(
            array_diff(scandir($this->dataDir), array('.', '..')),
            function ($f) { return strpos($f, '.jdbagg_') === 0; }
        );
        $this->assertEmpty($mutexes);
    }

    /**
     * @brief acquireExclusive() deduplicates repeated table names before acquiring.
     *
     * @details
     * Passing the same table name multiple times must result in exactly one
     * mutex directory on disk.  Creating duplicate mutexes would cause
     * releaseAll() to attempt double-removal and could hide accounting bugs
     * inside JdbAggLock.
     *
     * @covers JdbAggLock::acquireExclusive
     * @group  acquireExclusive
     *
     * @return void
     */
    public function testAcquireExclusiveDeduplicatesDuplicateTables()
    {
        $this->lock->acquireExclusive(array('orders', 'orders', 'orders'));
        $mutexes = array_filter(
            array_diff(scandir($this->dataDir), array('.', '..')),
            function ($f) { return strpos($f, '.jdbagg_') === 0; }
        );
        $this->assertCount(1, $mutexes);
        $this->lock->releaseAll();
    }

    // =========================================================================
    // acquireExclusive() – mutual exclusion
    // =========================================================================

    /**
     * @brief A second lock instance cannot acquire a table already held by a first instance.
     *
     * @details
     * While lock1 holds 'orders', lock2's attempt to acquire 'orders' must
     * fail and return false.  lock2 is not released explicitly because it
     * failed to acquire anything, so there is nothing to clean up.
     *
     * @covers JdbAggLock::acquireExclusive
     * @group  mutualExclusion
     *
     * @return void
     */
    public function testSecondLockOnSameTableFailsWhileFirstHolds()
    {
        $lock2 = new JdbAggLock($this->dataDir, 'mkdir', 100);
        $this->lock->acquireExclusive(array('orders'));
        $this->assertFalse($lock2->acquireExclusive(array('orders')));
        $this->lock->releaseAll();
    }

    /**
     * @brief A second lock instance can acquire a table after the first instance releases it.
     *
     * @details
     * Verifies that releaseAll() removes the mutex directory so that a
     * subsequent acquireExclusive() call by another instance can succeed.
     *
     * @covers JdbAggLock::acquireExclusive
     * @covers JdbAggLock::releaseAll
     * @group  mutualExclusion
     *
     * @return void
     */
    public function testSecondLockSucceedsAfterFirstReleases()
    {
        $lock2 = new JdbAggLock($this->dataDir, 'mkdir', 100);
        $this->lock->acquireExclusive(array('orders'));
        $this->lock->releaseAll();
        $this->assertTrue($lock2->acquireExclusive(array('orders')));
        $lock2->releaseAll();
    }

    // =========================================================================
    // acquireExclusive() – all-or-nothing rollback
    // =========================================================================

    /**
     * @brief A partial acquisition is fully rolled back when one mutex cannot be obtained.
     *
     * @details
     * Scenario:
     *   - lock1 holds 'z_tbl'.
     *   - lock2 requests ['a_tbl', 'z_tbl']; after alphabetical sorting the
     *     acquisition order is a_tbl → z_tbl.
     *   - lock2 acquires a_tbl successfully, then fails on z_tbl.
     *   - Expected: lock2 rolls back a_tbl so its mutex directory no longer exists.
     *
     * This guarantees the all-or-nothing semantic: a failed acquireExclusive()
     * leaves no partial lock footprint on the filesystem.
     *
     * @covers JdbAggLock::acquireExclusive
     * @group  rollback
     *
     * @return void
     */
    public function testPartialFailureRollsBackAlreadyAcquiredMutex()
    {
        $lock1 = new JdbAggLock($this->dataDir, 'mkdir', 100);
        $lock2 = new JdbAggLock($this->dataDir, 'mkdir', 100);

        $lock1->acquireExclusive(array('z_tbl'));
        $result = $lock2->acquireExclusive(array('a_tbl', 'z_tbl'));

        $this->assertFalse($result);
        // a_tbl must have been rolled back; its mutex directory must not exist.
        $this->assertFalse(is_dir($this->_mutexPath('a_tbl')));

        $lock1->releaseAll();
    }

    /**
     * @brief After a failed rollback attempt, isHolding() reports that no lock is held.
     *
     * @details
     * Complements testPartialFailureRollsBackAlreadyAcquiredMutex() by checking
     * the in-memory state of lock2: even if the filesystem rollback were correct,
     * the instance must not internally track any tables as held.
     * lock2 is not released explicitly because it never successfully acquired
     * anything.
     *
     * @covers JdbAggLock::acquireExclusive
     * @covers JdbAggLock::isHolding
     * @group  rollback
     *
     * @return void
     */
    public function testAfterRollbackLockHoldsNothing()
    {
        $lock1 = new JdbAggLock($this->dataDir, 'mkdir', 100);
        $lock2 = new JdbAggLock($this->dataDir, 'mkdir', 100);

        $lock1->acquireExclusive(array('z_tbl'));
        $lock2->acquireExclusive(array('a_tbl', 'z_tbl'));

        $this->assertFalse($lock2->isHolding());
        $lock1->releaseAll();
    }

    // =========================================================================
    // releaseAll()
    // =========================================================================

    /**
     * @brief releaseAll() removes all mutex directories created by the lock instance.
     *
     * @covers JdbAggLock::releaseAll
     * @group  releaseAll
     *
     * @return void
     */
    public function testReleaseAllRemovesMutexDirectories()
    {
        $this->lock->acquireExclusive(array('orders', 'lines'));
        $this->lock->releaseAll();
        $this->assertFalse(is_dir($this->_mutexPath('orders')));
        $this->assertFalse(is_dir($this->_mutexPath('lines')));
    }

    /**
     * @brief releaseAll() is idempotent: calling it twice does not throw and leaves no mutex.
     *
     * @details
     * A second consecutive call must silently succeed.  This guards against
     * double-release bugs in calling code (e.g., explicit release followed by
     * the implicit release in a destructor or teardown).
     *
     * @covers JdbAggLock::releaseAll
     * @group  releaseAll
     *
     * @return void
     */
    public function testReleaseAllIsIdempotent()
    {
        $this->lock->acquireExclusive(array('orders'));
        $this->lock->releaseAll();
        $this->lock->releaseAll(); // must not throw on a second call
        $this->assertFalse(is_dir($this->_mutexPath('orders')));
    }

    /**
     * @brief releaseAll() is safe to call on a fresh instance that has never acquired a lock.
     *
     * @details
     * This guards against null-pointer or empty-state exceptions when teardown
     * logic calls releaseAll() unconditionally before any lock has been taken.
     * The test passes as long as no exception or fatal error is raised; the
     * explicit assertTrue(true) serves as a sentinel assertion to mark
     * exception-free execution as the expected outcome.
     *
     * @covers JdbAggLock::releaseAll
     * @group  releaseAll
     *
     * @return void
     */
    public function testReleaseAllSafeBeforeAnyAcquire()
    {
        $this->lock->releaseAll(); // must not throw on a never-acquired instance
        $this->assertTrue(true);
    }

    // =========================================================================
    // isHolding() and heldTables()
    // =========================================================================

    /**
     * @brief isHolding() returns false on a fresh instance before any acquisition.
     *
     * @covers JdbAggLock::isHolding
     * @group  stateInspection
     *
     * @return void
     */
    public function testIsHoldingFalseBeforeAcquire()
    {
        $this->assertFalse($this->lock->isHolding());
    }

    /**
     * @brief isHolding() returns true after a successful acquireExclusive() call.
     *
     * @covers JdbAggLock::isHolding
     * @covers JdbAggLock::acquireExclusive
     * @group  stateInspection
     *
     * @return void
     */
    public function testIsHoldingTrueAfterAcquire()
    {
        $this->lock->acquireExclusive(array('orders'));
        $this->assertTrue($this->lock->isHolding());
        $this->lock->releaseAll();
    }

    /**
     * @brief isHolding() returns false after releaseAll() is called.
     *
     * @covers JdbAggLock::isHolding
     * @covers JdbAggLock::releaseAll
     * @group  stateInspection
     *
     * @return void
     */
    public function testIsHoldingFalseAfterReleaseAll()
    {
        $this->lock->acquireExclusive(array('orders'));
        $this->lock->releaseAll();
        $this->assertFalse($this->lock->isHolding());
    }

    /**
     * @brief heldTables() contains every table name passed to acquireExclusive().
     *
     * @details
     * The returned collection must include all originally requested table names
     * so that callers can audit which locks are currently active.
     *
     * @covers JdbAggLock::heldTables
     * @covers JdbAggLock::acquireExclusive
     * @group  stateInspection
     *
     * @return void
     */
    public function testHeldTablesContainsAcquiredNames()
    {
        $this->lock->acquireExclusive(array('orders', 'lines'));
        $held = $this->lock->heldTables();
        $this->assertContains('orders', $held);
        $this->assertContains('lines',  $held);
        $this->lock->releaseAll();
    }

    /**
     * @brief heldTables() returns an empty collection after releaseAll().
     *
     * @details
     * Verifies that in-memory tracking is cleared in tandem with the
     * filesystem cleanup performed by releaseAll().
     *
     * @covers JdbAggLock::heldTables
     * @covers JdbAggLock::releaseAll
     * @group  stateInspection
     *
     * @return void
     */
    public function testHeldTablesEmptyAfterRelease()
    {
        $this->lock->acquireExclusive(array('orders'));
        $this->lock->releaseAll();
        $this->assertEmpty($this->lock->heldTables());
    }
}
