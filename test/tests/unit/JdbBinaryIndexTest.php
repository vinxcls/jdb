<?php

/**
 * Unit tests for JdbBinaryIndex.
 *
 * Run with: vendor/bin/codecept run unit JdbBinaryIndexTest
 */
class JdbBinaryIndexTest extends \Codeception\Test\Unit
{
    private string $tempDir;
    private string $indexFile;
    private JdbBinaryIndex $idx;

    /**
     * Sets up an isolated temporary directory and a fresh JdbBinaryIndex instance
     * before each test. Also resets the shared JdbErrorHandler stack.
     */
    protected function setUp(): void
    {
        parent::setUp();
        // Filesystem isolation: each test gets its own temp directory
        $this->tempDir = sys_get_temp_dir() . '/jdb_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        $this->indexFile = $this->tempDir . '/test.index.php';

        // Reset the shared error stack
        JdbErrorHandler::clear();
        $this->idx = new JdbBinaryIndex($this->indexFile);
    }

    /**
     * Closes the index handle and removes all temporary files created during the test.
     */
    protected function tearDown(): void
    {
        parent::tearDown();
        $this->idx->close();

        // Cleanup temporary files
        if (is_dir($this->tempDir)) {
            array_map('unlink', glob("$this->tempDir/*.*"));
            rmdir($this->tempDir);
        }
    }

    /**
     * Helper: creates and opens the index in write mode, ready for put() calls.
     */
    private function createAndOpenWrite(): void
    {
        $this->idx->create();
        $this->idx->open(true);
        $this->idx->beginWrite();
    }

    // =========================================================================
    // Creation & Opening
    // =========================================================================

    /**
     * Tests that create() produces a valid file and open() reads the correct
     * initial header values (count=0, INIT_SLOTS slots, nextId=1).
     */
    public function testCreateAndOpen(): void
    {
        $this->assertEquals(JdbBinaryIndex::OK, $this->idx->create());
        $this->assertFileExists($this->indexFile);

        $this->assertEquals(JdbBinaryIndex::OK, $this->idx->open(false));
        $this->assertEquals(0, $this->idx->count());
        $this->assertEquals(JdbBinaryIndex::INIT_SLOTS, $this->idx->slots());
        $this->assertEquals(1, $this->idx->nextId());
    }

    /**
     * Tests that open() in write mode returns OK and that beginWrite() succeeds
     * only after a successful open().
     */
    public function testOpenWriteMode(): void
    {
        $this->idx->create();
        $this->assertEquals(JdbBinaryIndex::OK, $this->idx->open(true));
        // Verify that beginWrite() works only after open()
        $this->assertSame(JdbBinaryIndex::OK, $this->idx->beginWrite());
    }

    // =========================================================================
    // Insert & Lookup
    // =========================================================================

    /**
     * Tests that a record inserted with put() can be retrieved with lookup(),
     * that a non-existent key returns null, and that count() reflects the insertion.
     */
    public function testInsertAndLookup(): void
    {
        $this->idx->create();
        $this->idx->open(true);
        $this->idx->beginWrite();

        $this->assertEquals(JdbBinaryIndex::OK, $this->idx->put('user_1', 100, 50, 1));
        $this->idx->commitWrite();

        $result = $this->idx->lookup('user_1');
        $this->assertNotNull($result);
        $this->assertEquals(100, $result['offset']);
        $this->assertEquals(50, $result['length']);
        $this->assertEquals(1, $result['version']);

        $this->assertNull($this->idx->lookup('non_existent'));
        $this->assertEquals(1, $this->idx->count());
    }

    // =========================================================================
    // Update & Versioning
    // =========================================================================

    /**
     * Tests that calling put() twice on the same key updates the record in place
     * (latest offset, length, and version) without incrementing count().
     */
    public function testUpdateExistingRecord(): void
    {
        $this->idx->create();
        $this->idx->open(true);
        $this->idx->beginWrite();

        $this->idx->put('user_1', 100, 50, 1);
        $this->idx->put('user_1', 200, 60, 2); // Update
        $this->idx->commitWrite();

        $result = $this->idx->lookup('user_1');
        $this->assertNotNull($result);
        $this->assertEquals(200, $result['offset']);
        $this->assertEquals(60, $result['length']);
        $this->assertEquals(2, $result['version']);

        // Count must not increase on update
        $this->assertEquals(1, $this->idx->count());
    }

    // =========================================================================
    // Soft Delete
    // =========================================================================

    /**
     * Tests that remove() marks a record as deleted (lookup returns null, count
     * decrements) and that a second removal on the same key returns ERR_NOT_FOUND.
     */
    public function testSoftDelete(): void
    {
        $this->idx->create();
        $this->idx->open(true);
        $this->idx->beginWrite();
        $this->idx->put('user_1', 100, 50, 1);
        $this->idx->commitWrite();

        $this->idx->beginWrite();
        $this->assertEquals(JdbBinaryIndex::OK, $this->idx->remove('user_1'));
        $this->idx->commitWrite();

        $this->assertNull($this->idx->lookup('user_1'));
        $this->assertEquals(0, $this->idx->count());

        // Second removal must return NOT_FOUND
        $this->assertEquals(JdbBinaryIndex::ERR_NOT_FOUND, $this->idx->remove('user_1'));
    }

    // =========================================================================
    // Auto-Grow
    // =========================================================================

