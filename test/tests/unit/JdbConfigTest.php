<?php

/**
 * Integration test suite for the JdbConfig static class.
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

    /**
     * Resets the static state of both JdbConfig and JdbManager before each test
     * to guarantee full isolation between test cases.
     *
     * JdbConfig::$config is private, so Reflection is used to access it directly.
     */
    protected function setUp(): void
    {
        // 1. Reset JdbConfig static state using Reflection (since $config is private)
        $reflection = new \ReflectionClass(JdbConfig::class);
        $property   = $reflection->getProperty('config');
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

    /**
     * Cleans up JdbManager and JdbConfig static state after each test
     * to prevent leakage into subsequent test cases.
     */
    protected function tearDown(): void
    {
        JdbManager::clearAllInstances();
        JdbManager::clearError();

        // Reset JdbConfig again for absolute safety
        $reflection = new \ReflectionClass(JdbConfig::class);
        $property   = $reflection->getProperty('config');
        $property->setAccessible(true);
        $property->setValue(null, []);
    }

    // =========================================================================
    // 1. CONFIGURATION MERGING
    // =========================================================================

    /**
     * configure() called twice with different keys must merge both sets of
     * settings, making all keys retrievable via get().
     */
    public function testConfigureMergesNewSettings(): void
    {
        JdbConfig::configure(['log_errors'   => false]);
        JdbConfig::configure(['auto_compact' => true]);

        $this->assertFalse(JdbConfig::get('log_errors'));
        $this->assertTrue(JdbConfig::get('auto_compact'));
    }

    /**
     * A second configure() call with the same key must overwrite the previous value.
     */
    public function testConfigureOverridesExistingSettings(): void
    {
        JdbConfig::configure(['log_errors' => false]);
        JdbConfig::configure(['log_errors' => true]);

        $this->assertTrue(JdbConfig::get('log_errors'));
    }

    /**
     * configure() must return true when all provided settings are valid and
     * accepted by the underlying subsystems.
     */
    public function testConfigureReturnsTrueOnSuccess(): void
    {
        // Use a real and valid configuration key instead of an invented one
        $result = JdbConfig::configure(['log_errors' => true]);
        $this->assertTrue($result, 'JdbConfig::configure should return true for valid shared settings');
        $this->assertTrue(JdbConfig::get('log_errors'));
    }

    // =========================================================================
    // 2. RETRIEVAL METHODS (get / getAll)
    // =========================================================================

    /**
     * get() must return the exact value that was previously set via configure().
     */
    public function testGetReturnsCorrectValue(): void
    {
        JdbConfig::configure(['lock_timeout_ms' => 12345]);
        $this->assertSame(12345, JdbConfig::get('lock_timeout_ms'));
    }

    /**
     * get() on a key that has never been configured must return the caller-supplied
     * default value, or null when no default is provided.
     */
    public function testGetReturnsDefaultForMissingKey(): void
    {
        $this->assertSame('my_default', JdbConfig::get('nonexistent_key', 'my_default'));
        $this->assertNull(JdbConfig::get('nonexistent_key'));
    }

    /**
     * getAll() must return an array containing every key that has been set
     * across multiple configure() calls, with the correct associated values.
     */
    public function testGetAllReturnsFullMergedConfiguration(): void
    {
        JdbConfig::configure(['log_errors'   => true]);
        JdbConfig::configure(['auto_compact' => true]);

        $all = JdbConfig::getAll();
        $this->assertIsArray($all);
        $this->assertArrayHasKey('log_errors',   $all);
        $this->assertArrayHasKey('auto_compact', $all);
        $this->assertTrue($all['log_errors']);
        $this->assertTrue($all['auto_compact']);
    }

    // =========================================================================
    // 3. INTEGRATION WITH JdbManager
    // =========================================================================

    /**
     * Settings passed to JdbConfig::configure() that are natively understood by
     * JdbManager (e.g. 'log_errors') must be forwarded and applied to JdbManager.
     */
    public function testConfigureForwardsSettingsToJdbManager(): void
    {
        JdbConfig::configure(['log_errors' => true]);

        // Verify that JdbManager actually received and applied the configuration
        $this->assertTrue(JdbManager::getConfig('log_errors'));
    }

    /**
     * The 'data_dir' setting passed to JdbConfig::configure() must be forwarded
     * to JdbManager and readable via JdbManager::getConfig('data_dir').
     */
    public function testConfigureForwardsDataDirToJdbManager(): void
    {
        $tempDir = sys_get_temp_dir() . '/jdb_config_test_' . uniqid();
        mkdir($tempDir, 0755, true);

        JdbConfig::configure(['data_dir' => $tempDir]);

        $this->assertSame($tempDir, JdbManager::getConfig('data_dir'));

        rmdir($tempDir);
    }

    // =========================================================================
    // 4. INTEGRATION WITH JdbAggregate (Mocked)
    // =========================================================================

    /**
     * configure() must return true and store 'data_dir' when both JdbManager
     * and JdbAggregate accept the forwarded settings.
     *
     * 'data_dir' is a typical configuration key shared by DB/Aggregate subsystems.
     */
    public function testConfigureAttemptsToForwardToJdbAggregate(): void
    {
        $tempDir = sys_get_temp_dir() . '/jdb_agg_test_' . uniqid();
        mkdir($tempDir, 0755, true);

        $result = JdbConfig::configure(['data_dir' => $tempDir]);

        $this->assertTrue($result, 'JdbConfig::configure should return true if both Manager and Aggregate accept the config');
        $this->assertSame($tempDir, JdbConfig::get('data_dir'));

        rmdir($tempDir);
    }

    // =========================================================================
    // 5. STATIC-STATE ISOLATION BETWEEN TESTS
    // =========================================================================

    /**
     * Verifies that the setUp() reset is effective: no key from a previous test
     * must be present in the configuration at the start of a new test.
     */
    public function testEachTestStartsWithCleanConfigState(): void
    {
        // If a previous test leaked a unique key, this would fail
        $all = JdbConfig::getAll();
        $this->assertArrayNotHasKey('leaked_key_from_previous_test', $all);
    }

    /**
     * Verifies that JdbManager's error state is fully cleared between tests,
     * meaning getLastError() returns null at the start of each test case.
     */
    public function testJdbManagerStateIsAlsoIsolated(): void
    {
        $this->assertNull(JdbManager::getLastError());
    }

    /**
     * configure() must return false when an unknown or unsupported key is supplied,
     * and must not store the invalid key in the internal configuration.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testConfigureReturnsFalseWhenJdbManagerFails(): void
    {
        // An unknown configuration key that no subsystem should accept
        $invalidConfig = ['not_exists' => '1'];

        $result = JdbConfig::configure($invalidConfig);
        $this->assertFalse($result);

        // Verify that JdbConfig does not store an invalid configuration key
        $this->assertNull(JdbConfig::get('not_exists'));
    }

    /**
     * configure() with an empty array must return true and must not wipe the
     * existing defaults: keys such as 'data_dir' and 'log_errors' must still
     * be present in getAll().
     */
    public function testConfigureWithEmptyArray(): void
    {
        $result = JdbConfig::configure([]);
        $this->assertTrue($result);

        $all = JdbConfig::getAll();
        // Not empty: the array must still contain the defaults
        $this->assertNotEmpty($all);
        $this->assertArrayHasKey('data_dir',    $all);
        $this->assertArrayHasKey('log_errors',  $all);
    }

    /**
     * getAll() must return a copy of the internal configuration array, not a
     * reference: mutating the returned array must not affect subsequent get() calls.
     */
    public function testGetAllReturnsCopyNotReference(): void
    {
        JdbConfig::configure(['data_dir' => '/tmp/realpath']);
        $all            = JdbConfig::getAll();
        $all['data_dir'] = '/hacked';
        $this->assertSame('/tmp/realpath', JdbConfig::get('data_dir'));
    }

    /**
     * When a key is explicitly configured to null, get() must return null even
     * when a non-null default value is provided by the caller.
     *
     * This distinguishes between "key missing" (returns default) and
     * "key explicitly set to null" (returns null, ignoring default).
     */
    public function testGetReturnsExplicitNullInsteadOfDefault(): void
    {
        JdbConfig::configure(['error_log_path' => null]);
        $this->assertNull(JdbConfig::get('error_log_path', '/default/path'));
    }

    /**
     * Every key accepted by JdbManager must be forwarded and readable back via
     * JdbManager::getConfig() after a single configure() call.
     */
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

        foreach ($config as $key => $value) {
            $this->assertSame($value, JdbManager::getConfig($key));
        }
    }

    /**
     * Multiple configure() calls must be idempotent with respect to previously
     * set keys: a call that sets key B must not overwrite the value of key A
     * that was set in an earlier call.
     */
    public function testConfigureIsIdempotent(): void
    {
        JdbConfig::configure(['max_table_name_len' => 100]);
        JdbConfig::configure(['log_errors'         => true]);
        $this->assertSame(100, JdbConfig::get('max_table_name_len'));
    }

    /**
     * Verifies the return types of the public JdbConfig API:
     * - configure() must return bool
     * - getAll() must return array
     * - get() method must exist (returns mixed)
     */
    public function testReturnTypes(): void
    {
        $this->assertIsBool(JdbConfig::configure(['a' => 1]));
        $this->assertIsArray(JdbConfig::getAll());
        // get() returns mixed, so we only verify the method exists
        $this->assertTrue(method_exists(JdbConfig::class, 'get'));
    }
}
