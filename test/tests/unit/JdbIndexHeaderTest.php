<?php
/**
 * Unit tests for JdbIndexHeader
 * 
 * Posiziona questo file in: tests/unit/JdbIndexHeaderTest.php
 * Assicurati che JdbIndexHeader sia autoloadato o incluso nel bootstrap di Codeception.
 * 
 * @coversDefaultClass \JdbIndexHeader
 */
class JdbIndexHeaderTest extends \Codeception\Test\Unit
{
    private string $tempDir;
    private string $testFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/jdb_header_test_' . uniqid();
        if (!mkdir($this->tempDir, 0755, true) && !is_dir($this->tempDir)) {
            $this->fail('Failed to create temporary directory');
        }
        $this->testFile = $this->tempDir . '/test.index.php';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->testFile)) {
            @unlink($this->testFile);
        }
        if (is_dir($this->tempDir)) {
            @rmdir($this->tempDir);
        }
        parent::tearDown();
    }

    // =========================================================================
    // Write & Read Roundtrip
    // =========================================================================
    /**
     * @covers ::write
     * @covers ::read
     */
    public function testWriteAndReadRoundtrip(): void
    {
        $fp = fopen($this->testFile, 'w+b');
        $this->assertIsResource($fp);

        $slots   = 1024;
        $count   = 512;
        $nextId  = 999;
        $dirty   = 0;

        $this->assertTrue(JdbIndexHeader::write($fp, $slots, $count, $nextId, $dirty));

        fseek($fp, 0);
        $header = JdbIndexHeader::read($fp);

        $this->assertNotNull($header);
        $this->assertEquals('JDB1', $header['magic']);
        $this->assertEquals(1, $header['version']);
        $this->assertEquals($slots, (int)$header['slots']);
        $this->assertEquals($count, (int)$header['count']);
        $this->assertEquals($nextId, (int)$header['next_id']);
        $this->assertEquals($dirty, (int)$header['dirty']);

        fclose($fp);
    }

    /**
     * @covers ::write
     * @covers ::read
     */
    public function testWriteAndReadDirtyFlag(): void
    {
        $fp = fopen($this->testFile, 'w+b');
        $this->assertTrue(JdbIndexHeader::write($fp, 0, 0, 0, 1));
        fseek($fp, 0);
        $header = JdbIndexHeader::read($fp);
        $this->assertEquals(1, (int)$header['dirty']);
        fclose($fp);
    }

    // =========================================================================
    // Value Clamping & Validation
    // =========================================================================
    /**
     * @covers ::write
     * @covers ::read
     */
    public function testWriteClampsValuesToValidRanges(): void
    {
        $fp = fopen($this->testFile, 'w+b');
        // Pass out-of-range values: slots < 0, count > max, dirty > 1
        JdbIndexHeader::write($fp, -100, 0xFFFFFFFF + 1, 500, 2);
        fseek($fp, 0);
        $header = JdbIndexHeader::read($fp);

        $this->assertEquals(0, (int)$header['slots']);
        $this->assertEquals(0xFFFFFFFF, (int)$header['count']);
        $this->assertEquals(500, (int)$header['next_id']);
        $this->assertEquals(1, (int)$header['dirty']); // 2 clamped to 1
        fclose($fp);
    }

    // =========================================================================
    // Read Failure Scenarios
    // =========================================================================
    /**
     * @covers ::read
     */
    public function testReadFailsOnInvalidResource(): void
    {
        $this->assertNull(JdbIndexHeader::read('not_a_resource'));
        $this->assertNull(JdbIndexHeader::read(null));
        $this->assertNull(JdbIndexHeader::read(123));
    }

    /**
     * @covers ::read
     */
    public function testReadFailsOnShortFile(): void
    {
        $fp = fopen($this->testFile, 'w+b');
        fwrite($fp, 'short_data');
        fseek($fp, 0);
        $this->assertNull(JdbIndexHeader::read($fp));
        fclose($fp);
    }

    /**
     * @covers ::read
     */
    public function testReadFailsOnWrongMagic(): void
    {
        $fp = fopen($this->testFile, 'w+b');
        // Write 18 bytes with invalid magic "XXXX"
        $raw = pack('a4CVVVC', 'XXXX', 1, 0, 0, 0, 0);
        fwrite($fp, $raw);
        fseek($fp, 0);
        $this->assertNull(JdbIndexHeader::read($fp));
        fclose($fp);
    }

    // =========================================================================
    // Dirty Bit Path Operations
    // =========================================================================
    /**
     * @covers ::readDirtyBit
     */
    public function testReadDirtyBitReturnsTrueForMissingFile(): void
    {
        $this->assertTrue(JdbIndexHeader::readDirtyBit($this->tempDir . '/missing.index.php'));
    }

    /**
     * @covers ::writeDirtyBit
     * @covers ::readDirtyBit
     */
    public function testWriteAndReadDirtyBitOnExistingFile(): void
    {
        // Create empty file first
        touch($this->testFile);
        
        JdbIndexHeader::writeDirtyBit($this->testFile, 1);
        $this->assertTrue(JdbIndexHeader::readDirtyBit($this->testFile));

        JdbIndexHeader::writeDirtyBit($this->testFile, 0);
        $this->assertFalse(JdbIndexHeader::readDirtyBit($this->testFile));
    }

    /**
     * @covers ::writeDirtyBit
     * @covers ::readDirtyBit
     */
    public function testWriteDirtyBitCreatesFileIfMissing(): void
    {
        $missingFile = $this->tempDir . '/new.index.php';
        $this->assertFalse(file_exists($missingFile));

        $this->assertTrue(JdbIndexHeader::writeDirtyBit($missingFile, 1));
        $this->assertFileExists($missingFile);
        $this->assertTrue(JdbIndexHeader::readDirtyBit($missingFile));

        @unlink($missingFile);
    }

    // =========================================================================
    // Create If Missing
    // =========================================================================
    /**
     * @covers ::createIfMissing
     * @covers ::read
     */
    public function testCreateIfMissingCreatesValidHeader(): void
    {
        JdbIndexHeader::createIfMissing($this->testFile, 10, 1);
        $this->assertFileExists($this->testFile);

        $fp = fopen($this->testFile, 'rb');
        $header = JdbIndexHeader::read($fp);
        
        $this->assertNotNull($header);
        $this->assertEquals(0, (int)$header['slots']);
        $this->assertEquals(0, (int)$header['count']);
        $this->assertEquals(10, (int)$header['next_id']);
        $this->assertEquals(1, (int)$header['dirty']);
        fclose($fp);
    }

    /**
     * @covers ::createIfMissing
     */
    public function testCreateIfMissingDoesNotOverwriteExistingFile(): void
    {
        file_put_contents($this->testFile, 'existing_content');
        JdbIndexHeader::createIfMissing($this->testFile, 999, 0);
        
        $this->assertEquals('existing_content', file_get_contents($this->testFile));
    }

    // =========================================================================
    // Format Integrity
    // =========================================================================
    /**
     * @covers ::write
     */
    public function testHeaderSizeIsExactly32Bytes(): void
    {
        $fp = fopen($this->testFile, 'w+b');
        JdbIndexHeader::write($fp, 100, 50, 1, 0);
        fseek($fp, 0, SEEK_END);
        $size = ftell($fp);
        
        $this->assertEquals(JdbIndexHeader::HDR_SIZE, $size);
        $this->assertEquals(32, $size);
        fclose($fp);
    }

    // =========================================================================
    // A. write() CON RESOURCE NON VALIDA
    // =========================================================================

    /**
     * write() deve restituire null quando il primo argomento non è una resource.
     *
     * @covers ::write
     */
    public function testWriteReturnsNullOnNonResource(): void
    {
        $this->assertNull(JdbIndexHeader::write('not_a_resource', 0, 0, 0, 0));
        $this->assertNull(JdbIndexHeader::write(null, 0, 0, 0, 0));
        $this->assertNull(JdbIndexHeader::write(42, 0, 0, 0, 0));
    }

    // =========================================================================
    // B. read() SU HANDLE CHIUSO
    // =========================================================================

    /**
     * read() su un handle già chiuso con fclose() deve restituire null
     * senza generare warning fatali.
     *
     * @covers ::read
     */
    public function testReadReturnsNullOnClosedHandle(): void
    {
        $fp = fopen($this->testFile, 'w+b');
        JdbIndexHeader::write($fp, 10, 5, 1, 0);
        fclose($fp); // ora $fp non è più una resource valida

        // In PHP 8+ una resource chiusa non passa is_resource(), in PHP 7
        // potrebbe essere considerata ancora una resource ma di tipo "Unknown".
        // In entrambi i casi read() deve restituire null senza eccezioni.
        $result = JdbIndexHeader::read($fp);
        $this->assertNull($result);
    }

    // =========================================================================
    // C. BYTES RISERVATI (18–31) EFFETTIVAMENTE ZERO
    // =========================================================================

    /**
     * I 14 byte riservati dopo il dirty bit (posizioni 18–31) devono essere
     * tutti \x00: la classe usa str_pad per riempire fino a HDR_SIZE.
     *
     * @covers ::write
     */
    public function testReservedBytesAreZeroPadded(): void
    {
        $fp = fopen($this->testFile, 'w+b');
        JdbIndexHeader::write($fp, 512, 256, 7, 1);
        fseek($fp, 0);
        $raw = fread($fp, JdbIndexHeader::HDR_SIZE);
        fclose($fp);

        $this->assertSame(JdbIndexHeader::HDR_SIZE, strlen($raw),
            'Il file deve contenere esattamente HDR_SIZE byte');

        // Bytes 18–31 sono i 14 byte di padding riservati
        $reserved = substr($raw, 18, 14);
        $this->assertSame(str_repeat("\x00", 14), $reserved,
            'I byte riservati (18–31) devono essere zero');
    }

    // =========================================================================
    // D. readDirtyBit SU FILE TROPPO CORTO
    // =========================================================================

    /**
     * Se il file esiste ma ha meno di 18 byte, readDirtyBit non riesce a
     * leggere il byte alla posizione HDR_DIRTY_BYTE (17) e deve restituire
     * true (convenzione "safe default = rebuild needed").
     *
     * @covers ::readDirtyBit
     */
    public function testReadDirtyBitReturnsTrueOnTooShortFile(): void
    {
        // File di 10 byte: troppo corto per contenere il dirty byte (pos 17)
        file_put_contents($this->testFile, str_repeat("\x00", 10));

        $result = JdbIndexHeader::readDirtyBit($this->testFile);
        $this->assertTrue($result,
            'readDirtyBit deve restituire true (rebuild) se il file è più corto di HDR_DIRTY_BYTE');
    }

    // =========================================================================
    // E. writeDirtyBit SU FILE NUOVO: HEADER STRUTTURALMENTE VALIDO
    // =========================================================================

    /**
     * Quando writeDirtyBit crea un file nuovo, deve scrivere un header
     * con magic e version corretti (non solo il byte dirty).
     *
     * @covers ::writeDirtyBit
     */
    public function testWriteDirtyBitOnNewFileCreatesValidHeader(): void
    {
        $newFile = $this->tempDir . '/brand_new.index.php';
        $this->assertFalse(file_exists($newFile));

        JdbIndexHeader::writeDirtyBit($newFile, 1);

        $fp     = fopen($newFile, 'rb');
        $header = JdbIndexHeader::read($fp);
        fclose($fp);

        $this->assertNotNull($header,
            'read() deve restituire un header valido sul file creato da writeDirtyBit');
        $this->assertSame(JdbIndexHeader::MAGIC, $header['magic']);
        $this->assertSame(JdbIndexHeader::VERSION, (int)$header['version']);
        $this->assertSame(1, (int)$header['dirty']);
    }

    /**
     * writeDirtyBit(file, 0) su file nuovo deve creare un file con dirty=0.
     *
     * @covers ::writeDirtyBit
     * @covers ::readDirtyBit
     */
    public function testWriteDirtyBitZeroOnNewFileCreatesCleanHeader(): void
    {
        $newFile = $this->tempDir . '/clean_new.index.php';

        $result = JdbIndexHeader::writeDirtyBit($newFile, 0);
        $this->assertTrue($result);

        $this->assertFalse(JdbIndexHeader::readDirtyBit($newFile),
            'Un file creato con dirty=0 deve essere letto come clean');
    }

    // =========================================================================
    // F. createIfMissing CON dirty=0
    // =========================================================================

    /**
     * createIfMissing con dirty=0 deve scrivere un header con dirty bit = 0.
     *
     * @covers ::createIfMissing
     * @covers ::read
     */
    public function testCreateIfMissingWithDirtyZero(): void
    {
        JdbIndexHeader::createIfMissing($this->testFile, 5, 0);

        $fp     = fopen($this->testFile, 'rb');
        $header = JdbIndexHeader::read($fp);
        fclose($fp);

        $this->assertNotNull($header);
        $this->assertSame(0, (int)$header['dirty'],
            'Il dirty bit deve essere 0 quando createIfMissing è chiamato con dirty=0');
        $this->assertSame(5, (int)$header['next_id']);
    }

    // =========================================================================
    // G. createIfMissing PARAMETRI DEFAULT
    // =========================================================================

    /**
     * createIfMissing($file) senza argomenti opzionali deve usare
     * next_id=0 e dirty=1 (valori di default nella firma del metodo).
     *
     * @covers ::createIfMissing
     * @covers ::read
     */
    public function testCreateIfMissingUsesDefaultParameters(): void
    {
        JdbIndexHeader::createIfMissing($this->testFile); // next_id=0, dirty=1 per default

        $fp     = fopen($this->testFile, 'rb');
        $header = JdbIndexHeader::read($fp);
        fclose($fp);

        $this->assertNotNull($header);
        $this->assertSame(0, (int)$header['next_id'],
            'Il next_id di default deve essere 0');
        $this->assertSame(1, (int)$header['dirty'],
            'Il dirty di default deve essere 1');
    }

    // =========================================================================
    // H. createIfMissing IDEMPOTENZA
    // =========================================================================

    /**
     * Chiamare createIfMissing due volte sullo stesso file deve essere
     * un no-op: il contenuto del file non deve cambiare.
     *
     * @covers ::createIfMissing
     */
    public function testCreateIfMissingIsIdempotent(): void
    {
        JdbIndexHeader::createIfMissing($this->testFile, 42, 1);
        $after_first = file_get_contents($this->testFile);

        JdbIndexHeader::createIfMissing($this->testFile, 99, 0); // argomenti diversi: deve ignorarli
        $after_second = file_get_contents($this->testFile);

        $this->assertSame($after_first, $after_second,
            'createIfMissing non deve modificare un file già esistente');
    }

    // =========================================================================
    // I. ROUNDTRIP CON VALORI uint32 MASSIMI
    // =========================================================================

    /**
     * Tutti i campi al valore massimo uint32 (0xFFFFFFFF) devono sopravvivere
     * al ciclo write→read senza overflow o troncamento.
     *
     * @covers ::write
     * @covers ::read
     */
    public function testMaxUint32ValuesRoundtrip(): void
    {
        $max = 0xFFFFFFFF;
        $fp  = fopen($this->testFile, 'w+b');
        JdbIndexHeader::write($fp, $max, $max, $max, 1); // dirty: 1 (max clampato)
        fseek($fp, 0);
        $header = JdbIndexHeader::read($fp);
        fclose($fp);

        $this->assertNotNull($header);
        // PHP legge i uint32 LE come interi non-signed; su PHP 64-bit sono positivi
        $this->assertSame($max, (int)$header['slots']  & 0xFFFFFFFF);
        $this->assertSame($max, (int)$header['count']  & 0xFFFFFFFF);
        $this->assertSame($max, (int)$header['next_id'] & 0xFFFFFFFF);
        $this->assertSame(1,   (int)$header['dirty']);
    }

    // =========================================================================
    // J. TOGGLE DEL DIRTY BIT MULTIPLO
    // =========================================================================

    /**
     * Il dirty bit deve poter essere alternato più volte su un file
     * già inizializzato (1→0→1→0).
     *
     * @covers ::writeDirtyBit
     * @covers ::readDirtyBit
     */
    public function testDirtyBitMultipleToggles(): void
    {
        // Inizializza con un header valido
        $fp = fopen($this->testFile, 'w+b');
        JdbIndexHeader::write($fp, 64, 10, 1, 0);
        fclose($fp);

        JdbIndexHeader::writeDirtyBit($this->testFile, 1);
        $this->assertTrue(JdbIndexHeader::readDirtyBit($this->testFile), 'dopo toggle→1');

        JdbIndexHeader::writeDirtyBit($this->testFile, 0);
        $this->assertFalse(JdbIndexHeader::readDirtyBit($this->testFile), 'dopo toggle→0');

        JdbIndexHeader::writeDirtyBit($this->testFile, 1);
        $this->assertTrue(JdbIndexHeader::readDirtyBit($this->testFile), 'dopo toggle→1 di nuovo');

        JdbIndexHeader::writeDirtyBit($this->testFile, 0);
        $this->assertFalse(JdbIndexHeader::readDirtyBit($this->testFile), 'dopo toggle→0 finale');
    }

    /**
     * I toggle del dirty bit non devono corrompere il resto dell'header:
     * magic, version, slots, count e next_id devono essere invariati.
     *
     * @covers ::writeDirtyBit
     * @covers ::read
     */
    public function testDirtyBitToggleDoesNotCorruptHeader(): void
    {
        $fp = fopen($this->testFile, 'w+b');
        JdbIndexHeader::write($fp, 128, 64, 7, 0);
        fclose($fp);

        JdbIndexHeader::writeDirtyBit($this->testFile, 1);
        JdbIndexHeader::writeDirtyBit($this->testFile, 0);

        $fp     = fopen($this->testFile, 'rb');
        $header = JdbIndexHeader::read($fp);
        fclose($fp);

        $this->assertNotNull($header);
        $this->assertSame(JdbIndexHeader::MAGIC,   $header['magic']);
        $this->assertSame(JdbIndexHeader::VERSION, (int)$header['version']);
        $this->assertSame(128, (int)$header['slots']);
        $this->assertSame(64,  (int)$header['count']);
        $this->assertSame(7,   (int)$header['next_id']);
        $this->assertSame(0,   (int)$header['dirty']);
    }

    // =========================================================================
    // K. POSIZIONE ESATTA DEL DIRTY BYTE
    // =========================================================================

    /**
     * Il dirty byte deve trovarsi fisicamente alla posizione HDR_DIRTY_BYTE (17)
     * nel file binario, non a un byte diverso.
     *
     * @covers ::write
     */
    public function testDirtyByteIsAtCorrectOffset(): void
    {
        $fp = fopen($this->testFile, 'w+b');
        JdbIndexHeader::write($fp, 0, 0, 0, 1); // dirty=1
        fseek($fp, 0);
        $raw = fread($fp, JdbIndexHeader::HDR_SIZE);
        fclose($fp);

        $dirtyByte = ord($raw[JdbIndexHeader::HDR_DIRTY_BYTE]);
        $this->assertSame(1, $dirtyByte,
            'Il dirty byte alla posizione HDR_DIRTY_BYTE deve essere 0x01 quando dirty=1');

        // Scrivi dirty=0 e ricontrolla la stessa posizione
        $fp = fopen($this->testFile, 'w+b');
        JdbIndexHeader::write($fp, 0, 0, 0, 0);
        fseek($fp, 0);
        $raw = fread($fp, JdbIndexHeader::HDR_SIZE);
        fclose($fp);

        $dirtyByte = ord($raw[JdbIndexHeader::HDR_DIRTY_BYTE]);
        $this->assertSame(0, $dirtyByte,
            'Il dirty byte alla posizione HDR_DIRTY_BYTE deve essere 0x00 quando dirty=0');
    }

    // =========================================================================
    // L. SECONDARY INDEX CONVENTION (next_id=0)
    // =========================================================================

    /**
     * Gli indici secondari passano sempre next_id=0 per convenzione.
     * Verifica che write+read preservi next_id=0 senza effetti collaterali.
     *
     * @covers ::write
     * @covers ::read
     */
    public function testSecondaryIndexConventionNextIdZero(): void
    {
        $fp = fopen($this->testFile, 'w+b');
        JdbIndexHeader::write($fp, 4096, 200, 0, 0); // next_id=0 come secondary index
        fseek($fp, 0);
        $header = JdbIndexHeader::read($fp);
        fclose($fp);

        $this->assertNotNull($header);
        $this->assertSame(0, (int)$header['next_id'],
            'Gli indici secondari devono preservare next_id=0');
        $this->assertSame(4096, (int)$header['slots']);
        $this->assertSame(200,  (int)$header['count']);
    }

    // =========================================================================
    // M. VERSION COSTANTE NEL ROUNDTRIP
    // =========================================================================

    /**
     * Il byte version scritto deve corrispondere alla costante VERSION
     * della classe, non a un valore hardcoded.
     *
     * @covers ::write
     * @covers ::read
     */
    public function testVersionConstantIsPreservedInRoundtrip(): void
    {
        $fp = fopen($this->testFile, 'w+b');
        JdbIndexHeader::write($fp, 0, 0, 0, 0);
        fseek($fp, 0);
        $header = JdbIndexHeader::read($fp);
        fclose($fp);

        $this->assertNotNull($header);
        $this->assertSame(
            JdbIndexHeader::VERSION,
            (int)$header['version'],
            'La version letta deve corrispondere alla costante JdbIndexHeader::VERSION'
        );
    }

    // =========================================================================
    // N. writeDirtyBit – fallimenti su file esistente
    // =========================================================================

    /**
     * writeDirtyBit deve restituire false se il file esiste ma non è scrivibile.
     *
     * @covers ::writeDirtyBit
     */
    public function testWriteDirtyBitFailsWhenFileNotWritable(): void
    {
        // Crea un file regolare e rendilo solo lettura
        file_put_contents($this->testFile, 'dummy');
        chmod($this->testFile, 0444);

        $result = @JdbIndexHeader::writeDirtyBit($this->testFile, 1);
        $this->assertFalse($result);
        // Ripristina permessi per la pulizia
        chmod($this->testFile, 0644);
    }

    /**
     * writeDirtyBit deve restituire false se il percorso esiste ma è una directory,
     * non un file regolare.
     *
     * @covers ::writeDirtyBit
     */
    public function testWriteDirtyBitFailsWhenPathIsDirectory(): void
    {
        $dir = $this->tempDir . '/test_dir';
        mkdir($dir);

        $result = @JdbIndexHeader::writeDirtyBit($dir, 1);
        $this->assertFalse($result);
    }

    // =========================================================================
    // O. readDirtyBit – fallimento apertura file
    // =========================================================================

    /**
     * readDirtyBit deve restituire true (rebuild) se il file esiste ma non è
     * leggibile (ad esempio permessi 000).
     *
     * @covers ::readDirtyBit
     */
    public function testReadDirtyBitReturnsTrueWhenFileNotReadable(): void
    {
        file_put_contents($this->testFile, 'content');
        chmod($this->testFile, 0000);

        $result = @JdbIndexHeader::readDirtyBit($this->testFile);
        $this->assertTrue($result);

        chmod($this->testFile, 0644);
    }

    // =========================================================================
    // P. createIfMissing – fallimento creazione file
    // =========================================================================

    /**
     * createIfMissing non deve lanciare errori se la directory non è scrivibile.
     * La funzione è silente e non restituisce bool; testiamo che non ci siano
     * errori fatali e che il file non venga creato.
     *
     * @covers ::createIfMissing
     */
    public function testCreateIfMissingSilentlyFailsWhenDirectoryNotWritable(): void
    {
        $roDir = $this->tempDir . '/readonly_dir';
        mkdir($roDir, 0555);
        $fileInReadOnly = $roDir . '/test.index.php';

        // Dovrebbe fallire silenziosamente (nessun file creato)
        @JdbIndexHeader::createIfMissing($fileInReadOnly, 1, 1);

        $this->assertFileDoesNotExist($fileInReadOnly);

        // Ripristina permessi per cleanup
        chmod($roDir, 0755);
        rmdir($roDir);
    }
}
