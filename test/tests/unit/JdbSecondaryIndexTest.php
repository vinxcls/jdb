<?php

/**
 * Test suite per JdbSecondaryIndex – versione estesa
 *
 * Requisiti:
 * - Codeception 4.x / 5.x
 * - PHP 7.4+ (test scritti con sintassi moderna, compatibili con la classe target PHP 5.5+)
 * - Assicurati che `jdb.php` sia caricato dall'autoloader o incluso nel bootstrap.
 *
 * @covers JdbSecondaryIndex
 */
class JdbSecondaryIndexTest extends \Codeception\Test\Unit
{
    protected string $tempDir;
    protected string $idxFile;
    protected string $dataFile;
    protected JdbSecondaryIndex $secondaryIdx;
    protected int $originalSortChunkSize;

    protected function setUp(): void
    {
        parent::setUp();

        // Salva configurazione statica per evitarne l'inquinamento tra i test
        $this->originalSortChunkSize = JdbSecondaryIndex::getSortChunkSize();

        // Directory temporanea isolata per ogni test
        $this->tempDir = sys_get_temp_dir() . '/jdb_sec_test_' . uniqid();
        if (!mkdir($this->tempDir, 0755, true)) {
            $this->fail("Impossibile creare la directory temporanea: {$this->tempDir}");
        }

        $this->idxFile  = $this->tempDir . '/sec.index.php';
        $this->dataFile = $this->tempDir . '/data.jsonl.php';

        // Crea il file dati con l'header PHP standard di JDB (16 byte)
        file_put_contents($this->dataFile, "<?php die(); ?>\n");

        $this->secondaryIdx = new JdbSecondaryIndex($this->idxFile, 'score');

        // Mock di JdbLock per bypassare il locking del filesystem durante i test
        $mockLock = $this->createMock(JdbLock::class);
        $mockLock->method('acquireMutex')->willReturn(['type' => 'mock', 'token' => 'test_lock']);
        $mockLock->method('releaseMutex')->willReturn(null);
        $this->secondaryIdx->setLock($mockLock);
    }

    protected function tearDown(): void
    {
        // Pulizia file temporanei
        $files = glob($this->tempDir . '/*');
        foreach ($files as $file) {
            @unlink($file);
        }
        @rmdir($this->tempDir);

        // Ripristina configurazione statica
        JdbSecondaryIndex::setSortChunkSize($this->originalSortChunkSize);

        parent::tearDown();
    }

    /**
     * Helper: scrive record JSON nel file dati
     */
    protected function writeDataLines(array $records): void
    {
        $fp = fopen($this->dataFile, 'ab');
        foreach ($records as $record) {
            fwrite($fp, json_encode($record) . "\n");
        }
        fclose($fp);
    }

    /**
     * Helper: crea un indice su un campo specifico con mock lock
     */
    protected function makeIndex(string $field): JdbSecondaryIndex
    {
        $idxFile = $this->tempDir . '/sec_' . $field . '.index.php';
        $idx = new JdbSecondaryIndex($idxFile, $field);
        $mockLock = $this->createMock(JdbLock::class);
        $mockLock->method('acquireMutex')->willReturn(['type' => 'mock', 'token' => 'test_lock']);
        $mockLock->method('releaseMutex')->willReturn(null);
        $idx->setLock($mockLock);
        return $idx;
    }

    // =========================================================================
    // TEST: Costruttore e Getter
    // =========================================================================

    public function testConstructorAndGetters(): void
    {
        $idx = new JdbSecondaryIndex('/fake/path.index.php', 'email');
        $this->assertEquals('/fake/path.index.php', $idx->getFile());
        $this->assertEquals('email', $idx->getField());
    }

    public function testConstructorPreservesFieldWithSpecialChars(): void
    {
        $idx = new JdbSecondaryIndex('/fake/path.index.php', 'user.meta.age');
        $this->assertEquals('user.meta.age', $idx->getField());
    }

    // =========================================================================
    // TEST: Gestione Dirty Bit
    // =========================================================================

    public function testDirtyBitManagement(): void
    {
        // File inesistente = considerato sporco
        $this->assertTrue($this->secondaryIdx->isDirty());

        $this->secondaryIdx->createIfMissing();
        $this->assertFileExists($this->idxFile);

        // createIfMissing imposta dirty=1
        $this->assertTrue($this->secondaryIdx->isDirty());

        // markDirty mantiene/sporca il bit
        $this->secondaryIdx->markDirty();
        $this->assertTrue($this->secondaryIdx->isDirty());
    }

