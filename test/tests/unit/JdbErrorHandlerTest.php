<?php
/**
 * JdbErrorHandlerTest – Full test suite for the JdbErrorHandler static class.
 *
 * Compatible with PHPUnit 9+ and Codeception Unit suite.
 *
 * Every test method is self-contained:
 *   - setUp() resets static state ($errors, $configs)
 *   - tearDown() ensures no static leakage and cleans up temporary log files
 *
 * Run with:
 *   vendor/bin/phpunit tests/unit/JdbErrorHandlerTest.php
 *   vendor/bin/codecept run unit JdbErrorHandlerTest
 */
class JdbErrorHandlerTest extends \PHPUnit\Framework\TestCase
{
    private $tempLogPath;

    // ── Lifecycle ───────────────────────────────────────────────────────────
    protected function setUp(): void
    {
        // 1. Reset JdbErrorHandler static state using Reflection
        $reflection = new \ReflectionClass(JdbErrorHandler::class);

        $errorsProp = $reflection->getProperty('errors');
        $errorsProp->setAccessible(true);
        $errorsProp->setValue(null, []);

        $configsProp = $reflection->getProperty('configs');
        $configsProp->setAccessible(true);
        $configsProp->setValue(null, []);

        // 3. Setup temporary log file path
        $this->tempLogPath = sys_get_temp_dir() . '/jdb_eh_test_' . uniqid('', true) . '.log';
    }

    protected function tearDown(): void
    {
        // Clean up static state again for absolute safety
        $reflection = new \ReflectionClass(JdbErrorHandler::class);
        $errorsProp = $reflection->getProperty('errors');
        $errorsProp->setAccessible(true);
        $errorsProp->setValue(null, []);

        $configsProp = $reflection->getProperty('configs');
        $configsProp->setAccessible(true);
        $configsProp->setValue(null, []);

        // Clean up temporary log file
        if (file_exists($this->tempLogPath)) {
            unlink($this->tempLogPath);
        }
    }

    // =========================================================================
    // 1. CONFIGURATION
    // =========================================================================
    public function testConfigureStoresConfigForComponent(): void
    {
        $config = ['log_errors' => true, 'error_log_path' => '/tmp/test.log'];
        JdbErrorHandler::configure('db_core', $config);
        
        // We verify it works indirectly by triggering a log, or we could use reflection.
        // Let's use reflection to assert the config was stored.
        $reflection = new \ReflectionClass(JdbErrorHandler::class);
        $configsProp = $reflection->getProperty('configs');
        $configsProp->setAccessible(true);
        $configs = $configsProp->getValue();
        
        $this->assertArrayHasKey('db_core', $configs);
        $this->assertSame($config, $configs['db_core']);
    }

    // =========================================================================
    // 2. ERROR RECORDING (set)
    // =========================================================================
    public function testSetStoresErrorWithDefaultMessageMasking(): void
    {
        // Default behavior (or detailed_errors => false) should mask the message
        JdbErrorHandler::configure('auth', ['detailed_errors' => false]);
        JdbErrorHandler::set('auth', 'login', 'Sensitive DB password failed');
        
        $error = JdbErrorHandler::getLast('auth');
        $this->assertNotNull($error);
        $this->assertSame('login', $error['method']);
        $this->assertSame('An internal error occurred', $error['message']);
        $this->assertIsInt($error['time']);
    }

    public function testSetStoresDetailedErrorWhenConfigured(): void
    {
        JdbErrorHandler::configure('auth', ['detailed_errors' => true]);
        JdbErrorHandler::set('auth', 'login', 'Sensitive DB password failed');
        
        $error = JdbErrorHandler::getLast('auth');
        $this->assertNotNull($error);
        $this->assertSame('Sensitive DB password failed', $error['message']);
    }

    public function testSetLogsErrorToFileWhenConfigured(): void
    {
        JdbErrorHandler::configure('cache', [
            'detailed_errors' => true,
            'log_errors'      => true,
            'error_log_path'  => $this->tempLogPath,
        ]);
        
        JdbErrorHandler::set('cache', 'fetch', 'Redis connection timeout');
        
        $this->assertFileExists($this->tempLogPath);
        $logContent = file_get_contents($this->tempLogPath);
        $this->assertStringContainsString('cache::fetch - Redis connection timeout', $logContent);
        $this->assertMatchesRegularExpression('/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\]/', $logContent);
    }

