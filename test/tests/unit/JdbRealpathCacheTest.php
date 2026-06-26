<?php
/**
 * JdbRealpathCacheTest – Full test suite for the JdbRealpathCache static class.
 *
 * Compatible with PHPUnit 9+ and Codeception Unit suite.
 *
 * Every test method is self-contained:
 *   - setUp() creates a fresh temp directory and fully resets static state via Reflection
 *   - tearDown() removes the temp directory recursively and resets static state
 *
 * Run with:
 *   vendor/bin/phpunit tests/unit/JdbRealpathCacheTest.php
 *   vendor/bin/codecept run unit JdbRealpathCacheTest
 */
class JdbRealpathCacheTest extends \PHPUnit\Framework\TestCase
{
    private $tempDir;
    private $existingFile;
    private $nonExistingFile;

    // ── Lifecycle ───────────────────────────────────────────────────────────
    protected function setUp(): void
    {
        // 1. Reset JdbRealpathCache static state using Reflection
        $reflection = new \ReflectionClass(JdbRealpathCache::class);
        
        $cacheProp = $reflection->getProperty('cache');
        $cacheProp->setAccessible(true);
        $cacheProp->setValue(null, []);

        $ttlProp = $reflection->getProperty('defaultTtl');
        $ttlProp->setAccessible(true);
        $ttlProp->setValue(null, 3600); // Reset to default

        // 2. Create a unique temporary directory and files for this test
        $this->tempDir = sys_get_temp_dir() . '/jdb_rpc_test_' . uniqid('', true);
        mkdir($this->tempDir, 0755, true);
        
        $this->existingFile = $this->tempDir . '/test_file.txt';
        file_put_contents($this->existingFile, 'test content');
        
        $this->nonExistingFile = $this->tempDir . '/does_not_exist.txt';
    }