    public function testMarkDirtyAfterRebuildRestoresDirtyState(): void
    {
        $this->writeDataLines([
            ['id' => 'u1', 'score' => 10],
        ]);
        $this->secondaryIdx->rebuild($this->dataFile, 16);
        $this->assertFalse($this->secondaryIdx->isDirty());

        // Dopo markDirty() l'indice torna sporco
        $this->secondaryIdx->markDirty();
        $this->assertTrue($this->secondaryIdx->isDirty());
    }

    public function testCreateIfMissingIsIdempotent(): void
    {
        $this->secondaryIdx->createIfMissing();
        $mtime1 = filemtime($this->idxFile);

        // Seconda chiamata non deve sovrascrivere il file
        $this->secondaryIdx->createIfMissing();
        $mtime2 = filemtime($this->idxFile);

        $this->assertEquals($mtime1, $mtime2, 'createIfMissing non deve riscrivere il file se esiste già');
    }

    // =========================================================================
    // TEST: toSortableKey – prefissi e ordinamento
    // =========================================================================

    public function testToSortableKeyTypeOrdering(): void
    {
        // Prefissi ASCII: F(70) < I(73) < S(83)
        $floatKey = JdbSecondaryIndex::toSortableKey(3.14);
        $intKey   = JdbSecondaryIndex::toSortableKey(100);
        $strKey   = JdbSecondaryIndex::toSortableKey('hello');

        $this->assertStringStartsWith('F', $floatKey);
        $this->assertStringStartsWith('I', $intKey);
        $this->assertStringStartsWith('S', $strKey);

        $this->assertGreaterThan($intKey, $strKey);
        $this->assertGreaterThan($floatKey, $intKey);
    }

    public function testToSortableKeyNumericStrings(): void
    {
        // Le stringhe numeriche vengono normalizzate
        $this->assertStringStartsWith('I', JdbSecondaryIndex::toSortableKey('42'));
        $this->assertStringStartsWith('F', JdbSecondaryIndex::toSortableKey('9.99'));
    }

    public function testToSortableKeyNullAndEmptyString(): void
    {
        // null e stringa vuota producono entrambi 'S' (solo prefisso, body vuoto)
        $this->assertEquals('S', JdbSecondaryIndex::toSortableKey(null));
        $this->assertEquals('S', JdbSecondaryIndex::toSortableKey(''));
    }

    public function testToSortableKeyIntegerOrdering(): void
    {
        // Gli interi negativi devono risultare < positivi dopo la conversione
        $negKey  = JdbSecondaryIndex::toSortableKey(-100);
        $zeroKey = JdbSecondaryIndex::toSortableKey(0);
        $posKey  = JdbSecondaryIndex::toSortableKey(100);

        $this->assertLessThan($zeroKey, $negKey);
        $this->assertLessThan($posKey, $zeroKey);
    }

    public function testToSortableKeyIntegersAreSortedCorrectly(): void
    {
        $keys = array_map(
            fn($v) => JdbSecondaryIndex::toSortableKey($v),
            [5, 1, 3, 2, 4]
        );
        $sorted = $keys;
        sort($sorted);
        $expected = array_map(
            fn($v) => JdbSecondaryIndex::toSortableKey($v),
            [1, 2, 3, 4, 5]
        );
        $this->assertEquals($expected, $sorted);
    }

    public function testToSortableKeyFloatPrecision(): void
    {
        // Tre cifre decimali: 1.001 < 1.002
        $k1 = JdbSecondaryIndex::toSortableKey(1.001);
        $k2 = JdbSecondaryIndex::toSortableKey(1.002);
        $this->assertLessThan($k2, $k1);
    }

    public function testToSortableKeyNegativeFloat(): void
    {
        $negFloat = JdbSecondaryIndex::toSortableKey(-9.99);
        $posFloat = JdbSecondaryIndex::toSortableKey(9.99);
        $this->assertLessThan($posFloat, $negFloat);
    }

