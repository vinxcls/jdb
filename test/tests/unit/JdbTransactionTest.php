<?php
/**
 * JdbTransactionTest – Full test suite for the JdbTransaction class.
 *
 * Compatible with PHPUnit 9+ and Codeception Unit suite.
 *
 * Every test method is self-contained:
 *   - setUp() creates a fresh temp directory, configures JdbManager, and mocks dependencies
 *   - tearDown() cleans up the temp directory and resets static states
 *
 * Run with:
 *   vendor/bin/phpunit tests/unit/JdbTransactionTest.php
 *   vendor/bin/codecept run unit JdbTransactionTest
 */
class JdbTransactionTest extends \PHPUnit\Framework\TestCase
{
    private $testDataDir;
    private $testTable = 'txn_test_users';

    // ── Lifecycle ───────────────────────────────────────────────────────────
    protected function setUp(): void
    {
        // 1. Create a unique temporary directory
        $this->testDataDir = sys_get_temp_dir() . '/jdb_txn_test_' . uniqid('', true);
        mkdir($this->testDataDir, 0755, true);

        // 2. Configure JdbManager to use this directory
        JdbManager::configure(['data_dir' => $this->testDataDir]);
        JdbManager::clearAllInstances();
        JdbManager::clearError();

        // 3. Mock JdbAggLock if it doesn't exist (to prevent fatal errors on 'new JdbAggLock')
        if (!class_exists('JdbAggLock')) {
            eval('
                class JdbAggLock {
                    private $acquired = false;
                    public function __construct($dir, $backend, $timeout) {}
                    public function acquireExclusive($tables) {
                        $this->acquired = true;
                        return true; // Always succeed in test environment
                    }
                    public function releaseAll() {
                        $this->acquired = false;
                    }
                }
            ');
        }

        // 4. Mock JdbLock if it doesn't exist (used in recover())
        if (!class_exists('JdbLock')) {
            eval('
                class JdbLock {
                    public function __construct($backend, $timeout) {}
                    public function acquireMutex($path) { return "mock_mutex_token"; }
                    public function releaseMutex($mutex) {}
                }
            ');
        }

        // 5. Ensure JdbErrorHandler is clean
        JdbErrorHandler::clear('JdbTransaction');
    }

    protected function tearDown(): void
    {
        JdbManager::clearAllInstances();
        JdbManager::clearError();
        JdbErrorHandler::clear('JdbTransaction');
        $this->rrmdir($this->testDataDir);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }

    // =========================================================================
    // 1. LIFECYCLE: BEGIN, COMMIT, ROLLBACK, RESET
    // =========================================================================
    public function testBeginChangesStateToActiveAndCreatesTxnFile(): void
    {
        JdbManager::insert($this->testTable, ['name' => 'Alice']);
        
        $txn = new JdbTransaction($this->testDataDir);
        $result = $txn->begin([$this->testTable]);
        
        $this->assertTrue($result);
        $this->assertSame(JdbTransaction::STATE_ACTIVE, $txn->getState());
        $this->assertTrue($txn->isActive());
        
        // Verify .txn file was created
        $files = glob($this->testDataDir . '/.txn_*.php');
        $this->assertCount(1, $files);
    }

    public function testCommitRemovesTxnFileAndChangesState(): void
    {
        $txn = new JdbTransaction($this->testDataDir);
        $txn->begin([$this->testTable]);
        
        $txnFilesBefore = glob($this->testDataDir . '/.txn_*.php');
        $this->assertCount(1, $txnFilesBefore);
        
        $result = $txn->commit();
        
        $this->assertTrue($result);
        $this->assertSame(JdbTransaction::STATE_COMMITTED, $txn->getState());
        
        $txnFilesAfter = glob($this->testDataDir . '/.txn_*.php');
        $this->assertCount(0, $txnFilesAfter);
    }

    public function testRollbackTruncatesDataAndRemovesTxnFile(): void
    {
        // 1. Initial state
        $id1 = JdbManager::insert($this->testTable, ['name' => 'Alice']);
        $this->assertNotFalse($id1);
        
        // 2. Begin transaction
        $txn = new JdbTransaction($this->testDataDir);
        $txn->begin([$this->testTable]);
        
        // 3. Perform operation inside transaction
        $id2 = $txn->insert($this->testTable, ['name' => 'Bob']);
        $this->assertNotFalse($id2);
        $this->assertSame(2, JdbManager::count($this->testTable));
        
        // 4. Rollback
        $result = $txn->rollback();
        
        $this->assertTrue($result);
        $this->assertSame(JdbTransaction::STATE_ROLLED_BACK, $txn->getState());
        
        // 5. Verify Bob is gone and Alice remains
        $this->assertSame(1, JdbManager::count($this->testTable));
        $this->assertNotNull(JdbManager::findById($this->testTable, $id1));
        $this->assertNull(JdbManager::findById($this->testTable, $id2));
        
        // Verify .txn file is gone
        $this->assertCount(0, glob($this->testDataDir . '/.txn_*.php'));
    }

    public function testResetReturnsToIdleState(): void
    {
        $txn = new JdbTransaction($this->testDataDir);
        $txn->begin([$this->testTable]);
        $txn->rollback(); // Must rollback before reset
        
        $txn->reset();
        $this->assertSame(JdbTransaction::STATE_IDLE, $txn->getState());
        $this->assertFalse($txn->isActive());
    }

    public function testResetOnActiveTransactionDoesNothingAndLogsTrace(): void
    {
        $txn = new JdbTransaction($this->testDataDir);
        $txn->begin([$this->testTable]);
        
        // Reset should be ignored, state remains ACTIVE
        $txn->reset();
        $this->assertSame(JdbTransaction::STATE_ACTIVE, $txn->getState());
    }

    // =========================================================================
    // 2. WRITE OPERATIONS (Insert, Update, Delete)
    // =========================================================================
    public function testInsertFailsWhenNotActive(): void
    {
        $txn = new JdbTransaction($this->testDataDir);
        $result = $txn->insert($this->testTable, ['name' => 'Alice']);
        
        $this->assertFalse($result);
        $this->assertNotNull(JdbErrorHandler::getLast('JdbTransaction'));
    }

    public function testUpdateSucceedsWhenActive(): void
    {
        $id = JdbManager::insert($this->testTable, ['name' => 'Alice']);
        
        $txn = new JdbTransaction($this->testDataDir);
        $txn->begin([$this->testTable]);
        
        $result = $txn->update($this->testTable, $id, ['name' => 'Alice Updated']);
        $this->assertTrue($result);
        $this->assertSame('Alice Updated', JdbManager::findById($this->testTable, $id)['name']);
        
        $txn->commit();
    }

    public function testDeleteSucceedsWhenActive(): void
    {
        $id = JdbManager::insert($this->testTable, ['name' => 'Alice']);
        
        $txn = new JdbTransaction($this->testDataDir);
        $txn->begin([$this->testTable]);
        
        $result = $txn->delete($this->testTable, $id);
        $this->assertTrue($result);
        $this->assertNull(JdbManager::findById($this->testTable, $id));
        
        $txn->commit();
    }

    public function testInsertBatchSucceedsWhenActive(): void
    {
        $txn = new JdbTransaction($this->testDataDir);
        $txn->begin([$this->testTable]);
        
        $result = $txn->insertBatch($this->testTable, [
            ['name' => 'Alice'],
            ['name' => 'Bob']
        ]);
        
        $this->assertIsArray($result);
        $this->assertSame(2, $result['inserted']);
        $this->assertCount(2, $result['ids']);
        
        $txn->commit();
        $this->assertSame(2, JdbManager::count($this->testTable));
    }

    // =========================================================================
    // 3. CRASH RECOVERY
    // =========================================================================
    public function testRecoverRollsBackOrphanedTransactionWithDeadPid(): void
    {
        // 1. Create initial data
        $id1 = JdbManager::insert($this->testTable, ['name' => 'Alice']);
        $dataFile = JdbManager::getDataPath($this->testTable);
        $sizeBefore = filesize($dataFile);
        
        // 2. Manually craft an orphaned .txn file with a FAKE, DEAD PID (e.g., 9999999)
        // We use a fake PID so _isProcessAlive returns false, triggering recovery.
        $fakePid = 9999999;
        $this->createFakeTxnFile($this->testTable, $fakePid, $sizeBefore);
        
        $txnFiles = glob($this->testDataDir . '/.txn_*.php');
        $this->assertCount(1, $txnFiles);
        
        // 3. Add data that should be rolled back
        $id2 = JdbManager::insert($this->testTable, ['name' => 'Bob']);
        $this->assertSame(2, JdbManager::count($this->testTable));
        
        // 4. Run recovery
        $recoveredCount = JdbTransaction::recover($this->testDataDir);
        
        // 5. Verify results
        $this->assertSame(1, $recoveredCount);
        $this->assertCount(0, glob($this->testDataDir . '/.txn_*.php')); // .txn file deleted
        $this->assertSame(1, JdbManager::count($this->testTable)); // Bob is gone
        $this->assertNotNull(JdbManager::findById($this->testTable, $id1)); // Alice remains
    }

    public function testRecoverIgnoresActiveTransactionWithLivingPid(): void
    {
        // 1. Create a .txn file with the CURRENT, LIVING PID
        $livingPid = getmypid();
        $dataFile = JdbManager::getDataPath($this->testTable) ?: ($this->testDataDir . '/' . $this->testTable . '.data.php');
        $sizeBefore = file_exists($dataFile) ? filesize($dataFile) : 0;
        
        $this->createFakeTxnFile($this->testTable, $livingPid, $sizeBefore);
        
        // 2. Run recovery
        $recoveredCount = JdbTransaction::recover($this->testDataDir);
        
        // 3. Verify it was IGNORED (not recovered) because PID is alive
        $this->assertSame(0, $recoveredCount);
        $this->assertCount(1, glob($this->testDataDir . '/.txn_*.php')); // .txn file still exists
    }

    public function testRecoverDeletesCorruptedTxnFile(): void
    {
        // Create a file with invalid magic/corrupted content
        $corruptedFile = $this->testDataDir . '/.txn_999999_abcdef12.php';
        file_put_contents($corruptedFile, 'This is not a valid JDBT file');
        
        $recoveredCount = JdbTransaction::recover($this->testDataDir);
        
        $this->assertSame(0, $recoveredCount);
        $this->assertFileDoesNotExist($corruptedFile); // Corrupted file should be deleted
    }

    // =========================================================================
    // 4. FILE PARSING & DEBUGGING
    // =========================================================================
    public function testDumpTxnFileReturnsReadableStringForValidFile(): void
    {
        $this->createFakeTxnFile($this->testTable, 999999, 0);
        $files = glob($this->testDataDir . '/.txn_*.php');

        $dump = JdbTransaction::dumpTxnFile($files[0]);

        // "JDBT" is validated internally but not printed in the dump output.
        // We verify the fields that are actually rendered.
        $this->assertStringContainsString('Version:   1', $dump);
        $this->assertStringContainsString($this->testTable, $dump);
        $this->assertStringContainsString('(dead)', $dump); // Fake PID is dead
    }

    public function testDumpTxnFileReturnsErrorForInvalidFile(): void
    {
        $invalidFile = $this->testDataDir . '/invalid.php';
        file_put_contents($invalidFile, 'garbage');
        
        $dump = JdbTransaction::dumpTxnFile($invalidFile);
        $this->assertStringContainsString('Invalid or corrupted file', $dump);
    }

    // =========================================================================
    // 5. ERROR HANDLING
    // =========================================================================
    public function testGetLastErrorReturnsNullInitially(): void
    {
        $txn = new JdbTransaction($this->testDataDir);
        $this->assertNull($txn->getLastError());
    }

    public function testGetLastErrorPopulatedOnFailedBegin(): void
    {
        // Enable detailed errors for this component to inspect the original message
        JdbErrorHandler::configure('JdbTransaction', ['detailed_errors' => true]);

        $txn = new JdbTransaction($this->testDataDir);
        $txn->begin([$this->testTable]);

        // Attempting begin() again must fail
        $txn->begin([$this->testTable]);

        $error = $txn->getLastError();
        $this->assertNotNull($error);
        $this->assertSame('begin', $error['method']);
        $this->assertStringContainsString('Transaction already started', $error['message']);
    }

    // =========================================================================
    // 6. MULTI-TABLE TRANSACTIONS
    // =========================================================================
    public function testBeginWithMultipleTablesLocksAndRollsBackAll()
    {
        $table2 = 'txn_test_products';
        JdbManager::insert($this->testTable, ['name' => 'Alice']);
        JdbManager::insert($table2, ['product' => 'Book']);

        $txn = new JdbTransaction($this->testDataDir);
        $ok = $txn->begin([$this->testTable, $table2]);
        $this->assertTrue($ok);

        $txn->insert($this->testTable, ['name' => 'Bob']);
        $txn->insert($table2, ['product' => 'Pen']);

        $this->assertSame(2, JdbManager::count($this->testTable));
        $this->assertSame(2, JdbManager::count($table2));

        $txn->rollback();

        $this->assertSame(1, JdbManager::count($this->testTable));
        $this->assertSame(1, JdbManager::count($table2));
    }

    public function testCommitWithMultipleTablesKeepsAllChanges()
    {
        $table2 = 'txn_test_products';
        JdbManager::insert($this->testTable, ['name' => 'Alice']);

        $txn = new JdbTransaction($this->testDataDir);
        $txn->begin([$this->testTable, $table2]);
        $txn->insert($this->testTable, ['name' => 'Bob']);
        $txn->insert($table2, ['product' => 'Lamp']);
        $txn->commit();

        $this->assertSame(2, JdbManager::count($this->testTable));
        $this->assertSame(1, JdbManager::count($table2));
    }

    // =========================================================================
    // 7. EDGE CASES AND VALIDATION
    // =========================================================================
    public function testBeginFailsWithEmptyTableList()
    {
        $txn = new JdbTransaction($this->testDataDir);
        $result = $txn->begin([]);
        $this->assertFalse($result);
        $this->assertStringContainsString('Empty table list', $txn->getLastError()['message']);
    }

    public function testBeginFailsIfTableNameTooLong()
    {
        $longTable = str_repeat('a', 64); // JdbTransaction::TXN_MAX_NAME = 63
        $txn = new JdbTransaction($this->testDataDir);
        $result = $txn->begin([$longTable]);
        $this->assertFalse($result);
        $this->assertStringContainsString('too long', $txn->getLastError()['message']);
    }

    public function testCannotBeginTwiceWithoutCommitOrRollback()
    {
        $txn = new JdbTransaction($this->testDataDir);
        $this->assertTrue($txn->begin([$this->testTable]));
        $this->assertFalse($txn->begin([$this->testTable]));
        $this->assertSame(JdbTransaction::STATE_ACTIVE, $txn->getState());
    }

    public function testWriteOperationsFailAfterCommit()
    {
        $txn = new JdbTransaction($this->testDataDir);
        $txn->begin([$this->testTable]);
        $txn->commit();

        $result = $txn->insert($this->testTable, ['name' => 'Charlie']);
        $this->assertFalse($result);
        $this->assertStringContainsString('No active transaction', $txn->getLastError()['message']);
    }

    public function testWriteOperationsFailAfterRollback()
    {
        $txn = new JdbTransaction($this->testDataDir);
        $txn->begin([$this->testTable]);
        $txn->rollback();

        $result = $txn->update($this->testTable, 1, ['name' => 'Fail']);
        $this->assertFalse($result);
    }

    // =========================================================================
    // 8. RECOVERY WITH MULTIPLE TABLES
    // =========================================================================

    public function testRecoverRollsBackOrphanedTransactionWithMultipleTables()
    {
        $table2 = 'txn_test_orders';
        $path1 = JdbManager::getDataPath($this->testTable);
        $initialSize1 = file_exists($path1) ? filesize($path1) : 0;
        $path2 = JdbManager::getDataPath($table2);
        $initialSize2 = file_exists($path2) ? filesize($path2) : 0;

        $fakePid = 9999998;
        $randomHex = bin2hex(random_bytes(8));
        
        // Payload: magic (4), version (2), count (1), pid (4)
        $payload = pack('a4vCV', JdbTransaction::TXN_MAGIC, JdbTransaction::TXN_VERSION, 2, $fakePid);
        $payload .= $this->packU64(time());
        $payload .= pack('a8', hex2bin($randomHex));
        $payload .= pack('V', JdbUtil::crc32u($payload));

        // Table 1 record
        $tableData = pack('Ca63', strlen($this->testTable), $this->testTable);
        $tableData .= $this->packU64($initialSize1) . $this->packU64(0);
        // Table 2 record
        $tableData .= pack('Ca63', strlen($table2), $table2);
        $tableData .= $this->packU64($initialSize2) . $this->packU64(0);

        $contents = JdbUtil::PHP_DIE_HEADER . $payload . $tableData;
        $txnFile = $this->testDataDir . "/.txn_{$fakePid}_{$randomHex}.php";
        file_put_contents($txnFile, $contents);

        // Simulate writes that should be rolled back
        JdbManager::insert($this->testTable, ['name' => 'Should vanish']);
        JdbManager::insert($table2, ['item' => 'Should vanish']);
        $this->assertSame(1, JdbManager::count($this->testTable));
        $this->assertSame(1, JdbManager::count($table2));

        $recovered = JdbTransaction::recover($this->testDataDir);
        $this->assertSame(1, $recovered);
        $this->assertFileDoesNotExist($txnFile);
        $this->assertSame(0, JdbManager::count($this->testTable));
        $this->assertSame(0, JdbManager::count($table2));
    }

    // =========================================================================
    // 9. OPTIMISTIC VERSIONING (update/delete with expectedVersion)
    // =========================================================================
    public function testUpdateWithExpectedVersionFailsIfVersionMismatch()
    {
        $id = JdbManager::insert($this->testTable, ['name' => 'Alice', '_version' => 5]);
        $txn = new JdbTransaction($this->testDataDir);
        $txn->begin([$this->testTable]);

        $result = $txn->update($this->testTable, $id, ['name' => 'Wrong'], 10);
        $this->assertFalse($result);
        $this->assertNotEmpty($txn->getLastError()['message']);
    }

    // =========================================================================
    // 10. RESET BEHAVIOR AFTER DIFFERENT STATES
    // =========================================================================
    public function testResetClearsErrorAndAllowsNewBeginAfterCommit()
    {
        $txn = new JdbTransaction($this->testDataDir);
        $txn->begin([$this->testTable]);
        $txn->insert($this->testTable, ['name' => 'Data']);
        $txn->commit();
        $this->assertSame(JdbTransaction::STATE_COMMITTED, $txn->getState());

        $txn->reset();
        $this->assertSame(JdbTransaction::STATE_IDLE, $txn->getState());
        $this->assertNull($txn->getLastError());

        // Can begin again
        $this->assertTrue($txn->begin([$this->testTable]));
        $txn->rollback();
    }

    public function testResetClearsErrorAndAllowsNewBeginAfterRollback()
    {
        $txn = new JdbTransaction($this->testDataDir);
        $txn->begin([$this->testTable]);
        $txn->rollback();
        $txn->reset();
        $this->assertTrue($txn->begin([$this->testTable]));
        $txn->commit();
    }

    // =========================================================================
    // 11. ERROR FORWARDING FROM JdbManager (simulated failure)
    // =========================================================================
    public function testInsertFailureForwardsJdbManagerError()
    {
        // Force JdbManager to fail by closing the data file (simulate readonly)
        // We use a mock or simply rely on JdbManager's internal error.
        // A simple way: create a table with a non-writable data directory.
        $badTable = 'bad_table';
        $dataPath = JdbManager::getDataPath($badTable);
        touch($dataPath);
        chmod($dataPath, 0444); // read-only

        $txn = new JdbTransaction($this->testDataDir);
        $txn->begin([$badTable]);
        $result = @$txn->insert($badTable, ['fail' => 1]); // sopprime il warning

        $this->assertFalse($result);
        $error = $txn->getLastError();
        $this->assertNotEmpty($error['message']);

        // Restore permissions for cleanup
        chmod($dataPath, 0644);
        unlink($dataPath);
    }

    // =========================================================================
    // 12. PARSER AND CRC INTEGRITY
    // =========================================================================
    public function testParseTxnFileReturnsNullForTruncatedFile()
    {
        // Create a valid .txn file
        $this->createFakeTxnFile($this->testTable, 999999, 0);
        $files = glob($this->testDataDir . '/.txn_*.php');
        $this->assertCount(1, $files);
        $path = $files[0];

        $content = file_get_contents($path);
        // Truncate the last 10 bytes
        file_put_contents($path, substr($content, 0, strlen($content) - 10));

        $reflection = new ReflectionClass(JdbTransaction::class);
        $method = $reflection->getMethod('parseTxnFile');
        $method->setAccessible(true);
        $result = $method->invoke(null, $path);

        $this->assertNull($result);
    }

    // =========================================================================
    // 13. TRANSIZIONI DI STATO ILLEGALI
    // =========================================================================

    /**
     * commit() called a second time must fail: the .txn file no longer exists
     * and the state is no longer ACTIVE.
     */
    public function testDoubleCommitFails(): void
    {
        $txn = new JdbTransaction($this->testDataDir);
        $txn->begin([$this->testTable]);

        $this->assertTrue($txn->commit());
        $this->assertSame(JdbTransaction::STATE_COMMITTED, $txn->getState());

        // Second commit: must fail
        $result = $txn->commit();
        $this->assertFalse($result);
        $error = $txn->getLastError();
        $this->assertNotNull($error);
        $this->assertSame('commit', $error['method']);
    }

    /**
     * rollback() called after a completed commit must fail: the state is no longer ACTIVE.
     */
    public function testRollbackAfterCommitFails(): void
    {
        $txn = new JdbTransaction($this->testDataDir);
        $txn->begin([$this->testTable]);
        $txn->commit();

        $result = $txn->rollback();
        $this->assertFalse($result);

        $error = $txn->getLastError();
        $this->assertNotNull($error);
        $this->assertSame('rollback', $error['method']);
        $this->assertStringContainsString(JdbTransaction::STATE_COMMITTED, $error['message']);
    }

    /**
     * commit() called after a completed rollback must fail.
     */
    public function testCommitAfterRollbackFails(): void
    {
        $txn = new JdbTransaction($this->testDataDir);
        $txn->begin([$this->testTable]);
        $txn->rollback();

        $result = $txn->commit();
        $this->assertFalse($result);

        $error = $txn->getLastError();
        $this->assertNotNull($error);
        $this->assertSame('commit', $error['method']);
        $this->assertStringContainsString(JdbTransaction::STATE_ROLLED_BACK, $error['message']);
    }

    /**
     * rollback() called twice must fail on the second call.
     */
    public function testDoubleRollbackFails(): void
    {
        $txn = new JdbTransaction($this->testDataDir);
        $txn->begin([$this->testTable]);

        $this->assertTrue($txn->rollback());

        $result = $txn->rollback();
        $this->assertFalse($result);

        $error = $txn->getLastError();
        $this->assertNotNull($error);
        $this->assertSame('rollback', $error['method']);
    }

    // =========================================================================
    // 14. INSERTBATCH: FAILURE OUTSIDE TRANSACTION AND ROLLBACK
    // =========================================================================

    /**
     * insertBatch() called without an active transaction must return false.
     */
    public function testInsertBatchFailsWhenNotActive(): void
    {
        $txn = new JdbTransaction($this->testDataDir);

        $result = $txn->insertBatch($this->testTable, [
            ['name' => 'Alice'],
            ['name' => 'Bob'],
        ]);

        $this->assertFalse($result);
        $error = $txn->getLastError();
        $this->assertNotNull($error);
        $this->assertSame('insertBatch', $error['method']);
    }

    /**
     * An insertBatch executed inside a transaction that is then rolled back must
     * leave no traces: the ID counter must return to its value before begin().
     */
    public function testInsertBatchIsFullyUndoneOnRollback(): void
    {
        // Stato iniziale: 1 record
        JdbManager::insert($this->testTable, ['name' => 'Alice']);

        $txn = new JdbTransaction($this->testDataDir);
        $txn->begin([$this->testTable]);

        $result = $txn->insertBatch($this->testTable, [
            ['name' => 'Bob'],
            ['name' => 'Charlie'],
            ['name' => 'Diana'],
        ]);
        $this->assertIsArray($result);
        $this->assertSame(3, $result['inserted']);
        $this->assertSame(4, JdbManager::count($this->testTable));

        $txn->rollback();

        // After rollback only Alice must remain
        $this->assertSame(1, JdbManager::count($this->testTable));
        foreach ($result['ids'] as $id) {
            $this->assertNull(JdbManager::findById($this->testTable, $id),
                "Record with id=$id should have been removed by rollback");
        }
    }

    /**
     * delete() called without an active transaction must return false.
     */
    public function testDeleteFailsWhenNotActive(): void
    {
        $id = JdbManager::insert($this->testTable, ['name' => 'Alice']);

        $txn = new JdbTransaction($this->testDataDir);
        $result = $txn->delete($this->testTable, $id);

        $this->assertFalse($result);
        $error = $txn->getLastError();
        $this->assertNotNull($error);
        $this->assertSame('delete', $error['method']);
        // The record must not have been touched
        $this->assertNotNull(JdbManager::findById($this->testTable, $id));
    }

    // =========================================================================
    // 15. DELETE WITH VERSION MISMATCH (OPTIMISTIC LOCKING)
    // =========================================================================

    /**
     * delete() with a wrong expectedVersion must fail without deleting the record.
     */
    public function testDeleteWithExpectedVersionFailsIfVersionMismatch(): void
    {
        $id = JdbManager::insert($this->testTable, ['name' => 'Alice', '_version' => 3]);

        $txn = new JdbTransaction($this->testDataDir);
        $txn->begin([$this->testTable]);

        // Versione attesa sbagliata: il record ha _version=3, noi passiamo 99
        $result = $txn->delete($this->testTable, $id, 99);

        $this->assertFalse($result);
        $error = $txn->getLastError();
        $this->assertNotNull($error);
        $this->assertNotEmpty($error['message']);

        // The record must still be present
        $this->assertNotNull(JdbManager::findById($this->testTable, $id));

        $txn->rollback();
    }

    /**
     * delete() with the correct expectedVersion must permanently delete the record.
     */
    public function testDeleteWithCorrectExpectedVersionSucceeds(): void
    {
        $id          = JdbManager::insert($this->testTable, ['name' => 'Alice']);
        $realVersion = JdbManager::getEntryVersion($this->testTable, $id);
        $this->assertNotFalse($realVersion);

        $txn = new JdbTransaction($this->testDataDir);
        $txn->begin([$this->testTable]);

        $result = $txn->delete($this->testTable, $id, $realVersion);
        $this->assertTrue($result);
        $this->assertNull(JdbManager::findById($this->testTable, $id));

        $txn->commit();
        $this->assertNull(JdbManager::findById($this->testTable, $id));
        $this->assertSame(0, JdbManager::count($this->testTable));
    }

    // =========================================================================
    // 16. CRASH RECOVERY: DIRECTORY PULITA E TRANSAZIONI MULTIPLE
    // =========================================================================

    /**
     * recover() on a directory with no .txn files must return 0.
     */
    public function testRecoverOnCleanDirectoryReturnsZero(): void
    {
        $count = JdbTransaction::recover($this->testDataDir);
        $this->assertSame(0, $count);
    }

    /**
     * recover() must correctly recover multiple orphan transactions and
     * restituire il conteggio esatto.
     */
    public function testRecoverHandlesMultipleOrphanedTransactions(): void
    {
        // Crea due file .txn orfani (PID fittizi morti)
        $this->createFakeTxnFile($this->testTable, 9999991, 0);
        $this->createFakeTxnFile($this->testTable, 9999992, 0);

        $txnFiles = glob($this->testDataDir . '/.txn_*.php');
        $this->assertCount(2, $txnFiles);

        $recovered = JdbTransaction::recover($this->testDataDir);

        $this->assertSame(2, $recovered);
        $this->assertCount(0, glob($this->testDataDir . '/.txn_*.php'));
    }

    /**
     * recover() must not touch .txn files belonging to alive processes while
     * recovering dead ones in the same pass: only dead-process files are counted.
     */
    public function testRecoverCountsOnlyDeadPidTransactions(): void
    {
        $livingPid = getmypid();
        $deadPid   = 9999993;

        $this->createFakeTxnFile($this->testTable, $livingPid, 0);
        $this->createFakeTxnFile($this->testTable, $deadPid,   0);

        $recovered = JdbTransaction::recover($this->testDataDir);

        // Only the entry with the dead PID must have been recovered
        $this->assertSame(1, $recovered);

        // The entry with the alive PID must still exist
        $remaining = glob($this->testDataDir . '/.txn_*.php');
        $this->assertCount(1, $remaining);
        $this->assertStringContainsString((string)$livingPid, basename($remaining[0]));
    }

    // =========================================================================
    // 17. CRC INTEGRITY IN THE .TXN FILE
    // =========================================================================

    /**
     * A .txn file with a tampered CRC must be considered corrupted by
     * parseTxnFile() (which must return null).
     */
    public function testParseTxnFileReturnsNullOnCrcMismatch(): void
    {
        $this->createFakeTxnFile($this->testTable, 999888, 0);
        $files = glob($this->testDataDir . '/.txn_*.php');
        $this->assertCount(1, $files);
        $path = $files[0];

        $content = file_get_contents($path);

        // Corrupt 4 bytes in the CRC area (last 4 bytes of the fixed header, after the PHP header)
        $crcOffset = JdbUtil::DATA_HEADER_SIZE + JdbTransaction::TXN_FIXED_SIZE - 4;
        $content[$crcOffset]     = chr(0xFF);
        $content[$crcOffset + 1] = chr(0xFF);
        file_put_contents($path, $content);

        $reflection = new ReflectionClass(JdbTransaction::class);
        $method = $reflection->getMethod('parseTxnFile');
        $method->setAccessible(true);

        $result = $method->invoke(null, $path);
        $this->assertNull($result, 'parseTxnFile should return null for a wrong CRC');
    }

    /**
     * recover() must silently delete a .txn file with a corrupted CRC
     * and must not count it as a recovered transaction.
     */
    public function testRecoverDeletesFilesWithBadCrc(): void
    {
        $this->createFakeTxnFile($this->testTable, 999777, 0);
        $files = glob($this->testDataDir . '/.txn_*.php');
        $path  = $files[0];

        // Corrompe il CRC
        $content    = file_get_contents($path);
        $crcOffset  = JdbUtil::DATA_HEADER_SIZE + JdbTransaction::TXN_FIXED_SIZE - 4;
        $content[$crcOffset] = chr(0xAB);
        file_put_contents($path, $content);

        $recovered = JdbTransaction::recover($this->testDataDir);

        $this->assertSame(0, $recovered);
        $this->assertFileDoesNotExist($path, 'The corrupted file must be deleted by recover()');
    }

    // =========================================================================
    // 18. DUMPTXNFILE SU FILE INESISTENTE
    // =========================================================================
 
    /**
     * dumpTxnFile() on a non-existent path must return the error message
     * instead of throwing an exception or triggering a warning.
     */
    public function testDumpTxnFileOnNonExistentPathReturnsError(): void
    {
        $nonExistent = $this->testDataDir . '/.txn_no_such_file.php';
 
        $dump = JdbTransaction::dumpTxnFile($nonExistent);
 
        $this->assertIsString($dump);
        $this->assertStringContainsString('Invalid or corrupted file', $dump);
    }
 
    // =========================================================================
    // 19. RIUTILIZZO COMPLETO DELL'ISTANZA
    // =========================================================================
 
    /**
     * The same instance can be reused across multiple complete cycles:
     *   Cycle 1: begin → insert → rollback → reset
     *   Cycle 2: begin → insert → commit
     * Data from cycle 1 must not appear in the final result.
     */
    public function testFullReuseLifecycle(): void
    {
        $txn = new JdbTransaction($this->testDataDir);
 
        // ── Cycle 1: insert then rollback ───────────────────────────────────
        $this->assertTrue($txn->begin([$this->testTable]));
        $txn->insert($this->testTable, ['name' => 'Temporary']);
        $this->assertTrue($txn->rollback());
        $this->assertSame(JdbTransaction::STATE_ROLLED_BACK, $txn->getState());
 
        $txn->reset();
        $this->assertSame(JdbTransaction::STATE_IDLE, $txn->getState());
 
        // ── Cycle 2: insert then commit ─────────────────────────────────────
        $this->assertTrue($txn->begin([$this->testTable]));
        $id2 = $txn->insert($this->testTable, ['name' => 'Permanent']);
        $this->assertNotFalse($id2);
        $this->assertTrue($txn->commit());
 
        // Final result: exactly 1 record, the one from cycle 2.
        // Note: after rollback the ID counter may be reset,
        // so we compare by content rather than by ID.
        $this->assertSame(1, JdbManager::count($this->testTable));
 
        $all   = JdbManager::readAll($this->testTable);
        $names = array_column($all, 'name');
 
        $this->assertNotContains('Temporary', $names,
            "Record inserted in cycle 1 then rolled back must not exist");
        $this->assertContains('Permanent', $names,
            "Record inserted in cycle 2 then committed must exist");
    }
 
    /**
     * After a commit, reset() must also clear any error left over from a
     * failed operation within the same transaction.
     */
    public function testResetClearsLastErrorFromPreviousCycle(): void
    {
        $txn = new JdbTransaction($this->testDataDir);
 
        // Cycle 1: generate an error (update on a non-existent record)
        $txn->begin([$this->testTable]);
        $txn->update($this->testTable, 999, ['name' => 'Ghost']); // Deve fallire
        $txn->rollback();
 
        $this->assertNotNull($txn->getLastError());
 
        $txn->reset();
        $this->assertNull($txn->getLastError(),
            "reset() must clear the error from the previous cycle");
    }
 
    // =========================================================================
    // 20. COSTRUTTORE: NORMALIZZAZIONE DEL PATH
    // =========================================================================
 
    /**
     * The constructor must strip any trailing slash or backslash from dataDir
     * so that .txn file paths are built correctly.
     */
    public function testConstructorNormalizesTrailingSlash(): void
    {
        $dirWithSlash = $this->testDataDir . '/';
        $txn = new JdbTransaction($dirWithSlash);
 
        $txn->begin([$this->testTable]);
 
        $files = glob($this->testDataDir . '/.txn_*.php');
        $this->assertCount(1, $files,
            "The .txn file must be created in the correct directory even with a trailing slash");
 
        // The path must not contain a double slash
        $this->assertStringNotContainsString('//', $files[0]);
 
        $txn->commit();
    }
 
    /**
     * The constructor must also strip the trailing backslash (Windows-style path).
     */
    public function testConstructorNormalizesTrailingBackslash(): void
    {
        $dirWithBackslash = $this->testDataDir . '\\';
        $txn = new JdbTransaction($dirWithBackslash);
 
        // begin() must work without generating malformed paths
        $result = $txn->begin([$this->testTable]);
        $this->assertTrue($result);
        $txn->rollback();
    }

    // =========================================================================
    // HELPER METHODS
    // =========================================================================

    private function createFakeTxnFile(string $table, int $fakePid, int $dataOffset): void
    {
        $randomHex   = JdbUtil::randomSuffix(8);
        $randomBytes = hex2bin($randomHex);

        $payload  = pack('a4vCV', JdbTransaction::TXN_MAGIC, JdbTransaction::TXN_VERSION, 1, $fakePid);
        $payload .= $this->packU64(time());
        $payload .= pack('a8', $randomBytes);
        $payload .= pack('V', JdbUtil::crc32u($payload));

        $nameLen    = strlen($table);
        $tableData  = pack('Ca63', $nameLen, $table);
        $tableData .= $this->packU64($dataOffset);
        $tableData .= $this->packU64(0);

        $contents = JdbUtil::PHP_DIE_HEADER . $payload . $tableData;
        $txnName  = sprintf('.txn_%d_%s.php', $fakePid, $randomHex);
        file_put_contents($this->testDataDir . '/' . $txnName, $contents);
    }

    /**
     * Replicates JdbTransaction::_packU64 for the helper method.
     */
    private function packU64(int $value): string
    {
        $lo = $value & 0xFFFFFFFF;
        $hi = ($value >> 32) & 0xFFFFFFFF;
        return pack('VV', $lo, $hi);
    }
}