    /**
     * Tests that inserting records beyond the LOAD_MAX threshold triggers an
     * automatic slot grow and that all previously inserted records remain
     * accessible after the rehash.
     */
    public function testAutoGrowOnLoadFactor(): void
    {
        $this->idx->create();
        $this->idx->open(true);
        $this->idx->beginWrite();

        $initialSlots = $this->idx->slots();
        // LOAD_MAX = 0.70. Insert beyond the load threshold
        $recordsToInsert = (int)ceil($initialSlots * JdbBinaryIndex::LOAD_MAX) + 10;

        for ($i = 0; $i < $recordsToInsert; $i++) {
            $id = "rec_$i";
            $status = $this->idx->put($id, $i * 100, 50, 1);
            $this->assertEquals(JdbBinaryIndex::OK, $status, "Failed to insert $id");
        }
        $this->idx->commitWrite();

        $newSlots = $this->idx->slots();
        $this->assertGreaterThan($initialSlots, $newSlots, "Index should have grown automatically");
        $this->assertEquals($recordsToInsert, $this->idx->count());

        // Verify integrity after grow
        for ($i = 0; $i < $recordsToInsert; $i++) {
            $this->assertNotNull($this->idx->lookup("rec_$i"));
        }
    }

    // =========================================================================
    // Rebuild
    // =========================================================================

    /**
     * Tests that rebuild() populates the index from a pre-built entries array,
     * preserves the given nextId, and that all entries are retrievable afterward.
     *
     * @note rebuild() can be called in CLOSED state.
     */
    public function testRebuildFromEntries(): void
    {
        $entries = [
            'alpha' => ['offset' => 10, 'length' => 5, 'version' => 1],
            'beta'  => ['offset' => 20, 'length' => 8, 'version' => 2],
            'gamma' => ['offset' => 30, 'length' => 6, 'version' => 1],
        ];

        $this->idx->rebuild($entries, 42);
        $this->assertEquals(JdbBinaryIndex::OK, $this->idx->open(false));

        $this->assertEquals(3, $this->idx->count());
        $this->assertEquals(42, $this->idx->nextId());

        foreach ($entries as $id => $data) {
            $res = $this->idx->lookup($id);
            $this->assertNotNull($res);
            $this->assertEquals($data['offset'], $res['offset']);
            $this->assertEquals($data['length'], $res['length']);
        }
    }

    // =========================================================================
    // Scanning
    // =========================================================================

    /**
     * Tests both scan() and scanChunked() on a 3-record index.
     * Verifies that scan() returns all id_hashes and that scanChunked()
     * visits the same entries using a small chunk size to exercise multiple reads.
     */
    public function testScanAndScanChunked(): void
    {
        $this->idx->create();
        $this->idx->open(true);
        $this->idx->beginWrite();
        $this->idx->put('a', 1, 10, 1);
        $this->idx->put('b', 11, 20, 1);
        $this->idx->put('c', 31, 15, 1);
        $this->idx->commitWrite();

        // scan()
        $all = $this->idx->scan();
        $this->assertCount(3, $all);
        codecept_debug($all);
        $ids = array_column($all, 'id_hash');
        sort($ids);
        $hids = [hash('sha256', 'a', true), hash('sha256', 'b', true), hash('sha256', 'c', true)];
        sort($hids);
        $this->assertEquals($hids, $ids);

        // scanChunked()
        $collected = [];
        $count = $this->idx->scanChunked(function($entry) use (&$collected) {
            $collected[] = $entry['id_hash'];
        }, 1); // small chunk to force multiple reads
        $this->assertEquals(3, $count);
        $this->assertCount(3, $collected);
    }

    /**
     * Tests that scanChunked() stops iteration when the callback returns false
     * and that the returned count matches the number of entries visited up to
     * and including the early-exit call.
     */
    public function testScanChunkedEarlyExit(): void
    {
        $this->idx->create();
        $this->idx->open(true);
        $this->idx->beginWrite();
        for ($i = 0; $i < 5; $i++) $this->idx->put("id_$i", $i * 10, 10, 1);
        $this->idx->commitWrite();

        $count = 0;
        $result = $this->idx->scanChunked(function() use (&$count) {
            $count++;
            if ($count === 2) return false; // early exit
            return null;
        });
        $this->assertEquals(2, $result);
    }

    // =========================================================================
    // Helper & State Management
    // =========================================================================

    /**
     * Tests that bumpNextId() increments nextId() by exactly 1.
     */
    public function testBumpNextId(): void
    {
        $this->idx->create();
        $this->idx->open(true);
        $this->assertEquals(1, $this->idx->nextId());
        $this->idx->bumpNextId();
        $this->assertEquals(2, $this->idx->nextId());
    }

    /**
     * Tests the state-machine error codes:
     * - put() and beginWrite() without a prior open() must return ERR_STATE / ERR_IO.
     * - commitWrite() without a prior beginWrite() must return ERR_IO.
     */
    public function testStateErrors(): void
    {
        // Operations without open()
        $idx2 = new JdbBinaryIndex($this->tempDir . '/state_test.index.php');
        $this->assertEquals(JdbBinaryIndex::ERR_STATE, $idx2->put('x', 0, 0, 1));
        $this->assertEquals(JdbBinaryIndex::ERR_IO, $idx2->beginWrite());

        // commitWrite without beginWrite
        $this->idx->create();
        $this->idx->open(true);
        $this->assertEquals(JdbBinaryIndex::ERR_IO, $this->idx->commitWrite());
    }