    public function testToSortableKeyStringTruncatedAt119Chars(): void
    {
        $long = str_repeat('a', 200);
        $key  = JdbSecondaryIndex::toSortableKey($long);
        // Prefisso 'S' + max 119 char = 120 byte
        $this->assertLessThanOrEqual(120, strlen($key));
    }

    public function testToSortableKeyStringLexicographicOrder(): void
    {
        $kA = JdbSecondaryIndex::toSortableKey('apple');
        $kB = JdbSecondaryIndex::toSortableKey('banana');
        $kZ = JdbSecondaryIndex::toSortableKey('zebra');
        $this->assertLessThan($kB, $kA);
        $this->assertLessThan($kZ, $kB);
    }

    public function testToSortableKeyBooleanTreatedAsString(): void
    {
        // bool non è int/float/string-numerica → fallback a (string)bool
        $trueKey  = JdbSecondaryIndex::toSortableKey(true);
        $falseKey = JdbSecondaryIndex::toSortableKey(false);
        $this->assertStringStartsWith('S', $trueKey);
        $this->assertStringStartsWith('S', $falseKey);
    }

    public function testToSortableKeyExponentialNumericString(): void
    {
        // '1e3' deve essere riconosciuta come float
        $key = JdbSecondaryIndex::toSortableKey('1e3');
        $this->assertStringStartsWith('F', $key);
    }

    // =========================================================================
    // TEST: Rebuild – scenari base ed edge case
    // =========================================================================

    public function testRebuildBasicAndEdgeValues(): void
    {
        $this->writeDataLines([
            ['id' => 'u1', 'score' => 10],
            ['id' => 'u2', 'score' => 50],
            ['id' => 'u3', 'score' => 5],
            ['id' => 'u4', '_deleted' => true], // Tombstone: escluso
            ['id' => 'u5', 'score' => 30],
        ]);

        $this->assertTrue($this->secondaryIdx->rebuild($this->dataFile, 16));
        $this->assertFalse($this->secondaryIdx->isDirty());

        // Ordine atteso: 5, 10, 30, 50
        $this->assertEquals(5,  $this->secondaryIdx->getEdgeValue('first'));
        $this->assertEquals(50, $this->secondaryIdx->getEdgeValue('last'));
    }

    public function testRebuildEmptyData(): void
    {
        $this->assertTrue($this->secondaryIdx->rebuild($this->dataFile, 16));
        $this->assertFalse($this->secondaryIdx->isDirty());

        // Indice vuoto restituisce false
        $this->assertFalse($this->secondaryIdx->getEdgeValue('first'));
        $this->assertFalse($this->secondaryIdx->getEdgeValue('last'));
    }

    public function testRebuildSkipsRecordsWithoutIndexedField(): void
    {
        $this->writeDataLines([
            ['id' => 'u1', 'score' => 10],
            ['id' => 'u2', 'name' => 'Alice'],  // campo 'score' assente → saltato
            ['id' => 'u3', 'score' => 20],
        ]);

        $this->secondaryIdx->rebuild($this->dataFile, 16);

        $dataFp = fopen($this->dataFile, 'rb');
        $ids    = [];
        $this->secondaryIdx->rangeQuery($dataFp, null, null, function ($r) use (&$ids) {
            $ids[] = $r['id'];
        });
        fclose($dataFp);

        $this->assertNotContains('u2', $ids);
        $this->assertCount(2, $ids);
    }

    public function testRebuildLastWriteWinsForDuplicateId(): void
    {
        // u1 inserito con score=10, poi aggiornato a score=99
        $this->writeDataLines([
            ['id' => 'u1', 'score' => 10],
            ['id' => 'u1', 'score' => 99],
        ]);

        $this->secondaryIdx->rebuild($this->dataFile, 16);

        // L'indice deve contenere solo un record per u1 con il valore aggiornato
        $dataFp = fopen($this->dataFile, 'rb');
        $found  = [];
        $this->secondaryIdx->rangeQuery($dataFp, null, null, function ($r) use (&$found) {
            $found[] = $r['score'];
        });
        fclose($dataFp);

        $this->assertCount(1, $found);
        $this->assertEquals(99, $found[0]);
    }