    public function testSetDoesNotLogToFileWhenLogErrorsIsFalse(): void
    {
        JdbErrorHandler::configure('cache', [
            'detailed_errors' => true,
            'log_errors'      => false,
            'error_log_path'  => $this->tempLogPath,
        ]);
        
        JdbErrorHandler::set('cache', 'fetch', 'Redis connection timeout');
        
        $this->assertFileDoesNotExist($this->tempLogPath);
    }

    // =========================================================================
    // 3. ERROR RETRIEVAL (getLast / hasComponentError)
    // =========================================================================
    public function testGetLastReturnsCorrectError(): void
    {
        JdbErrorHandler::set('worker', 'process', 'Job failed');
        $error = JdbErrorHandler::getLast('worker');
        
        $this->assertSame('process', $error['method']);
        $this->assertSame('An internal error occurred', $error['message']);
    }

    public function testGetLastReturnsNullForUnknownComponent(): void
    {
        $this->assertNull(JdbErrorHandler::getLast('nonexistent_component'));
    }

    public function testHasErrorReturnsTrueWhenErrorExists(): void
    {
        JdbErrorHandler::set('worker', 'process', 'Job failed');
        $this->assertTrue(JdbErrorHandler::hasComponentError('worker'));
    }

    public function testHasErrorReturnsFalseWhenNoError(): void
    {
        $this->assertFalse(JdbErrorHandler::hasComponentError('nonexistent_component'));
    }

    // =========================================================================
    // 4. ERROR CLEARING (clear)
    // =========================================================================
    public function testClearRemovesSpecificComponentError(): void
    {
        JdbErrorHandler::set('comp_a', 'method1', 'Error A');
        JdbErrorHandler::set('comp_b', 'method2', 'Error B');
        
        JdbErrorHandler::clear('comp_a');
        
        $this->assertFalse(JdbErrorHandler::hasComponentError('comp_a'));
        $this->assertTrue(JdbErrorHandler::hasComponentError('comp_b')); // comp_b should remain
    }

    public function testClearAllRemovesAllErrorsWhenComponentIsNull(): void
    {
        JdbErrorHandler::set('comp_a', 'method1', 'Error A');
        JdbErrorHandler::set('comp_b', 'method2', 'Error B');
        
        JdbErrorHandler::clear(); // No argument = clear all
        
        $this->assertFalse(JdbErrorHandler::hasComponentError('comp_a'));
        $this->assertFalse(JdbErrorHandler::hasComponentError('comp_b'));
    }

    // =========================================================================
    // 5. STATIC-STATE ISOLATION BETWEEN TESTS
    // =========================================================================
    public function testEachTestStartsWithCleanErrorState(): void
    {
        // If a previous test leaked an error, this would fail.
        $this->assertFalse(JdbErrorHandler::hasComponentError('leaked_component'));
        $this->assertNull(JdbErrorHandler::getLast('leaked_component'));
    }

    public function testEachTestStartsWithCleanConfigState(): void
    {
        $reflection = new \ReflectionClass(JdbErrorHandler::class);
        $configsProp = $reflection->getProperty('configs');
        $configsProp->setAccessible(true);
        $configs = $configsProp->getValue();
        
        $this->assertEmpty($configs);
    }

    public function testConfigureOverwritesExistingConfig(): void
    {
        $firstConfig = ['log_errors' => true, 'error_log_path' => '/tmp/first.log'];
        $secondConfig = ['detailed_errors' => true, 'log_errors' => false];

        JdbErrorHandler::configure('db_core', $firstConfig);
        JdbErrorHandler::configure('db_core', $secondConfig);

        $reflection = new \ReflectionClass(JdbErrorHandler::class);
        $configsProp = $reflection->getProperty('configs');
        $configsProp->setAccessible(true);
        $configs = $configsProp->getValue();

        $this->assertSame($secondConfig, $configs['db_core']);
    }

    public function testSetOverwritesPreviousErrorForSameComponent(): void
    {
        JdbErrorHandler::configure('comp', ['detailed_errors' => true]);
        JdbErrorHandler::set('comp', 'method1', 'First error');
        JdbErrorHandler::set('comp', 'method2', 'Second error');

        $error = JdbErrorHandler::getLast('comp');
        $this->assertSame('method2', $error['method']);
        $this->assertSame('Second error', $error['message']);
    }

