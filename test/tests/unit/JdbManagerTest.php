<?php

/**
 * JdbManagerTest – Suite estesa per la classe statica JdbManager.
 *
 * Compatible con PHPUnit 9+ e Codeception Unit suite.
 *
 * Ogni metodo di test è auto-contenuto:
 *   - setUp()    crea una directory temporanea fresca e azzera lo stato statico
 *   - tearDown() rimuove la directory ricorsivamente e resetta lo stato
 *
 * Run with:
 *   vendor/bin/phpunit tests/unit/JdbManagerTest.php
 *   vendor/bin/codecept run unit JdbManagerTest
 */
class JdbManagerTest extends \PHPUnit\Framework\TestCase
{
    private string $testDataDir;

    // ── Table names ───────────────────────────────────────────────────────────
    const T_USERS    = 'users';
    const T_PRODUCTS = 'products';
    const T_ORDERS   = 'orders';

    // ── Lifecycle ─────────────────────────────────────────────────────────────

    protected function setUp(): void
    {
        $this->testDataDir = sys_get_temp_dir() . '/jdb_dm_test_' . uniqid('', true);
        mkdir($this->testDataDir, 0755, true);

        JdbManager::resetAll();
        JdbManager::configure(['data_dir' => $this->testDataDir]);
        JdbManager::clearError();

        // Azzera la config per le tabelle note
        JdbManager::configureSecondaryIndexes(self::T_USERS,    []);
        JdbManager::configureSecondaryIndexes(self::T_PRODUCTS, []);
        JdbManager::configureSecondaryIndexes(self::T_ORDERS,   []);
    }

    protected function tearDown(): void
    {
        JdbManager::resetAll();
        JdbManager::clearError();
        $this->rrmdir($this->testDataDir);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }

    // ── Seed helpers ──────────────────────────────────────────────────────────

    private function seedUsersWithAge(): void
    {
        JdbManager::configureSecondaryIndexes(self::T_USERS, ['age']);
        JdbManager::insert(self::T_USERS, ['name' => 'Alice', 'age' => 25]);
        JdbManager::insert(self::T_USERS, ['name' => 'Bob',   'age' => 30]);
        JdbManager::insert(self::T_USERS, ['name' => 'Carol', 'age' => 35]);
        JdbManager::insert(self::T_USERS, ['name' => 'Dave',  'age' => 40]);
    }

    private function seedProducts(): void
    {
        JdbManager::configureSecondaryIndexes(self::T_PRODUCTS, ['price']);
        JdbManager::insert(self::T_PRODUCTS, ['name' => 'Widget',  'price' => 9.99,  'category' => 'tools']);
        JdbManager::insert(self::T_PRODUCTS, ['name' => 'Gadget',  'price' => 19.99, 'category' => 'tools']);
        JdbManager::insert(self::T_PRODUCTS, ['name' => 'Doohick', 'price' => 4.99,  'category' => 'misc']);
    }

    // =========================================================================
    // 1. CONFIGURATION
    // =========================================================================

    public function testConfigureOverridesDefaults(): void
    {
        JdbManager::configure(['log_errors' => true]);
        $this->assertTrue(JdbManager::getConfig('log_errors'));
    }

    public function testConfigureIsMerged(): void
    {
        JdbManager::configure(['log_errors' => true]);
        $this->assertTrue(JdbManager::getConfig('validate_table_names'));
    }

    public function testConfigureReturnsFalseOnUnknownKey(): void
    {
        $result = JdbManager::configure(['nonexistent_option' => 'value']);
        $this->assertFalse($result);
        $this->assertNotNull(JdbManager::getLastError());
    }

    public function testConfigureUnknownKeyDoesNotChangeOtherSettings(): void
    {
        $before = JdbManager::getConfig('log_errors');
        JdbManager::configure(['unknown_key' => true]);
        $this->assertSame($before, JdbManager::getConfig('log_errors'));
    }

    public function testGetConfigReturnsNullForUnknownKey(): void
    {
        $this->assertNull(JdbManager::getConfig('nonexistent_key'));
    }

    public function testGetConfigLockBackendDefault(): void
    {
        $this->assertSame('flock', JdbManager::getConfig('lock_backend'));
    }

    public function testConfigureLockBackendMkdir(): void
    {
        $result = JdbManager::configure(['lock_backend' => 'mkdir']);
        $this->assertTrue($result);
        $this->assertSame('mkdir', JdbManager::getConfig('lock_backend'));
        JdbManager::configure(['lock_backend' => 'flock']); // ripristina
    }

    public function testGetJdbVersion(): void
    {
        $version = JdbManager::getJdbVersion();
        $this->assertIsString($version);
        $this->assertNotEmpty($version);
    }

    public function testConfigureAutoCompact(): void
    {
        $result = JdbManager::configure([
            'auto_compact'           => true,
            'auto_compact_threshold' => 0.5,
        ]);
        $this->assertTrue($result);
        $this->assertTrue(JdbManager::getConfig('auto_compact'));
        $this->assertEqualsWithDelta(0.5, JdbManager::getConfig('auto_compact_threshold'), 0.001);
    }

    // =========================================================================
    // 2. SECONDARY INDEX CONFIGURATION
    // =========================================================================

    public function testConfigureSecondaryIndexesStoresFields(): void
    {
        JdbManager::configureSecondaryIndexes(self::T_USERS, ['email', 'age']);
        $cfg = JdbManager::getTableConfig(self::T_USERS);
        $this->assertSame(['email', 'age'], $cfg['secondary_fields']);
    }

    public function testGetTableConfigReturnsEmptyArrayForUnknownTable(): void
    {
        $this->assertSame([], JdbManager::getTableConfig('unknown_xyz'));
    }

    public function testConfigureSecondaryIndexesInvalidatesInstanceCache(): void
    {
        JdbManager::insert(self::T_USERS, ['name' => 'Alice', 'age' => 30]);
        JdbManager::configureSecondaryIndexes(self::T_USERS, ['age']);
        $this->assertTrue(JdbManager::hasSecondaryIndex(self::T_USERS, 'age'));
    }

    public function testConfigureSecondaryIndexesWithEmptyArrayClearsIndexes(): void
    {
        JdbManager::configureSecondaryIndexes(self::T_USERS, ['age']);
        JdbManager::insert(self::T_USERS, ['name' => 'Alice', 'age' => 30]);
        JdbManager::configureSecondaryIndexes(self::T_USERS, []);
        $this->assertFalse(JdbManager::hasSecondaryIndex(self::T_USERS, 'age'));
    }

    public function testClearTableConfigRemovesOnlyTargetTable(): void
    {
        JdbManager::configureSecondaryIndexes(self::T_USERS,    ['age']);
        JdbManager::configureSecondaryIndexes(self::T_PRODUCTS, ['price']);

        JdbManager::clearTableConfig(self::T_USERS);

        $this->assertSame([], JdbManager::getTableConfig(self::T_USERS));
        // products deve rimanere intatto
        $this->assertSame(['price'], JdbManager::getTableConfig(self::T_PRODUCTS)['secondary_fields']);
    }

    public function testClearAllConfigRemovesAllTableConfigs(): void
    {
        JdbManager::configureSecondaryIndexes(self::T_USERS,    ['age']);
        JdbManager::configureSecondaryIndexes(self::T_PRODUCTS, ['price']);

        JdbManager::clearAllConfig();

        $this->assertSame([], JdbManager::getTableConfig(self::T_USERS));
        $this->assertSame([], JdbManager::getTableConfig(self::T_PRODUCTS));
    }

    public function testResetAllRestoresFactoryDefaults(): void
    {
        JdbManager::configure(['log_errors' => true, 'lock_timeout_ms' => 9999]);
        JdbManager::configureSecondaryIndexes(self::T_USERS, ['age']);

        JdbManager::resetAll();
        JdbManager::configure(['data_dir' => $this->testDataDir]); // necessario dopo resetAll

        $this->assertFalse(JdbManager::getConfig('log_errors'));
        $this->assertSame(500, JdbManager::getConfig('lock_timeout_ms'));
        $this->assertSame([], JdbManager::getTableConfig(self::T_USERS));
    }

    public function testInitSecondaryIndexesIsAlias(): void
    {
        JdbManager::initSecondaryIndexes(self::T_USERS, ['score']);
        $this->assertSame(['score'], JdbManager::getTableConfig(self::T_USERS)['secondary_fields']);
    }

    public function testInitSecondaryIndexesWithSizeSetsSortChunkSize(): void
    {
        $before = JdbManager::getSortChunkSize();
        JdbManager::initSecondaryIndexes(self::T_USERS, ['age'], 512);
        $this->assertSame(512, JdbManager::getSortChunkSize());
        JdbSecondaryIndex::setSortChunkSize($before); // ripristina
    }

    // =========================================================================
    // 3. configureSortChunkSize / getSortChunkSize
    // =========================================================================

    public function testConfigureSortChunkSizeValid(): void
    {
        $this->assertTrue(JdbManager::configureSortChunkSize(1024));
        $this->assertSame(1024, JdbManager::getSortChunkSize());
    }

    public function testConfigureSortChunkSizeBoundaryLow(): void
    {
        $this->assertTrue(JdbManager::configureSortChunkSize(1));
        $this->assertSame(1, JdbManager::getSortChunkSize());
    }

    public function testConfigureSortChunkSizeBoundaryHigh(): void
    {
        $this->assertTrue(JdbManager::configureSortChunkSize(100000));
        $this->assertSame(100000, JdbManager::getSortChunkSize());
    }

    public function testConfigureSortChunkSizeRejectsZero(): void
    {
        $this->assertFalse(JdbManager::configureSortChunkSize(0));
        $this->assertNotNull(JdbManager::getLastError());
    }

    public function testConfigureSortChunkSizeRejectsTooLarge(): void
    {
        $this->assertFalse(JdbManager::configureSortChunkSize(100001));
    }

    public function testConfigureSortChunkSizeRejectsNegative(): void
    {
        $this->assertFalse(JdbManager::configureSortChunkSize(-1));
    }

    public function testConfigureSortChunkSizeRejectsNonInt(): void
    {
        $before = JdbManager::getSortChunkSize();
        // Un float non è accettato
        $this->assertFalse(JdbManager::configureSortChunkSize(512.5));
        $this->assertSame($before, JdbManager::getSortChunkSize());
    }

    // =========================================================================
    // 4. TABLE NAME VALIDATION
    // =========================================================================

    public function testInsertRejectsMissingTableName(): void
    {
        $this->assertFalse(JdbManager::insert('', ['name' => 'x']));
        $this->assertNotNull(JdbManager::getLastError());
    }

    public function testInsertRejectsTableNameWithSpecialChars(): void
    {
        $this->assertFalse(JdbManager::insert('my-table!', ['name' => 'x']));
    }

    public function testInsertRejectsTableNameWithPathTraversal(): void
    {
        $this->assertFalse(JdbManager::insert('../secret', ['name' => 'x']));
    }

    public function testInsertRejectsTableNameWithForwardSlash(): void
    {
        $this->assertFalse(JdbManager::insert('foo/bar', ['name' => 'x']));
    }

    public function testInsertRejectsTableNameWithBackslash(): void
    {
        $this->assertFalse(JdbManager::insert('foo\\bar', ['name' => 'x']));
    }

    public function testInsertRejectsTooLongTableName(): void
    {
        $this->assertFalse(JdbManager::insert(str_repeat('a', 65), ['name' => 'x']));
    }

    public function testInsertAcceptsTableNameAtMaxLength(): void
    {
        // esattamente 64 caratteri → valido
        $tableName = str_repeat('a', 64);
        $id = JdbManager::insert($tableName, ['name' => 'x']);
        $this->assertNotFalse($id);
    }

    public function testValidationCanBeDisabledGlobally(): void
    {
        JdbManager::configure(['validate_table_names' => false]);
        $id = JdbManager::insert(self::T_USERS, ['name' => 'Alice']);
        $this->assertNotFalse($id);
    }

    public function testPathTraversalBlockedEvenWhenValidationDisabled(): void
    {
        // Il blocco path traversal è incondizionato e non bypassabile dalla config
        JdbManager::configure(['validate_table_names' => false]);
        $this->assertFalse(JdbManager::insert('../etc/passwd', ['name' => 'x']));
    }

    // =========================================================================
    // 5. RECORD DATA VALIDATION
    // =========================================================================

    public function testInsertRejectsEmptyArray(): void
    {
        $result = JdbManager::insert(self::T_USERS, []);
        $this->assertFalse($result);
        $err = JdbManager::getLastError();
        $this->assertNotNull($err);
        $this->assertArrayHasKey('message', $err);
        $this->assertNotEmpty($err['message']);
    }

    public function testUpdateRejectsEmptyDataArray(): void
    {
        $id = JdbManager::insert(self::T_USERS, ['name' => 'Alice']);
        $this->assertFalse(JdbManager::update(self::T_USERS, $id, []));
    }

    // =========================================================================
    // 6. ERROR MANAGEMENT
    // =========================================================================

    public function testGetLastErrorReturnsNullInitially(): void
    {
        $this->assertNull(JdbManager::getLastError());
    }

    public function testClearErrorResetsLastError(): void
    {
        JdbManager::insert('', ['x' => 1]);
        $this->assertNotNull(JdbManager::getLastError());
        JdbManager::clearError();
        $this->assertNull(JdbManager::getLastError());
    }

    public function testLastErrorArrayContainsRequiredKeys(): void
    {
        JdbManager::insert('', ['x' => 1]);
        $err = JdbManager::getLastError();
        $this->assertArrayHasKey('method',  $err);
        $this->assertArrayHasKey('message', $err);
        $this->assertArrayHasKey('time',    $err);
    }

    public function testGetDbErrorReturnsEmptyStringForInvalidTable(): void
    {
        $this->assertSame('', JdbManager::getDbError(''));
    }

    public function testGetDbErrorReturnsEmptyStringAfterSuccessfulOperation(): void
    {
        JdbManager::insert(self::T_USERS, ['name' => 'Alice']);
        $this->assertSame('', JdbManager::getDbError(self::T_USERS));
    }

    public function testGetDbErrorReturnsStringType(): void
    {
        JdbManager::insert(self::T_USERS, ['name' => 'Alice']);
        $this->assertIsString(JdbManager::getDbError(self::T_USERS));
    }