    public function testRebuildTombstoneRemovesExistingEntry(): void
    {
        // u1 presente, poi eliminato con tombstone
        $this->writeDataLines([
            ['id' => 'u1', 'score' => 10],
            ['id' => 'u2', 'score' => 20],
            ['id' => 'u1', '_deleted' => true],
        ]);

        $this->secondaryIdx->rebuild($this->dataFile, 16);

        $dataFp = fopen($this->dataFile, 'rb');
        $ids    = [];
        $this->secondaryIdx->rangeQuery($dataFp, null, null, function ($r) use (&$ids) {
            $ids[] = $r['id'];
        });
        fclose($dataFp);

        $this->assertNotContains('u1', $ids);
        $this->assertContains('u2', $ids);
    }

    public function testRebuildThenReinsertAfterTombstone(): void
    {
        // u1: inserito → cancellato → reinserito con nuovo valore
        $this->writeDataLines([
            ['id' => 'u1', 'score' => 10],
            ['id' => 'u1', '_deleted' => true],
            ['id' => 'u1', 'score' => 55],
        ]);

        $this->secondaryIdx->rebuild($this->dataFile, 16);

        $dataFp = fopen($this->dataFile, 'rb');
        $found  = [];
        $this->secondaryIdx->rangeQuery($dataFp, null, null, function ($r) use (&$found) {
            $found[] = $r['score'];
        });
        fclose($dataFp);

        $this->assertCount(1, $found);
        $this->assertEquals(55, $found[0]);
    }

    public function testRebuildWithFloatValues(): void
    {
        $idx = $this->makeIndex('price');
        $this->writeDataLines([
            ['id' => 'p1', 'price' => 9.99],
            ['id' => 'p2', 'price' => 4.50],
            ['id' => 'p3', 'price' => 19.95],
        ]);

        $idx->rebuild($this->dataFile, 16);

        $this->assertEquals(4.50,  $idx->getEdgeValue('first'));
        $this->assertEquals(19.95, $idx->getEdgeValue('last'));
    }

    public function testRebuildWithStringValues(): void
    {
        $idx = $this->makeIndex('name');
        $this->writeDataLines([
            ['id' => 'u1', 'name' => 'Zara'],
            ['id' => 'u2', 'name' => 'Alice'],
            ['id' => 'u3', 'name' => 'Marco'],
        ]);

        $idx->rebuild($this->dataFile, 16);

        $this->assertEquals('Alice', $idx->getEdgeValue('first'));
        $this->assertEquals('Zara',  $idx->getEdgeValue('last'));
    }

    public function testRebuildWithNegativeIntegers(): void
    {
        $this->writeDataLines([
            ['id' => 'u1', 'score' => -5],
            ['id' => 'u2', 'score' => 0],
            ['id' => 'u3', 'score' => -100],
            ['id' => 'u4', 'score' => 10],
        ]);

        $this->secondaryIdx->rebuild($this->dataFile, 16);

        $this->assertEquals(-100, $this->secondaryIdx->getEdgeValue('first'));
        $this->assertEquals(10,   $this->secondaryIdx->getEdgeValue('last'));
    }

    public function testRebuildWithSingleRecord(): void
    {
        $this->writeDataLines([
            ['id' => 'u1', 'score' => 42],
        ]);

        $this->secondaryIdx->rebuild($this->dataFile, 16);

        $this->assertEquals(42, $this->secondaryIdx->getEdgeValue('first'));
        $this->assertEquals(42, $this->secondaryIdx->getEdgeValue('last'));
    }

    // =========================================================================
    // TEST: Rebuild con chunkSize piccolo (multi-chunk + k-way merge)
    // =========================================================================

    public function testRebuildMultiChunkSort(): void
    {
        // Chunk size = 2 → forza la divisione in più chunk
        JdbSecondaryIndex::setSortChunkSize(2);

        $records = [];
        for ($i = 10; $i >= 1; $i--) {
            $records[] = ['id' => "u$i", 'score' => $i];
        }
        $this->writeDataLines($records);

        $this->assertTrue($this->secondaryIdx->rebuild($this->dataFile, 16));

        $this->assertEquals(1,  $this->secondaryIdx->getEdgeValue('first'));
        $this->assertEquals(10, $this->secondaryIdx->getEdgeValue('last'));
    }