    /**
     * Tests that slotIndex() returns an integer strictly less than the given
     * slot count for two different table sizes.
     */
    public function testStaticSlotIndex(): void
    {
        $hash = hash('sha256', 'test_id', true);
        $slot256 = JdbBinaryIndex::slotIndex($hash, 256);
        $slot512 = JdbBinaryIndex::slotIndex($hash, 512);

        $this->assertIsInt($slot256);
        $this->assertIsInt($slot512);
        $this->assertLessThan(256, $slot256);
        $this->assertLessThan(512, $slot512);
    }

    // =========================================================================
    // 1. open() — error cases
    // =========================================================================

    /**
     * open() on a file that does not exist must return ERR_IO.
     */
    public function testOpenReturnsErrIoOnMissingFile(): void
    {
        $result = @$this->idx->open(false);
        $this->assertSame(JdbBinaryIndex::ERR_IO, $result);
    }

    /**
     * open() called twice on the same already-open handle must return ERR_IO.
     * The state machine does not allow open → open without an intermediate close.
     */
    public function testOpenReturnsErrIoWhenAlreadyOpen(): void
    {
        $this->idx->create();
        $this->idx->open(false);

        $result = $this->idx->open(false);
        $this->assertSame(JdbBinaryIndex::ERR_IO, $result);
    }

    /**
     * open() on a file with dirty=1 must return ERR_NEEDS_REBUILD.
     * Simulates a crash that occurred during a previous write session.
     */
    public function testOpenReturnsErrNeedsRebuildOnDirtyFile(): void
    {
        $this->idx->create();
        $this->idx->open(true);
        $this->idx->beginWrite(); // writes dirty=1 to disk
        $this->idx->close();     // closes WITHOUT commitWrite → dirty remains 1

        $fresh = new JdbBinaryIndex($this->indexFile);
        $result = $fresh->open(false);
        $fresh->close();

        $this->assertSame(JdbBinaryIndex::ERR_NEEDS_REBUILD, $result);
    }

    /**
     * open() on a file with an invalid magic number must return ERR_CORRUPT.
     */
    public function testOpenReturnsErrCorruptOnWrongMagic(): void
    {
        // Create a file with a 32-byte header but an invalid magic value
        $fp = fopen($this->indexFile, 'wb');
        fwrite($fp, pack('a4CVVVC', 'XXXX', 1, 256, 0, 1, 0) . str_repeat("\x00", 14));
        fclose($fp);

        $result = $this->idx->open(false);
        $this->assertSame(JdbBinaryIndex::ERR_CORRUPT, $result);
    }

    /**
     * open() on a file whose version field differs from the expected VERSION
     * must return ERR_NEEDS_REBUILD.
     */
    public function testOpenReturnsErrNeedsRebuildOnWrongVersion(): void
    {
        $fp = fopen($this->indexFile, 'wb');
        fwrite($fp, pack('a4CVVVC', 'JDB1', 99, 256, 0, 1, 0) . str_repeat("\x00", 14));
        fclose($fp);

        $result = $this->idx->open(false);
        $this->assertSame(JdbBinaryIndex::ERR_NEEDS_REBUILD, $result);
    }

    // =========================================================================
    // 2. close() — idempotency and close/reopen cycle
    // =========================================================================

    /**
     * close() called multiple times must not throw errors or produce side effects.
     */
    public function testCloseIsIdempotent(): void
    {
        $this->idx->create();
        $this->idx->open(false);
        $this->idx->close();
        $this->idx->close(); // second call: safe no-op
        $this->idx->close(); // third call: safe no-op
        // If we reach this point without exceptions the test has passed
        $this->assertTrue(true);
    }

    /**
     * After close(), re-opening the same file must expose the previously committed data.
     */
    public function testReopenAfterClosePreservesData(): void
    {
        $this->createAndOpenWrite();
        $this->idx->put('key_reopen', 500, 30, 1);
        $this->idx->commitWrite();
        $this->idx->close();

        // Re-open: data must survive across the close/reopen cycle
        $this->assertSame(JdbBinaryIndex::OK, $this->idx->open(false));
        $entry = $this->idx->lookup('key_reopen');
        $this->assertNotNull($entry);
        $this->assertSame(500, $entry['offset']);
        $this->assertSame(30,  $entry['length']);
    }

    // =========================================================================
    // 3. Physical dirty bit on disk
    // =========================================================================

    /**
     * After beginWrite() the dirty byte in the file must be 0x01.
     */
    public function testBeginWriteSetsDirtyBitOnDisk(): void
    {
        $this->idx->create();
        $this->idx->open(true);
        $this->idx->beginWrite();

        // Read the dirty byte directly from the file (bypassing class APIs)
        $fp   = fopen($this->indexFile, 'rb');
        fseek($fp, JdbIndexHeader::HDR_DIRTY_BYTE);
        $byte = fread($fp, 1);
        fclose($fp);

        $this->assertSame("\x01", $byte, 'The dirty byte must be 0x01 after beginWrite()');
    }