    // =========================================================================
    // 7. INSTANCE CACHE
    // =========================================================================

    public function testSameInstanceReusedForSameTable(): void
    {
        JdbManager::insert(self::T_USERS, ['name' => 'Alice']);
        JdbManager::insert(self::T_USERS, ['name' => 'Bob']);
        $this->assertSame(2, JdbManager::count(self::T_USERS));
    }

    public function testClearInstanceEvictsHandleButPreservesData(): void
    {
        JdbManager::insert(self::T_USERS, ['name' => 'Alice']);
        JdbManager::insert(self::T_USERS, ['name' => 'Bob']);
        JdbManager::clearInstance(self::T_USERS);
        $this->assertSame(2, JdbManager::count(self::T_USERS));
    }

    public function testClearInstanceOnNonCachedTableIsNoOp(): void
    {
        JdbManager::clearInstance('nonexistent_xyz');
        $this->assertNull(JdbManager::getLastError());
    }

    public function testClearAllInstancesEvictsAllHandlesButPreservesData(): void
    {
        JdbManager::insert(self::T_USERS,    ['name' => 'Alice']);
        JdbManager::insert(self::T_PRODUCTS, ['sku'  => 'P1']);
        JdbManager::clearAllInstances();
        $this->assertSame(1, JdbManager::count(self::T_USERS));
        $this->assertSame(1, JdbManager::count(self::T_PRODUCTS));
    }

    public function testClearAllInstancesWithResetConfigAlsoClearsTableConfig(): void
    {
        JdbManager::configureSecondaryIndexes(self::T_USERS, ['age']);
        JdbManager::clearAllInstances(true);
        $this->assertSame([], JdbManager::getTableConfig(self::T_USERS));
    }

    // =========================================================================
    // 8. INSERT
    // =========================================================================