    public function testRebuildMultiChunkOrderIsCorrect(): void
    {
        JdbSecondaryIndex::setSortChunkSize(3);

        $values = [7, 2, 9, 1, 5, 3, 8, 4, 6];
        $records = array_map(fn($v, $i) => ['id' => "u$i", 'score' => $v], $values, array_keys($values));
        $this->writeDataLines($records);

        $this->secondaryIdx->rebuild($this->dataFile, 16);

        $dataFp  = fopen($this->dataFile, 'rb');
        $results = [];
        $this->secondaryIdx->rangeQuery($dataFp, null, null, function ($r) use (&$results) {
            $results[] = $r['score'];
        });
        fclose($dataFp);

        $expected = $values;
        sort($expected);
        $this->assertEquals($expected, $results);
    }

    // =========================================================================
    // TEST: getEdgeValue
    // =========================================================================

    public function testGetEdgeValueReturnsFalseOnDirtyIndex(): void
    {
        $this->secondaryIdx->createIfMissing();
        $this->assertTrue($this->secondaryIdx->isDirty());

        $this->assertFalse($this->secondaryIdx->getEdgeValue('first'));
        $this->assertFalse($this->secondaryIdx->getEdgeValue('last'));
    }

    public function testGetEdgeValueReturnsFalseOnMissingFile(): void
    {
        // File inesistente → dirty → false
        $this->assertFalse($this->secondaryIdx->getEdgeValue('first'));
    }

    public function testGetEdgeValueFloatRoundtrip(): void
    {
        $idx = $this->makeIndex('price');
        $this->writeDataLines([
            ['id' => 'a', 'price' => 1.500],
            ['id' => 'b', 'price' => 3.750],
        ]);
        $idx->rebuild($this->dataFile, 16);

        // I float vengono ricodificati con 3 decimali
        $this->assertEquals(1.5,  $idx->getEdgeValue('first'));
        $this->assertEquals(3.75, $idx->getEdgeValue('last'));
    }

    public function testGetEdgeValueStringRoundtrip(): void
    {
        $idx = $this->makeIndex('tag');
        $this->writeDataLines([
            ['id' => 'a', 'tag' => 'beta'],
            ['id' => 'b', 'tag' => 'alpha'],
        ]);
        $idx->rebuild($this->dataFile, 16);

        $this->assertEquals('alpha', $idx->getEdgeValue('first'));
        $this->assertEquals('beta',  $idx->getEdgeValue('last'));
    }

    // =========================================================================
    // TEST: Range Query
    // =========================================================================

    public function testRangeQueryBasic(): void
    {
        $this->writeDataLines([
            ['id' => 'u1', 'score' => 10],
            ['id' => 'u2', 'score' => 20],
            ['id' => 'u3', 'score' => 30],
        ]);
        $this->secondaryIdx->rebuild($this->dataFile, 16);

        $dataFp  = fopen($this->dataFile, 'rb');
        $results = [];
        $count   = $this->secondaryIdx->rangeQuery($dataFp, 15, 25, function ($record) use (&$results) {
            $results[] = $record['id'];
        });
        fclose($dataFp);

        $this->assertEquals(1, $count);
        $this->assertEquals(['u2'], $results);
    }

    public function testRangeQueryWithLimit(): void
    {
        $this->writeDataLines([
            ['id' => 'u1', 'score' => 1],
            ['id' => 'u2', 'score' => 2],
            ['id' => 'u3', 'score' => 3],
            ['id' => 'u4', 'score' => 4],
        ]);
        $this->secondaryIdx->rebuild($this->dataFile, 16);

        $dataFp  = fopen($this->dataFile, 'rb');
        $results = [];
        $count   = $this->secondaryIdx->rangeQuery($dataFp, 0, 10, function ($record) use (&$results) {
            $results[] = $record['id'];
        }, 2);
        fclose($dataFp);

        $this->assertEquals(2, $count);
        $this->assertEquals(['u1', 'u2'], $results);
    }

    public function testRangeQueryEarlyExit(): void
    {
        $this->writeDataLines([
            ['id' => 'u1', 'score' => 1],
            ['id' => 'u2', 'score' => 2],
            ['id' => 'u3', 'score' => 3],
        ]);
        $this->secondaryIdx->rebuild($this->dataFile, 16);

        $dataFp  = fopen($this->dataFile, 'rb');
        $results = [];
        $count   = $this->secondaryIdx->rangeQuery($dataFp, 0, 10, function ($record) use (&$results) {
            $results[] = $record['id'];
            return false; // Arresto anticipato
        });
        fclose($dataFp);

        $this->assertEquals(1, $count);
        $this->assertEquals(['u1'], $results);
    }