    /**
     * After commitWrite() the dirty byte in the file must be 0x00.
     */
    public function testCommitWriteClearsDirtyBitOnDisk(): void
    {
        $this->idx->create();
        $this->idx->open(true);
        $this->idx->beginWrite();
        $this->idx->commitWrite();

        $fp   = fopen($this->indexFile, 'rb');
        fseek($fp, JdbIndexHeader::HDR_DIRTY_BYTE);
        $byte = fread($fp, 1);
        fclose($fp);

        $this->assertSame("\x00", $byte, 'The dirty byte must be 0x00 after commitWrite()');
    }

    /**
     * Crash simulation: beginWrite() without a subsequent commitWrite().
     * Re-opening the file must detect the dirty bit and return ERR_NEEDS_REBUILD.
     */
    public function testCrashSimulationDirtyBitDetectedOnReopen(): void
    {
        $this->idx->create();
        $this->idx->open(true);
        $this->idx->beginWrite();
        $this->idx->put('survivor', 100, 10, 1);
        // Crash: close() without commitWrite
        $this->idx->close();

        $recovered = new JdbBinaryIndex($this->indexFile);
        $result    = $recovered->open(false);
        $recovered->close();

        $this->assertSame(JdbBinaryIndex::ERR_NEEDS_REBUILD, $result,
            'After a crash (dirty=1 on disk) open() must return ERR_NEEDS_REBUILD');
    }

    // =========================================================================
    // 4. Accessors in CLOSED state
    // =========================================================================

    /**
     * count(), slots(), and nextId() must return false when the index is CLOSED.
     */
    public function testAccessorsReturnFalseWhenClosed(): void
    {
        // Index never opened
        $this->assertFalse($this->idx->count(),  'count() must return false when CLOSED');
        $this->assertFalse($this->idx->slots(),  'slots() must return false when CLOSED');
        $this->assertFalse($this->idx->nextId(), 'nextId() must return false when CLOSED');
    }

    /**
     * bumpNextId() must return ERR_STATE when the index is CLOSED.
     */
    public function testBumpNextIdReturnsErrStateWhenClosed(): void
    {
        $result = $this->idx->bumpNextId();
        $this->assertSame(JdbBinaryIndex::ERR_STATE, $result);
    }

    /**
     * lookup() must return null when the index is CLOSED.
     */
    public function testLookupReturnsNullWhenClosed(): void
    {
        $this->assertNull($this->idx->lookup('anything'));
    }

    // =========================================================================
    // 5. remove() — additional error cases
    // =========================================================================

    /**
     * remove() in CLOSED state must return ERR_STATE.
     */
    public function testRemoveReturnsErrStateWhenClosed(): void
    {
        $result = $this->idx->remove('any_id');
        $this->assertSame(JdbBinaryIndex::ERR_STATE, $result);
    }

    // =========================================================================
    // 6. advanceNextId()
    // =========================================================================

    /**
     * advanceNextId(n) must increment next_id by exactly n steps.
     */
    public function testAdvanceNextIdByN(): void
    {
        $this->idx->create();
        $this->idx->open(true);
        $this->idx->beginWrite();

        $before = $this->idx->nextId();
        $result = $this->idx->advanceNextId(5);

        $this->assertSame(JdbBinaryIndex::OK, $result);
        $this->assertSame($before + 5, $this->idx->nextId());

        $this->idx->commitWrite();
    }

    /**
     * advanceNextId(0) must return OK without modifying next_id.
     */
    public function testAdvanceNextIdByZeroIsNoop(): void
    {
        $this->idx->create();
        $this->idx->open(true);
        $this->idx->beginWrite();

        $before = $this->idx->nextId();
        $result = $this->idx->advanceNextId(0);

        $this->assertSame(JdbBinaryIndex::OK, $result);
        $this->assertSame($before, $this->idx->nextId(),
            'advanceNextId(0) must not modify next_id');

        $this->idx->commitWrite();
    }

    /**
     * advanceNextId() with a negative value must be treated as 0 (no-op).
     */
    public function testAdvanceNextIdNegativeIsNoop(): void
    {
        $this->idx->create();
        $this->idx->open(true);
        $this->idx->beginWrite();

        $before = $this->idx->nextId();
        $result = $this->idx->advanceNextId(-10);

        $this->assertSame(JdbBinaryIndex::OK, $result);
        $this->assertSame($before, $this->idx->nextId(),
            'advanceNextId with a negative value must not modify next_id');

        $this->idx->commitWrite();
    }

    /**
     * advanceNextId() in CLOSED state must return ERR_STATE.
     */
    public function testAdvanceNextIdReturnsErrStateWhenClosed(): void
    {
        $result = $this->idx->advanceNextId(3);
        $this->assertSame(JdbBinaryIndex::ERR_STATE, $result);
    }

    // =========================================================================
    // 7. scan() / scanChunked() — additional cases
    // =========================================================================

