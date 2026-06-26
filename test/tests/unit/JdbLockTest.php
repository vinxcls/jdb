<?php

/**
 * Unit Test per la classe JdbLock
 * Framework: Codeception (Unit)
 * 
 * Per eseguire i test:
 *   codecept run unit JdbLockTest
 */
class JdbLockTest extends \Codeception\Test\Unit
{
    /** @var string */
    private $tempDir;

    /** @var string */
    private $tempFile;

    protected function setUp(): void
    {
        parent::setUp();
        // Pulisci lo stack di errore per evitare inquinamento tra i test
        JdbErrorHandler::clear();
        
        $this->tempDir = sys_get_temp_dir() . '/jdb_lock_test_' . bin2hex(random_bytes(4));
        if (!mkdir($this->tempDir, 0755, true) && !is_dir($this->tempDir)) {
            $this->fail('Impossibile creare la directory temporanea');
        }
        $this->tempFile = $this->tempDir . '/test_data.txt';
        touch($this->tempFile);
    }

    protected function tearDown(): void
    {
        // Rimuovi eventuali directory .lock rimaste
        $locks = glob($this->tempDir . '/*.lock');
        foreach ($locks as $lockDir) {
            if (is_dir($lockDir)) {
                $this->deleteDirRecursive($lockDir);
            }
        }
        $this->deleteDirRecursive($this->tempDir);
        parent::tearDown();
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (array_diff(scandir($dir), ['.', '..']) as $e) {
            $p = $dir . DIRECTORY_SEPARATOR . $e;
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    /**
     * Elimina ricorsivamente una directory
     */
    private function deleteDirRecursive(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = array_diff(scandir($dir), ['.', '..']);
        foreach ($items as $item) {
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->deleteDirRecursive($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    // =========================================================================
    // Configurazione & Costruttore
    // =========================================================================

    public function testConstructDefaultsToFlock()
    {
        $lock = new JdbLock();
        $this->assertSame('flock', $lock->getBackend());
        $this->assertSame(500, $lock->getTimeoutMs());
    }

    public function testConstructCustomBackendAndTimeout()
    {
        $lock = new JdbLock('mkdir', 1200);
        $this->assertSame('mkdir', $lock->getBackend());
        $this->assertSame(1200, $lock->getTimeoutMs());
    }

    public function testSetTimeoutMsUpdatesCorrectly()
    {
        $lock = new JdbLock();
        $lock->setTimeoutMs(999);
        $this->assertSame(999, $lock->getTimeoutMs());

        // Verifica il limite minimo (max(1, ...))
        $lock->setTimeoutMs(-5);
        $this->assertSame(1, $lock->getTimeoutMs());
    }

    // =========================================================================
    // Backend Flock
    // =========================================================================

    public function testFlockAcquireAndReleaseFile()
    {
        $lock = new JdbLock('flock', 500);
        $fp = $lock->acquireFile($this->tempFile, 'ab');
        
        $this->assertIsResource($fp);
        $stat = fstat($fp);
        $this->assertNotFalse($stat);
        
        $lock->releaseFile($fp);
        $this->assertTrue(true); // Nessun errore lanciato
    }

    public function testFlockAcquireSharedFile()
    {
        $lock = new JdbLock('flock', 500);
        $fp = $lock->acquireFileShared($this->tempFile);
        
        $this->assertIsResource($fp);
        $lock->releaseFile($fp);
    }

    public function testFlockMutexAcquireAndRelease()
    {
        $lock = new JdbLock('flock', 500);
        $mutexPath = $this->tempDir . '/mutex_test.lock';
        
        $token = $lock->acquireMutex($mutexPath);
        $this->assertIsArray($token);
        $this->assertArrayHasKey('type', $token);
        $this->assertSame('flock', $token['type']);
        $this->assertFileExists($mutexPath);
        
        $lock->releaseMutex($token);
    }

    // =========================================================================
    // Backend Mkdir
    // =========================================================================

    public function testMkdirAcquireAndReleaseFile()
    {
        $lock = new JdbLock('mkdir', 500);
        $fp = $lock->acquireFile($this->tempFile, 'ab');
        
        $this->assertIsResource($fp);
        
        // Verifica creazione directory e file owner
        $lockDir = $this->tempFile . '.lock';
        $this->assertDirectoryExists($lockDir);
        $this->assertFileExists($lockDir . '/owner');
        
        $lock->releaseFile($fp);
        
        // Verifica pulizia automatica
        $this->assertDirectoryDoesNotExist($lockDir);
    }

    public function testMkdirSharedLockUsesExclusive()
    {
        // Il backend mkdir non supporta lock condivisi, quindi usa mkdir esclusivo
        $lock = new JdbLock('mkdir', 500);
        $fp = $lock->acquireFileShared($this->tempFile);
        
        $this->assertIsResource($fp);
        $this->assertDirectoryExists($this->tempFile . '.lock');
        
        $lock->releaseFile($fp);
        $this->assertDirectoryDoesNotExist($this->tempFile . '.lock');
    }

    public function testMkdirMutexAcquireAndRelease()
    {
        $lock = new JdbLock('mkdir', 500);
        $mutexDir = $this->tempDir . '/mutex_test';
        
        $token = $lock->acquireMutex($mutexDir);
        $this->assertIsArray($token);
        $this->assertSame('mkdir', $token['type']);
        $this->assertDirectoryExists($mutexDir);
        $this->assertFileExists($mutexDir . '/owner');
        
        $lock->releaseMutex($token);
        $this->assertDirectoryDoesNotExist($mutexDir);
    }

    // =========================================================================
    // Gestione Errori & Casi Limite
    // =========================================================================

    public function testAcquireFileInvalidPathReturnsFalse()
    {
        $lock = new JdbLock('flock', 100);
        $fp = @$lock->acquireFile('/nonexistent/path_' . time() . '/file.txt', 'ab');
        
        $this->assertFalse($fp);
        
        // Verifica che l'errore sia stato tracciato
        $trace = JdbErrorHandler::formatStack();
        $this->assertStringContainsString('fopen', $trace);
    }

    public function testReleaseMutexInvalidTokenDoesNotThrow()
    {
        $lock = new JdbLock('flock', 500);
        
        // Non deve generare eccezioni o warning
        $lock->releaseMutex(false);
        $lock->releaseMutex(null);
        $lock->releaseMutex(['invalid' => 'data']);
        
        $this->assertTrue(true);
    }

    public function testMkdirStaleLockOwnerFileMissing()
    {
        $lock = new JdbLock('mkdir', 1000);
        $lockDir = $this->tempFile . '.lock';
        
        // Simula una lock directory orfana (crea dir ma manca owner)
        mkdir($lockDir, 0700);
        
        // L'acquisizione dovrebbe gestire la mancanza di owner o resettarla
        $fp = $lock->acquireFile($this->tempFile, 'ab');
        $this->assertIsResource($fp);
        $lock->releaseFile($fp);
    }

    // =========================================================================
    // A. Costruttore & configurazione
    // =========================================================================

    /**
     * Un backend sconosciuto deve essere normalizzato a 'flock'.
     */
    public function testConstructorWithInvalidBackendDefaultsToFlock(): void
    {
        $lock = new JdbLock('invalid_backend', 500);
        $this->assertSame(JdbLock::BACKEND_FLOCK, $lock->getBackend());
    }

    /**
     * setTimeoutMs(0) deve essere clampato a 1 (max(1, ...)).
     */
    public function testSetTimeoutMsZeroIsClamped(): void
    {
        $lock = new JdbLock();
        $lock->setTimeoutMs(0);
        $this->assertSame(1, $lock->getTimeoutMs());
    }

    /**
     * setTimeoutMs con float deve essere castato a int prima del clamp.
     */
    public function testSetTimeoutMsFloatIsCastToInt(): void
    {
        $lock = new JdbLock();
        $lock->setTimeoutMs(750.9);
        $this->assertSame(750, $lock->getTimeoutMs());
    }

    /**
     * Le costanti di classe devono avere i valori attesi.
     */
    public function testBackendConstants(): void
    {
        $this->assertSame('flock', JdbLock::BACKEND_FLOCK);
        $this->assertSame('mkdir', JdbLock::BACKEND_MKDIR);
    }

    // =========================================================================
    // B. flock – struttura completa del token di acquireMutex
    // =========================================================================

    /**
     * acquireMutex (flock) deve restituire un token con le chiavi
     * 'type', 'fp' e 'file'.
     */
    public function testFlockMutexTokenHasAllRequiredKeys(): void
    {
        $lock      = new JdbLock('flock', 500);
        $mutexPath = $this->tempDir . '/mutex_struct.lock';

        $token = $lock->acquireMutex($mutexPath);
        $this->assertIsArray($token);
        $this->assertArrayHasKey('type', $token);
        $this->assertArrayHasKey('fp',   $token);
        $this->assertArrayHasKey('file', $token);
        $this->assertSame('flock', $token['type']);
        $this->assertIsResource($token['fp']);
        $this->assertSame($mutexPath, $token['file']);

        $lock->releaseMutex($token);
    }

    /**
     * Il file di lock del mutex (flock) deve esistere su disco durante
     * il periodo in cui il mutex è acquisito.
     */
    public function testFlockMutexLockFileExistsDuringLock(): void
    {
        $lock      = new JdbLock('flock', 500);
        $mutexPath = $this->tempDir . '/mutex_exists.lock';

        $token = $lock->acquireMutex($mutexPath);
        $this->assertFileExists($mutexPath,
            'Il file di lock del mutex deve esistere mentre è acquisito');

        $lock->releaseMutex($token);
    }

    // =========================================================================
    // C. flock – acquireFileShared su file inesistente
    // =========================================================================

    /**
     * acquireFileShared con backend flock su un file che non esiste deve
     * restituire false e registrare l'errore in JdbErrorHandler.
     */
    public function testFlockAcquireFileSharedOnMissingFileReturnsFalse(): void
    {
        $lock   = new JdbLock('flock', 100); // timeout breve
        $result = @$lock->acquireFileShared($this->tempDir . '/nonexistent.txt');

        $this->assertFalse($result);
        // _acquireFileFlockShared fa usleep e poi JdbErrorHandler::push dopo il timeout
        $this->assertTrue(JdbErrorHandler::hasStackError(),
            'JdbErrorHandler deve contenere un errore dopo il fallimento di acquireFileShared');
    }

    // =========================================================================
    // D. flock – acquireFile con openMode diverso da 'ab'
    // =========================================================================

    /**
     * acquireFile con modalità 'rb' (lettura) su un file esistente
     * deve restituire una resource valida.
     */
    public function testFlockAcquireFileReadMode(): void
    {
        $lock = new JdbLock('flock', 500);
        $fp   = $lock->acquireFile($this->tempFile, 'rb');

        $this->assertIsResource($fp,
            'acquireFile con "rb" su file esistente deve restituire una resource');

        $lock->releaseFile($fp);
    }

    /**
     * acquireFile con modalità 'r+b' (lettura/scrittura) su un file esistente
     * deve restituire una resource valida.
     */
    public function testFlockAcquireFileReadWriteMode(): void
    {
        $lock = new JdbLock('flock', 500);
        $fp   = $lock->acquireFile($this->tempFile, 'r+b');

        $this->assertIsResource($fp);
        $lock->releaseFile($fp);
    }

    // =========================================================================
    // E. flock – releaseMutex NON elimina il file di lock
    // =========================================================================

    /**
     * Con il backend flock, releaseMutex rilascia il lock ma NON cancella
     * il file dal filesystem (comportamento standard di flock).
     */
    public function testFlockReleaseMutexDoesNotDeleteLockFile(): void
    {
        $lock      = new JdbLock('flock', 500);
        $mutexPath = $this->tempDir . '/persistent.lock';

        $token = $lock->acquireMutex($mutexPath);
        $lock->releaseMutex($token);

        $this->assertFileExists($mutexPath,
            'Il backend flock non deve cancellare il file di lock dopo releaseMutex');
    }

    // =========================================================================
    // F. flock – ciclo doppio acquire → release
    // =========================================================================

    /**
     * La stessa istanza deve poter eseguire due cicli completi
     * acquireFile → releaseFile consecutivi.
     */
    public function testFlockDoubleAcquireReleaseCycle(): void
    {
        $lock = new JdbLock('flock', 500);

        // Primo ciclo
        $fp1 = $lock->acquireFile($this->tempFile, 'ab');
        $this->assertIsResource($fp1);
        $lock->releaseFile($fp1);

        // Secondo ciclo immediato
        $fp2 = $lock->acquireFile($this->tempFile, 'ab');
        $this->assertIsResource($fp2,
            'Deve essere possibile riacquisire il file subito dopo il rilascio');
        $lock->releaseFile($fp2);
    }

    /**
     * La stessa istanza deve poter eseguire due cicli completi
     * acquireMutex → releaseMutex consecutivi.
     */
    public function testFlockDoubleMutexCycle(): void
    {
        $lock      = new JdbLock('flock', 500);
        $mutexPath = $this->tempDir . '/double_mutex.lock';

        $token1 = $lock->acquireMutex($mutexPath);
        $this->assertIsArray($token1);
        $lock->releaseMutex($token1);

        $token2 = $lock->acquireMutex($mutexPath);
        $this->assertIsArray($token2,
            'Deve essere possibile riacquisire il mutex subito dopo il rilascio');
        $lock->releaseMutex($token2);
    }

    // =========================================================================
    // G. mkdir – contenuto del file owner
    // =========================================================================

    /**
     * Il file owner deve contenere "PID:timestamp" dove PID è un intero
     * positivo e timestamp è un float (microtime).
     */
    public function testMkdirOwnerFileContainsPidAndTimestamp(): void
    {
        $lock = new JdbLock('mkdir', 500);
        $fp   = $lock->acquireFile($this->tempFile, 'ab');
        $this->assertIsResource($fp);

        $lockDir   = $this->tempFile . '.lock';
        $ownerFile = $lockDir . '/owner';
        $content   = file_get_contents($ownerFile);

        $this->assertNotFalse($content);
        $parts = explode(':', $content, 2);
        $this->assertCount(2, $parts, 'Il contenuto deve avere la forma "PID:timestamp"');

        $pid       = (int)$parts[0];
        $timestamp = (float)$parts[1];

        $this->assertGreaterThan(0, $pid,
            'Il PID nel file owner deve essere positivo');
        $this->assertSame(getmypid(), $pid,
            'Il PID nel file owner deve corrispondere al processo corrente');
        $this->assertGreaterThan(0.0, $timestamp,
            'Il timestamp nel file owner deve essere un valore microtime positivo');

        $lock->releaseFile($fp);
    }

    /**
     * Il file owner del mutex mkdir deve anch'esso contenere PID:timestamp.
     */
    public function testMkdirMutexOwnerFileContainsPidAndTimestamp(): void
    {
        $lock      = new JdbLock('mkdir', 500);
        $mutexPath = $this->tempDir . '/owner_check';

        $token   = $lock->acquireMutex($mutexPath);
        $content = file_get_contents($mutexPath . '/owner');

        $parts = explode(':', $content, 2);
        $this->assertSame(getmypid(), (int)$parts[0]);
        $this->assertGreaterThan(0.0, (float)$parts[1]);

        $lock->releaseMutex($token);
    }

    // =========================================================================
    // H. mkdir – stale lock con PID morto
    // =========================================================================

    /**
     * Se la lock directory esiste con il file owner di un PID certamente
     * morto, _breakStaleMkdirLock deve rimuoverla e la successiva
     * acquireFile deve avere successo.
     */
    public function testMkdirBreaksStaleLockWithDeadPid(): void
    {
        if (!function_exists('posix_kill')) {
            $this->markTestSkipped('posix_kill non disponibile su questa piattaforma');
        }

        $lockDir   = $this->tempFile . '.lock';
        $ownerFile = $lockDir . '/owner';

        // Crea una lock directory orfana con un PID sicuramente non esistente
        mkdir($lockDir, 0700);
        file_put_contents($ownerFile, '9999999:' . microtime(true));

        $lock = new JdbLock('mkdir', 1000);
        $fp   = $lock->acquireFile($this->tempFile, 'ab');

        $this->assertIsResource($fp,
            'acquireFile deve riuscire dopo aver rotto il lock orfano con PID morto');
        $lock->releaseFile($fp);
    }

    // =========================================================================
    // I. mkdir – stale lock con timestamp troppo vecchio
    // =========================================================================

    /**
     * Se la lock directory esiste ma il timestamp del file owner è molto
     * precedente (superiore a 3× timeout), deve essere considerata stale
     * e rimossa, permettendo l'acquisizione.
     */
    public function testMkdirBreaksStaleLockWithOldTimestamp(): void
    {
        $lockDir   = $this->tempFile . '.lock';
        $ownerFile = $lockDir . '/owner';

        // Crea lock con timestamp vecchissimo (1000 secondi fa) e PID corrente
        // (per escludere il ramo "processo morto" e testare solo il ramo età)
        mkdir($lockDir, 0700);
        file_put_contents($ownerFile, getmypid() . ':' . (microtime(true) - 1000));

        $lock = new JdbLock('mkdir', 500); // staleAfterSec = max(2, 0.5 * 3) = 2s
        $fp   = $lock->acquireFile($this->tempFile, 'ab');

        $this->assertIsResource($fp,
            'acquireFile deve riuscire dopo aver rotto il lock con timestamp troppo vecchio');
        $lock->releaseFile($fp);
    }

    // =========================================================================
    // J. mkdir – acquireFile con path non valido
    // =========================================================================

    /**
     * acquireFile (mkdir backend) con un percorso in una directory che non
     * esiste deve restituire false e registrare l'errore in JdbErrorHandler.
     * Nota: la mkdir della lock directory riesce se la parent esiste, ma
     * fopen del file dati fallisce se il file non è raggiungibile.
     */
    public function testMkdirAcquireFileInvalidPathReturnsFalse(): void
    {
        $nonExistent = $this->tempDir . '/ghost/data.txt';
        $lock        = new JdbLock('mkdir', 200);

        // La mkdir della lockDir potrebbe riuscire (crea ghost/data.txt.lock),
        // ma fopen di ghost/data.txt fallisce → false + JdbErrorHandler
        $fp = @$lock->acquireFile($nonExistent, 'ab');

        $this->assertFalse($fp);
        $this->assertTrue(JdbErrorHandler::hasStackError(),
            'JdbErrorHandler deve contenere un errore dopo il fallimento di acquireFile (mkdir)');

        // Pulizia eventuale della lockDir creata
        $this->rrmdir($nonExistent . '.lock');
    }

    // =========================================================================
    // K. mkdir – riacquisizione immediata dopo releaseFile
    // =========================================================================

    /**
     * Dopo releaseFile(), activeMkdirLockDir deve essere null: una seconda
     * acquisizione immediata deve riuscire senza conflitti.
     */
    public function testMkdirReacquireImmediatelyAfterRelease(): void
    {
        $lock = new JdbLock('mkdir', 500);

        $fp1 = $lock->acquireFile($this->tempFile, 'ab');
        $this->assertIsResource($fp1);
        $lock->releaseFile($fp1);

        // Lock dir deve essere sparita
        $this->assertDirectoryDoesNotExist($this->tempFile . '.lock',
            'La lock directory deve essere rimossa dopo releaseFile()');

        // Riacquisizione immediata
        $fp2 = $lock->acquireFile($this->tempFile, 'ab');
        $this->assertIsResource($fp2,
            'La riacquisizione immediata deve avere successo');
        $lock->releaseFile($fp2);
    }

    // =========================================================================
    // L. mkdir – releaseMutex rimuove dir e owner
    // =========================================================================

    /**
     * Dopo releaseMutex (mkdir), la directory di lock e il file owner
     * devono essere entrambi rimossi dal filesystem.
     */
    public function testMkdirReleaseMutexCleansUpDirAndOwner(): void
    {
        $lock      = new JdbLock('mkdir', 500);
        $mutexPath = $this->tempDir . '/cleanup_mutex';

        $token     = $lock->acquireMutex($mutexPath);
        $ownerFile = $mutexPath . '/owner';

        $this->assertDirectoryExists($mutexPath);
        $this->assertFileExists($ownerFile);

        $lock->releaseMutex($token);

        $this->assertDirectoryDoesNotExist($mutexPath,
            'La directory del mutex deve essere rimossa dopo releaseMutex');
        $this->assertFileDoesNotExist($ownerFile,
            'Il file owner deve essere rimosso dopo releaseMutex');
    }

    // =========================================================================
    // M. mkdir – doppio releaseFile non genera errori
    // =========================================================================

    /**
     * Chiamare releaseFile() due volte sulla stessa resource (prima volta
     * reale, seconda volta su handle chiuso) non deve generare eccezioni.
     * _releaseMkdirLockDir() è no-op se activeMkdirLockDir è già null.
     */
    public function testMkdirReleaseMkdirLockDirIsNoopWhenAlreadyNull(): void
    {
        $lock = new JdbLock('mkdir', 500);

        // Ciclo 1: acquire → release (activeMkdirLockDir torna a null)
        $fp1 = $lock->acquireFile($this->tempFile, 'ab');
        $this->assertIsResource($fp1);
        $lock->releaseFile($fp1);

        $this->assertDirectoryDoesNotExist($this->tempFile . '.lock',
            'La lock directory deve essere rimossa dopo il primo releaseFile');

        // Ciclo 2: dimostra che _releaseMkdirLockDir è no-op quando null:
        // se activeMkdirLockDir non fosse stato azzerato, il secondo acquireFile
        // fallirebbe trovando la lock directory ancora "occupata".
        $fp2 = $lock->acquireFile($this->tempFile, 'ab');
        $this->assertIsResource($fp2,
            'Il secondo ciclo deve riuscire: _releaseMkdirLockDir è no-op quando null');
        $lock->releaseFile($fp2);

        $this->assertDirectoryDoesNotExist($this->tempFile . '.lock',
            'La lock directory deve essere rimossa anche dopo il secondo releaseFile');
    }

    // =========================================================================
    // N. Struttura token mkdir – acquireMutex
    // =========================================================================

    /**
     * Il token di acquireMutex (mkdir) deve avere le chiavi 'type', 'dir'
     * e 'owner', con i valori corretti.
     */
    public function testMkdirMutexTokenStructure(): void
    {
        $lock      = new JdbLock('mkdir', 500);
        $mutexPath = $this->tempDir . '/token_struct';

        $token = $lock->acquireMutex($mutexPath);

        $this->assertIsArray($token);
        $this->assertArrayHasKey('type',  $token);
        $this->assertArrayHasKey('dir',   $token);
        $this->assertArrayHasKey('owner', $token);
        $this->assertSame('mkdir',               $token['type']);
        $this->assertSame($mutexPath,            $token['dir']);
        $this->assertSame($mutexPath . '/owner', $token['owner']);

        $lock->releaseMutex($token);
    }
}