    public function testRangeQueryOnDirtyIndexReturnsMinusOne(): void
    {
        $this->secondaryIdx->createIfMissing();
        $this->assertTrue($this->secondaryIdx->isDirty());

        $dataFp = fopen($this->dataFile, 'rb');
        $count  = $this->secondaryIdx->rangeQuery($dataFp, null, null, function () {});
        fclose($dataFp);

        $this->assertEquals(-1, $count);
    }

    public function testRangeQueryUnbounded(): void
    {
        $this->writeDataLines([
            ['id' => 'u1', 'score' => 10],
            ['id' => 'u2', 'score' => 20],
        ]);
        $this->secondaryIdx->rebuild($this->dataFile, 16);

        $dataFp = fopen($this->dataFile, 'rb');
        $count  = $this->secondaryIdx->rangeQuery($dataFp, null, null, function () {});
        fclose($dataFp);

        $this->assertEquals(2, $count);
    }

    public function testRangeQueryNoResults(): void
    {
        $this->writeDataLines([
            ['id' => 'u1', 'score' => 10],
            ['id' => 'u2', 'score' => 20],
        ]);
        $this->secondaryIdx->rebuild($this->dataFile, 16);

        $dataFp = fopen($this->dataFile, 'rb');
        $count  = $this->secondaryIdx->rangeQuery($dataFp, 50, 100, function () {});
        fclose($dataFp);

        $this->assertEquals(0, $count);
    }

    public function testRangeQueryExactMatch(): void
    {
        $this->writeDataLines([
            ['id' => 'u1', 'score' => 10],
            ['id' => 'u2', 'score' => 20],
            ['id' => 'u3', 'score' => 30],
        ]);
        $this->secondaryIdx->rebuild($this->dataFile, 16);

        $dataFp  = fopen($this->dataFile, 'rb');
        $results = [];
        $count   = $this->secondaryIdx->rangeQuery($dataFp, 20, 20, function ($r) use (&$results) {
            $results[] = $r['id'];
        });
        fclose($dataFp);

        $this->assertEquals(1, $count);
        $this->assertEquals(['u2'], $results);
    }

    public function testRangeQueryInclusiveBounds(): void
    {
        $this->writeDataLines([
            ['id' => 'u1', 'score' => 10],
            ['id' => 'u2', 'score' => 20],
            ['id' => 'u3', 'score' => 30],
        ]);
        $this->secondaryIdx->rebuild($this->dataFile, 16);

        $dataFp  = fopen($this->dataFile, 'rb');
        $results = [];
        $this->secondaryIdx->rangeQuery($dataFp, 10, 30, function ($r) use (&$results) {
            $results[] = $r['id'];
        });
        fclose($dataFp);

        // Tutti e tre devono essere inclusi (bounds inclusi)
        $this->assertCount(3, $results);
    }

    public function testRangeQueryOnlyLowerBound(): void
    {
        $this->writeDataLines([
            ['id' => 'u1', 'score' => 5],
            ['id' => 'u2', 'score' => 15],
            ['id' => 'u3', 'score' => 25],
        ]);
        $this->secondaryIdx->rebuild($this->dataFile, 16);

        $dataFp  = fopen($this->dataFile, 'rb');
        $results = [];
        $this->secondaryIdx->rangeQuery($dataFp, 10, null, function ($r) use (&$results) {
            $results[] = $r['id'];
        });
        fclose($dataFp);

        $this->assertNotContains('u1', $results);
        $this->assertContains('u2', $results);
        $this->assertContains('u3', $results);
    }

    public function testRangeQueryOnlyUpperBound(): void
    {
        $this->writeDataLines([
            ['id' => 'u1', 'score' => 5],
            ['id' => 'u2', 'score' => 15],
            ['id' => 'u3', 'score' => 25],
        ]);
        $this->secondaryIdx->rebuild($this->dataFile, 16);

        $dataFp  = fopen($this->dataFile, 'rb');
        $results = [];
        $this->secondaryIdx->rangeQuery($dataFp, null, 10, function ($r) use (&$results) {
            $results[] = $r['id'];
        });
        fclose($dataFp);

        $this->assertContains('u1', $results);
        $this->assertNotContains('u2', $results);
        $this->assertNotContains('u3', $results);
    }

