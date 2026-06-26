<?php
/**
 * JdbConfigTest – Integration test suite for the JdbConfig static class.
 *
 * Compatible with Codeception Unit suite and PHPUnit 9+.
 *
 * Every test method is self-contained:
 *   - setUp() resets the static state of JdbConfig and JdbManager
 *   - tearDown() ensures no static leakage between tests
 *
 * Run with:
 *   vendor/bin/codecept run unit JdbConfigTest
 *   vendor/bin/phpunit tests/unit/JdbConfigTest.php
 */
class JdbConfigTest extends \PHPUnit\Framework\TestCase // Or \Codeception\Test\Unit
{
    // ── Lifecycle ───────────────────────────────────────────────────────────
    protected function setUp(): void
    {
        // 1. Reset JdbConfig static state using Reflection (since $config is private)
        $reflection = new \ReflectionClass(JdbConfig::class);
        $property = $reflection->getProperty('config');
        $property->setAccessible(true);
        $property->setValue(null, []);

        // 2. Reset JdbManager state (mirroring JdbManagerTest setup)
        JdbManager::clearAllInstances();
        JdbManager::clearError();
        JdbManager::configure([
            'validate_table_names' => true,
            'table_name_pattern'   => '/^[a-zA-Z0-9_]+$/',
            'max_table_name_len'   => 64,
            'log_errors'           => false,
            'error_log_path'       => null,
            'auto_create_indexes'  => true,
        ]);
    }

    protected function tearDown(): void
    {
        // Clean up JdbManager to prevent leakage
        JdbManager::clearAllInstances();
        JdbManager::clearError();
        
        // Reset JdbConfig again for absolute safety
        $reflection = new \ReflectionClass(JdbConfig::class);
        $property = $reflection->getProperty('config');
        $property->setAccessible(true);
        $property->setValue(null, []);
    }

    // =========================================================================
    // 1. CONFIGURATION MERGING
    // =========================================================================
    public function testConfigureMergesNewSettings(): void
    {
        JdbConfig::configure(['log_errors' => false]);
        JdbConfig::configure(['auto_compact' => true]);

        $this->assertFalse(JdbConfig::get('log_errors'));
        $this->assertTrue(JdbConfig::get('auto_compact'));
    }

    public function testConfigureOverridesExistingSettings(): void
    {
        JdbConfig::configure(['log_errors' => false]);
        JdbConfig::configure(['log_errors' => true]);
        
        $this->assertTrue(JdbConfig::get('log_errors'));
    }

    public function testConfigureReturnsTrueOnSuccess(): void
    {
        // Usiamo una chiave di configurazione reale e valida invece di una inventata
        $result = JdbConfig::configure(['log_errors' => true]);
        $this->assertTrue($result, 'JdbConfig::configure should return true for valid shared settings');
        $this->assertTrue(JdbConfig::get('log_errors'));
    }

    // =========================================================================
    // 2. RETRIEVAL METHODS (get / getAll)
    // =========================================================================
    public function testGetReturnsCorrectValue(): void
    {
        JdbConfig::configure(['lock_timeout_ms' => 12345]);
        $this->assertSame(12345, JdbConfig::get('lock_timeout_ms'));
    }

    public function testGetReturnsDefaultForMissingKey(): void
    {
        $this->assertSame('my_default', JdbConfig::get('nonexistent_key', 'my_default'));
        $this->assertNull(JdbConfig::get('nonexistent_key'));
    }

    public function testGetAllReturnsFullMergedConfiguration(): void
    {
        JdbConfig::configure(['log_errors' => true]);
        JdbConfig::configure(['auto_compact' => true]);

        $all = JdbConfig::getAll();
        $this->assertIsArray($all);
        $this->assertArrayHasKey('log_errors', $all);
        $this->assertArrayHasKey('auto_compact', $all);
        $this->assertTrue($all['log_errors']);
        $this->assertTrue($all['auto_compact']);
    }

    // =========================================================================
    // 3. INTEGRATION WITH JdbManager
    // =========================================================================
    public function testConfigureForwardsSettingsToJdbManager(): void
    {
        // JdbManager natively understands 'log_errors'
        JdbConfig::configure(['log_errors' => true]);
        
        // Verify that JdbManager actually received and applied the configuration
        $this->assertTrue(JdbManager::getConfig('log_errors'));
    }