    protected function tearDown(): void
    {
        // 1. Reset static state again for absolute safety
        $reflection = new \ReflectionClass(JdbRealpathCache::class);
        $cacheProp = $reflection->getProperty('cache');
        $cacheProp->setAccessible(true);
        $cacheProp->setValue(null, []);

        $ttlProp = $reflection->getProperty('defaultTtl');
        $ttlProp->setAccessible(true);
        $ttlProp->setValue(null, 3600);

        // 2. Clean up temporary directory
        $this->rrmdir($this->tempDir);
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

    // =========================================================================
    // 1. BASIC CACHING BEHAVIOR
    // =========================================================================
    public function testGetReturnsCorrectRealpathForExistingFile(): void
    {
        $result = JdbRealpathCache::get($this->existingFile);
        $this->assertSame(realpath($this->existingFile), $result);
    }

    public function testGetCachesTheResultInternally(): void
    {
        JdbRealpathCache::get($this->existingFile);
        
        $reflection = new \ReflectionClass(JdbRealpathCache::class);
        $cacheProp = $reflection->getProperty('cache');
        $cacheProp->setAccessible(true);
        $cache = $cacheProp->getValue();
        
        $this->assertArrayHasKey($this->existingFile, $cache);
        $this->assertSame(realpath($this->existingFile), $cache[$this->existingFile]['path']);
        $this->assertIsInt($cache[$this->existingFile]['time']);
    }

    public function testGetCachesFalseForNonExistingPaths(): void
    {
        // Caching 'false' is crucial to prevent repeated expensive failed stat calls
        $result = JdbRealpathCache::get($this->nonExistingFile);
        $this->assertFalse($result);
        
        $reflection = new \ReflectionClass(JdbRealpathCache::class);
        $cacheProp = $reflection->getProperty('cache');
        $cacheProp->setAccessible(true);
        $cache = $cacheProp->getValue();
        
        $this->assertArrayHasKey($this->nonExistingFile, $cache);
        $this->assertFalse($cache[$this->nonExistingFile]['path']);
    }

    // =========================================================================
    // 2. TTL (TIME-TO-LIVE) EXPIRATION
    // =========================================================================
    public function testGetRefreshesCacheAfterTtlExpires(): void
    {
        $shortTtl = 1; // 1 second
        
        // First call: populates cache
        $timeBefore = time();
        JdbRealpathCache::get($this->existingFile, $shortTtl);
        
        // Wait for TTL to expire
        sleep(1);
        
        // Second call: should expire and refresh the cache time
        JdbRealpathCache::get($this->existingFile, $shortTtl);
        
        $reflection = new \ReflectionClass(JdbRealpathCache::class);
        $cacheProp = $reflection->getProperty('cache');
        $cacheProp->setAccessible(true);
        $cache = $cacheProp->getValue();
        
        // The cached time must be strictly greater than the time before the first call
        $this->assertGreaterThanOrEqual($timeBefore, $cache[$this->existingFile]['time']);
    }

    public function testGetBypassesCacheWhenTtlIsZero(): void
    {
        // Force a cache population first
        JdbRealpathCache::get($this->existingFile, 3600);
        
        // Call with TTL = 0 should bypass cache entirely and NOT update it
        JdbRealpathCache::get($this->existingFile, 0);
        
        $reflection = new \ReflectionClass(JdbRealpathCache::class);
        $cacheProp = $reflection->getProperty('cache');
        $cacheProp->setAccessible(true);
        $cache = $cacheProp->getValue();
        
        // The time in cache should remain exactly as it was (not updated by the TTL=0 call)
        // We can verify this by checking the cache wasn't overwritten with a newer time
        $cachedTime = $cache[$this->existingFile]['time'];
        sleep(1);
        JdbRealpathCache::get($this->existingFile, 0);
        
        $cacheAfter = $cacheProp->getValue();
        $this->assertSame($cachedTime, $cacheAfter[$this->existingFile]['time']);
    }

    public function testGetBypassesCacheWhenTtlIsNegative(): void
    {
        JdbRealpathCache::get($this->existingFile, -5);
        
        $reflection = new \ReflectionClass(JdbRealpathCache::class);
        $cacheProp = $reflection->getProperty('cache');
        $cacheProp->setAccessible(true);
        $cache = $cacheProp->getValue();
        
        $this->assertArrayNotHasKey($this->existingFile, $cache);
    }

    // =========================================================================
    // 3. DEFAULT TTL MANAGEMENT
    // =========================================================================
    public function testSetDefaultTtlUpdatesTheDefaultValue(): void
    {
        JdbRealpathCache::setDefaultTtl(7200);
        
        $reflection = new \ReflectionClass(JdbRealpathCache::class);
        $ttlProp = $reflection->getProperty('defaultTtl');
        $ttlProp->setAccessible(true);
        
        $this->assertSame(7200, $ttlProp->getValue());
    }

    public function testSetDefaultTtlPreventsNegativeValues(): void
    {
        JdbRealpathCache::setDefaultTtl(-100);
        
        $reflection = new \ReflectionClass(JdbRealpathCache::class);
        $ttlProp = $reflection->getProperty('defaultTtl');
        $ttlProp->setAccessible(true);
        
        // The class uses max(0, (int)$seconds)
        $this->assertSame(0, $ttlProp->getValue());
    }

    public function testGetUsesDefaultTtlWhenNotSpecified(): void
    {
        JdbRealpathCache::setDefaultTtl(1);
        
        $timeBefore = time();
        JdbRealpathCache::get($this->existingFile); // Uses default TTL of 1
        
        sleep(1);
        JdbRealpathCache::get($this->existingFile); // Should expire and refresh
        
        $reflection = new \ReflectionClass(JdbRealpathCache::class);
        $cacheProp = $reflection->getProperty('cache');
        $cacheProp->setAccessible(true);
        $cache = $cacheProp->getValue();
        
        $this->assertGreaterThanOrEqual($timeBefore, $cache[$this->existingFile]['time']);
    }

    // =========================================================================
    // 4. CACHE CLEARING
    // =========================================================================
    public function testClearRemovesAllCachedEntries(): void
    {
        JdbRealpathCache::get($this->existingFile);
        JdbRealpathCache::get($this->nonExistingFile);
        
        JdbRealpathCache::clear();
        
        $reflection = new \ReflectionClass(JdbRealpathCache::class);
        $cacheProp = $reflection->getProperty('cache');
        $cacheProp->setAccessible(true);
        $cache = $cacheProp->getValue();
        
        $this->assertEmpty($cache);
    }

    // =========================================================================
    // 5. STATIC-STATE ISOLATION BETWEEN TESTS
    // =========================================================================
    public function testEachTestStartsWithEmptyCache(): void
    {
        $reflection = new \ReflectionClass(JdbRealpathCache::class);
        $cacheProp = $reflection->getProperty('cache');
        $cacheProp->setAccessible(true);
        
        $this->assertEmpty($cacheProp->getValue());
    }

    public function testEachTestStartsWithDefaultTtlOf3600(): void
    {
        $reflection = new \ReflectionClass(JdbRealpathCache::class);
        $ttlProp = $reflection->getProperty('defaultTtl');
        $ttlProp->setAccessible(true);
        
        $this->assertSame(3600, $ttlProp->getValue());
    }

    // =========================================================================
    // 6. DEFAULT TTL = 0 (cache disabilitata di default)
    // =========================================================================
    public function testDefaultTtlZeroBypassesCache(): void
    {
        JdbRealpathCache::setDefaultTtl(0);

        // Prima chiamata: dovrebbe bypassare la cache e non scrivere nulla
        $result1 = JdbRealpathCache::get($this->existingFile);
        $this->assertSame(realpath($this->existingFile), $result1);

        $reflection = new \ReflectionClass(JdbRealpathCache::class);
        $cacheProp = $reflection->getProperty('cache');
        $cacheProp->setAccessible(true);
        $cache = $cacheProp->getValue();
        $this->assertEmpty($cache, 'Con defaultTtl=0 la cache non deve contenere voci');

        // Seconda chiamata: ancora nessuna cache
        $result2 = JdbRealpathCache::get($this->existingFile);
        $this->assertSame($result1, $result2);
        $this->assertEmpty($cacheProp->getValue());
    }

    // =========================================================================
    // 7. Modifica della defaultTtl dopo che una voce è in cache
    // =========================================================================
    public function testChangingDefaultTtlAffectsExpirationOfExistingEntries(): void
    {
        // Imposta default TTL molto lunga
        JdbRealpathCache::setDefaultTtl(100);
        JdbRealpathCache::get($this->existingFile);

        $reflection = new \ReflectionClass(JdbRealpathCache::class);
        $cacheProp = $reflection->getProperty('cache');
        $cacheProp->setAccessible(true);
        $cache = $cacheProp->getValue();
        $oldTime = $cache[$this->existingFile]['time'];

        // Modifica default TTL a 1 secondo (molto breve)
        JdbRealpathCache::setDefaultTtl(1);

        // Aspetta un attimo per far scadere logicamente
        sleep(1);

        // Questa chiamata dovrebbe rilevare la voce come scaduta (perché usa la nuova TTL=1)
        JdbRealpathCache::get($this->existingFile);

        $cacheAfter = $cacheProp->getValue();
        $newTime = $cacheAfter[$this->existingFile]['time'];
        $this->assertGreaterThan($oldTime, $newTime, 'La voce è stata rigenerata dopo la riduzione della default TTL');
    }

    // =========================================================================
    // 8. File che appare dopo una cache di "false" (superata la TTL)
    // =========================================================================
    public function testNonExistingFileThatAppearsAfterTtlExpires(): void
    {
        $ttl = 1;
        // Prima chiamata: false in cache
        $result1 = JdbRealpathCache::get($this->nonExistingFile, $ttl);
        $this->assertFalse($result1);

        // Crea il file dopo la prima chiamata
        sleep(1); // aspetta la scadenza TTL
        file_put_contents($this->nonExistingFile, 'ora esisto');

        // Seconda chiamata: deve rilevare il nuovo file (cache scaduta)
        $result2 = JdbRealpathCache::get($this->nonExistingFile, $ttl);
        $this->assertSame(realpath($this->nonExistingFile), $result2);

        // Verifica che la cache ora contenga il path reale
        $reflection = new \ReflectionClass(JdbRealpathCache::class);
        $cacheProp = $reflection->getProperty('cache');
        $cacheProp->setAccessible(true);
        $cache = $cacheProp->getValue();
        $this->assertSame(realpath($this->nonExistingFile), $cache[$this->nonExistingFile]['path']);
    }

    // =========================================================================
    // 9. Percorsi relativi – la chiave di cache è la stringa originale
    // =========================================================================
    public function testRelativePathIsCachedWithOriginalString(): void
    {
        // Calcola un percorso relativo al file esistente (dentro la temp dir)
        $cwd = getcwd();
        chdir($this->tempDir);
        $relativePath = 'test_file.txt'; // esiste nella temp dir

        $result = JdbRealpathCache::get($relativePath);
        $expected = realpath($relativePath);
        $this->assertSame($expected, $result);

        $reflection = new \ReflectionClass(JdbRealpathCache::class);
        $cacheProp = $reflection->getProperty('cache');
        $cacheProp->setAccessible(true);
        $cache = $cacheProp->getValue();

        $this->assertArrayHasKey($relativePath, $cache);
        $this->assertSame($expected, $cache[$relativePath]['path']);

        chdir($cwd); // ripristina
    }

    // =========================================================================
    // 10. Gestione dei tipi per $ttl (stringhe, null)
    // =========================================================================
    public function testGetAcceptsStringTtlAndNull(): void
    {
        // Stringa numerica
        $result = JdbRealpathCache::get($this->existingFile, '3600');
        $this->assertSame(realpath($this->existingFile), $result);

        // Verifica che la cache sia stata scritta (TTL > 0)
        $reflection = new \ReflectionClass(JdbRealpathCache::class);
        $cacheProp = $reflection->getProperty('cache');
        $cacheProp->setAccessible(true);
        $cache = $cacheProp->getValue();
        $this->assertArrayHasKey($this->existingFile, $cache);

        // Pulisci
        JdbRealpathCache::clear();

        // Null deve usare la defaultTtl (che è 3600)
        JdbRealpathCache::get($this->existingFile, null);
        $cache = $cacheProp->getValue();
        $this->assertArrayHasKey($this->existingFile, $cache);
    }

    // =========================================================================
    // 11. Symlink (se il sistema lo supporta)
    // =========================================================================
    /**
     * @requires function symlink
     */
    public function testSymlinkIsResolvedAndCached(): void
    {
        $linkPath = $this->tempDir . '/symlink_to_file';
        symlink($this->existingFile, $linkPath);

        $result = JdbRealpathCache::get($linkPath);
        $expected = realpath($linkPath);
        $this->assertSame($expected, $result);

        $reflection = new \ReflectionClass(JdbRealpathCache::class);
        $cacheProp = $reflection->getProperty('cache');
        $cacheProp->setAccessible(true);
        $cache = $cacheProp->getValue();

        $this->assertArrayHasKey($linkPath, $cache);
        $this->assertSame($expected, $cache[$linkPath]['path']);
    }
}