    public function testRangeQueryNegativeRange(): void
    {
        $this->writeDataLines([
            ['id' => 'u1', 'score' => -100],
            ['id' => 'u2', 'score' => -50],
            ['id' => 'u3', 'score' => 0],
            ['id' => 'u4', 'score' => 50],
        ]);
        $this->secondaryIdx->rebuild($this->dataFile, 16);

        $dataFp  = fopen($this->dataFile, 'rb');
        $results = [];
        $this->secondaryIdx->rangeQuery($dataFp, -75, -10, function ($r) use (&$results) {
            $results[] = $r['id'];
        });
        fclose($dataFp);

        $this->assertNotContains('u1', $results);
        $this->assertContains('u2', $results);
        $this->assertNotContains('u3', $results);
        $this->assertNotContains('u4', $results);
    }

    public function testRangeQueryStringField(): void
    {
        $idx = $this->makeIndex('name');
        $this->writeDataLines([
            ['id' => 'u1', 'name' => 'Alice'],
            ['id' => 'u2', 'name' => 'Bob'],
            ['id' => 'u3', 'name' => 'Charlie'],
            ['id' => 'u4', 'name' => 'Zara'],
        ]);
        $idx->rebuild($this->dataFile, 16);

        $dataFp  = fopen($this->dataFile, 'rb');
        $results = [];
        $idx->rangeQuery($dataFp, 'Alice', 'Charlie', function ($r) use (&$results) {
            $results[] = $r['name'];
        });
        fclose($dataFp);

        $this->assertContains('Alice',   $results);
        $this->assertContains('Bob',     $results);
        $this->assertContains('Charlie', $results);
        $this->assertNotContains('Zara', $results);
    }

    public function testRangeQueryFloatField(): void
    {
        $idx = $this->makeIndex('price');
        $this->writeDataLines([
            ['id' => 'p1', 'price' => 4.99],
            ['id' => 'p2', 'price' => 9.99],
            ['id' => 'p3', 'price' => 14.99],
        ]);
        $idx->rebuild($this->dataFile, 16);

        $dataFp  = fopen($this->dataFile, 'rb');
        $results = [];
        $idx->rangeQuery($dataFp, 5.0, 12.0, function ($r) use (&$results) {
            $results[] = $r['id'];
        });
        fclose($dataFp);

        $this->assertNotContains('p1', $results);
        $this->assertContains('p2', $results);
        $this->assertNotContains('p3', $results);
    }

    public function testRangeQueryResultsAreSortedByValue(): void
    {
        $this->writeDataLines([
            ['id' => 'u5', 'score' => 50],
            ['id' => 'u1', 'score' => 10],
            ['id' => 'u3', 'score' => 30],
            ['id' => 'u2', 'score' => 20],
            ['id' => 'u4', 'score' => 40],
        ]);
        $this->secondaryIdx->rebuild($this->dataFile, 16);

        $dataFp  = fopen($this->dataFile, 'rb');
        $scores  = [];
        $this->secondaryIdx->rangeQuery($dataFp, null, null, function ($r) use (&$scores) {
            $scores[] = $r['score'];
        });
        fclose($dataFp);

        $sorted = $scores;
        sort($sorted);
        $this->assertEquals($sorted, $scores, 'rangeQuery deve restituire i risultati in ordine crescente di valore');
    }

    public function testRangeQueryOnEmptyIndexReturnsZero(): void
    {
        $this->secondaryIdx->rebuild($this->dataFile, 16);

        $dataFp = fopen($this->dataFile, 'rb');
        $count  = $this->secondaryIdx->rangeQuery($dataFp, null, null, function () {});
        fclose($dataFp);

        $this->assertEquals(0, $count);
    }

    public function testRangeQueryLimitZeroMeansNoLimit(): void
    {
        $this->writeDataLines([
            ['id' => 'u1', 'score' => 1],
            ['id' => 'u2', 'score' => 2],
            ['id' => 'u3', 'score' => 3],
        ]);
        $this->secondaryIdx->rebuild($this->dataFile, 16);

        $dataFp = fopen($this->dataFile, 'rb');
        $count  = $this->secondaryIdx->rangeQuery($dataFp, null, null, function () {}, 0);
        fclose($dataFp);

        $this->assertEquals(3, $count);
    }