    public function testGetLastReturnsArrayWithExpectedKeysAndTypes(): void
    {
        JdbErrorHandler::configure('comp', ['detailed_errors' => true]);
        $before = time();
        JdbErrorHandler::set('comp', 'testMethod', 'Test message');
        $after = time();

        $error = JdbErrorHandler::getLast('comp');
        $this->assertIsArray($error);
        $this->assertArrayHasKey('method', $error);
        $this->assertArrayHasKey('message', $error);
        $this->assertArrayHasKey('time', $error);
        $this->assertSame('testMethod', $error['method']);
        $this->assertSame('Test message', $error['message']);
        $this->assertIsInt($error['time']);
        $this->assertGreaterThanOrEqual($before, $error['time']);
        $this->assertLessThanOrEqual($after, $error['time']);
    }

    public function testGetLastReturnsNullAfterClear(): void
    {
        JdbErrorHandler::set('comp', 'method', 'Error');
        JdbErrorHandler::clear('comp');
        $this->assertNull(JdbErrorHandler::getLast('comp'));
    }

    public function testDifferentComponentsUseTheirOwnConfigs(): void
    {
        JdbErrorHandler::configure('compA', ['detailed_errors' => true]);
        JdbErrorHandler::configure('compB', ['detailed_errors' => false]);

        JdbErrorHandler::set('compA', 'method', 'Secret error A');
        JdbErrorHandler::set('compB', 'method', 'Secret error B');

        $errorA = JdbErrorHandler::getLast('compA');
        $errorB = JdbErrorHandler::getLast('compB');

        $this->assertSame('Secret error A', $errorA['message']);
        $this->assertSame('An internal error occurred', $errorB['message']);
    }

    public function testErrorLogAppendsMultipleEntries(): void
    {
        JdbErrorHandler::configure('logger', [
            'detailed_errors' => true,
            'log_errors'      => true,
            'error_log_path'  => $this->tempLogPath,
        ]);

        JdbErrorHandler::set('logger', 'first', 'First error');
        JdbErrorHandler::set('logger', 'second', 'Second error');

        $lines = file($this->tempLogPath, FILE_IGNORE_NEW_LINES);
        $this->assertCount(2, $lines);
        $this->assertStringContainsString('logger::first - First error', $lines[0]);
        $this->assertStringContainsString('logger::second - Second error', $lines[1]);
    }

    public function testSetDoesNotLogIfErrorLogPathIsMissingOrEmpty(): void
    {
        $tempPath = $this->tempLogPath;
        JdbErrorHandler::configure('comp', [
            'detailed_errors' => true,
            'log_errors'      => true,
            // error_log_path non impostato
        ]);
        JdbErrorHandler::set('comp', 'method', 'Message');
        $this->assertFileDoesNotExist($tempPath);

        JdbErrorHandler::configure('comp', [
            'detailed_errors' => true,
            'log_errors'      => true,
            'error_log_path'  => '', // vuoto
        ]);
        JdbErrorHandler::set('comp', 'method', 'Message');
        $this->assertFileDoesNotExist($tempPath);
    }

    public function testClearAllRemovesAllErrorsAndGetLastReturnsNull(): void
    {
        JdbErrorHandler::set('comp1', 'm1', 'e1');
        JdbErrorHandler::set('comp2', 'm2', 'e2');
        JdbErrorHandler::clear();

        $this->assertNull(JdbErrorHandler::getLast('comp1'));
        $this->assertNull(JdbErrorHandler::getLast('comp2'));
        $this->assertFalse(JdbErrorHandler::hasComponentError('comp1'));
        $this->assertFalse(JdbErrorHandler::hasComponentError('comp2'));
    }

    public function testSetAcceptsComponentWithSpecialCharacters(): void
    {
        $component = 'my/comp@name';
        JdbErrorHandler::configure($component, ['detailed_errors' => true]);
        JdbErrorHandler::set($component, 'method', 'Test message');

        $error = JdbErrorHandler::getLast($component);
        $this->assertSame('Test message', $error['message']);
    }

    public function testSetDoesNotThrowExceptionWhenLogPathIsNotWritable(): void
    {
        $notWritablePath = sys_get_temp_dir() . '/non_writable_' . uniqid();
        touch($notWritablePath);
        chmod($notWritablePath, 0444); // readonly

        JdbErrorHandler::configure('comp', [
            'detailed_errors' => true,
            'log_errors'      => true,
            'error_log_path'  => $notWritablePath,
        ]);

        // Non deve lanciare eccezioni
        JdbErrorHandler::set('comp', 'method', 'Message');
        $this->assertTrue(true); // se arriviamo qui, ok

        unlink($notWritablePath);
    }
}