    public function testInsertReturnsPositiveAutoIncrementInteger(): void
    {
        $id = JdbManager::insert(self::T_USERS, ['name' => 'Alice']);
        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);
    }

    public function testInsertWithExplicitCustomId(): void
    {
        $result = JdbManager::insert(self::T_USERS, ['name' => 'Alice'], 'alice-1');
        $this->assertSame('alice-1', $result);
        $this->assertSame('Alice', JdbManager::findById(self::T_USERS, 'alice-1')['name']);
    }

    public function testInsertWithIdKeyInDataUsesItAsCustomId(): void
    {
        $result = JdbManager::insert(self::T_USERS, ['id' => 'u-99', 'name' => 'Bob']);
        $this->assertSame('u-99', $result);
        $record = JdbManager::findById(self::T_USERS, 'u-99');
        $this->assertSame('Bob',  $record['name']);
        $this->assertSame('u-99', $record['id']);
    }

    public function testInsertAutoIdsAreMonotonicallyIncreasing(): void
    {
        $id1 = JdbManager::insert(self::T_USERS, ['name' => 'Alice']);
        $id2 = JdbManager::insert(self::T_USERS, ['name' => 'Bob']);
        $this->assertGreaterThan($id1, $id2);
    }

    public function testInsertDuplicateCustomIdReturnsFalse(): void
    {
        JdbManager::insert(self::T_USERS, ['name' => 'Alice'], 'same-id');
        $this->assertFalse(JdbManager::insert(self::T_USERS, ['name' => 'Bob'], 'same-id'));
    }

    public function testInsertIntoMultipleTablesAreMutuallyIsolated(): void
    {
        JdbManager::insert(self::T_USERS,    ['name' => 'Alice']);
        $pid = JdbManager::insert(self::T_PRODUCTS, ['sku' => 'P1'], 'prod-999');
        $this->assertSame(1, JdbManager::count(self::T_USERS));
        $this->assertSame(1, JdbManager::count(self::T_PRODUCTS));
        $this->assertNull(JdbManager::findById(self::T_USERS, $pid));
    }

    public function testInsertCustomIdParameterOverridesIdInData(): void
    {
        // $customId ha priorità su $data['id']
        $result = JdbManager::insert(self::T_USERS, ['id' => 'data-id', 'name' => 'Alice'], 'param-id');
        $this->assertSame('param-id', $result);
        $this->assertNotNull(JdbManager::findById(self::T_USERS, 'param-id'));
        $this->assertNull(JdbManager::findById(self::T_USERS, 'data-id'));
    }

    // =========================================================================
    // 9. INSERT BATCH
    // =========================================================================

    public function testInsertBatchReturnsInsertedCountAndIds(): void
    {
        $result = JdbManager::insertBatch(self::T_USERS, [
            ['name' => 'Alice', 'age' => 25],
            ['name' => 'Bob',   'age' => 30],
            ['name' => 'Carol', 'age' => 35],
        ]);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('inserted', $result);
        $this->assertArrayHasKey('ids', $result);
        $this->assertSame(3, $result['inserted']);
        $this->assertCount(3, $result['ids']);
    }

    public function testInsertBatchEmptyArrayReturnsZero(): void
    {
        $result = JdbManager::insertBatch(self::T_USERS, []);
        $this->assertSame(0, $result['inserted']);
        $this->assertSame([], $result['ids']);
    }

    public function testInsertBatchRecordsAreQueryable(): void
    {
        JdbManager::insertBatch(self::T_USERS, [
            ['name' => 'Alice'],
            ['name' => 'Bob'],
        ]);
        $this->assertSame(2, JdbManager::count(self::T_USERS));
    }

    public function testInsertBatchReturnsFalseOnInvalidTable(): void
    {
        $this->assertFalse(JdbManager::insertBatch('', [['name' => 'x']]));
    }

    public function testInsertBatchReturnsFalseIfAnyRowIsEmpty(): void
    {
        $result = JdbManager::insertBatch(self::T_USERS, [
            ['name' => 'Alice'],
            [], // riga non valida
            ['name' => 'Carol'],
        ]);
        $this->assertFalse($result);
    }

    public function testInsertBatchWithExplicitIdsUsesThemAsIds(): void
    {
        $result = JdbManager::insertBatch(self::T_USERS, [
            ['id' => 'u-a', 'name' => 'Alice'],
            ['id' => 'u-b', 'name' => 'Bob'],
        ]);
        $this->assertContains('u-a', $result['ids']);
        $this->assertContains('u-b', $result['ids']);
        $this->assertNotNull(JdbManager::findById(self::T_USERS, 'u-a'));
    }

    // =========================================================================
    // 10. READ / FIND
    // =========================================================================

    public function testReadAllOnEmptyTableReturnsEmptyArray(): void
    {
        $this->assertSame([], JdbManager::readAll(self::T_USERS));
    }

    public function testReadAllReturnsEveryInsertedRecord(): void
    {
        JdbManager::insert(self::T_USERS, ['name' => 'Alice']);
        JdbManager::insert(self::T_USERS, ['name' => 'Bob']);
        $this->assertCount(2, JdbManager::readAll(self::T_USERS));
    }

    public function testReadAllExcludesDeletedRecords(): void
    {
        $id = JdbManager::insert(self::T_USERS, ['name' => 'Alice']);
        JdbManager::insert(self::T_USERS, ['name' => 'Bob']);
        JdbManager::delete(self::T_USERS, $id);
        $result = JdbManager::readAll(self::T_USERS);
        $this->assertCount(1, $result);
        $this->assertSame('Bob', $result[0]['name']);
    }

    public function testReadAllOnInvalidTableReturnsFalse(): void
    {
        $this->assertFalse(JdbManager::readAll(''));
    }

    public function testFindByIdReturnsCorrectRecord(): void
    {
        $id = JdbManager::insert(self::T_USERS, ['name' => 'Alice', 'age' => 30]);
        $rec = JdbManager::findById(self::T_USERS, $id);
        $this->assertSame('Alice', $rec['name']);
        $this->assertSame(30, $rec['age']);
    }

    public function testFindByIdReturnsNullForNonExistentId(): void
    {
        $this->assertNull(JdbManager::findById(self::T_USERS, 9999));
    }

    public function testFindByIdReturnsNullAfterDelete(): void
    {
        $id = JdbManager::insert(self::T_USERS, ['name' => 'Alice']);
        JdbManager::delete(self::T_USERS, $id);
        $this->assertNull(JdbManager::findById(self::T_USERS, $id));
    }

    public function testFindByIdReturnsNullOnInvalidTable(): void
    {
        $this->assertNull(JdbManager::findById('', 1));
    }

    public function testFindWhereReturnsOnlyMatchingRecords(): void
    {
        JdbManager::insert(self::T_USERS, ['name' => 'Alice', 'role' => 'admin']);
        JdbManager::insert(self::T_USERS, ['name' => 'Bob',   'role' => 'user']);
        JdbManager::insert(self::T_USERS, ['name' => 'Carol', 'role' => 'admin']);
        $admins = JdbManager::findWhere(self::T_USERS, ['role' => 'admin']);
        $this->assertCount(2, $admins);
        $names = array_column($admins, 'name');
        $this->assertContains('Alice', $names);
        $this->assertNotContains('Bob', $names);
    }

    public function testFindWhereWithMultipleConditionsIsAnAnd(): void
    {
        JdbManager::insert(self::T_USERS, ['name' => 'Alice', 'role' => 'admin', 'active' => true]);
        JdbManager::insert(self::T_USERS, ['name' => 'Bob',   'role' => 'admin', 'active' => false]);
        $result = JdbManager::findWhere(self::T_USERS, ['role' => 'admin', 'active' => true]);
        $this->assertCount(1, $result);
        $this->assertSame('Alice', $result[0]['name']);
    }

    public function testFindWhereReturnsNullWhenNoMatch(): void
    {
        JdbManager::insert(self::T_USERS, ['name' => 'Alice', 'role' => 'admin']);
        $this->assertNull(JdbManager::findWhere(self::T_USERS, ['role' => 'superuser']));
    }

    public function testFindWhereOnInvalidTableReturnsFalse(): void
    {
        $this->assertFalse(JdbManager::findWhere('', ['name' => 'x']));
    }

    // =========================================================================
    // 11. forEachRecord (streaming)
    // =========================================================================

    public function testForEachCallsCallbackForEachRecord(): void
    {
        JdbManager::insert(self::T_USERS, ['name' => 'Alice']);
        JdbManager::insert(self::T_USERS, ['name' => 'Bob']);
        $names = [];
        $count = JdbManager::forEachRecord(self::T_USERS, function ($r) use (&$names) {
            $names[] = $r['name'];
        });
        $this->assertSame(2, $count);
        $this->assertContains('Alice', $names);
        $this->assertContains('Bob', $names);
    }

    public function testForEachStopsOnCallbackReturnFalse(): void
    {
        JdbManager::insert(self::T_USERS, ['name' => 'Alice']);
        JdbManager::insert(self::T_USERS, ['name' => 'Bob']);
        JdbManager::insert(self::T_USERS, ['name' => 'Carol']);
        $called = 0;
        JdbManager::forEachRecord(self::T_USERS, function ($r) use (&$called) {
            $called++;
            return false;
        });
        $this->assertSame(1, $called);
    }

    public function testForEachWithConditionFiltersRecords(): void
    {
        JdbManager::insert(self::T_USERS, ['name' => 'Alice', 'role' => 'admin']);
        JdbManager::insert(self::T_USERS, ['name' => 'Bob',   'role' => 'user']);
        $names = [];
        JdbManager::forEachRecord(self::T_USERS, function ($r) use (&$names) {
            $names[] = $r['name'];
        }, ['role' => 'admin']);
        $this->assertSame(['Alice'], $names);
    }

    public function testForEachWithLimitRespectsLimit(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            JdbManager::insert(self::T_USERS, ['name' => "User$i"]);
        }
        $collected = [];
        JdbManager::forEachRecord(self::T_USERS, function ($r) use (&$collected) {
            $collected[] = $r;
        }, [], 3);
        $this->assertCount(3, $collected);
    }

    public function testForEachReturnsFalseOnInvalidTable(): void
    {
        $this->assertFalse(JdbManager::forEachRecord('', function () {}));
    }

    public function testForEachReturnsFalseOnNonCallable(): void
    {
        JdbManager::insert(self::T_USERS, ['name' => 'Alice']);
        $this->assertFalse(JdbManager::forEachRecord(self::T_USERS, 'not_a_callable_function_xyz'));
    }

    // =========================================================================
    // 12. UPDATE
    // =========================================================================

    public function testUpdateModifiesFieldInRecord(): void
    {
        $id = JdbManager::insert(self::T_USERS, ['name' => 'Alice', 'age' => 30]);
        $this->assertTrue(JdbManager::update(self::T_USERS, $id, ['name' => 'Alice', 'age' => 31]));
        $this->assertSame(31, JdbManager::findById(self::T_USERS, $id)['age']);
    }

    public function testUpdateReturnsFalseForNonExistentRecord(): void
    {
        $this->assertFalse(JdbManager::update(self::T_USERS, 9999, ['name' => 'Ghost']));
    }

    public function testUpdatePreservesNumericIdInRecord(): void
    {
        $id = JdbManager::insert(self::T_USERS, ['name' => 'Alice']);
        JdbManager::update(self::T_USERS, $id, ['name' => 'Updated']);
        $this->assertSame($id, JdbManager::findById(self::T_USERS, $id)['id']);
    }

    public function testUpdateOnInvalidTableReturnsFalse(): void
    {
        $this->assertFalse(JdbManager::update('', 1, ['name' => 'x']));
    }

    public function testUpdateDoesNotAffectSiblingRecords(): void
    {
        $id1 = JdbManager::insert(self::T_USERS, ['name' => 'Alice']);
        $id2 = JdbManager::insert(self::T_USERS, ['name' => 'Bob']);
        JdbManager::update(self::T_USERS, $id1, ['name' => 'Alice Updated']);
        $this->assertSame('Bob', JdbManager::findById(self::T_USERS, $id2)['name']);
    }

    public function testUpdateWithCorrectExpectedVersionSucceeds(): void
    {
        $id      = JdbManager::insert(self::T_USERS, ['name' => 'Alice']);
        $version = JdbManager::getEntryVersion(self::T_USERS, $id);
        $this->assertTrue(JdbManager::update(self::T_USERS, $id, ['name' => 'Updated'], $version));
    }

    public function testUpdateWithWrongExpectedVersionFails(): void
    {
        $id = JdbManager::insert(self::T_USERS, ['name' => 'Alice']);
        // Versione sbagliata → OCC fallisce
        $this->assertFalse(JdbManager::update(self::T_USERS, $id, ['name' => 'Updated'], 9999));
    }

    // =========================================================================
    // 13. DELETE
    // =========================================================================

    public function testDeleteRemovesRecordFromTable(): void
    {
        $id = JdbManager::insert(self::T_USERS, ['name' => 'Alice']);
        $this->assertTrue(JdbManager::delete(self::T_USERS, $id));
        $this->assertNull(JdbManager::findById(self::T_USERS, $id));
    }

    public function testDeleteReturnsFalseForNonExistentId(): void
    {
        $this->assertFalse(JdbManager::delete(self::T_USERS, 9999));
    }

    public function testDeleteReturnsFalseOnSecondDeleteSameId(): void
    {
        $id = JdbManager::insert(self::T_USERS, ['name' => 'Alice']);
        JdbManager::delete(self::T_USERS, $id);
        $this->assertFalse(JdbManager::delete(self::T_USERS, $id));
    }

    public function testDeleteOnInvalidTableReturnsFalse(): void
    {
        $this->assertFalse(JdbManager::delete('', 1));
    }

    public function testDeleteDecreasesCountByOne(): void
    {
        $id = JdbManager::insert(self::T_USERS, ['name' => 'Alice']);
        JdbManager::insert(self::T_USERS, ['name' => 'Bob']);
        JdbManager::delete(self::T_USERS, $id);
        $this->assertSame(1, JdbManager::count(self::T_USERS));
    }

    public function testDeleteDoesNotAffectSiblingRecords(): void
    {
        $id1 = JdbManager::insert(self::T_USERS, ['name' => 'Alice']);
        $id2 = JdbManager::insert(self::T_USERS, ['name' => 'Bob']);
        JdbManager::delete(self::T_USERS, $id1);
        $this->assertSame('Bob', JdbManager::findById(self::T_USERS, $id2)['name']);
    }

    public function testDeleteWithCorrectExpectedVersionSucceeds(): void
    {
        $id      = JdbManager::insert(self::T_USERS, ['name' => 'Alice']);
        $version = JdbManager::getEntryVersion(self::T_USERS, $id);
        $this->assertTrue(JdbManager::delete(self::T_USERS, $id, $version));
    }

    public function testDeleteWithWrongExpectedVersionFails(): void
    {
        $id = JdbManager::insert(self::T_USERS, ['name' => 'Alice']);
        $this->assertFalse(JdbManager::delete(self::T_USERS, $id, 9999));
        // Il record deve essere ancora presente
        $this->assertNotNull(JdbManager::findById(self::T_USERS, $id));
    }

    // =========================================================================
    // 14. getEntryVersion
    // =========================================================================

    public function testGetEntryVersionReturnsIntegerAfterInsert(): void
    {
        $id = JdbManager::insert(self::T_USERS, ['name' => 'Alice']);
        $v  = JdbManager::getEntryVersion(self::T_USERS, $id);
        $this->assertIsInt($v);
        $this->assertGreaterThanOrEqual(1, $v);
    }

    public function testGetEntryVersionIncrementsAfterUpdate(): void
    {
        $id = JdbManager::insert(self::T_USERS, ['name' => 'Alice']);
        $v1 = JdbManager::getEntryVersion(self::T_USERS, $id);
        JdbManager::update(self::T_USERS, $id, ['name' => 'Alice Updated']);
        $v2 = JdbManager::getEntryVersion(self::T_USERS, $id);
        $this->assertGreaterThan($v1, $v2);
    }

    public function testGetEntryVersionReturnsFalseForNonExistentId(): void
    {
        $this->assertFalse(JdbManager::getEntryVersion(self::T_USERS, 9999));
    }

    public function testGetEntryVersionReturnsFalseOnInvalidTable(): void
    {
        $this->assertFalse(JdbManager::getEntryVersion('', 1));
    }

    // =========================================================================
    // 15. COUNT / EXISTS
    // =========================================================================

    public function testCountReturnsZeroOnEmptyTable(): void
    {
        $this->assertSame(0, JdbManager::count(self::T_USERS));
    }

    public function testCountReturnsCorrectTotalAfterInserts(): void
    {
        JdbManager::insert(self::T_USERS, ['name' => 'Alice']);
        JdbManager::insert(self::T_USERS, ['name' => 'Bob']);
        $this->assertSame(2, JdbManager::count(self::T_USERS));
    }

    public function testCountExcludesDeletedRecords(): void
    {
        $id = JdbManager::insert(self::T_USERS, ['name' => 'Alice']);
        JdbManager::insert(self::T_USERS, ['name' => 'Bob']);
        JdbManager::delete(self::T_USERS, $id);
        $this->assertSame(1, JdbManager::count(self::T_USERS));
    }

    public function testCountOnInvalidTableReturnsFalse(): void
    {
        $this->assertFalse(JdbManager::count(''));
    }

    public function testExistsReturnsTrueForActiveRecord(): void
    {
        $id = JdbManager::insert(self::T_USERS, ['name' => 'Alice']);
        $this->assertTrue(JdbManager::exists(self::T_USERS, $id));
    }

    public function testExistsReturnsFalseForNonExistentId(): void
    {
        $this->assertFalse(JdbManager::exists(self::T_USERS, 9999));
    }

    public function testExistsReturnsFalseAfterDelete(): void
    {
        $id = JdbManager::insert(self::T_USERS, ['name' => 'Alice']);
        JdbManager::delete(self::T_USERS, $id);
        $this->assertFalse(JdbManager::exists(self::T_USERS, $id));
    }

    public function testExistsOnInvalidTableReturnsFalse(): void
    {
        $this->assertFalse(JdbManager::exists('', 1));
    }

    // =========================================================================
    // 16. COMPACT
    // =========================================================================

    public function testCompactReturnsArrayWithExpectedKeys(): void
    {
        JdbManager::insert(self::T_USERS, ['name' => 'Alice']);
        $stats = JdbManager::compact(self::T_USERS);
        $this->assertIsArray($stats);
        foreach (['records_kept', 'deleted_records', 'old_size', 'new_size'] as $key) {
            $this->assertArrayHasKey($key, $stats);
        }
    }

    public function testCompactReclaimsSpaceAfterDeletes(): void
    {
        $id = JdbManager::insert(self::T_USERS, ['data' => str_repeat('x', 500)]);
        JdbManager::insert(self::T_USERS, ['data' => str_repeat('y', 500)]);
        JdbManager::delete(self::T_USERS, $id);
        $stats = JdbManager::compact(self::T_USERS);
        $this->assertSame(1, $stats['records_kept']);
        $this->assertGreaterThan($stats['new_size'], $stats['old_size']);
    }

    public function testCompactPreservesActiveRecords(): void
    {
        $id  = JdbManager::insert(self::T_USERS, ['name' => 'Alice']);
        $id2 = JdbManager::insert(self::T_USERS, ['name' => 'ToDelete']);
        JdbManager::delete(self::T_USERS, $id2);
        JdbManager::compact(self::T_USERS);
        $this->assertSame('Alice', JdbManager::findById(self::T_USERS, $id)['name']);
    }

    public function testCompactOnInvalidTableReturnsFalse(): void
    {
        $this->assertFalse(JdbManager::compact(''));
    }

    public function testCountAfterCompactRemainsCorrect(): void
    {
        JdbManager::insert(self::T_USERS, ['name' => 'Alice']);
        $id2 = JdbManager::insert(self::T_USERS, ['name' => 'Bob']);
        JdbManager::delete(self::T_USERS, $id2);
        JdbManager::compact(self::T_USERS);
        $this->assertSame(1, JdbManager::count(self::T_USERS));
    }

    // =========================================================================
    // 17. GET STATS
    // =========================================================================

    public function testGetStatsReturnsArrayWithExpectedKeys(): void
    {
        JdbManager::insert(self::T_USERS, ['name' => 'Alice']);
        $stats = JdbManager::getStats(self::T_USERS);
        $this->assertIsArray($stats);
        foreach (['active_records', 'total_lines', 'data_file_size', 'index_slots'] as $key) {
            $this->assertArrayHasKey($key, $stats);
        }
    }

    public function testGetStatsActiveRecordsMatchesCount(): void
    {
        JdbManager::insert(self::T_USERS, ['name' => 'Alice']);
        JdbManager::insert(self::T_USERS, ['name' => 'Bob']);
        $this->assertSame(2, JdbManager::getStats(self::T_USERS)['active_records']);
    }

    public function testGetStatsOnInvalidTableReturnsFalse(): void
    {
        $this->assertFalse(JdbManager::getStats(''));
    }

    public function testGetStatsDataFileSizeGrowsAfterInsert(): void
    {
        $sizeBefore = JdbManager::getStats(self::T_USERS)['data_file_size'];
        JdbManager::insert(self::T_USERS, ['name' => 'Alice', 'data' => str_repeat('x', 100)]);
        $sizeAfter = JdbManager::getStats(self::T_USERS)['data_file_size'];
        $this->assertGreaterThan($sizeBefore, $sizeAfter);
    }

    // =========================================================================
    // 18. selectPage (paginazione cursor-based)
    // =========================================================================

    public function testSelectPageFirstPageReturnsCorrectCount(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            JdbManager::insert(self::T_USERS, ['name' => "User$i"]);
        }
        $page = JdbManager::selectPage(self::T_USERS, 3);
        $this->assertIsArray($page);
        $this->assertArrayHasKey('records', $page);
        $this->assertCount(3, $page['records']);
    }

    public function testSelectPageSecondPageReturnsNextRecords(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            JdbManager::insert(self::T_USERS, ['name' => "User$i"]);
        }
        $page1 = JdbManager::selectPage(self::T_USERS, 3);
        $this->assertNotEmpty($page1['next_cursor'] ?? null);
        $page2 = JdbManager::selectPage(self::T_USERS, 3, $page1['next_cursor']);
        $this->assertCount(3, $page2['records']);
        // Nessun record duplicato tra le due pagine
        $ids1 = array_column($page1['records'], 'id');
        $ids2 = array_column($page2['records'], 'id');
        $this->assertEmpty(array_intersect($ids1, $ids2));
    }

    public function testSelectPageReturnsFalseOnInvalidTable(): void
    {
        $this->assertFalse(JdbManager::selectPage('', 10));
    }

    public function testSelectPageReturnsEmptyRecordsOnEmptyTable(): void
    {
        $page = JdbManager::selectPage(self::T_USERS, 10);
        $this->assertIsArray($page);
        $this->assertEmpty($page['records']);
    }

    // =========================================================================
    // 19. AGGREGATE
    // =========================================================================

    public function testAggregateCount(): void
    {
        JdbManager::insert(self::T_USERS, ['name' => 'Alice', 'age' => 25]);
        JdbManager::insert(self::T_USERS, ['name' => 'Bob',   'age' => 30]);
        $result = JdbManager::aggregate(self::T_USERS, 'count', 'age');
        $this->assertSame(2, $result);
    }

    public function testAggregateSum(): void
    {
        JdbManager::insert(self::T_USERS, ['name' => 'Alice', 'age' => 25]);
        JdbManager::insert(self::T_USERS, ['name' => 'Bob',   'age' => 30]);
        $result = JdbManager::aggregate(self::T_USERS, 'sum', 'age');
        $this->assertSame(55, (int)$result);
    }

    public function testAggregateAvg(): void
    {
        JdbManager::insert(self::T_USERS, ['name' => 'Alice', 'age' => 20]);
        JdbManager::insert(self::T_USERS, ['name' => 'Bob',   'age' => 40]);
        $result = JdbManager::aggregate(self::T_USERS, 'avg', 'age');
        $this->assertEqualsWithDelta(30.0, $result, 0.01);
    }

    public function testAggregateMin(): void
    {
        JdbManager::insert(self::T_USERS, ['name' => 'Alice', 'age' => 20]);
        JdbManager::insert(self::T_USERS, ['name' => 'Bob',   'age' => 40]);
        $this->assertSame(20, (int)JdbManager::aggregate(self::T_USERS, 'min', 'age'));
    }

    public function testAggregateMax(): void
    {
        JdbManager::insert(self::T_USERS, ['name' => 'Alice', 'age' => 20]);
        JdbManager::insert(self::T_USERS, ['name' => 'Bob',   'age' => 40]);
        $this->assertSame(40, (int)JdbManager::aggregate(self::T_USERS, 'max', 'age'));
    }

    public function testAggregateReturnsFalseOnInvalidTable(): void
    {
        $this->assertFalse(JdbManager::aggregate('', 'count', 'age'));
    }

    public function testAggregateReturnsFalseOnInvalidField(): void
    {
        JdbManager::insert(self::T_USERS, ['name' => 'Alice', 'age' => 25]);
        $this->assertFalse(JdbManager::aggregate(self::T_USERS, 'sum', ''));
    }

    public function testAggregateWithConditionFiltersCorrectly(): void
    {
        JdbManager::insert(self::T_USERS, ['name' => 'Alice', 'age' => 25, 'role' => 'admin']);
        JdbManager::insert(self::T_USERS, ['name' => 'Bob',   'age' => 30, 'role' => 'user']);
        JdbManager::insert(self::T_USERS, ['name' => 'Carol', 'age' => 35, 'role' => 'admin']);
        $result = JdbManager::aggregate(self::T_USERS, 'count', 'age', ['role' => 'admin']);
        $this->assertSame(2, $result);
    }

    // =========================================================================
    // 20. PATH HELPERS
    // =========================================================================

    public function testGetDataDirReturnsConfiguredPath(): void
    {
        $this->assertSame($this->testDataDir, JdbManager::getDataDir());
    }

    public function testGetDataPathReturnsCorrectPath(): void
    {
        $expected = $this->testDataDir . '/users.jsonl.php';
        $this->assertSame($expected, JdbManager::getDataPath('users'));
    }

    public function testGetIndexPathReturnsCorrectPath(): void
    {
        $expected = $this->testDataDir . '/users.index.php';
        $this->assertSame($expected, JdbManager::getIndexPath('users'));
    }

    public function testGetSecondaryIndexPathReturnsCorrectPath(): void
    {
        $expected = $this->testDataDir . '/users.idx_email.php';
        $this->assertSame($expected, JdbManager::getSecondaryIndexPath('users', 'email'));
    }

    public function testGetSecondaryIndexPathSanitizesFieldName(): void
    {
        // Caratteri non alfanumerici nel nome campo vengono sostituiti da _
        $path = JdbManager::getSecondaryIndexPath('users', 'user.meta.age');
        $this->assertStringContainsString('idx_user_meta_age', $path);
    }

    // =========================================================================
    // 21. truncateTable
    // =========================================================================

    public function testTruncateTableWithOffsetZeroDeletesDataFile(): void
    {
        JdbManager::insert(self::T_USERS, ['name' => 'Alice']);
        $dataFile = JdbManager::getDataPath(self::T_USERS);
        $this->assertFileExists($dataFile);

        $result = JdbManager::truncateTable(self::T_USERS, 0);
        $this->assertTrue($result);
        $this->assertFileDoesNotExist($dataFile);
    }

    public function testTruncateTableRemovesIndexFiles(): void
    {
        JdbManager::insert(self::T_USERS, ['name' => 'Alice']);
        $indexFile = JdbManager::getIndexPath(self::T_USERS);
        $this->assertFileExists($indexFile);

        JdbManager::truncateTable(self::T_USERS, 0);
        $this->assertFileDoesNotExist($indexFile);
    }

    public function testTruncateTableEvictsInstanceCache(): void
    {
        JdbManager::insert(self::T_USERS, ['name' => 'Alice']);
        JdbManager::truncateTable(self::T_USERS, 0);
        // Dopo l'evict, il prossimo insert deve partire da zero
        $id = JdbManager::insert(self::T_USERS, ['name' => 'Bob']);
        $this->assertSame(1, JdbManager::count(self::T_USERS));
    }

    // =========================================================================
    // 22. SECONDARY INDEXES – hasSecondaryIndex / getSecondaryIndexes
    // =========================================================================

    public function testHasSecondaryIndexReturnsFalseWithoutConfig(): void
    {
        JdbManager::insert(self::T_USERS, ['name' => 'Alice', 'age' => 30]);
        $this->assertFalse(JdbManager::hasSecondaryIndex(self::T_USERS, 'age'));
    }

    public function testHasSecondaryIndexReturnsTrueAfterConfig(): void
    {
        JdbManager::configureSecondaryIndexes(self::T_USERS, ['age']);
        JdbManager::insert(self::T_USERS, ['name' => 'Alice', 'age' => 30]);
        $this->assertTrue(JdbManager::hasSecondaryIndex(self::T_USERS, 'age'));
    }

    public function testHasSecondaryIndexReturnsFalseForNonIndexedField(): void
    {
        JdbManager::configureSecondaryIndexes(self::T_USERS, ['age']);
        JdbManager::insert(self::T_USERS, ['name' => 'Alice', 'age' => 30]);
        $this->assertFalse(JdbManager::hasSecondaryIndex(self::T_USERS, 'name'));
    }

    public function testHasSecondaryIndexReturnsFalseForInvalidTable(): void
    {
        $this->assertFalse(JdbManager::hasSecondaryIndex('', 'age'));
    }

    public function testHasSecondaryIndexReturnsFalseForInvalidField(): void
    {
        JdbManager::configureSecondaryIndexes(self::T_USERS, ['age']);
        $this->assertFalse(JdbManager::hasSecondaryIndex(self::T_USERS, ''));
    }

    public function testGetSecondaryIndexesReturnsAllConfiguredFields(): void
    {
        JdbManager::configureSecondaryIndexes(self::T_USERS, ['age', 'email']);
        JdbManager::insert(self::T_USERS, ['name' => 'Alice', 'age' => 30, 'email' => 'a@b.com']);
        $indexes = JdbManager::getSecondaryIndexes(self::T_USERS);
        $this->assertContains('age',   $indexes);
        $this->assertContains('email', $indexes);
    }

    public function testGetSecondaryIndexesReturnsEmptyForInvalidTable(): void
    {
        $this->assertSame([], JdbManager::getSecondaryIndexes(''));
    }

    // =========================================================================
    // 23. SECONDARY INDEXES – selectRange
    // =========================================================================

    public function testSelectRangeReturnsFalseForNonIndexedField(): void
    {
        JdbManager::insert(self::T_USERS, ['name' => 'Alice', 'age' => 25]);
        $this->assertFalse(JdbManager::selectRange(self::T_USERS, 'age', 20, 30));
    }

    public function testSelectRangeReturnsFalseForInvalidTable(): void
    {
        $this->assertFalse(JdbManager::selectRange('', 'age', 20, 30));
    }

    public function testSelectRangeReturnsFalseForInvalidField(): void
    {
        JdbManager::configureSecondaryIndexes(self::T_USERS, ['age']);
        $this->assertFalse(JdbManager::selectRange(self::T_USERS, '', 20, 30));
    }

    public function testSelectRangeReturnsBothBoundsInclusive(): void
    {
        $this->seedUsersWithAge();
        $result = JdbManager::selectRange(self::T_USERS, 'age', 25, 35);
        $this->assertIsArray($result);
        $this->assertCount(3, $result);
        $names = array_column($result, 'name');
        $this->assertContains('Alice', $names);
        $this->assertContains('Bob',   $names);
        $this->assertContains('Carol', $names);
    }

    public function testSelectRangeWithNullMinReturnsFromStart(): void
    {
        $this->seedUsersWithAge();
        $result = JdbManager::selectRange(self::T_USERS, 'age', null, 30);
        $this->assertCount(2, $result);
    }

    public function testSelectRangeWithNullMaxReturnsToEnd(): void
    {
        $this->seedUsersWithAge();
        $result = JdbManager::selectRange(self::T_USERS, 'age', 35, null);
        $this->assertCount(2, $result);
    }

    public function testSelectRangeWithBothNullReturnsAll(): void
    {
        $this->seedUsersWithAge();
        $result = JdbManager::selectRange(self::T_USERS, 'age', null, null);
        $this->assertCount(4, $result);
    }

    public function testSelectRangeReturnsNullWhenNoMatch(): void
    {
        $this->seedUsersWithAge();
        $this->assertNull(JdbManager::selectRange(self::T_USERS, 'age', 99, 100));
    }

    public function testSelectRangeWithLimitRespectsLimit(): void
    {
        $this->seedUsersWithAge();
        $result = JdbManager::selectRange(self::T_USERS, 'age', null, null, [], 2);
        $this->assertCount(2, $result);
    }

    public function testSelectRangeWithAdditionalConditionFiltersCorrectly(): void
    {
        JdbManager::configureSecondaryIndexes(self::T_USERS, ['age']);
        JdbManager::insert(self::T_USERS, ['name' => 'Alice', 'age' => 30, 'role' => 'admin']);
        JdbManager::insert(self::T_USERS, ['name' => 'Bob',   'age' => 30, 'role' => 'user']);
        $result = JdbManager::selectRange(self::T_USERS, 'age', 30, 30, ['role' => 'admin']);
        $this->assertCount(1, $result);
        $this->assertSame('Alice', $result[0]['name']);
    }

    public function testSelectRangeResultsAreSortedByFieldValue(): void
    {
        $this->seedUsersWithAge();
        $result = JdbManager::selectRange(self::T_USERS, 'age', null, null);
        $ages   = array_column($result, 'age');
        $sorted = $ages;
        sort($sorted);
        $this->assertSame($sorted, $ages, 'selectRange deve restituire i risultati in ordine crescente');
    }

    // =========================================================================
    // 24. SECONDARY INDEXES – selectRangeFrom / selectRangeTo / selectByIndexedField
    // =========================================================================

    public function testSelectRangeFromAppliesOnlyLowerBound(): void
    {
        $this->seedUsersWithAge();
        $result = JdbManager::selectRangeFrom(self::T_USERS, 'age', 35);
        $this->assertCount(2, $result);
        foreach ($result as $r) {
            $this->assertGreaterThanOrEqual(35, $r['age']);
        }
    }

    public function testSelectRangeToAppliesOnlyUpperBound(): void
    {
        $this->seedUsersWithAge();
        $result = JdbManager::selectRangeTo(self::T_USERS, 'age', 30);
        $this->assertCount(2, $result);
        foreach ($result as $r) {
            $this->assertLessThanOrEqual(30, $r['age']);
        }
    }

    public function testSelectByIndexedFieldMatchesExactValue(): void
    {
        $this->seedUsersWithAge();
        $result = JdbManager::selectByIndexedField(self::T_USERS, 'age', 30);
        $this->assertCount(1, $result);
        $this->assertSame('Bob', $result[0]['name']);
    }

    public function testSelectByIndexedFieldReturnsNullWhenNoMatch(): void
    {
        $this->seedUsersWithAge();
        $this->assertNull(JdbManager::selectByIndexedField(self::T_USERS, 'age', 99));
    }

    // =========================================================================
    // 25. SECONDARY INDEXES – selectRangeEach
    // =========================================================================

    public function testSelectRangeEachCallsCallbackForEachMatchingRecord(): void
    {
        $this->seedUsersWithAge();
        $seen  = [];
        $count = JdbManager::selectRangeEach(
            self::T_USERS, 'age', 25, 35,
            function ($r) use (&$seen) { $seen[] = $r['name']; }
        );
        $this->assertSame(3, $count);
        $this->assertContains('Alice', $seen);
        $this->assertContains('Bob',   $seen);
        $this->assertContains('Carol', $seen);
    }

    public function testSelectRangeEachStopsOnCallbackFalse(): void
    {
        $this->seedUsersWithAge();
        $called = 0;
        JdbManager::selectRangeEach(
            self::T_USERS, 'age', null, null,
            function () use (&$called) { $called++; return false; }
        );
        $this->assertSame(1, $called);
    }

    public function testSelectRangeEachReturnsFalseForNonIndexedField(): void
    {
        JdbManager::insert(self::T_USERS, ['name' => 'Alice', 'age' => 25]);
        $result = JdbManager::selectRangeEach(
            self::T_USERS, 'age', null, null, function ($r) {}
        );
        $this->assertFalse($result);
    }

    public function testSelectRangeEachReturnsFalseForInvalidTable(): void
    {
        $this->assertFalse(
            JdbManager::selectRangeEach('', 'age', null, null, function ($r) {})
        );
    }

    public function testSelectRangeEachReturnsFalseForInvalidField(): void
    {
        JdbManager::configureSecondaryIndexes(self::T_USERS, ['age']);
        $this->assertFalse(
            JdbManager::selectRangeEach(self::T_USERS, '', null, null, function ($r) {})
        );
    }

    public function testSelectRangeEachWithLimitRespectsLimit(): void
    {
        $this->seedUsersWithAge();
        $collected = [];
        JdbManager::selectRangeEach(
            self::T_USERS, 'age', null, null,
            function ($r) use (&$collected) { $collected[] = $r; },
            [],
            2
        );
        $this->assertCount(2, $collected);
    }

    // =========================================================================
    // 26. SECONDARY INDEXES – stato dirty e rebuild
    // =========================================================================

    public function testCheckSecondaryIndexesReturnsStatusArray(): void
    {
        JdbManager::configureSecondaryIndexes(self::T_USERS, ['age']);
        JdbManager::insert(self::T_USERS, ['name' => 'Alice', 'age' => 30]);
        $status = JdbManager::checkSecondaryIndexes(self::T_USERS);
        $this->assertIsArray($status);
        $this->assertArrayHasKey('age',   $status);
        $this->assertArrayHasKey('dirty', $status['age']);
    }

    public function testSecondaryIndexIsDirtyImmediatelyAfterInsert(): void
    {
        JdbManager::configureSecondaryIndexes(self::T_USERS, ['age']);
        JdbManager::insert(self::T_USERS, ['name' => 'Alice', 'age' => 30]);
        $this->assertTrue(JdbManager::secondaryIndexesNeedRebuild(self::T_USERS));
    }

    public function testSecondaryIndexIsDirtyAfterUpdate(): void
    {
        JdbManager::configureSecondaryIndexes(self::T_USERS, ['age']);
        $id = JdbManager::insert(self::T_USERS, ['name' => 'Alice', 'age' => 25]);
        JdbManager::rebuildSecondaryIndexes(self::T_USERS);
        JdbManager::update(self::T_USERS, $id, ['name' => 'Alice', 'age' => 26]);
        $this->assertTrue(JdbManager::secondaryIndexesNeedRebuild(self::T_USERS));
    }

    public function testSecondaryIndexIsDirtyAfterDelete(): void
    {
        JdbManager::configureSecondaryIndexes(self::T_USERS, ['age']);
        $id = JdbManager::insert(self::T_USERS, ['name' => 'Alice', 'age' => 25]);
        JdbManager::rebuildSecondaryIndexes(self::T_USERS);
        JdbManager::delete(self::T_USERS, $id);
        $this->assertTrue(JdbManager::secondaryIndexesNeedRebuild(self::T_USERS));
    }

    public function testRebuildSecondaryIndexesClearsDirtyFlag(): void
    {
        JdbManager::configureSecondaryIndexes(self::T_USERS, ['age']);
        JdbManager::insert(self::T_USERS, ['name' => 'Alice', 'age' => 30]);
        JdbManager::rebuildSecondaryIndexes(self::T_USERS);
        $this->assertFalse(JdbManager::secondaryIndexesNeedRebuild(self::T_USERS));
    }

    public function testRebuildSecondaryIndexesOnInvalidTableSetsLastError(): void
    {
        JdbManager::rebuildSecondaryIndexes('');
        $this->assertNotNull(JdbManager::getLastError());
    }

    public function testCheckSecondaryIndexesReturnsEmptyArrayForInvalidTable(): void
    {
        $this->assertSame([], JdbManager::checkSecondaryIndexes(''));
    }

    public function testSelectRangeTriggersLazyRebuildOnDirtyIndex(): void
    {
        // selectRange deve ricostruire l'indice lazy e restituire risultati anche dopo insert
        JdbManager::configureSecondaryIndexes(self::T_USERS, ['age']);
        JdbManager::insert(self::T_USERS, ['name' => 'Alice', 'age' => 25]);
        JdbManager::insert(self::T_USERS, ['name' => 'Bob',   'age' => 35]);
        // Non chiamiamo rebuildSecondaryIndexes esplicitamente — la rebuild è lazy
        $result = JdbManager::selectRange(self::T_USERS, 'age', 25, 30);
        $this->assertNotFalse($result);
        $this->assertIsArray($result);
    }

    // =========================================================================
    // 27. FIELD TYPE COVERAGE PER SECONDARY INDEXES
    // =========================================================================

    public function testSelectRangeOnStringField(): void
    {
        JdbManager::configureSecondaryIndexes(self::T_USERS, ['name']);
        JdbManager::insert(self::T_USERS, ['name' => 'Alice']);
        JdbManager::insert(self::T_USERS, ['name' => 'Bob']);
        JdbManager::insert(self::T_USERS, ['name' => 'Carol']);
        $result = JdbManager::selectRange(self::T_USERS, 'name', 'Alice', 'Bob');
        $this->assertIsArray($result);
        $names = array_column($result, 'name');
        $this->assertContains('Alice', $names);
        $this->assertContains('Bob',   $names);
        $this->assertNotContains('Carol', $names);
    }

    public function testSelectRangeOnFloatField(): void
    {
        $this->seedProducts();
        $result = JdbManager::selectRange(self::T_PRODUCTS, 'price', 4.99, 9.99);
        $this->assertIsArray($result);
        $this->assertCount(2, $result);
    }

    public function testSelectRangeOnNegativeIntegerField(): void
    {
        JdbManager::configureSecondaryIndexes(self::T_USERS, ['score']);
        JdbManager::insert(self::T_USERS, ['name' => 'A', 'score' => -10]);
        JdbManager::insert(self::T_USERS, ['name' => 'B', 'score' =>   0]);
        JdbManager::insert(self::T_USERS, ['name' => 'C', 'score' =>  10]);
        $result = JdbManager::selectRange(self::T_USERS, 'score', -10, 0);
        $this->assertCount(2, $result);
    }

    public function testRecordsWithoutIndexedFieldAreExcludedFromRange(): void
    {
        JdbManager::configureSecondaryIndexes(self::T_USERS, ['age']);
        JdbManager::insert(self::T_USERS, ['name' => 'Alice', 'age' => 30]);
        JdbManager::insert(self::T_USERS, ['name' => 'Bob']); // campo 'age' assente
        $result = JdbManager::selectRange(self::T_USERS, 'age', null, null);
        $this->assertCount(1, $result);
        $this->assertSame('Alice', $result[0]['name']);
    }

    // =========================================================================
    // 28. MULTI-TABLE ISOLATION
    // =========================================================================

    public function testDataInOneTableDoesNotLeakToAnother(): void
    {
        JdbManager::insert(self::T_USERS,    ['name' => 'Alice']);
        JdbManager::insert(self::T_PRODUCTS, ['sku'  => 'P1']);
        $users    = JdbManager::readAll(self::T_USERS);
        $products = JdbManager::readAll(self::T_PRODUCTS);
        $this->assertCount(1, $users);
        $this->assertCount(1, $products);
        $this->assertArrayHasKey('name', $users[0]);
        $this->assertArrayHasKey('sku',  $products[0]);
    }

    public function testSecondaryIndexConfigsAreIsolatedPerTable(): void
    {
        JdbManager::configureSecondaryIndexes(self::T_USERS,    ['age']);
        JdbManager::configureSecondaryIndexes(self::T_PRODUCTS, ['price']);
        $this->assertFalse(JdbManager::hasSecondaryIndex(self::T_USERS,    'price'));
        $this->assertFalse(JdbManager::hasSecondaryIndex(self::T_PRODUCTS, 'age'));
    }

    public function testCountsAreIndependentPerTable(): void
    {
        JdbManager::insert(self::T_USERS,    ['name' => 'Alice']);
        JdbManager::insert(self::T_USERS,    ['name' => 'Bob']);
        JdbManager::insert(self::T_PRODUCTS, ['sku'  => 'P1']);
        $this->assertSame(2, JdbManager::count(self::T_USERS));
        $this->assertSame(1, JdbManager::count(self::T_PRODUCTS));
    }

    // =========================================================================
    // 29. ISOLAMENTO STATICO TRA I TEST
    // =========================================================================

    public function testEachTestStartsWithEmptyTable(): void
    {
        $this->assertSame(0, JdbManager::count(self::T_USERS));
    }

    public function testEachTestStartsWithNullErrorState(): void
    {
        $this->assertNull(JdbManager::getLastError());
    }

    public function testEachTestStartsWithNoSecondaryIndexConfig(): void
    {
        JdbManager::insert(self::T_USERS, ['name' => 'Alice', 'age' => 30]);
        $this->assertFalse(JdbManager::hasSecondaryIndex(self::T_USERS, 'age'));
    }

    // =========================================================================
    // 30. LOG-ERROR CONFIGURATION
    // =========================================================================

    public function testLogErrorsWritesToSpecifiedFile(): void
    {
        $logFile = $this->testDataDir . '/dm_errors.log';
        JdbManager::configure([
            'log_errors'     => true,
            'error_log_path' => $logFile,
        ]);
        JdbManager::insert('', ['x' => 1]); // genera errore
        $this->assertFileExists($logFile);
        $this->assertStringContainsString('JdbManager', file_get_contents($logFile));
    }

    // =========================================================================
    // 31. CONCURRENCY
    // =========================================================================

    public function testConcurrentReadsOnCleanIndexSucceed(): void
    {
        if (!extension_loaded('pcntl')) {
            $this->markTestSkipped('pcntl extension required for concurrency testing.');
        }
        $table = 'race_clean';
        $id    = JdbManager::insert($table, ['data' => 'stable']);
        $this->assertNotFalse($id);
        JdbManager::clearAllInstances();

        $pids = [];
        for ($i = 0; $i < 5; $i++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                usleep(random_int(0, 3000));
                JdbManager::findById($table, $id);
                exit(0);
            }
            $pids[] = $pid;
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        JdbManager::clearAllInstances();
        $result = JdbManager::findById($table, $id);
        $this->assertNotNull($result);
        $this->assertSame('stable', $result['data']);
    }

    public function testMkdirLockBackendPreventsRebuildRace(): void
    {
        if (!extension_loaded('pcntl')) {
            $this->markTestSkipped('pcntl extension required for concurrency testing.');
        }
        $table = 'race_mkdir';
        try {
            JdbManager::configure(['lock_backend' => 'mkdir']);
            $id = JdbManager::insert($table, ['data' => 'mkdir_lock']);
            $this->assertNotFalse($id);
            JdbManager::clearAllInstances();
            @unlink($this->testDataDir . '/' . $table . '.index.php');

            $pids = [];
            for ($i = 0; $i < 5; $i++) {
                $pid = pcntl_fork();
                if ($pid === 0) {
                    usleep(random_int(0, 3000));
                    JdbManager::findById($table, $id);
                    exit(0);
                }
                $pids[] = $pid;
            }
            foreach ($pids as $pid) {
                pcntl_waitpid($pid, $status);
            }

            JdbManager::clearAllInstances();
            $result = JdbManager::findById($table, $id);
            $this->assertNotNull($result);
            $this->assertNull(JdbManager::getLastError());
        } finally {
            JdbManager::configure(['lock_backend' => 'flock']);
        }
    }

    // =========================================================================
    // 32. CONFIGURAZIONE AVANZATA – pattern e lunghezza personalizzati
    // =========================================================================

    public function testCustomTableNamePatternIsRespected(): void
    {
        // Permette solo lettere minuscole
        JdbManager::configure(['table_name_pattern' => '/^[a-z]+$/']);
        $this->assertFalse(JdbManager::insert('UPPERCASE', ['name' => 'x']));
        $this->assertNotFalse(JdbManager::insert('lowercase', ['name' => 'x']));
    }

    public function testCustomMaxTableNameLenIsRespected(): void
    {
        JdbManager::configure(['max_table_name_len' => 10]);
        // 10 caratteri → valido
        $this->assertNotFalse(JdbManager::insert(str_repeat('a', 10), ['name' => 'x']));
        // 11 caratteri → non valido
        $this->assertFalse(JdbManager::insert(str_repeat('a', 11), ['name' => 'x']));
    }

    public function testRealpathCacheTtlIsAppliedViaConfiguration(): void
    {
        // configure() deve propagare il TTL a JdbRealpathCache senza errori
        $result = JdbManager::configure(['realpath_cache_ttl' => 60]);
        $this->assertTrue($result);
        $this->assertSame(60, JdbManager::getConfig('realpath_cache_ttl'));
    }

    public function testConfigureDetailedErrors(): void
    {
        $result = JdbManager::configure(['detailed_errors' => true]);
        $this->assertTrue($result);
        $this->assertTrue(JdbManager::getConfig('detailed_errors'));
    }

    // =========================================================================
    // 33. INSERT BATCH – casi avanzati
    // =========================================================================

    public function testInsertBatchMixedExplicitAndAutoIds(): void
    {
        $result = JdbManager::insertBatch(self::T_USERS, [
            ['id' => 'fixed-1', 'name' => 'Alice'],
            ['name' => 'Bob'],   // auto-increment
            ['id' => 'fixed-3', 'name' => 'Carol'],
        ]);
        $this->assertSame(3, $result['inserted']);
        $this->assertContains('fixed-1', $result['ids']);
        $this->assertContains('fixed-3', $result['ids']);
        // Bob deve avere un ID auto generato (intero)
        $auto = array_diff($result['ids'], ['fixed-1', 'fixed-3']);
        $this->assertCount(1, $auto);
        $this->assertIsInt(reset($auto));
    }

    public function testInsertBatchDoesNotCommitOnPartialFailure(): void
    {
        // La terza riga è vuota → tutto il batch deve essere annullato
        $before = JdbManager::count(self::T_USERS);
        JdbManager::insertBatch(self::T_USERS, [
            ['name' => 'Alice'],
            ['name' => 'Bob'],
            [],
        ]);
        $this->assertSame($before, JdbManager::count(self::T_USERS));
    }

    public function testInsertBatchLargeSet(): void
    {
        $rows = [];
        for ($i = 1; $i <= 100; $i++) {
            $rows[] = ['name' => "User$i", 'score' => $i];
        }
        $result = JdbManager::insertBatch(self::T_USERS, $rows);
        $this->assertSame(100, $result['inserted']);
        $this->assertSame(100, JdbManager::count(self::T_USERS));
    }

    // =========================================================================
    // 34. forEachRecord – casi avanzati
    // =========================================================================

    public function testForEachOnEmptyTableReturnsZero(): void
    {
        $count = JdbManager::forEachRecord(self::T_USERS, function () {});
        $this->assertSame(0, $count);
    }

    public function testForEachDoesNotVisitDeletedRecords(): void
    {
        $id1 = JdbManager::insert(self::T_USERS, ['name' => 'Alice']);
        $id2 = JdbManager::insert(self::T_USERS, ['name' => 'Bob']);
        JdbManager::delete(self::T_USERS, $id1);
        $names = [];
        JdbManager::forEachRecord(self::T_USERS, function ($r) use (&$names) {
            $names[] = $r['name'];
        });
        $this->assertNotContains('Alice', $names);
        $this->assertContains('Bob', $names);
    }

    public function testForEachVisitsLatestVersionAfterUpdate(): void
    {
        $id = JdbManager::insert(self::T_USERS, ['name' => 'Alice', 'age' => 25]);
        JdbManager::update(self::T_USERS, $id, ['name' => 'Alice', 'age' => 26]);
        $ages = [];
        JdbManager::forEachRecord(self::T_USERS, function ($r) use (&$ages) {
            $ages[] = $r['age'];
        });
        // Deve essere presente solo la versione più recente
        $this->assertSame([26], $ages);
    }

    public function testForEachWithMultipleConditions(): void
    {
        JdbManager::insert(self::T_USERS, ['role' => 'admin', 'active' => 1]);
        JdbManager::insert(self::T_USERS, ['role' => 'admin', 'active' => 0]);
        JdbManager::insert(self::T_USERS, ['role' => 'user',  'active' => 1]);
        $count = JdbManager::forEachRecord(self::T_USERS, function () {}, ['role' => 'admin', 'active' => 1]);
        $this->assertSame(1, $count);
    }

    // =========================================================================
    // 35. UPDATE – casi avanzati
    // =========================================================================

    public function testFindByIdReturnsLatestVersionAfterMultipleUpdates(): void
    {
        $id = JdbManager::insert(self::T_USERS, ['name' => 'Alice', 'age' => 20]);
        JdbManager::update(self::T_USERS, $id, ['name' => 'Alice', 'age' => 21]);
        JdbManager::update(self::T_USERS, $id, ['name' => 'Alice', 'age' => 22]);
        $this->assertSame(22, JdbManager::findById(self::T_USERS, $id)['age']);
    }

    public function testReadAllReturnsLatestVersionOnly(): void
    {
        $id = JdbManager::insert(self::T_USERS, ['name' => 'Alice', 'age' => 20]);
        JdbManager::update(self::T_USERS, $id, ['name' => 'Alice', 'age' => 99]);
        $all  = JdbManager::readAll(self::T_USERS);
        $ages = array_column($all, 'age');
        $this->assertSame([99], $ages);
    }

    public function testUpdateAfterDeleteReturnsFalse(): void
    {
        $id = JdbManager::insert(self::T_USERS, ['name' => 'Alice']);
        JdbManager::delete(self::T_USERS, $id);
        $this->assertFalse(JdbManager::update(self::T_USERS, $id, ['name' => 'Ghost']));
    }

    public function testUpdateWithStringId(): void
    {
        JdbManager::insert(self::T_USERS, ['name' => 'Alice'], 'str-id');
        $result = JdbManager::update(self::T_USERS, 'str-id', ['name' => 'Updated']);
        $this->assertTrue($result);
        $this->assertSame('Updated', JdbManager::findById(self::T_USERS, 'str-id')['name']);
    }

    // =========================================================================
    // 36. COMPACT – casi avanzati
    // =========================================================================

    public function testCompactAfterMultipleUpdatesKeepsOnlyLatest(): void
    {
        $id = JdbManager::insert(self::T_USERS, ['name' => 'Alice', 'v' => 1]);
        JdbManager::update(self::T_USERS, $id, ['name' => 'Alice', 'v' => 2]);
        JdbManager::update(self::T_USERS, $id, ['name' => 'Alice', 'v' => 3]);
        $stats = JdbManager::compact(self::T_USERS);
        $this->assertSame(1, $stats['records_kept']);
        $this->assertSame(3, JdbManager::findById(self::T_USERS, $id)['v']);
    }

    public function testGetStatsAfterCompactShowsSmallerSize(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $id = JdbManager::insert(self::T_USERS, ['data' => str_repeat('x', 200)]);
        }
        // Cancella 4 record su 5 per creare molto spazio recuperabile
        $all = JdbManager::readAll(self::T_USERS);
        foreach (array_slice($all, 1) as $r) {
            JdbManager::delete(self::T_USERS, $r['id']);
        }
        $sizeBefore = JdbManager::getStats(self::T_USERS)['data_file_size'];
        JdbManager::compact(self::T_USERS);
        JdbManager::clearInstance(self::T_USERS);
        $sizeAfter = JdbManager::getStats(self::T_USERS)['data_file_size'];
        $this->assertLessThan($sizeBefore, $sizeAfter);
    }

    public function testCompactOnEmptyTableSucceeds(): void
    {
        $stats = JdbManager::compact(self::T_USERS);
        $this->assertIsArray($stats);
        $this->assertSame(0, $stats['records_kept']);
    }

    // =========================================================================
    // 37. selectPage – casi avanzati
    // =========================================================================

    public function testSelectPageLastPageHasFewerRecords(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            JdbManager::insert(self::T_USERS, ['name' => "User$i"]);
        }
        $page1 = JdbManager::selectPage(self::T_USERS, 3);
        $page2 = JdbManager::selectPage(self::T_USERS, 3, $page1['next_cursor'] ?? null);
        $this->assertCount(2, $page2['records']);
    }

    public function testSelectPageWithConditionFiltersResults(): void
    {
        JdbManager::insert(self::T_USERS, ['name' => 'Alice', 'role' => 'admin']);
        JdbManager::insert(self::T_USERS, ['name' => 'Bob',   'role' => 'user']);
        JdbManager::insert(self::T_USERS, ['name' => 'Carol', 'role' => 'admin']);
        $page = JdbManager::selectPage(self::T_USERS, 10, null, ['role' => 'admin']);
        $this->assertIsArray($page);
        $this->assertCount(2, $page['records']);
    }

    public function testSelectPageAllRecordsRetrievableViaMultiplePages(): void
    {
        for ($i = 1; $i <= 7; $i++) {
            JdbManager::insert(self::T_USERS, ['name' => "User$i"]);
        }
        $all    = [];
        $cursor = null;
        do {
            $page   = JdbManager::selectPage(self::T_USERS, 3, $cursor);
            $all    = array_merge($all, $page['records']);
            $cursor = $page['next_cursor'] ?? null;
        } while ($cursor !== null);
        $this->assertCount(7, $all);
        // Nessun duplicato
        $ids = array_column($all, 'id');
        $this->assertCount(7, array_unique($ids));
    }

    // =========================================================================
    // 38. aggregate – casi avanzati
    // =========================================================================

    public function testAggregateCountOnEmptyTable(): void
    {
        $result = JdbManager::aggregate(self::T_USERS, 'count', 'age');
        $this->assertSame(0, (int)$result);
    }

    public function testAggregateMinOnIndexedFieldUsesEdgeValue(): void
    {
        // min/max su campo indicizzato è O(1) via getEdgeValue
        JdbManager::configureSecondaryIndexes(self::T_USERS, ['age']);
        JdbManager::insert(self::T_USERS, ['name' => 'Alice', 'age' => 10]);
        JdbManager::insert(self::T_USERS, ['name' => 'Bob',   'age' => 50]);
        JdbManager::rebuildSecondaryIndexes(self::T_USERS);
        $this->assertSame(10, (int)JdbManager::aggregate(self::T_USERS, 'min', 'age'));
        $this->assertSame(50, (int)JdbManager::aggregate(self::T_USERS, 'max', 'age'));
    }

    public function testAggregateSumIgnoresDeletedRecords(): void
    {
        $id1 = JdbManager::insert(self::T_USERS, ['name' => 'Alice', 'score' => 100]);
        JdbManager::insert(self::T_USERS, ['name' => 'Bob',   'score' => 200]);
        JdbManager::delete(self::T_USERS, $id1);
        $this->assertSame(200, (int)JdbManager::aggregate(self::T_USERS, 'sum', 'score'));
    }

    public function testAggregateAvgWithSingleRecord(): void
    {
        JdbManager::insert(self::T_USERS, ['name' => 'Alice', 'score' => 42]);
        $result = JdbManager::aggregate(self::T_USERS, 'avg', 'score');
        $this->assertEqualsWithDelta(42.0, $result, 0.001);
    }

    // =========================================================================
    // 39. truncateTable – casi avanzati
    // =========================================================================

    public function testTruncateTableWithNonZeroOffsetTruncatesFile(): void
    {
        // Scrivi l'header (16 byte) e un record, poi tronca all'offset del solo header
        JdbManager::insert(self::T_USERS, ['name' => 'Alice']);
        $dataFile    = JdbManager::getDataPath(self::T_USERS);
        $headerSize  = 16; // "<?php die(); ? >\n"
        $sizeAfterInsert = filesize($dataFile);
        $this->assertGreaterThan($headerSize, $sizeAfterInsert);

        $result = JdbManager::truncateTable(self::T_USERS, $headerSize);
        $this->assertTrue($result);
        clearstatcache(true, $dataFile);
        $this->assertSame($headerSize, filesize($dataFile));
    }

    public function testTruncateTableRemovesSecondaryIndexFiles(): void
    {
        JdbManager::configureSecondaryIndexes(self::T_USERS, ['age']);
        JdbManager::insert(self::T_USERS, ['name' => 'Alice', 'age' => 30]);
        JdbManager::rebuildSecondaryIndexes(self::T_USERS);

        $secIdxPath = JdbManager::getSecondaryIndexPath(self::T_USERS, 'age');
        $this->assertFileExists($secIdxPath);

        JdbManager::truncateTable(self::T_USERS, 0);
        $this->assertFileDoesNotExist($secIdxPath);
    }

    // =========================================================================
    // 40. clearTableConfig – casi avanzati
    // =========================================================================

    public function testClearTableConfigOnNonExistentTableIsNoOp(): void
    {
        JdbManager::clearTableConfig('table_that_does_not_exist');
        $this->assertNull(JdbManager::getLastError());
    }

    public function testClearTableConfigEvictsInstanceSoReopenHasNoIndex(): void
    {
        JdbManager::configureSecondaryIndexes(self::T_USERS, ['age']);
        JdbManager::insert(self::T_USERS, ['name' => 'Alice', 'age' => 30]);
        // Prima dell'evict, l'indice esiste
        $this->assertTrue(JdbManager::hasSecondaryIndex(self::T_USERS, 'age'));

        JdbManager::clearTableConfig(self::T_USERS);
        // Dopo l'evict, la nuova istanza non ha la config dell'indice
        $this->assertFalse(JdbManager::hasSecondaryIndex(self::T_USERS, 'age'));
    }

    // =========================================================================
    // 41. PATH HELPERS – fallback DATA_PATH / '.'
    // =========================================================================

    public function testGetDataDirFallsBackToDotWhenNothingConfigured(): void
    {
        JdbManager::resetAll();
        // Nessun data_dir e nessuna costante DATA_PATH → deve restituire '.'
        if (!defined('DATA_PATH')) {
            $this->assertSame('.', JdbManager::getDataDir());
        } else {
            $this->assertSame(DATA_PATH, JdbManager::getDataDir());
        }
    }

    public function testGetDataPathUsesConfiguredDataDir(): void
    {
        // data_dir è impostato in setUp()
        $path = JdbManager::getDataPath('orders');
        $this->assertStringStartsWith($this->testDataDir, $path);
        $this->assertStringEndsWith('orders.jsonl.php', $path);
    }

    public function testGetIndexPathUsesConfiguredDataDir(): void
    {
        $path = JdbManager::getIndexPath('orders');
        $this->assertStringStartsWith($this->testDataDir, $path);
        $this->assertStringEndsWith('orders.index.php', $path);
    }

    // =========================================================================
    // 42. selectRangeEach – casi avanzati
    // =========================================================================

    public function testSelectRangeEachWithConditionsFiltersCorrectly(): void
    {
        JdbManager::configureSecondaryIndexes(self::T_USERS, ['age']);
        JdbManager::insert(self::T_USERS, ['name' => 'Alice', 'age' => 30, 'role' => 'admin']);
        JdbManager::insert(self::T_USERS, ['name' => 'Bob',   'age' => 30, 'role' => 'user']);
        JdbManager::insert(self::T_USERS, ['name' => 'Carol', 'age' => 35, 'role' => 'admin']);
        $names = [];
        JdbManager::selectRangeEach(
            self::T_USERS, 'age', 25, 40,
            function ($r) use (&$names) { $names[] = $r['name']; },
            ['role' => 'admin']
        );
        $this->assertContains('Alice', $names);
        $this->assertContains('Carol', $names);
        $this->assertNotContains('Bob', $names);
    }

    public function testSelectRangeEachResultsAreSortedByFieldValue(): void
    {
        $this->seedUsersWithAge();
        $ages = [];
        JdbManager::selectRangeEach(
            self::T_USERS, 'age', null, null,
            function ($r) use (&$ages) { $ages[] = $r['age']; }
        );
        $sorted = $ages;
        sort($sorted);
        $this->assertSame($sorted, $ages);
    }

    public function testSelectRangeEachOnEmptyTableReturnsZero(): void
    {
        JdbManager::configureSecondaryIndexes(self::T_USERS, ['age']);
        $count = JdbManager::selectRangeEach(
            self::T_USERS, 'age', null, null, function () {}
        );
        $this->assertSame(0, $count);
    }

    // =========================================================================
    // 43. checkSecondaryIndexes – struttura completa del risultato
    // =========================================================================

    public function testCheckSecondaryIndexesIncludesFileSizeKey(): void
    {
        JdbManager::configureSecondaryIndexes(self::T_USERS, ['age']);
        JdbManager::insert(self::T_USERS, ['name' => 'Alice', 'age' => 30]);
        $status = JdbManager::checkSecondaryIndexes(self::T_USERS);
        $this->assertArrayHasKey('file_size', $status['age']);
    }

    public function testCheckSecondaryIndexesReportsCleanAfterRebuild(): void
    {
        JdbManager::configureSecondaryIndexes(self::T_USERS, ['age']);
        JdbManager::insert(self::T_USERS, ['name' => 'Alice', 'age' => 30]);
        JdbManager::rebuildSecondaryIndexes(self::T_USERS);
        $status = JdbManager::checkSecondaryIndexes(self::T_USERS);
        $this->assertFalse($status['age']['dirty']);
    }

    public function testCheckSecondaryIndexesMultipleFields(): void
    {
        JdbManager::configureSecondaryIndexes(self::T_USERS, ['age', 'name']);
        JdbManager::insert(self::T_USERS, ['name' => 'Alice', 'age' => 30]);
        $status = JdbManager::checkSecondaryIndexes(self::T_USERS);
        $this->assertArrayHasKey('age',  $status);
        $this->assertArrayHasKey('name', $status);
    }

    // =========================================================================
    // 44. getEntryVersion – OCC integrazione
    // =========================================================================

    public function testOccUpdateThenDeleteWithCorrectVersionSucceeds(): void
    {
        $id = JdbManager::insert(self::T_USERS, ['name' => 'Alice']);
        $v1 = JdbManager::getEntryVersion(self::T_USERS, $id);
        JdbManager::update(self::T_USERS, $id, ['name' => 'Updated'], $v1);
        $v2 = JdbManager::getEntryVersion(self::T_USERS, $id);
        $this->assertTrue(JdbManager::delete(self::T_USERS, $id, $v2));
    }

    public function testOccStaleVersionAfterConcurrentUpdateFails(): void
    {
        $id = JdbManager::insert(self::T_USERS, ['name' => 'Alice']);
        $v1 = JdbManager::getEntryVersion(self::T_USERS, $id);
        // Simula una modifica concorrente
        JdbManager::update(self::T_USERS, $id, ['name' => 'Concurrent Update']);
        // Tentativo con la versione ormai stale → deve fallire
        $this->assertFalse(JdbManager::update(self::T_USERS, $id, ['name' => 'Late Update'], $v1));
        // Il record deve avere il valore dell'update concorrente
        $this->assertSame('Concurrent Update', JdbManager::findById(self::T_USERS, $id)['name']);
    }

    // =========================================================================
    // 45. getStats – dettaglio campi
    // =========================================================================

    public function testGetStatsTotalLinesGrowsWithDeletesAndUpdates(): void
    {
        $id = JdbManager::insert(self::T_USERS, ['name' => 'Alice']);
        $statsAfterInsert = JdbManager::getStats(self::T_USERS);
        JdbManager::update(self::T_USERS, $id, ['name' => 'Alice Updated']);
        $statsAfterUpdate = JdbManager::getStats(self::T_USERS);
        $this->assertGreaterThan(
            $statsAfterInsert['total_lines'],
            $statsAfterUpdate['total_lines']
        );
    }

    public function testGetStatsTotalLinesIsAtLeastActiveRecords(): void
    {
        JdbManager::insert(self::T_USERS, ['name' => 'Alice']);
        JdbManager::insert(self::T_USERS, ['name' => 'Bob']);
        $stats = JdbManager::getStats(self::T_USERS);
        $this->assertGreaterThanOrEqual($stats['active_records'], $stats['total_lines']);
    }

    public function testGetStatsIndexSlotsIsAtLeastActiveRecords(): void
    {
        JdbManager::insert(self::T_USERS, ['name' => 'Alice']);
        JdbManager::insert(self::T_USERS, ['name' => 'Bob']);
        $stats = JdbManager::getStats(self::T_USERS);
        $this->assertGreaterThanOrEqual($stats['active_records'], $stats['index_slots']);
    }

    public function testGetStatsActiveRecordsDecreasesAfterDelete(): void
    {
        $id = JdbManager::insert(self::T_USERS, ['name' => 'Alice']);
        JdbManager::insert(self::T_USERS, ['name' => 'Bob']);
        $before = JdbManager::getStats(self::T_USERS)['active_records'];
        JdbManager::delete(self::T_USERS, $id);
        $after = JdbManager::getStats(self::T_USERS)['active_records'];
        $this->assertSame($before - 1, $after);
    }

    public function testGetStatsReturnsZeroActiveRecordsOnEmptyTable(): void
    {
        $stats = JdbManager::getStats(self::T_USERS);
        $this->assertSame(0, $stats['active_records']);
    }

    // =========================================================================
    // 46. secondaryIndexesNeedRebuild – edge case
    // =========================================================================

    public function testSecondaryIndexesNeedRebuildReturnsFalseWhenNoIndexConfigured(): void
    {
        JdbManager::insert(self::T_USERS, ['name' => 'Alice']);
        $this->assertFalse(JdbManager::secondaryIndexesNeedRebuild(self::T_USERS));
    }

    public function testSecondaryIndexesNeedRebuildReturnsFalseOnInvalidTable(): void
    {
        $this->assertFalse(JdbManager::secondaryIndexesNeedRebuild(''));
    }

    public function testRebuildSecondaryIndexesRebuildsAllConfiguredFields(): void
    {
        JdbManager::configureSecondaryIndexes(self::T_USERS, ['age', 'name']);
        JdbManager::insert(self::T_USERS, ['name' => 'Alice', 'age' => 30]);
        JdbManager::rebuildSecondaryIndexes(self::T_USERS);
        $status = JdbManager::checkSecondaryIndexes(self::T_USERS);
        $this->assertFalse($status['age']['dirty']);
        $this->assertFalse($status['name']['dirty']);
    }

    public function testRebuildSecondaryIndexesIsIdempotent(): void
    {
        JdbManager::configureSecondaryIndexes(self::T_USERS, ['age']);
        JdbManager::insert(self::T_USERS, ['name' => 'Alice', 'age' => 30]);
        JdbManager::rebuildSecondaryIndexes(self::T_USERS);
        JdbManager::rebuildSecondaryIndexes(self::T_USERS);
        $this->assertNull(JdbManager::getLastError());
        $this->assertFalse(JdbManager::secondaryIndexesNeedRebuild(self::T_USERS));
    }

    // =========================================================================
    // 47. selectRange / selectByIndexedField dopo operazioni strutturali
    // =========================================================================

    public function testSelectRangeAfterCompactReturnsCorrectResults(): void
    {
        JdbManager::configureSecondaryIndexes(self::T_USERS, ['age']);
        JdbManager::insert(self::T_USERS, ['name' => 'Alice', 'age' => 25]);
        $idBob = JdbManager::insert(self::T_USERS, ['name' => 'Bob', 'age' => 30]);
        JdbManager::insert(self::T_USERS, ['name' => 'Carol', 'age' => 35]);
        JdbManager::delete(self::T_USERS, $idBob);
        JdbManager::compact(self::T_USERS);
        JdbManager::clearInstance(self::T_USERS);
        $result = JdbManager::selectRange(self::T_USERS, 'age', null, null);
        $this->assertNotFalse($result);
        $this->assertCount(2, $result);
        $names = array_column($result, 'name');
        $this->assertNotContains('Bob', $names);
    }

    public function testSelectRangeAfterInsertBatchReturnsAllNewRecords(): void
    {
        JdbManager::configureSecondaryIndexes(self::T_USERS, ['age']);
        JdbManager::insertBatch(self::T_USERS, [
            ['name' => 'Alice', 'age' => 20],
            ['name' => 'Bob',   'age' => 30],
            ['name' => 'Carol', 'age' => 40],
        ]);
        $result = JdbManager::selectRange(self::T_USERS, 'age', 20, 40);
        $this->assertCount(3, $result);
    }

    public function testSelectByIndexedFieldWithAdditionalCondition(): void
    {
        JdbManager::configureSecondaryIndexes(self::T_USERS, ['age']);
        JdbManager::insert(self::T_USERS, ['name' => 'Alice', 'age' => 30, 'role' => 'admin']);
        JdbManager::insert(self::T_USERS, ['name' => 'Bob',   'age' => 30, 'role' => 'user']);
        $result = JdbManager::selectByIndexedField(self::T_USERS, 'age', 30, ['role' => 'admin']);
        $this->assertCount(1, $result);
        $this->assertSame('Alice', $result[0]['name']);
    }

    public function testSelectByIndexedFieldReturnsAllMatchesForDuplicateValue(): void
    {
        JdbManager::configureSecondaryIndexes(self::T_USERS, ['age']);
        JdbManager::insert(self::T_USERS, ['name' => 'Alice', 'age' => 30]);
        JdbManager::insert(self::T_USERS, ['name' => 'Bob',   'age' => 30]);
        JdbManager::insert(self::T_USERS, ['name' => 'Carol', 'age' => 25]);
        $result = JdbManager::selectByIndexedField(self::T_USERS, 'age', 30);
        $this->assertCount(2, $result);
    }

    public function testSelectRangeFromReturnsFalseForNonIndexedField(): void
    {
        JdbManager::insert(self::T_USERS, ['name' => 'Alice', 'age' => 30]);
        $this->assertFalse(JdbManager::selectRangeFrom(self::T_USERS, 'age', 20));
    }

    public function testSelectRangeToReturnsFalseForNonIndexedField(): void
    {
        JdbManager::insert(self::T_USERS, ['name' => 'Alice', 'age' => 30]);
        $this->assertFalse(JdbManager::selectRangeTo(self::T_USERS, 'age', 40));
    }

    public function testSelectRangeExcludesDeletedRecords(): void
    {
        JdbManager::configureSecondaryIndexes(self::T_USERS, ['age']);
        JdbManager::insert(self::T_USERS, ['name' => 'Alice', 'age' => 25]);
        $idBob = JdbManager::insert(self::T_USERS, ['name' => 'Bob', 'age' => 30]);
        JdbManager::delete(self::T_USERS, $idBob);
        $result = JdbManager::selectRange(self::T_USERS, 'age', null, null);
        $this->assertCount(1, $result);
        $this->assertSame('Alice', $result[0]['name']);
    }

    // =========================================================================
    // 48. validate_table_names toggle
    // =========================================================================

    public function testReenablingValidationAfterDisableBlocksInvalidNames(): void
    {
        JdbManager::configure(['validate_table_names' => false]);
        JdbManager::configure(['validate_table_names' => true]);
        $this->assertFalse(JdbManager::insert('bad name!', ['x' => 1]));
    }

    public function testTableCreatedWithValidationDisabledIsFullyUsable(): void
    {
        JdbManager::configure(['validate_table_names' => false]);
        $table = 'table_with_underscores_ok';
        $id = JdbManager::insert($table, ['data' => 'ok']);
        $this->assertNotFalse($id);
        $this->assertSame('ok', JdbManager::findById($table, $id)['data']);
        JdbManager::configure(['validate_table_names' => true]);
    }

    // =========================================================================
    // 49. configure – lock_timeout_ms
    // =========================================================================

    public function testConfigureLockTimeoutMsAcceptsPositiveValue(): void
    {
        $this->assertTrue(JdbManager::configure(['lock_timeout_ms' => 1000]));
        $this->assertSame(1000, JdbManager::getConfig('lock_timeout_ms'));
    }

    public function testConfigureLockTimeoutMsAcceptsZero(): void
    {
        // La classe non esegue validazione sul range: accetta 0
        $this->assertTrue(JdbManager::configure(['lock_timeout_ms' => 0]));
        $this->assertSame(0, JdbManager::getConfig('lock_timeout_ms'));
    }

    public function testConfigureLockTimeoutMsAcceptsNegativeValue(): void
    {
        // La classe non esegue validazione sul range: accetta valori negativi
        $this->assertTrue(JdbManager::configure(['lock_timeout_ms' => -1]));
        $this->assertSame(-1, JdbManager::getConfig('lock_timeout_ms'));
    }

    // =========================================================================
    // 50. Persistenza dati su clearInstance
    // =========================================================================

    public function testDataPersistsAcrossMultipleClearInstanceCalls(): void
    {
        $id = JdbManager::insert(self::T_USERS, ['name' => 'Alice', 'score' => 42]);
        for ($i = 0; $i < 5; $i++) {
            JdbManager::clearInstance(self::T_USERS);
            $rec = JdbManager::findById(self::T_USERS, $id);
            $this->assertNotNull($rec);
            $this->assertSame(42, $rec['score']);
        }
    }

    public function testDataPersistsAfterClearAllInstances(): void
    {
        $id1 = JdbManager::insert(self::T_USERS,    ['name' => 'Alice']);
        $id2 = JdbManager::insert(self::T_PRODUCTS, ['sku'  => 'P1']);
        JdbManager::clearAllInstances();
        $this->assertNotNull(JdbManager::findById(self::T_USERS,    $id1));
        $this->assertNotNull(JdbManager::findById(self::T_PRODUCTS, $id2));
    }

    // =========================================================================
    // 51. findById – coercizione del tipo ID
    // =========================================================================

    public function testFindByIdWithStringLookupOnIntegerId(): void
    {
        $id = JdbManager::insert(self::T_USERS, ['name' => 'Alice']);
        $rec = JdbManager::findById(self::T_USERS, (string)$id);
        $this->assertNotNull($rec);
        $this->assertSame('Alice', $rec['name']);
    }

    public function testFindByIdWithIntLookupOnStringId(): void
    {
        JdbManager::insert(self::T_USERS, ['name' => 'Alice'], '42');
        $rec = JdbManager::findById(self::T_USERS, 42);
        $this->assertNotNull($rec);
    }

    // =========================================================================
    // 52. count filtrato tramite findWhere
    // =========================================================================

    public function testCountWithConditionReturnsFilteredCount(): void
    {
        // count() non accetta condizioni: si usa findWhere() e si conta il risultato
        JdbManager::insert(self::T_USERS, ['name' => 'Alice', 'role' => 'admin']);
        JdbManager::insert(self::T_USERS, ['name' => 'Bob',   'role' => 'user']);
        JdbManager::insert(self::T_USERS, ['name' => 'Carol', 'role' => 'admin']);
        $admins = JdbManager::findWhere(self::T_USERS, ['role' => 'admin']);
        $this->assertIsArray($admins);
        $this->assertCount(2, $admins);
    }

    public function testCountWithConditionExcludesDeletedRecords(): void
    {
        $id = JdbManager::insert(self::T_USERS, ['name' => 'Alice', 'role' => 'admin']);
        JdbManager::insert(self::T_USERS, ['name' => 'Bob', 'role' => 'admin']);
        JdbManager::delete(self::T_USERS, $id);
        $admins = JdbManager::findWhere(self::T_USERS, ['role' => 'admin']);
        $this->assertIsArray($admins);
        $this->assertCount(1, $admins);
    }

    public function testCountWithNoMatchingConditionReturnsNull(): void
    {
        // findWhere() restituisce null quando non ci sono corrispondenze
        JdbManager::insert(self::T_USERS, ['name' => 'Alice', 'role' => 'user']);
        $result = JdbManager::findWhere(self::T_USERS, ['role' => 'superuser']);
        $this->assertNull($result);
    }

    // =========================================================================
    // 53. exists con ID stringa
    // =========================================================================

    public function testExistsReturnsTrueForStringId(): void
    {
        JdbManager::insert(self::T_USERS, ['name' => 'Alice'], 'alice-99');
        $this->assertTrue(JdbManager::exists(self::T_USERS, 'alice-99'));
    }

    public function testExistsReturnsFalseForWrongStringId(): void
    {
        JdbManager::insert(self::T_USERS, ['name' => 'Alice'], 'alice-99');
        $this->assertFalse(JdbManager::exists(self::T_USERS, 'alice-100'));
    }

    // =========================================================================
    // 54. compact con secondary indexes
    // =========================================================================

    public function testCompactMarksDirtySecondaryIndexes(): void
    {
        JdbManager::configureSecondaryIndexes(self::T_USERS, ['age']);
        JdbManager::insert(self::T_USERS, ['name' => 'Alice', 'age' => 25]);
        JdbManager::rebuildSecondaryIndexes(self::T_USERS);
        $this->assertFalse(JdbManager::secondaryIndexesNeedRebuild(self::T_USERS));
        JdbManager::compact(self::T_USERS);
        $this->assertTrue(JdbManager::secondaryIndexesNeedRebuild(self::T_USERS));
    }

    public function testSelectRangeAfterCompactAndRebuildIsConsistent(): void
    {
        JdbManager::configureSecondaryIndexes(self::T_USERS, ['age']);
        JdbManager::insert(self::T_USERS, ['name' => 'Alice', 'age' => 20]);
        $idDel = JdbManager::insert(self::T_USERS, ['name' => 'Deleted', 'age' => 25]);
        JdbManager::insert(self::T_USERS, ['name' => 'Carol', 'age' => 30]);
        JdbManager::delete(self::T_USERS, $idDel);
        JdbManager::compact(self::T_USERS);
        JdbManager::clearInstance(self::T_USERS);
        JdbManager::rebuildSecondaryIndexes(self::T_USERS);
        $result = JdbManager::selectRange(self::T_USERS, 'age', null, null);
        $this->assertCount(2, $result);
        $names = array_column($result, 'name');
        $this->assertContains('Alice', $names);
        $this->assertContains('Carol', $names);
    }

    public function testMultipleSequentialCompactsAreIdempotent(): void
    {
        JdbManager::insert(self::T_USERS, ['name' => 'Alice']);
        JdbManager::compact(self::T_USERS);
        JdbManager::compact(self::T_USERS);
        JdbManager::compact(self::T_USERS);
        $this->assertSame(1, JdbManager::count(self::T_USERS));
        $this->assertNull(JdbManager::getLastError());
    }

    // =========================================================================
    // 55. insertBatch – secondary index rimane dirty dopo il batch
    // =========================================================================

    public function testSecondaryIndexIsDirtyAfterInsertBatch(): void
    {
        JdbManager::configureSecondaryIndexes(self::T_USERS, ['age']);
        JdbManager::rebuildSecondaryIndexes(self::T_USERS);
        JdbManager::insertBatch(self::T_USERS, [
            ['name' => 'Alice', 'age' => 25],
            ['name' => 'Bob',   'age' => 30],
        ]);
        $this->assertTrue(JdbManager::secondaryIndexesNeedRebuild(self::T_USERS));
    }

    // =========================================================================
    // 56. getTableConfig – struttura del risultato
    // =========================================================================

    public function testGetTableConfigContainsSecondaryFieldsKey(): void
    {
        JdbManager::configureSecondaryIndexes(self::T_USERS, ['age', 'email']);
        $cfg = JdbManager::getTableConfig(self::T_USERS);
        $this->assertArrayHasKey('secondary_fields', $cfg);
        $this->assertSame(['age', 'email'], $cfg['secondary_fields']);
    }

    public function testGetTableConfigIsImmutableCopy(): void
    {
        JdbManager::configureSecondaryIndexes(self::T_USERS, ['age']);
        $cfg = JdbManager::getTableConfig(self::T_USERS);
        $cfg['secondary_fields'][] = 'hacked_field';
        $cfgAgain = JdbManager::getTableConfig(self::T_USERS);
        $this->assertNotContains('hacked_field', $cfgAgain['secondary_fields'] ?? []);
    }

    // =========================================================================
    // 57. clearAllConfig – non cancella i file dati
    // =========================================================================

    public function testClearAllConfigDoesNotDeleteDataFiles(): void
    {
        JdbManager::insert(self::T_USERS, ['name' => 'Alice']);
        $dataFile = JdbManager::getDataPath(self::T_USERS);
        $this->assertFileExists($dataFile);
        JdbManager::clearAllConfig();
        $this->assertFileExists($dataFile);
    }

    public function testClearAllConfigAllowsInsertAfterReconfigure(): void
    {
        JdbManager::insert(self::T_USERS, ['name' => 'Alice']);
        JdbManager::clearAllConfig();
        JdbManager::configure(['data_dir' => $this->testDataDir]);
        $id = JdbManager::insert(self::T_USERS, ['name' => 'Bob']);
        $this->assertNotFalse($id);
    }

    // =========================================================================
    // 58. auto_compact
    // =========================================================================

    public function testAutoCompactIsTriggeredWhenThresholdIsExceeded(): void
    {
        JdbManager::configure([
            'auto_compact'             => true,
            'auto_compact_threshold'   => 0.5,
            'auto_compact_min_records' => 2,
        ]);
        $id1 = JdbManager::insert(self::T_USERS, ['data' => str_repeat('x', 200)]);
        $id2 = JdbManager::insert(self::T_USERS, ['data' => str_repeat('x', 200)]);
        JdbManager::insert(self::T_USERS, ['data' => str_repeat('x', 200)]);
        JdbManager::delete(self::T_USERS, $id1);
        JdbManager::delete(self::T_USERS, $id2);
        // Il prossimo insert supera la soglia e deve scatenare l'auto-compact
        JdbManager::insert(self::T_USERS, ['data' => 'trigger']);
        $stats = JdbManager::getStats(self::T_USERS);
        $this->assertSame(2, $stats['active_records']);
    }

    // =========================================================================
    // 59. Lettura filtrata tramite findWhere
    // =========================================================================

    public function testReadAllWithConditionReturnsOnlyMatchingRecords(): void
    {
        // readAll() non accetta condizioni: si usa findWhere()
        JdbManager::insert(self::T_USERS, ['name' => 'Alice', 'active' => true]);
        JdbManager::insert(self::T_USERS, ['name' => 'Bob',   'active' => false]);
        JdbManager::insert(self::T_USERS, ['name' => 'Carol', 'active' => true]);
        $result = JdbManager::findWhere(self::T_USERS, ['active' => true]);
        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $names = array_column($result, 'name');
        $this->assertNotContains('Bob', $names);
    }

    public function testReadAllWithConditionReturnsNullWhenNoMatch(): void
    {
        // findWhere() restituisce null quando non ci sono corrispondenze
        JdbManager::insert(self::T_USERS, ['name' => 'Alice', 'role' => 'user']);
        $result = JdbManager::findWhere(self::T_USERS, ['role' => 'superadmin']);
        $this->assertNull($result);
    }

    // =========================================================================
    // 60. Scenari end-to-end
    // =========================================================================

    public function testFullLifecycleInsertReadUpdateDeleteCompact(): void
    {
        $id = JdbManager::insert(self::T_USERS, ['name' => 'Alice', 'score' => 10]);
        $this->assertNotFalse($id);

        $rec = JdbManager::findById(self::T_USERS, $id);
        $this->assertSame('Alice', $rec['name']);

        JdbManager::update(self::T_USERS, $id, ['name' => 'Alice', 'score' => 99]);
        $this->assertSame(99, JdbManager::findById(self::T_USERS, $id)['score']);

        JdbManager::delete(self::T_USERS, $id);
        $this->assertNull(JdbManager::findById(self::T_USERS, $id));

        $stats = JdbManager::compact(self::T_USERS);
        $this->assertSame(0, $stats['records_kept']);
    }

    public function testFullLifecycleWithSecondaryIndex(): void
    {
        JdbManager::configureSecondaryIndexes(self::T_USERS, ['score']);

        JdbManager::insertBatch(self::T_USERS, [
            ['name' => 'Alice', 'score' => 10],
            ['name' => 'Bob',   'score' => 50],
            ['name' => 'Carol', 'score' => 30],
        ]);

        $mid = JdbManager::selectRange(self::T_USERS, 'score', 20, 40);
        $this->assertCount(1, $mid);
        $this->assertSame('Carol', $mid[0]['name']);

        JdbManager::update(self::T_USERS, $mid[0]['id'], ['name' => 'Carol', 'score' => 5]);
        JdbManager::rebuildSecondaryIndexes(self::T_USERS);

        $mid2 = JdbManager::selectRange(self::T_USERS, 'score', 20, 40);
        $this->assertNull($mid2);

        $this->assertSame(3, (int)JdbManager::aggregate(self::T_USERS, 'count', 'score'));
    }

    public function testMultiTableTransactionalIsolation(): void
    {
        $uid = JdbManager::insert(self::T_USERS,    ['name' => 'Alice']);
        $pid = JdbManager::insert(self::T_PRODUCTS, ['sku'  => 'P1']);

        JdbManager::delete(self::T_USERS, $uid);

        $this->assertNull(JdbManager::findById(self::T_USERS,    $uid));
        $this->assertNotNull(JdbManager::findById(self::T_PRODUCTS, $pid));
        $this->assertSame(0, JdbManager::count(self::T_USERS));
        $this->assertSame(1, JdbManager::count(self::T_PRODUCTS));
    }

    public function testBeginTransactionReturnsNullOnLockFailure(): void
    {
        // Abilita messaggi di errore dettagliati per questo test
        JdbManager::configure(['detailed_errors' => true]);

        $roDir = $this->testDataDir . '/readonly_lock';
        mkdir($roDir, 0555);  // solo lettura
        JdbManager::configure(['data_dir' => $roDir, 'lock_backend' => 'mkdir']);

        $tx = JdbManager::beginTransaction(['any_table']);
        $this->assertNull($tx);
        $err = JdbManager::getLastError();
        $this->assertNotNull($err);
        $this->assertStringContainsString('Lock acquisition failed', $err['message']);

        // Ripristina
        chmod($roDir, 0755);
        rmdir($roDir);
        JdbManager::configure([
            'data_dir' => $this->testDataDir,
            'lock_backend' => 'flock',
            'detailed_errors' => false
        ]);
    }

    public function testBeginTransactionSucceedsOnValidTables(): void
    {
        $tx = JdbManager::beginTransaction([self::T_USERS, self::T_PRODUCTS]);
        $this->assertInstanceOf(JdbTransaction::class, $tx);
        $this->assertTrue($tx->isActive());
        $tx->rollback();
    }

    // Sezione 62. _getInstance – error paths
    public function testGetInstanceFailsWhenDataDirNotWritable(): void
    {
        JdbManager::configure(['detailed_errors' => true]);

        $roDir = $this->testDataDir . '/readonly_data';
        mkdir($roDir, 0555);
        JdbManager::configure(['data_dir' => $roDir]);

        JdbManager::clearInstance(self::T_USERS);
        $result = JdbManager::count(self::T_USERS);
        $this->assertFalse($result);
        $err = JdbManager::getLastError();
        $this->assertStringContainsString('Data path missing or not writable', $err['message']);

        chmod($roDir, 0755);
        rmdir($roDir);
        JdbManager::configure(['data_dir' => $this->testDataDir, 'detailed_errors' => false]);
    }

    public function testGetInstanceFailsWhenEnsureDirectoryFails(): void
    {
        JdbManager::configure(['detailed_errors' => true]);

        // Crea una directory non scrivibile
        $roDir = $this->testDataDir . '/readonly_data_dir';
        mkdir($roDir, 0555);
        JdbManager::configure(['data_dir' => $roDir]);

        JdbManager::clearInstance(self::T_USERS);
        $result = JdbManager::count(self::T_USERS);
        $this->assertFalse($result);
        $err = JdbManager::getLastError();
        $this->assertStringContainsString('Data path missing or not writable', $err['message']);

        // Pulizia
        chmod($roDir, 0755);
        rmdir($roDir);
        JdbManager::configure(['data_dir' => $this->testDataDir, 'detailed_errors' => false]);
    }

    // Sezione 63. truncateTable – error scenarios
    public function testTruncateTableFailsWhenDataFileNotWritable(): void
    {
        // Inserisci un record per creare il file
        JdbManager::insert(self::T_USERS, ['name' => 'Alice']);
        JdbManager::clearInstance(self::T_USERS);

        $dataFile = JdbManager::getDataPath(self::T_USERS);
        $this->assertFileExists($dataFile);

        // Rendi il file solo lettura
        chmod($dataFile, 0444);
        clearstatcache(true, $dataFile);

        // Tenta di troncare a 16 byte (solo l’header) – deve fallire
        $result = JdbManager::truncateTable(self::T_USERS, 16);
        $this->assertFalse($result);

        // Ripristina
        chmod($dataFile, 0644);
        JdbManager::clearInstance(self::T_USERS);
    }

    // Sezione 64. insertBatch – edge cases
    public function testInsertBatchWithEmptyRowsReturnsZero(): void
    {
        $result = JdbManager::insertBatch(self::T_USERS, []);
        $this->assertIsArray($result);
        $this->assertSame(0, $result['inserted']);
        $this->assertSame([], $result['ids']);
    }

    // Sezione 65. getDbError
    public function testGetDbErrorReturnsEmptyStringOnInvalidTable(): void
    {
        $this->assertSame('', JdbManager::getDbError(''));
    }

    public function testGetDbErrorReturnsEmptyStringWhenNoError(): void
    {
        JdbManager::insert(self::T_USERS, ['name' => 'Alice']);
        $this->assertSame('', JdbManager::getDbError(self::T_USERS));
    }

    public function testGetDbErrorReturnsNonEmptyStringAfterError(): void
    {
        // Disabilita la creazione automatica (per sicurezza, anche se non basta)
        JdbManager::configure(['auto_create_indexes' => false]);

        // Crea un record per generare il file indice
        JdbManager::insert(self::T_USERS, ['name' => 'Alice']);
        $indexFile = JdbManager::getIndexPath(self::T_USERS);

        // Sovrascrive l'indice con un file vuoto (invalido)
        file_put_contents($indexFile, '');
        JdbManager::clearInstance(self::T_USERS);

        // Il prossimo accesso tenterà di aprire l'indice corrotto → errore
        @JdbManager::findById(self::T_USERS, 1);

        $err = JdbManager::getDbError(self::T_USERS);
        $this->assertIsString($err);
        $this->assertNotEmpty($err);

        // Ripristina configurazione
        JdbManager::configure(['auto_create_indexes' => true]);
    }

    // Sezione 66. update/delete with expectedVersion on non-existent record
    public function testUpdateWithExpectedVersionOnMissingIdReturnsFalse(): void
    {
        $result = JdbManager::update(self::T_USERS, 9999, ['name' => 'Ghost'], 1);
        $this->assertFalse($result);
    }

    public function testDeleteWithExpectedVersionOnMissingIdReturnsFalse(): void
    {
        $result = JdbManager::delete(self::T_USERS, 9999, 1);
        $this->assertFalse($result);
    }

    // Sezione 67. exists error paths
    public function testExistsReturnsFalseOnInvalidTable(): void
    {
        $this->assertFalse(JdbManager::exists('', 1));
    }

    // Sezione 68. count error paths
    public function testCountReturnsFalseOnInvalidTable(): void
    {
        $this->assertFalse(JdbManager::count(''));
    }

    // Sezione 69. getStats error paths
    public function testGetStatsReturnsFalseOnInvalidTable(): void
    {
        $this->assertFalse(JdbManager::getStats(''));
    }

    // Sezione 70. compact error paths
    public function testCompactReturnsFalseOnInvalidTable(): void
    {
        $this->assertFalse(JdbManager::compact(''));
    }

    // Sezione 72. selectRange invalid field
    public function testSelectRangeReturnsFalseOnInvalidFieldName(): void
    {
        JdbManager::configureSecondaryIndexes(self::T_USERS, ['age']);
        $this->assertFalse(JdbManager::selectRange(self::T_USERS, '', 1, 2));
    }

    public function testSelectRangeReturnsFalseOnNonIndexedField(): void
    {
        JdbManager::insert(self::T_USERS, ['name' => 'Alice', 'age' => 30]);
        $this->assertFalse(JdbManager::selectRange(self::T_USERS, 'age', 20, 40));
    }

    // Sezione 73. hasSecondaryIndex invalid field
    public function testHasSecondaryIndexReturnsFalseOnInvalidField(): void
    {
        $this->assertFalse(JdbManager::hasSecondaryIndex(self::T_USERS, ''));
    }
}