    // =========================================================================
    // TEST: Configurazione Statica – setSortChunkSize / getSortChunkSize
    // =========================================================================

    public function testSortChunkSizeConfiguration(): void
    {
      $this->assertTrue(JdbSecondaryIndex::setSortChunkSize(512));
        $this->assertEquals(512, JdbSecondaryIndex::getSortChunkSize());

        $this->assertFalse(JdbSecondaryIndex::setSortChunkSize(0));
        $this->assertFalse(JdbSecondaryIndex::setSortChunkSize(200000));
    }

    public function testSortChunkSizeBoundaryValues(): void
    {
        // Valori limite validi
        $this->assertTrue(JdbSecondaryIndex::setSortChunkSize(1));
        $this->assertEquals(1, JdbSecondaryIndex::getSortChunkSize());

        $this->assertTrue(JdbSecondaryIndex::setSortChunkSize(100000));
        $this->assertEquals(100000, JdbSecondaryIndex::getSortChunkSize());
    }

    public function testSortChunkSizeRejectsNonInteger(): void
    {
        // Un float non dovrebbe essere accettato come chunk size valido
        $before = JdbSecondaryIndex::getSortChunkSize();
        $result = JdbSecondaryIndex::setSortChunkSize(512.5);
        $this->assertFalse($result);
        $this->assertEquals($before, JdbSecondaryIndex::getSortChunkSize(), 'Il valore non deve cambiare su input invalido');
    }

    public function testSortChunkSizeRejectsNegative(): void
    {
        $this->assertFalse(JdbSecondaryIndex::setSortChunkSize(-1));
    }

    public function testSortChunkSizeInvalidDoesNotChangeCurrentValue(): void
    {
        JdbSecondaryIndex::setSortChunkSize(256);
        JdbSecondaryIndex::setSortChunkSize(0); // invalido
        $this->assertEquals(256, JdbSecondaryIndex::getSortChunkSize());
    }

    // =========================================================================
    // TEST: Scenari di integrazione
    // =========================================================================

    public function testMultipleRebuildsClearPreviousData(): void
    {
        // Prima costruzione con un set di dati
        $this->writeDataLines([
            ['id' => 'u1', 'score' => 100],
            ['id' => 'u2', 'score' => 200],
        ]);
        $this->secondaryIdx->rebuild($this->dataFile, 16);
        $this->assertEquals(100, $this->secondaryIdx->getEdgeValue('first'));

        // Sovrascrivi il file dati con nuovi record e rebuilda
        file_put_contents($this->dataFile, "<?php die(); ?>\n");
        $this->writeDataLines([
            ['id' => 'v1', 'score' => 5],
            ['id' => 'v2', 'score' => 10],
        ]);
        $this->secondaryIdx->rebuild($this->dataFile, 16);

        $this->assertEquals(5,  $this->secondaryIdx->getEdgeValue('first'));
        $this->assertEquals(10, $this->secondaryIdx->getEdgeValue('last'));
    }

    public function testIndexOnDifferentFieldsAreIndependent(): void
    {
        $idxScore = $this->secondaryIdx;
        $idxName  = $this->makeIndex('name');

        $this->writeDataLines([
            ['id' => 'u1', 'score' => 10, 'name' => 'Zara'],
            ['id' => 'u2', 'score' => 20, 'name' => 'Alice'],
        ]);

        $idxScore->rebuild($this->dataFile, 16);
        $idxName->rebuild($this->dataFile, 16);

        $this->assertEquals(10,      $idxScore->getEdgeValue('first'));
        $this->assertEquals('Alice', $idxName->getEdgeValue('first'));
    }

    public function testRebuildPreservesAllRecordsCountOnRangeQuery(): void
    {
        $count = 50;
        $records = [];
        for ($i = 1; $i <= $count; $i++) {
            $records[] = ['id' => "u$i", 'score' => $i];
        }
        // Inserisce in ordine inverso per verificare il sort
        $this->writeDataLines(array_reverse($records));
        $this->secondaryIdx->rebuild($this->dataFile, 16);

        $dataFp = fopen($this->dataFile, 'rb');
        $found  = 0;
        $this->secondaryIdx->rangeQuery($dataFp, null, null, function () use (&$found) {
            $found++;
        });
        fclose($dataFp);

        $this->assertEquals($count, $found);
    }
}