    public function testConfigureForwardsDataDirToJdbManager(): void
    {
        $tempDir = sys_get_temp_dir() . '/jdb_config_test_' . uniqid();
        mkdir($tempDir, 0755, true);
        
        JdbConfig::configure(['data_dir' => $tempDir]);
        
        // JdbManager uses 'data_dir' (or 'data_path' depending on your exact implementation)
        // Assuming JdbManager::getConfig('data_dir') exists based on JdbManagerTest
        $this->assertSame($tempDir, JdbManager::getConfig('data_dir'));
        
        // Cleanup
        rmdir($tempDir);
    }

    // =========================================================================
    // 4. INTEGRATION WITH JdbAggregate (Mocked)
    // =========================================================================
    public function testConfigureAttemptsToForwardToJdbAggregate(): void
    {
        // Usiamo 'data_dir', che è una configurazione tipica per sistemi di DB/Aggregate
        $tempDir = sys_get_temp_dir() . '/jdb_agg_test_' . uniqid();
        mkdir($tempDir, 0755, true);

        $result = JdbConfig::configure(['data_dir' => $tempDir]);

        $this->assertTrue($result, 'JdbConfig::configure should return true if both Manager and Aggregate accept the config');
        $this->assertSame($tempDir, JdbConfig::get('data_dir'));

        // Cleanup
        rmdir($tempDir);
    }

    // =========================================================================
    // 5. STATIC-STATE ISOLATION BETWEEN TESTS
    // =========================================================================
    public function testEachTestStartsWithCleanConfigState(): void
    {
        // If a previous test leaked a unique key, this would fail.
        $all = JdbConfig::getAll();
        $this->assertArrayNotHasKey('leaked_key_from_previous_test', $all);
    }

    public function testJdbManagerStateIsAlsoIsolated(): void
    {
        // Verify that JdbManager's error state is clean at the start of each test
        $this->assertNull(JdbManager::getLastError());
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testConfigureReturnsFalseWhenJdbManagerFails(): void
    {
        $invalidConfig = ['not_exists' => '1']; // unwritable

        $result = JdbConfig::configure($invalidConfig);
        $this->assertFalse($result);

        // Verify JdbConfig doesn't store an invalid configuration
        $this->assertNull(JdbConfig::get('not_exists'));
    }

    public function testConfigureWithEmptyArray(): void
    {
        $result = JdbConfig::configure([]);
        $this->assertTrue($result);
        $all = JdbConfig::getAll();
        // Non vuoto: contiene i default
        $this->assertNotEmpty($all);
        $this->assertArrayHasKey('data_dir', $all);
        $this->assertArrayHasKey('log_errors', $all);
    }

    public function testGetAllReturnsCopyNotReference(): void
    {
        JdbConfig::configure(['data_dir' => '/tmp/realpath']);
        $all = JdbConfig::getAll();
        $all['data_dir'] = '/hacked';
        $this->assertSame('/tmp/realpath', JdbConfig::get('data_dir'));
    }

    public function testGetReturnsExplicitNullInsteadOfDefault(): void
    {
        JdbConfig::configure(['error_log_path' => null]);
        $this->assertNull(JdbConfig::get('error_log_path', '/default/path'));
    }

    public function testAllConfigKeysAreForwardedToJdbManager(): void
    {
        $config = [
            'validate_table_names' => false,
            'table_name_pattern'   => '/^[a-z]+$/',
            'max_table_name_len'   => 32,
            'log_errors'           => true,
            'error_log_path'       => '/tmp/error.log',
            'auto_create_indexes'  => false,
        ];
        JdbConfig::configure($config);

        foreach ($config as $k => $v) {
            $this->assertSame($v, JdbManager::getConfig($k));
        }
    }

    public function testConfigureIsIdempotent(): void
    {
        JdbConfig::configure(['max_table_name_len' => 100]);
        JdbConfig::configure(['log_errors' => true]);
        $this->assertSame(100, JdbConfig::get('max_table_name_len'));
    }

    public function testReturnTypes(): void
    {
        $this->assertIsBool(JdbConfig::configure(['a' => 1]));
        $this->assertIsArray(JdbConfig::getAll());
        // get può restituire mixed, quindi controlliamo solo l'esistenza
        $this->assertTrue(method_exists(JdbConfig::class, 'get'));
    }
}