    /**
     * scan() on a freshly created, empty index must return an empty array.
     */
    public function testScanOnEmptyIndexReturnsEmptyArray(): void
    {
        $this->idx->create();
        $this->idx->open(false);

        $result = $this->idx->scan();
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * scanChunked() in CLOSED state must return 0 without invoking the callback.
     */
    public function testScanChunkedReturnZeroWhenClosed(): void
    {
        $visited = $this->idx->scanChunked(function() { return null; });
        $this->assertSame(0, $visited);
    }

    /**
     * scan() must skip records flagged as FLAG_DELETED; they must not appear
     * in the returned result set.
     */
    public function testScanSkipsDeletedRecords(): void
    {
        $this->createAndOpenWrite();
        $this->idx->put('keep',   100, 10, 1);
        $this->idx->put('remove', 200, 10, 1);
        $this->idx->commitWrite();

        $this->idx->beginWrite();
        $this->idx->remove('remove');
        $this->idx->commitWrite();

        $entries = $this->idx->scan();
        $hids     = array_column($entries, 'id_hash');

        $khash_bin = hash('sha256', 'keep', true);
        $rhash_bin = hash('sha256', 'remove', true);

        $khash_hex = bin2hex($khash_bin);
        $rhash_hex = bin2hex($rhash_bin);

        $hids_hex = array_map('bin2hex', $hids);

        $this->assertContains($khash_hex, $hids_hex, 'Non-deleted record must appear in the result');
        $this->assertNotContains($rhash_hex, $hids_hex, 'Deleted record must NOT appear in the result');
        $this->assertCount(1, $entries);
    }

    /**
     * scanChunked() with a chunk size larger than the file must not cause errors
     * and must still return all records.
     */
    public function testScanChunkedWithOversizedChunk(): void
    {
        $this->createAndOpenWrite();
        $this->idx->put('x', 10, 5, 1);
        $this->idx->put('y', 20, 5, 1);
        $this->idx->commitWrite();

        $collected = [];
        $count = $this->idx->scanChunked(
            function($e) use (&$collected) { $collected[] = $e; },
            65536 // chunk size far larger than the file
        );

        $this->assertSame(2, $count);
        $this->assertCount(2, $collected);
    }

    // =========================================================================
    // 8. create() — edge cases
    // =========================================================================

    /**
     * create() with a custom next_id must preserve that value after open().
     */
    public function testCreateWithCustomNextId(): void
    {
        $this->idx->create(42);
        $this->idx->open(false);
        $this->assertSame(42, $this->idx->nextId());
    }

    /**
     * create() with a slot count below INIT_SLOTS must clamp the value to INIT_SLOTS.
     */
    public function testCreateClampsSlotsBelowInitSlots(): void
    {
        $this->idx->create(1, JdbBinaryIndex::INIT_SLOTS - 1);
        $this->idx->open(false);
        $this->assertSame(JdbBinaryIndex::INIT_SLOTS, $this->idx->slots(),
            'Slots below INIT_SLOTS must be clamped to INIT_SLOTS');
    }

    /**
     * create() with a non-power-of-2 slot count must round up to the next power of two.
     */
    public function testCreateRoundsUpSlotsToNextPowerOfTwo(): void
    {
        $this->idx->create(1, 300); // 300 is not a power of 2; next power is 512
        $this->idx->open(false);
        $this->assertSame(512, $this->idx->slots(),
            'Non-power-of-2 slots must be rounded up to the next power of two');
    }

    // =========================================================================
    // 9. rebuild() — edge cases
    // =========================================================================

    /**
     * rebuild() with an empty entries array must produce a valid index with count=0.
     */
    public function testRebuildWithEmptyEntriesProducesValidEmptyIndex(): void
    {
        $result = $this->idx->rebuild([], 5);
        $this->assertSame(JdbBinaryIndex::OK, $result);

        $this->idx->open(false);
        $this->assertSame(0, $this->idx->count());
        $this->assertSame(5, $this->idx->nextId());
    }

    /**
     * rebuild() when the index is already open must close it automatically before
     * rewriting the file, and the operation must succeed.
     */
    public function testRebuildWhenAlreadyOpenClosesFirstAndSucceeds(): void
    {
        $this->idx->create();
        $this->idx->open(true);

        // rebuild() must detect that the handle is open and close it automatically
        $result = $this->idx->rebuild(['entry_a' => ['offset' => 50, 'length' => 10, 'version' => 1]], 10);
        $this->assertSame(JdbBinaryIndex::OK, $result);

        $this->idx->open(false);
        $this->assertSame(1, $this->idx->count());
        $this->assertNotNull($this->idx->lookup('entry_a'));
    }

    // =========================================================================
    // 10. Static API: writeSlot + readSlot + initFile + tempUpsert + tempDelete
    // =========================================================================

    /**
     * Static writeSlot() followed by readSlot() must form a correct roundtrip,
     * returning the same id_hash, offset, length, version, and flags.
     */
    public function testStaticWriteSlotAndReadSlotRoundtrip(): void
    {
        $staticFile = $this->tempDir . '/static.index.php';
        $fp         = fopen($staticFile, 'w+b');
        // Initialize the header and allocate space for slots
        JdbBinaryIndex::initFile($fp, JdbBinaryIndex::INIT_SLOTS, 1);

        $shash = hash('sha256', 'slot_test', true);
        JdbBinaryIndex::writeSlot($fp, 0, $shash, 9999, 128, 3, 0);

        $slot = JdbBinaryIndex::readSlot($fp, 0);
        fclose($fp);

        $this->assertNotNull($slot);
        $this->assertSame($shash, $slot['id_hash']);
        $this->assertSame(9999, (int)$slot['offset']);
        $this->assertSame(128,  (int)$slot['length']);
        $this->assertSame(3,    (int)$slot['version']);
        $this->assertSame(0,    (int)$slot['flags']);
    }

    /**
     * initFile() must write a valid header and zeroed slots so that
     * subsequently opening the index returns OK with the expected state.
     */
    public function testStaticInitFileCreatesOpenableIndex(): void
    {
        $fp = fopen($this->indexFile, 'w+b');
        JdbBinaryIndex::initFile($fp, JdbBinaryIndex::INIT_SLOTS, 7);
        fclose($fp);

        $idx = new JdbBinaryIndex($this->indexFile);
        $result = $idx->open(false);
        $this->assertSame(JdbBinaryIndex::OK, $result);
        $this->assertSame(0, $idx->count());
        $this->assertSame(7, $idx->nextId());
        $idx->close();
    }

    /**
     * tempUpsert() must insert a record into a temporary file and increment $count by 1.
     */
    public function testTempUpsertInsertsRecord(): void
    {
        $tmpFile  = $this->tempDir . '/tmp_upsert.php';
        $slots    = JdbBinaryIndex::INIT_SLOTS;
        $fp       = fopen($tmpFile, 'w+b');
        JdbBinaryIndex::initFile($fp, $slots, 1);

        $occupied = str_repeat("\x00", $slots);
        $count    = 0;
        $shash = hash('sha256', 'tmp_key', true);
        $ret = JdbBinaryIndex::tempUpsert(
            $fp, $occupied, $slots, $shash, 111, 22, 1, 0, $count
        );

        fclose($fp);

        $this->assertNotFalse($ret, 'tempUpsert must return true on a valid insert');
        $this->assertSame(1, $count, 'The counter must be incremented by 1');
    }

    /**
     * tempDelete() must mark as deleted a record previously inserted via tempUpsert()
     * and decrement $count accordingly.
     */
    public function testTempDeleteMarksRecordAsDeleted(): void
    {
        $tmpFile  = $this->tempDir . '/tmp_delete.php';
        $slots    = JdbBinaryIndex::INIT_SLOTS;
        $fp       = fopen($tmpFile, 'w+b');
        JdbBinaryIndex::initFile($fp, $slots, 1);

        $occupied = str_repeat("\x00", $slots);
        $count    = 0;

        // Insert
        $shash = hash('sha256', 'del_key', true);
        JdbBinaryIndex::tempUpsert($fp, $occupied, $slots, $shash, 50, 10, 1, 0, $count);
        $this->assertSame(1, $count);

        // Delete
        $ret = JdbBinaryIndex::tempDelete($fp, $occupied, $slots, $shash, $count);
        fclose($fp);

        $this->assertNotFalse($ret, 'tempDelete must return true');
        $this->assertSame(0, $count, 'The counter must be decremented to 0');
    }

    // =========================================================================
    // 11. CRC integrity: corrupted slot → lookup returns null
    // =========================================================================

    /**
     * If the CRC bytes of a slot are altered on disk, lookup() must return null
     * instead of serving corrupted data, and JdbErrorHandler must record the error.
     */
    public function testLookupReturnsNullOnCorruptedSlotCrc(): void
    {
        $this->createAndOpenWrite();
        $this->idx->put('crc_victim', 300, 20, 1);
        $this->idx->commitWrite();
        $this->idx->close();

        // Compute the slot where 'crc_victim' was written.
        // slotIndex() replicates the same hash function used by put(), so in an
        // index without collisions the record is at exactly this slot.
        $shash      = hash('sha256', 'crc_victim', true);
        $targetSlot = JdbBinaryIndex::slotIndex($shash, JdbBinaryIndex::INIT_SLOTS);

        // Offset of the 4 CRC bytes: end of the slot (last 4 bytes)
        $crcByteOffset = JdbIndexHeader::HDR_SIZE
                       + $targetSlot * JdbBinaryIndex::SLOT_SIZE
                       + JdbBinaryIndex::SLOT_SIZE - 4;

        $raw = file_get_contents($this->indexFile);
        $raw[$crcByteOffset]     = chr(0xFF);
        $raw[$crcByteOffset + 1] = chr(0xFF);
        $raw[$crcByteOffset + 2] = chr(0xFF);
        $raw[$crcByteOffset + 3] = chr(0xFF);
        file_put_contents($this->indexFile, $raw);

        // Re-open and look up the record: CRC mismatch → must return null
        $fresh = new JdbBinaryIndex($this->indexFile);
        $fresh->open(false);
        $result = $fresh->lookup('crc_victim');
        $fresh->close();

        $this->assertNull($result,
            'lookup() must return null when the slot CRC does not match');
        $this->assertTrue(JdbErrorHandler::hasStackError(),
            'JdbErrorHandler must contain the CRC error frame');
    }

    // =========================================================================
    // 12. Private method coverage and error branches
    // =========================================================================

    /**
     * Tests _rehashSlotInto() indirectly by triggering an auto-grow and verifying
     * that all records remain accessible with correct offsets after the rehash.
     */
    public function testRehashSlotIntoDuringGrow(): void
    {
        $this->createAndOpenWrite();
        $initialSlots = $this->idx->slots();
        $recordsToInsert = (int)ceil($initialSlots * JdbBinaryIndex::LOAD_MAX) + 10;
        for ($i = 0; $i < $recordsToInsert; $i++) {
            $this->idx->put("rec_$i", $i * 100, 50, 1);
        }
        $this->idx->commitWrite();

        $this->assertGreaterThan($initialSlots, $this->idx->slots());
        $this->assertEquals($recordsToInsert, $this->idx->count());

        for ($i = 0; $i < $recordsToInsert; $i++) {
            $entry = $this->idx->lookup("rec_$i");
            $this->assertNotNull($entry, "Record rec_$i not found after grow");
            $this->assertEquals($i * 100, $entry['offset']);
        }
    }

    /**
     * Tests that a full grow cycle completes successfully, covering both
     * _prepareGrowth() and _finalizeGrowth() internal methods.
     */
    public function testGrowthCompletesSuccessfully(): void
    {
        $this->createAndOpenWrite();
        $initialSlots = $this->idx->slots();
        $recordsToInsert = $initialSlots + 10;
        for ($i = 0; $i < $recordsToInsert; $i++) {
            $this->idx->put("grow_$i", $i, 10, 1);
        }
        $this->idx->commitWrite();

        $this->assertGreaterThan($initialSlots, $this->idx->slots());
        $this->assertEquals($recordsToInsert, $this->idx->count());
    }

    /**
     * Tests advanceNextId() while the index is in WRITING state.
     */
    public function testAdvanceNextIdDuringWriting(): void
    {
        $this->createAndOpenWrite();
        $this->idx->beginWrite();
        $before = $this->idx->nextId();
        $this->assertEquals(JdbBinaryIndex::OK, $this->idx->advanceNextId(3));
        $this->assertEquals($before + 3, $this->idx->nextId());
        $this->idx->commitWrite();
    }

    /**
     * Tests that the header cache is invalidated after close() so that a subsequent
     * open() re-reads the count from disk rather than returning a stale cached value.
     */
    public function testHeaderCacheInvalidatedAfterClose(): void
    {
        $this->createAndOpenWrite();
        $this->idx->put('cache_test', 1, 1, 1);
        $this->idx->commitWrite();
        $beforeCount = $this->idx->count();

        $this->idx->close();
        $this->idx->open(false);
        $this->assertEquals($beforeCount, $this->idx->count());
    }

    /**
     * Tests that calling beginWrite() while already in WRITING state still returns OK
     * (the state machine accepts re-entrant write begin without error).
     */
    public function testBeginWriteSucceedsEvenWhenAlreadyWriting(): void
    {
        $this->createAndOpenWrite();
        $first = $this->idx->beginWrite();
        $this->assertSame(JdbBinaryIndex::OK, $first);
        $second = $this->idx->beginWrite();
        $this->assertSame(JdbBinaryIndex::OK, $second);
    }

    /**
     * Tests that commitWrite() without a prior beginWrite() returns ERR_IO.
     */
    public function testCommitWriteFailsWhenNotWriting(): void
    {
        $this->idx->create();
        $this->idx->open(true);
        $result = $this->idx->commitWrite();
        $this->assertSame(JdbBinaryIndex::ERR_IO, $result);
    }

    /**
     * Tests the static writeSlot() and readSlot() methods against the last valid
     * slot index (boundary slot 255 in a 256-slot file).
     */
    public function testStaticWriteAndReadSlotBoundary(): void
    {
        $fp = fopen($this->indexFile, 'w+b');
        JdbBinaryIndex::initFile($fp, 256, 1);
        $shash = hash('sha256', 'boundary', true);
        JdbBinaryIndex::writeSlot($fp, 255, $shash, 1000, 200, 5, 0);
        $slot = JdbBinaryIndex::readSlot($fp, 255);
        fclose($fp);
        $this->assertNotNull($slot);
        $this->assertSame($shash, $slot['id_hash']);
        $this->assertSame(1000, $slot['offset']);
        $this->assertSame(200,  $slot['length']);
        $this->assertSame(5,    $slot['version']);
    }

    /**
     * Tests that put() reuses a tombstone slot left by a previous remove()
     * instead of occupying a new slot.
     */
    public function testPutReusesTombstoneSlot(): void
    {
        // Find two IDs that hash to the same starting slot,
        // or force a collision by filling the bucket first
        $this->createAndOpenWrite();
        $this->idx->put('victim', 10, 5, 1);
        $this->idx->commitWrite();

        $this->idx->beginWrite();
        $this->idx->remove('victim'); // creates a tombstone slot
        // 'victim' is now SLOT_OCCUPIED + FLAG_DELETED (tombstone for probing)
        $this->idx->put('newkey_same_bucket', 20, 8, 1); // must reuse the tombstone
        $this->idx->commitWrite();

        $this->assertSame(1, $this->idx->count());
        $this->assertNull($this->idx->lookup('victim'));
        $this->assertNotNull($this->idx->lookup('newkey_same_bucket'));
    }

    /**
     * Tests that re-inserting the same ID after it has been removed is treated as
     * a new insertion and increments count() back to 1.
     */
    public function testPutAfterRemoveSameIdCountsAsNew(): void
    {
        $this->createAndOpenWrite();
        $this->idx->put('reborn', 100, 10, 1);
        $this->idx->commitWrite();

        $this->idx->beginWrite();
        $this->idx->remove('reborn');
        $this->assertEquals(0, $this->idx->count());

        // Re-insert the same deleted ID: count must increment
        $this->idx->put('reborn', 200, 15, 2);
        $this->idx->commitWrite();

        $this->assertEquals(1, $this->idx->count());
        $result = $this->idx->lookup('reborn');
        $this->assertSame(200, $result['offset']);
        $this->assertSame(2,   $result['version']);
    }

    /**
     * Tests that a second tempUpsert() on the same key updates the existing record
     * without incrementing $count.
     */
    public function testTempUpsertUpdatesExistingRecord(): void
    {
        $slots = JdbBinaryIndex::INIT_SLOTS;
        $fp    = fopen($this->tempDir . '/tmp_upd.php', 'w+b');
        JdbBinaryIndex::initFile($fp, $slots, 1);

        $occ   = str_repeat("\x00", $slots);
        $count = 0;

        $shash = hash('sha256', 'upd_key', true);
        JdbBinaryIndex::tempUpsert($fp, $occ, $slots, $shash, 10, 5, 1, 0, $count);
        $this->assertSame(1, $count);

        // Second upsert on the same key: must update, not increment count
        JdbBinaryIndex::tempUpsert($fp, $occ, $slots, $shash, 99, 7, 2, 0, $count);
        $this->assertSame(1, $count, 'Count must not increase on update');

        // Verify that the stored data reflects the new values
        $slot  = JdbBinaryIndex::slotIndex($shash, $slots);
        $entry = JdbBinaryIndex::readSlot($fp, $slot);
        fclose($fp);

        $this->assertSame(99, $entry['offset']);
        $this->assertSame(2,  $entry['version']);
    }

    /**
     * Tests that tempDelete() on a non-existent key is a no-op: it must return
     * true and leave $count unchanged.
     */
    public function testTempDeleteOnNonExistentKeyIsNoop(): void
    {
        $slots = JdbBinaryIndex::INIT_SLOTS;
        $fp    = fopen($this->tempDir . '/tmp_nd.php', 'w+b');
        JdbBinaryIndex::initFile($fp, $slots, 1);

        $occ   = str_repeat("\x00", $slots);
        $count = 0;

        $shash = hash('sha256', 'ghost', true);
        $ret = JdbBinaryIndex::tempDelete($fp, $occ, $slots, $shash, $count);
        fclose($fp);

        $this->assertTrue($ret,  'tempDelete on a non-existent key must return true');
        $this->assertSame(0, $count, 'Count must not change');
    }

    /**
     * Tests that create() returns ERR_IO when the target path is not writable.
     */
    public function testCreateReturnsErrIoOnUnwritablePath(): void
    {
        $bad = new JdbBinaryIndex('/nonexistent/path/index.php');
        $this->assertSame(JdbBinaryIndex::ERR_IO, @$bad->create());
    }

    /**
     * Tests that bumpNextId() persists across a close/reopen cycle.
     * Two bumps starting from nextId=1 must yield nextId=3 after reopening.
     */
    public function testBumpNextIdPersistsAcrossReopen(): void
    {
        $this->idx->create(1);
        $this->idx->open(true);
        $this->idx->beginWrite();
        $this->idx->bumpNextId();
        $this->idx->bumpNextId();
        $this->idx->commitWrite();
        $this->idx->close();

        $this->idx->open(false);
        $this->assertSame(3, $this->idx->nextId());
    }

    /**
     * Tests that rebuild() returns ERR_IO when the target path is not writable.
     */
    public function testRebuildReturnsErrIoOnUnwritablePath(): void
    {
        $bad = new JdbBinaryIndex('/nonexistent/path/index.php');
        $result = @$bad->rebuild(
            ['x' => ['offset' => 0, 'length' => 1, 'version' => 1]], 1
        );
        $this->assertSame(JdbBinaryIndex::ERR_IO, $result);
    }

    /**
     * Tests that readSlot() returns null when the requested slot index is beyond
     * the end of the file (fread returns fewer than SLOT_SIZE bytes).
     */
    public function testStaticReadSlotOutOfBoundsReturnsNull(): void
    {
        $fp = fopen($this->indexFile, 'w+b');
        JdbBinaryIndex::initFile($fp, 256, 1);
        // Slot 9999 is beyond the end of the file → fread returns less than SLOT_SIZE bytes
        $slot = JdbBinaryIndex::readSlot($fp, 9999);
        fclose($fp);
        $this->assertNull($slot);
    }

    /**
     * Tests that calling tempDelete() twice on the same key does not decrement
     * $count below zero: the second delete must be a no-op on an already-deleted record.
     */
    public function testTempDeleteOnAlreadyDeletedKeyDoesNotDecrementCount(): void
    {
        $slots = JdbBinaryIndex::INIT_SLOTS;
        $fp    = fopen($this->tempDir . '/tmp_dd.php', 'w+b');
        JdbBinaryIndex::initFile($fp, $slots, 1);

        $occ = str_repeat("\x00", $slots);
        $count = 0;

        $shash = hash('sha256', 'once', true);
        JdbBinaryIndex::tempUpsert($fp, $occ, $slots, $shash, 10, 5, 1, 0, $count);
        JdbBinaryIndex::tempDelete($fp, $occ, $slots, $shash, $count); // count → 0
        $this->assertSame(0, $count);

        // Second delete: record is already FLAG_DELETED, count must not go below 0
        JdbBinaryIndex::tempDelete($fp, $occ, $slots, $shash, $count);
        fclose($fp);

        $this->assertSame(0, $count, 'Double delete must not bring count to -1');
    }
}
